<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

class PruneWebhookLogsCommand extends Command
{
    protected $signature = 'lead-pipeline:prune-webhook-logs
        {--days= : Aufbewahrungsfrist in Tagen (überschreibt die Config)}';

    protected $description = 'Löscht Webhook-Logs, die älter als die konfigurierte Aufbewahrungsfrist sind';

    public function handle(): int
    {
        $days   = (int) ($this->option('days') ?? config('lead-pipeline.webhooks.logging.retention_days', 30));
        $cutoff = now()->subDays($days);

        $deleted = LeadWebhookLog::query()->where('created_at', '<', $cutoff)->delete();

        $this->info("Gelöscht: {$deleted} Webhook-Logs älter als {$days} Tage.");

        return self::SUCCESS;
    }
}
