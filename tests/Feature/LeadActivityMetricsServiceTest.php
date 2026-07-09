<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

function scoped(): Illuminate\Database\Eloquent\Builder
{
    return Lead::query();
}

/**
 * `LeadActivity::$fillable` intentionally excludes `created_at`, so a plain
 * `activities()->create(['created_at' => ...])` is silently discarded by mass
 * assignment and Eloquent stamps `now()` instead. `make()` + `forceFill()` sets
 * it as a dirty attribute before the first save, so `updateTimestamps()` skips
 * overwriting it — the explicit timestamp actually persists.
 */
function movedActivity(Lead $lead, array $properties, Carbon\CarbonInterface $createdAt): LeadActivity
{
    $activity = $lead->activities()->make(['type' => LeadActivityTypeEnum::Moved->value, 'properties' => $properties]);
    $activity->forceFill(['created_at' => $createdAt]);
    $activity->save();

    return $activity;
}

afterEach(fn () => CarbonImmutable::setTestNow());

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

it('computes follow-up, untouched and contact-attempt stats (snapshot)', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');
    CarbonImmutable::setTestNow($now);

    // A: active, OVERDUE reminder, old, 2 contact attempts (Call + Email)
    $a = Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => $now->subDay(), 'created_at' => $now->subDays(3)]);
    $a->activities()->create(['type' => LeadActivityTypeEnum::Call->value, 'created_at' => $now->subDays(2)]);
    $a->activities()->create(['type' => LeadActivityTypeEnum::Email->value, 'created_at' => $now->subDay()]);

    // B: active, old (20d), NO activity → untouched
    Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => null, 'created_at' => $now->subDays(20)]);

    // C: active, FUTURE reminder, YOUNG (2d), no activity → NOT overdue, NOT untouched, but has a next step
    Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => $now->addDays(2), 'created_at' => $now->subDays(2)]);

    // D: active, old (20d), only a NOTE activity → NOT untouched (Note is a touch), Note NOT a contact attempt
    $d = Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => null, 'created_at' => $now->subDays(20)]);
    $d->activities()->create(['type' => LeadActivityTypeEnum::Note->value, 'created_at' => $now->subDays(10)]);

    $stats = app(LeadActivityMetricsService::class)->operationsStats(scoped());

    expect($stats['overdue_followups'])->toBe(1)          // A only (C is future)
        ->and($stats['untouched'])->toBe(1)               // B only (C young, D has a Note touch)
        ->and($stats['next_step_rate'])->toBe(50.0)       // A + C have reminders, of 4 active
        ->and($stats['avg_contact_attempts'])->toBe(0.5); // 2 Call/Email attempts / 4 active (D's Note excluded)
});

it('aggregates loss reasons descending', function (): void {
    Lead::factory()->count(3)->create(['lost_reason' => 'Finanzierung geplatzt']);
    Lead::factory()->create(['lost_reason' => 'Budget zu klein']);
    Lead::factory()->create(['lost_reason' => null]);

    $reasons = app(LeadActivityMetricsService::class)->lossReasons(scoped());

    expect($reasons[0]['reason'])->toBe('Finanzierung geplatzt')
        ->and($reasons[0]['count'])->toBe(3)
        ->and(collect($reasons)->firstWhere('reason', null))->toBeNull(); // nulls excluded
});

it('builds a funnel with drop-off per phase', function (): void {
    $board = LeadBoard::factory()->create();
    $p1    = LeadPhase::factory()->create([LeadPhase::fkColumn('lead_board') => $board->getKey(), 'sort' => 1, 'name' => 'Anfrage']);
    $p2    = LeadPhase::factory()->create([LeadPhase::fkColumn('lead_board') => $board->getKey(), 'sort' => 2, 'name' => 'Qualifiziert']);

    // The LeadBoardObserver mandatorily attaches a terminal "Nicht qualifiziert"
    // phase (sort = 1) on board creation, before p1/p2 exist. Push it past p2 so
    // it doesn't land between our two phases and skew the ordered funnel/drop_pct.
    $board->phases()->where('type', LeadPhaseTypeEnum::Disqualified->value)->update(['sort' => 99]);

    Lead::factory()->count(4)->create([Lead::fkColumn('lead_board') => $board->getKey(), Lead::fkColumn('lead_phase') => $p1->getKey()]);
    Lead::factory()->count(1)->create([Lead::fkColumn('lead_board') => $board->getKey(), Lead::fkColumn('lead_phase') => $p2->getKey()]);

    $funnel = app(LeadActivityMetricsService::class)->funnel($board);

    expect($funnel[0]['label'])->toBe('Anfrage')
        ->and($funnel[0]['count'])->toBe(4)
        ->and($funnel[1]['label'])->toBe('Qualifiziert')
        ->and($funnel[1]['count'])->toBe(1)
        ->and($funnel[1]['drop_pct'])->toBe(75.0);
});

it('computes average dwell time per phase from moved activities', function (): void {
    $lead = Lead::factory()->create();

    movedActivity($lead, ['new_phase' => 'phase-a'], now()->subDays(10));
    movedActivity($lead, ['old_phase' => 'phase-a', 'new_phase' => 'phase-b'], now()->subDays(6));

    $dwell   = app(LeadActivityMetricsService::class)->stageDwell(scoped());
    $byPhase = collect($dwell)->keyBy('phase_id');

    expect($byPhase['phase-a']['avg_days'])->toBe(4.0); // 10d ago → 6d ago
});

it('builds a 6x6 contact-time heatmap', function (): void {
    $now  = CarbonImmutable::parse('2026-03-16 09:30:00'); // Monday, slot 8-10
    $lead = Lead::factory()->create();

    // LeadActivity::$fillable excludes created_at (see movedActivity() docblock
    // above) — make()+forceFill()+save() to actually pin the timestamp.
    $activity = $lead->activities()->make(['type' => LeadActivityTypeEnum::Call->value]);
    $activity->forceFill(['created_at' => $now]);
    $activity->save();

    $heat = app(LeadActivityMetricsService::class)->contactHeatmap(scoped(), $now->subDays(7), $now->addDay());

    expect($heat['days'])->toHaveCount(6)
        ->and($heat['slots'])->toHaveCount(6)
        ->and($heat['matrix'][0][0])->toBe(1); // Monday, first slot
});

it('excludes non-contact activities and activities outside the window/hours from the heatmap', function (): void {
    $now  = CarbonImmutable::parse('2026-03-16 09:30:00'); // Monday, slot 8-10
    $lead = Lead::factory()->create();

    // A Note is not a contact activity → excluded regardless of timing.
    $note = $lead->activities()->make(['type' => LeadActivityTypeEnum::Note->value]);
    $note->forceFill(['created_at' => $now]);
    $note->save();

    // A Call at 21:00 falls outside the 8–20 window → excluded.
    $lateCall = $lead->activities()->make(['type' => LeadActivityTypeEnum::Call->value]);
    $lateCall->forceFill(['created_at' => $now->setTime(21, 0)]);
    $lateCall->save();

    // An Email a day before the requested [from, to] window → excluded.
    $outOfRange = $lead->activities()->make(['type' => LeadActivityTypeEnum::Email->value]);
    $outOfRange->forceFill(['created_at' => $now->subDays(10)]);
    $outOfRange->save();

    $heat = app(LeadActivityMetricsService::class)->contactHeatmap(scoped(), $now->subDays(7), $now->addDay());

    expect(array_sum(array_map('array_sum', $heat['matrix'])))->toBe(0);
});

it('computes pipeline velocity from open, win-rate, value and cycle time', function (): void {
    $now = CarbonImmutable::parse('2026-03-01 00:00:00');
    CarbonImmutable::setTestNow($now);

    // open
    Lead::factory()->count(2)->create(['status' => LeadStatusEnum::Active]);

    // won, converted after 10/20/30 days from created_at; only two carry a value
    Lead::factory()->create(['status' => LeadStatusEnum::Won, 'value' => 10000, 'converted_at' => $now->addDays(10)]);
    Lead::factory()->create(['status' => LeadStatusEnum::Won, 'value' => 20000, 'converted_at' => $now->addDays(20)]);
    Lead::factory()->create(['status' => LeadStatusEnum::Won, 'value' => null, 'converted_at' => $now->addDays(30)]);

    // lost
    Lead::factory()->create(['status' => LeadStatusEnum::Lost]);

    $velocity = app(LeadActivityMetricsService::class)->pipelineVelocity(scoped());

    expect($velocity['open'])->toBe(2)
        ->and($velocity['win_rate'])->toBe(75.0)      // 3 won / (3 won + 1 lost)
        ->and($velocity['avg_value'])->toBe(15000.0)  // (10000 + 20000) / 2, null excluded
        ->and($velocity['cycle_days'])->toBe(20.0)     // (10 + 20 + 30) / 3
        ->and($velocity['velocity'])->toBe(1125.0);    // 2 × 0.75 × 15000 / 20
});

it('guards pipeline velocity against division by zero', function (): void {
    Lead::factory()->count(2)->create(['status' => LeadStatusEnum::Active]);

    $velocity = app(LeadActivityMetricsService::class)->pipelineVelocity(scoped());

    expect($velocity['open'])->toBe(2)
        ->and($velocity['win_rate'])->toBe(0.0)   // no won/lost leads at all
        ->and($velocity['cycle_days'])->toBe(0.0) // no converted_at present
        ->and($velocity['velocity'])->toBe(0.0);
});
