<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

class SyncImmoScoutLeadsCommand extends Command
{
    protected $signature = 'lead-pipeline:sync-immoscout-leads {--days= : Explizites Zeitfenster in Tagen statt inkrementellem Abruf}';

    protected $description = 'Importiert neue ImmoScout24-Baufinanzierungs-Leads für alle aktiven Quellen.';

    public function handle(): int
    {
        $days = null !== $this->option('days') ? (int) $this->option('days') : null;

        $dispatched = 0;

        LeadSource::query()
            ->where('driver', 'immoscout24')
            ->where('status', LeadSourceStatusEnum::Active)
            ->each(function (LeadSource $source) use ($days, &$dispatched): void {
                $config = $source->config ?? [];

                if (false === ($config['auto_sync'] ?? true)) {
                    return;
                }

                if (blank($config['immoscout_connection_uuid'] ?? null)) {
                    return;
                }

                ImportImmoScoutLeadsJob::dispatch($source, $days);
                $dispatched++;
            });

        $this->info(sprintf('Dispatched %d ImmoScout24 import job(s).', $dispatched));

        return self::SUCCESS;
    }
}
