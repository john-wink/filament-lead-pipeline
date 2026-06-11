<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaCreativesJob;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaInsightsJob;
use JohnWink\FilamentLeadPipeline\Models\LeadReportAdSource;

class SyncMetaReportsCommand extends Command
{
    protected $signature = 'lead-pipeline:sync-meta-reports {--days=} {--skip-creatives}';

    protected $description = 'Dispatcht Meta-Insights- und Creative-Syncs für alle aktiven Reports';

    public function handle(): int
    {
        $days = (int) ($this->option('days') ?? config('lead-pipeline.reports.sync.backfill_days', 28));

        $sources = LeadReportAdSource::query()
            ->whereHas('report', fn ($query) => $query->where('is_active', true))
            ->get()
            ->unique('ad_account_id');

        foreach ($sources as $source) {
            SyncMetaInsightsJob::dispatch($source->facebook_connection_uuid, $source->ad_account_id, $source->campaign_ids, $days);

            if ( ! $this->option('skip-creatives')) {
                SyncMetaCreativesJob::dispatch($source->facebook_connection_uuid, $source->ad_account_id, $source->campaign_ids);
            }
        }

        $this->info("Dispatched syncs for {$sources->count()} ad accounts.");

        return self::SUCCESS;
    }
}
