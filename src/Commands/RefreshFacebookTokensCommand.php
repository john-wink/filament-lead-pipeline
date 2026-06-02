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

class RefreshFacebookTokensCommand extends Command
{
    protected $signature = 'lead-pipeline:facebook:refresh-tokens {--queue : Offload each connection refresh to the queue}';

    protected $description = 'Refresh Facebook long-lived tokens, warn about expiring ones, and report refresh health.';

    public function handle(): int
    {
        $this->reportHealth();

        $warningDays = (int) config('lead-pipeline.facebook.refresh.warning_days', 7);
        $threshold   = now()->addDays($warningDays);

        $this->warnExpiringSoon($threshold);

        $useQueue = (bool) ($this->option('queue') || config('lead-pipeline.facebook.refresh.queue', false));

        FacebookConnection::query()
            ->dueForRefresh($threshold)
            ->get()
            ->each(function (FacebookConnection $connection) use ($useQueue): void {
                $useQueue
                    ? RefreshFacebookConnection::dispatch($connection)
                    : RefreshFacebookConnection::dispatchSync($connection);
            });

        return self::SUCCESS;
    }

    private function warnExpiringSoon(CarbonInterface $threshold): void
    {
        FacebookConnection::query()
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->whereNotNull('token_expires_at')
            ->where('token_expires_at', '<=', $threshold)
            ->whereNull('expiring_soon_notified_at')
            ->get()
            ->each(function (FacebookConnection $connection): void {
                $daysLeft = max(0, (int) now()->diffInDays($connection->token_expires_at));
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
            ->where('token_expires_at', '<', now()->subHours($hours))
            ->get();

        if ($stuck->isNotEmpty()) {
            FacebookRefreshHealthCheckFailed::dispatch($stuck, $hours);
        }
    }
}
