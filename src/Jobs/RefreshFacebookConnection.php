<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Carbon\CarbonInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshFailed;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;
use Throwable;

/**
 * Refreshes a single Facebook connection's long-lived token, surviving transient
 * failures with exponential backoff and only escalating to NeedsReauth on terminal
 * (token-invalid) errors or after repeated transient failures past expiry.
 */
class RefreshFacebookConnection implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 1;

    public function __construct(public FacebookConnection $facebookConnection) {}

    public function handle(FacebookGraphService $facebook, FacebookPageSynchronizer $synchronizer): void
    {
        $connection = $this->facebookConnection->fresh();

        if (null === $connection || ! $connection->isConnected()) {
            return;
        }

        if ($this->isInBackoff($connection)) {
            return;
        }

        try {
            $result = $facebook->refreshLongLivedToken($connection->access_token);
        } catch (FacebookTokenInvalidException $e) {
            $this->markNeedsReauth($connection, $e->getMessage());

            return;
        } catch (FacebookTransientException $e) {
            $this->recordTransientFailure($connection, $e->getMessage());

            return;
        }

        $token     = $result['access_token'] ?? null;
        $expiresIn = $result['expires_in'] ?? null;

        if ( ! is_string($token) || '' === $token || ! is_int($expiresIn) || $expiresIn <= 0) {
            $this->recordTransientFailure($connection, 'Malformed token refresh response from Facebook.');

            return;
        }

        $connection->forceFill([
            'access_token'              => $token,
            'token_expires_at'          => now()->addSeconds($expiresIn),
            'last_refreshed_at'         => now(),
            'refresh_attempts'          => 0,
            'refresh_failed_at'         => null,
            'last_error'                => null,
            'expiring_soon_notified_at' => null,
        ])->save();

        try {
            $synchronizer->sync($connection);
        } catch (FacebookTokenInvalidException $e) {
            $this->markNeedsReauth($connection, $e->getMessage());

            return;
        } catch (Throwable) {
            // Page sync is non-fatal to token health; the token itself is valid.
        }

        FacebookTokenRefreshed::dispatch($connection);
    }

    private function isInBackoff(FacebookConnection $connection): bool
    {
        if (null === $connection->refresh_failed_at) {
            return false;
        }

        return now()->lessThan(
            $connection->refresh_failed_at->copy()->addSeconds($this->backoffSeconds($connection->refresh_attempts)),
        );
    }

    private function recordTransientFailure(FacebookConnection $connection, string $reason): void
    {
        $attempts = $connection->refresh_attempts + 1;

        $connection->forceFill([
            'refresh_attempts'  => $attempts,
            'refresh_failed_at' => now(),
            'last_error'        => Str::limit($reason, 1000),
        ])->save();

        if ($this->shouldEscalate($attempts, $connection->token_expires_at)) {
            $this->markNeedsReauth($connection, 'Escalated to re-auth after repeated transient failures.');

            return;
        }

        FacebookTokenRefreshFailed::dispatch($connection, $attempts);
    }

    private function markNeedsReauth(FacebookConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($reason, 1000),
        ])->save();

        $connection->pages()
            ->whereHas('leadSources')
            ->each(function (FacebookPage $page): void {
                $page->leadSources()->update([
                    'status'        => LeadSourceStatusEnum::Error,
                    'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
                ]);
            });

        FacebookConnectionNeedsReauth::dispatch($connection, $reason);
    }

    private function shouldEscalate(int $attempts, ?CarbonInterface $tokenExpiresAt): bool
    {
        $maxAttempts = (int) config('lead-pipeline.facebook.refresh.max_attempts', 5);

        return $attempts >= $maxAttempts
            && null !== $tokenExpiresAt
            && $tokenExpiresAt->isPast();
    }

    private function backoffSeconds(int $attempts): int
    {
        $base = (int) config('lead-pipeline.facebook.refresh.backoff_base', 300);
        $max  = (int) config('lead-pipeline.facebook.refresh.backoff_max', 21600);

        $exponent = max(0, $attempts - 1);

        return (int) min($max, $base * (2 ** $exponent));
    }
}
