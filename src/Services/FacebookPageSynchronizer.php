<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;

class FacebookPageSynchronizer
{
    public function __construct(
        private FacebookGraphService $facebook,
    ) {}

    /**
     * Synchronise Facebook pages for the given connection with what Facebook currently returns.
     *
     * Adds new pages, updates known pages (restoring soft-deleted ones if they reappear),
     * and soft-deletes pages that Facebook no longer lists.
     *
     * @return array{added: int, updated: int, removed: int}
     */
    public function sync(FacebookConnection $connection): array
    {
        $remotePages = $this->facebook->getUserPages($connection->access_token);
        $remoteIds   = array_column($remotePages, 'id');

        $added   = 0;
        $updated = 0;

        foreach ($remotePages as $remote) {
            $page = FacebookPage::withTrashed()
                ->where('facebook_connection_uuid', $connection->uuid)
                ->where('page_id', $remote['id'])
                ->first();

            if ($page === null) {
                FacebookPage::query()->create([
                    'facebook_connection_uuid' => $connection->uuid,
                    'page_id'                  => $remote['id'],
                    'page_name'                => $remote['name'],
                    'page_access_token'        => $remote['access_token'],
                ]);
                $added++;

                continue;
            }

            if ($page->trashed()) {
                $page->restore();
            }

            $page->update([
                'page_name'         => $remote['name'],
                'page_access_token' => $remote['access_token'],
            ]);
            $updated++;
        }

        $removed = FacebookPage::query()
            ->where('facebook_connection_uuid', $connection->uuid)
            ->when($remoteIds !== [], fn ($query) => $query->whereNotIn('page_id', $remoteIds))
            ->delete();

        return [
            'added'   => $added,
            'updated' => $updated,
            'removed' => $removed,
        ];
    }
}
