<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

it('prunes logs older than the retention window', function (): void {
    config()->set('lead-pipeline.webhooks.logging.retention_days', 30);

    $old = LeadWebhookLog::create(['event_type' => WebhookLogEventType::Incoming, 'outcome' => 'created']);
    $old->forceFill(['created_at' => now()->subDays(40)])->saveQuietly();

    $new = LeadWebhookLog::create(['event_type' => WebhookLogEventType::Incoming, 'outcome' => 'created']);
    $new->forceFill(['created_at' => now()->subDays(5)])->saveQuietly();

    $this->artisan('lead-pipeline:prune-webhook-logs')->assertSuccessful();

    expect(LeadWebhookLog::query()->count())->toBe(1)
        ->and(LeadWebhookLog::query()->first()->getKey())->toBe($new->getKey());
});

it('accepts a --days override', function (): void {
    $log = LeadWebhookLog::create(['event_type' => WebhookLogEventType::Incoming, 'outcome' => 'created']);
    $log->forceFill(['created_at' => now()->subDays(10)])->saveQuietly();

    $this->artisan('lead-pipeline:prune-webhook-logs', ['--days' => 7])->assertSuccessful();

    expect(LeadWebhookLog::query()->count())->toBe(0);
});
