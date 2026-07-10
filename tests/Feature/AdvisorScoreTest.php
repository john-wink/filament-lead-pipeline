<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

// activityBy2()/scopedLeads2(): file-local re-declarations of AdvisorMatrixTest's
// activityBy()/scopedLeads() helpers — Pest test files share one PHP process, so a
// same-named global function would fatal with "Cannot redeclare".
function scopedLeads2(): Illuminate\Database\Eloquent\Builder
{
    return Lead::query();
}

function activityBy2(Lead $lead, LeadActivityTypeEnum $type, int|string $causerId, CarbonImmutable $at, array $properties = []): void
{
    $activity = $lead->activities()->make([
        'type'        => $type->value,
        'properties'  => $properties,
        'causer_type' => config('lead-pipeline.user_model'),
        'causer_id'   => $causerId,
    ]);
    $activity->forceFill(['created_at' => $at]);
    $activity->save();
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('scores an active advisor above an idle one and respects configured weights', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
    config()->set('lead-pipeline.operations.score_weights', ['activity' => 100, 'tempo' => 0, 'result' => 0, 'diligence' => 0]);

    // Host `users.name` is a generated column (first_name + last_name) —
    // factories must set the parts, not `name` directly.
    $active = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Aktiv', 'last_name' => '']);
    $idle   = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Passiv', 'last_name' => '']);
    $board  = LeadBoard::factory()->create();
    $open   = LeadPhase::factory()->for($board, 'board')->open()->create();

    $leadA = Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $active->getKey()]);
    Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $idle->getKey()]);

    foreach (range(1, 4) as $i) {
        activityBy2($leadA, LeadActivityTypeEnum::Call, $active->getKey(), CarbonImmutable::parse('2026-06-14')->addHours($i));
    }

    $rows = collect(app(LeadActivityMetricsService::class)->advisorActivityMatrix(
        scopedLeads2(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    )['rows'])->keyBy('advisor_name');

    expect($rows['Aktiv']['scores']['total'])->toBeGreaterThan($rows['Passiv']['scores']['total'])
        ->and($rows['Passiv']['scores']['activity'])->toBe(0.0)
        ->and($rows['Aktiv']['scores']['total'])->toBe($rows['Aktiv']['scores']['activity']); // Gewicht 100/0/0/0
});

it('computes deltas against the equally sized previous window', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $advisor = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Delta', 'last_name' => '']);
    $board   = LeadBoard::factory()->create();
    $open    = LeadPhase::factory()->for($board, 'board')->open()->create();
    $lead    = Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $advisor->getKey()]);

    // Vorfenster (Mai): 3 Calls; aktuelles Fenster (Juni): 1 Call.
    foreach (range(1, 3) as $i) {
        activityBy2($lead, LeadActivityTypeEnum::Call, $advisor->getKey(), CarbonImmutable::parse('2026-05-10')->addHours($i));
    }
    activityBy2($lead, LeadActivityTypeEnum::Call, $advisor->getKey(), CarbonImmutable::parse('2026-06-10'));

    $row = collect(app(LeadActivityMetricsService::class)->advisorActivityMatrix(
        scopedLeads2(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    )['rows'])->firstWhere('advisor_name', 'Delta');

    expect($row['delta_score'])->not->toBeNull()
        ->and($row['delta_won'])->toBe(0);
});

it('returns null deltas for an unbounded range', function (): void {
    $advisor = config('lead-pipeline.user_model')::factory()->create();
    $board   = LeadBoard::factory()->create();
    $open    = LeadPhase::factory()->for($board, 'board')->open()->create();
    Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $advisor->getKey()]);

    $row = collect(app(LeadActivityMetricsService::class)->advisorActivityMatrix(
        scopedLeads2(),
        null,
        null,
    )['rows'])->first();

    expect($row['delta_score'])->toBeNull()
        ->and($row['delta_won'])->toBeNull();
});
