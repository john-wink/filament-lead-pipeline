<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Jobs;

use Exception;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

class RefreshFacebookTokens implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function handle(): void
    {
        $facebook = app(FacebookGraphService::class);

        $connections = FacebookConnection::query()
            ->where('status', 'connected')
            ->where('token_expires_at', '<=', now()->addDays(7))
            ->get();

        foreach ($connections as $connection) {
            try {
                $result = $facebook->refreshLongLivedToken($connection->access_token);

                $connection->update([
                    'access_token'     => $result['access_token'],
                    'token_expires_at' => now()->addSeconds($result['expires_in'] ?? 5184000),
                ]);

                $pages = $facebook->getUserPages($result['access_token']);

                foreach ($pages as $pageData) {
                    FacebookPage::query()->updateOrCreate(
                        [
                            'facebook_connection_uuid' => $connection->uuid,
                            'page_id'                  => $pageData['id'],
                        ],
                        [
                            'page_name'         => $pageData['name'],
                            'page_access_token' => $pageData['access_token'],
                        ],
                    );
                }
            } catch (Exception $e) {
                $connection->update(['status' => 'expired']);

                $connection->pages()
                    ->whereHas('leadSources')
                    ->each(function (FacebookPage $page) use ($e): void {
                        $page->leadSources()->update([
                            'status'        => LeadSourceStatusEnum::Error,
                            'error_message' => 'Facebook-Token abgelaufen: ' . $e->getMessage(),
                        ]);
                    });
            }
        }
    }
}
