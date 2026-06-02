<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
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
            ->where('status', FacebookConnectionStatusEnum::Connected)
            ->get()
            ->each(function (FacebookConnection $connection) use ($synchronizer): void {
                try {
                    $synchronizer->sync($connection);
                } catch (FacebookTokenInvalidException $e) {
                    $this->markNeedsReauth($connection, $e->getMessage());
                } catch (Throwable $e) {
                    Log::warning('SyncFacebookPages: sync failed', [
                        'connection' => $connection->uuid,
                        'error'      => $e->getMessage(),
                    ]);
                }
            });
    }

    private function markNeedsReauth(FacebookConnection $connection, string $reason): void
    {
        $connection->forceFill([
            'status'     => FacebookConnectionStatusEnum::NeedsReauth,
            'last_error' => Str::limit($reason, 1000),
        ])->save();

        $connection->pages()
            ->whereHas('leadSources')
            ->each(fn (FacebookPage $page) => $page->leadSources()->update([
                'status'        => LeadSourceStatusEnum::Error,
                'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
            ]));

        FacebookConnectionNeedsReauth::dispatch($connection, $reason);
    }
}
