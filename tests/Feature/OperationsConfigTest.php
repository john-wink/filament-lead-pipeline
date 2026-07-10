<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

it('reads the SLA threshold from config when no explicit value is passed', function (): void {
    Carbon::setTestNow('2026-03-15 12:00:00');
    config()->set('lead-pipeline.operations.sla_minutes', 240); // 4h SLA

    // Antwort nach 3h: unter konfiguriertem SLA (240), über Default (60).
    Lead::factory()->create([
        'created_at'        => now()->subHours(5),
        'first_response_at' => now()->subHours(2),
    ]);

    $stats = app(LeadActivityMetricsService::class)->responseStats(
        Lead::query(),
        CarbonImmutable::now()->subDays(1),
        CarbonImmutable::now(),
    );

    expect($stats['sla_pct'])->toBe(100.0);

    config()->set('lead-pipeline.operations.sla_minutes', 60);
    expect(app(LeadActivityMetricsService::class)->responseStats(
        Lead::query(),
        CarbonImmutable::now()->subDays(1),
        CarbonImmutable::now(),
    )['sla_pct'])->toBe(0.0);
});

it('ships default operations config values', function (): void {
    expect((int) config('lead-pipeline.operations.sla_minutes'))->toBe(60)
        ->and(config('lead-pipeline.operations.score_weights'))->toBe([
            'activity' => 30, 'tempo' => 25, 'result' => 30, 'diligence' => 15,
        ]);
});
