<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookForm;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use Throwable;

class FacebookPageSynchronizer
{
    public function __construct(
        private FacebookGraphService $facebook,
    ) {}

    /**
     * Synchronise Facebook pages (and their lead forms) for the given connection
     * with what Facebook currently returns.
     *
     * Adds new pages, updates known pages (restoring soft-deleted ones if they
     * reappear), and soft-deletes pages that Facebook no longer lists. The same
     * additive/destructive sync is applied to the forms of each kept page.
     *
     * @return array{added: int, updated: int, removed: int, forms_synced: int}
     */
    public function sync(FacebookConnection $connection): array
    {
        $remotePages = array_values(array_filter(
            $this->facebook->getUserPages($connection->access_token),
            fn (array $page): bool => $this->hasRequiredTasks($page['tasks'] ?? []),
        ));
        $remoteIds = array_column($remotePages, 'id');

        $added       = 0;
        $updated     = 0;
        $formsSynced = 0;

        foreach ($remotePages as $remote) {
            $page = FacebookPage::withTrashed()
                ->where('facebook_connection_uuid', $connection->uuid)
                ->where('page_id', $remote['id'])
                ->first();

            if ($page === null) {
                $page = FacebookPage::query()->create([
                    'facebook_connection_uuid' => $connection->uuid,
                    'page_id'                  => $remote['id'],
                    'page_name'                => $remote['name'],
                    'page_access_token'        => $remote['access_token'],
                ]);
                $added++;
            } else {
                if ($page->trashed()) {
                    $page->restore();
                }

                $page->update([
                    'page_name'         => $remote['name'],
                    'page_access_token' => $remote['access_token'],
                ]);
                $updated++;
            }

            $formsSynced += $this->syncFormsFor($page, $remote['access_token']);
        }

        $removed = FacebookPage::query()
            ->where('facebook_connection_uuid', $connection->uuid)
            ->when($remoteIds !== [], fn ($query) => $query->whereNotIn('page_id', $remoteIds))
            ->delete();

        return [
            'added'        => $added,
            'updated'      => $updated,
            'removed'      => $removed,
            'forms_synced' => $formsSynced,
        ];
    }

    private function syncFormsFor(FacebookPage $page, string $pageAccessToken): int
    {
        try {
            $remoteForms = $this->facebook->getPageLeadForms($page->page_id, $pageAccessToken);
        } catch (Throwable $e) {
            Log::warning('FacebookPageSynchronizer: failed to fetch lead forms', [
                'page_id' => $page->page_id,
                'error'   => $e->getMessage(),
            ]);

            return 0;
        }

        $remoteFormIds = array_column($remoteForms, 'id');

        foreach ($remoteForms as $remote) {
            FacebookForm::query()->updateOrCreate(
                [
                    'facebook_page_uuid' => $page->uuid,
                    'form_id'            => $remote['id'],
                ],
                [
                    'form_name' => $remote['name'] ?? "Form {$remote['id']}",
                    'status'    => $remote['status'] ?? null,
                    'cached_at' => now(),
                ],
            );
        }

        FacebookForm::query()
            ->where('facebook_page_uuid', $page->uuid)
            ->when($remoteFormIds !== [], fn ($query) => $query->whereNotIn('form_id', $remoteFormIds))
            ->delete();

        return count($remoteForms);
    }

    /**
     * @param  array<int, string>  $tasks
     */
    private function hasRequiredTasks(array $tasks): bool
    {
        $requirements = FacebookGraphService::LEAD_PIPELINE_REQUIRED_TASKS;

        $hasAll = [] === array_diff($requirements['required_all'], $tasks);
        $hasAny = [] !== array_intersect($requirements['required_any'], $tasks);

        return $hasAll && $hasAny;
    }
}
