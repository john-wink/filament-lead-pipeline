<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Concerns\MarksConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;
use Throwable;

class SyncFacebookPages implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use MarksConnectionNeedsReauth;
    use Queueable;
    use SerializesModels;

    public function handle(FacebookPageSynchronizer $synchronizer): void
    {
        FacebookConnection::query()
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->get()
            ->each(function (FacebookConnection $connection) use ($synchronizer): void {
                try {
                    $synchronizer->sync($connection);
                } catch (FacebookTokenInvalidException $e) {
                    $this->markConnectionNeedsReauth($connection, $e->getMessage());
                } catch (Throwable $e) {
                    Log::warning('SyncFacebookPages: sync failed', [
                        'connection' => $connection->uuid,
                        'error'      => $e->getMessage(),
                    ]);
                }
            });
    }
}
