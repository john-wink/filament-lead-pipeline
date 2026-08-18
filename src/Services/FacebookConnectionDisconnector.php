<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookForm;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadReportAdSource;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Throwable;

class FacebookConnectionDisconnector
{
    public function __construct(
        private FacebookGraphService $facebook,
    ) {}

    /**
     * Revokes the Meta app authorization (best effort — an already dead token
     * must never block the disconnect) and hard-deletes the connection with
     * its pages, forms and report ad sources. Leads, lead sources and reports
     * survive; lead sources keep their external facebook_page_id so the page
     * synchronizer can relink them on a later reconnect.
     *
     * The child rows are deleted explicitly instead of relying on the
     * DB-level cascades: the foreign keys were added via ALTER TABLE
     * (migration 0025), which SQLite silently drops — this keeps the
     * behavior identical across the test and production databases.
     */
    public function disconnect(FacebookConnection $connection): void
    {
        try {
            $this->facebook->revokePermissions($connection->access_token);
        } catch (Throwable $e) {
            Log::warning('Facebook permission revocation failed during disconnect', [
                'connection_uuid' => $connection->uuid,
                'error'           => $e->getMessage(),
            ]);
        }

        DB::transaction(function () use ($connection): void {
            $pageUuids = FacebookPage::withTrashed()
                ->where('facebook_connection_uuid', $connection->uuid)
                ->pluck('uuid');

            FacebookForm::query()->whereIn('facebook_page_uuid', $pageUuids)->delete();

            LeadSource::withTrashed()
                ->whereIn('facebook_page_uuid', $pageUuids)
                ->update(['facebook_page_uuid' => null]);

            FacebookPage::withTrashed()
                ->where('facebook_connection_uuid', $connection->uuid)
                ->forceDelete();

            LeadReportAdSource::query()
                ->where('facebook_connection_uuid', $connection->uuid)
                ->delete();

            $connection->delete();
        });
    }
}
