<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

function scoped(): Illuminate\Database\Eloquent\Builder
{
    return Lead::query();
}

it('computes response average, buckets and SLA', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');

    // responded in 30 min (under_1h, within 60-min SLA)
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => $now->subDays(1)->addMinutes(30)]);
    // responded in 5 hours (h1_24, breaches SLA)
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => $now->subDays(1)->addHours(5)]);
    // never responded
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => null]);

    $stats = app(LeadActivityMetricsService::class)->responseStats(scoped(), $now->subDays(7), $now, 60);

    expect($stats['total'])->toBe(3)
        ->and($stats['responded'])->toBe(2)
        ->and($stats['buckets']['under_1h'])->toBe(1)
        ->and($stats['buckets']['h1_24'])->toBe(1)
        ->and($stats['avg_minutes'])->toBe(165.0)   // (30 + 300) / 2
        ->and($stats['sla_pct'])->toBe(50.0);       // 1 of 2 responded within SLA
});

it('treats a backdated first_response_at by magnitude, never as negative', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');

    // Data anomaly: response stamped 90 min BEFORE the lead's created_at.
    // Signed diff would be -90 → silently under_1h + SLA-compliant. Absolute → 90 min.
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => $now->subDays(1)->subMinutes(90)]);

    $stats = app(LeadActivityMetricsService::class)->responseStats(scoped(), $now->subDays(7), $now, 60);

    expect($stats['responded'])->toBe(1)
        ->and($stats['buckets']['under_1h'])->toBe(0)   // NOT under_1h
        ->and($stats['buckets']['h1_24'])->toBe(1)      // 90 min → h1_24
        ->and($stats['sla_pct'])->toBe(0.0)             // 90 > 60 → NOT SLA-compliant
        ->and($stats['avg_minutes'])->toBe(90.0);
});
