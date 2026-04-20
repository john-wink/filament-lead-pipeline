<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;
use Throwable;

class SyncFacebookPages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(FacebookPageSynchronizer $synchronizer): void
    {
        FacebookConnection::query()
            ->where('status', 'connected')
            ->get()
            ->each(function (FacebookConnection $connection) use ($synchronizer): void {
                try {
                    $synchronizer->sync($connection);
                } catch (Throwable) {
                    // Leave token-refresh to RefreshFacebookTokens; skip silently here.
                }
            });
    }
}
