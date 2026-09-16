<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Carbon\CarbonInterface;
use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookRefreshHealthCheckFailed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use Throwable;

class RefreshFacebookTokensCommand extends Command
{
    /** @var array<string, string> */
    private const APP_CREDENTIALS = [
        'lead-pipeline.facebook.client_id'     => 'FACEBOOK_CLIENT_ID (or FACEBOOK_APP_ID)',
        'lead-pipeline.facebook.client_secret' => 'FACEBOOK_CLIENT_SECRET (or FACEBOOK_APP_SECRET)',
    ];

    protected $signature = 'lead-pipeline:facebook:refresh-tokens {--queue : Offload each connection refresh to the queue}';

    protected $description = 'Refresh Facebook long-lived tokens, warn about expiring ones, and report refresh health.';

    public function handle(): int
    {
        $this->reportHealth();

        $warningDays = (int) config('lead-pipeline.facebook.refresh.warning_days', 7);
        $threshold   = now()->addDays($warningDays);

        $missingCredentials = $this->missingAppCredentials();

        if ([] !== $missingCredentials && FacebookConnection::query()->dueForRefresh($threshold)->exists()) {
            $this->reportMissingAppCredentials($missingCredentials);

            return self::FAILURE;
        }

        $this->warnExpiringSoon($threshold);

        $useQueue = (bool) ($this->option('queue') || config('lead-pipeline.facebook.refresh.queue', false));

        $failedConnections = 0;

        FacebookConnection::query()
            ->dueForRefresh($threshold)
            ->get()
            ->each(function (FacebookConnection $connection) use ($useQueue, &$failedConnections): void {
                try {
                    if ($useQueue) {
                        RefreshFacebookConnection::dispatch($connection);
                    } else {
                        RefreshFacebookConnection::dispatchSync($connection);
                    }
                } catch (Throwable $exception) {
                    report($exception);

                    $failedConnections++;

                    $this->error(sprintf(
                        'Refreshing Facebook connection %s failed unexpectedly (%s); the exception was reported.',
                        $connection->getKey(),
                        $exception::class,
                    ));
                }
            });

        return 0 === $failedConnections ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array<string, string>
     */
    private function missingAppCredentials(): array
    {
        return array_filter(
            self::APP_CREDENTIALS,
            fn (string $configKey): bool => blank(config($configKey)),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * @param  array<string, string>  $missingCredentials
     */
    private function reportMissingAppCredentials(array $missingCredentials): void
    {
        foreach ($missingCredentials as $configKey => $envNames) {
            $this->error(sprintf('Facebook token refresh skipped: %s is not configured (set %s).', $configKey, $envNames));
        }

        $this->line('No connection was touched. Configure the Meta app credentials, or disable the scheduled refresh via lead-pipeline.facebook.refresh.enabled (LEAD_PIPELINE_FB_REFRESH_ENABLED=false).');
    }

    private function warnExpiringSoon(CarbonInterface $threshold): void
    {
        FacebookConnection::query()
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '>', now())
            ->where('token_expires_at', '<=', $threshold)
            ->whereNull('expiring_soon_notified_at')
            ->get()
            ->each(function (FacebookConnection $connection): void {
                $daysLeft = max(0, (int) ceil((float) now()->diffInDays($connection->token_expires_at)));
                $connection->forceFill(['expiring_soon_notified_at' => now()])->save();
                FacebookTokenExpiringSoon::dispatch($connection, $daysLeft);
            });
    }

    private function reportHealth(): void
    {
        $hours = (int) config('lead-pipeline.facebook.refresh.health_hours', 3);

        $stuck = FacebookConnection::query()
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<', now())
            ->where(function (\Illuminate\Database\Eloquent\Builder $q) use ($hours): void {
                $q->whereNull('last_refreshed_at')
                    ->orWhere('last_refreshed_at', '<', now()->subHours($hours));
            })
            ->get();

        if ($stuck->isNotEmpty()) {
            FacebookRefreshHealthCheckFailed::dispatch($stuck, $hours);
        }
    }
}
