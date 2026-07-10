<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

function scopedLeads(): Illuminate\Database\Eloquent\Builder
{
    return Lead::query();
}

/**
 * `LeadActivity::$fillable` includes `causer_type`/`causer_id` (unlike
 * `created_at`, which is intentionally excluded — see `movedActivity()` in
 * `LeadActivityMetricsServiceTest.php`), so only the timestamp needs the
 * make()+forceFill()+save() dance to avoid Eloquent stamping `now()` instead.
 */
function activityBy(Lead $lead, LeadActivityTypeEnum $type, int|string $causerId, CarbonImmutable $at, array $properties = []): void
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

it('counts activity per causer and results per assignee within the range', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');

    // Host `users.name` is a generated column (first_name + last_name) —
    // factories must set the parts, not `name` directly.
    $fleissig = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Fleissig', 'last_name' => '']);
    $faul     = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Faul', 'last_name' => '']);

    $board = LeadBoard::factory()->create();
    $open  = LeadPhase::factory()->for($board, 'board')->open()->create();
    $won   = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Won]);

    $leadA = Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $fleissig->getKey()]);
    $leadB = Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $faul->getKey()]);

    $now = CarbonImmutable::parse('2026-06-14 10:00:00');
    activityBy($leadA, LeadActivityTypeEnum::Call, $fleissig->getKey(), $now);
    activityBy($leadA, LeadActivityTypeEnum::Call, $fleissig->getKey(), $now->addHour());
    activityBy($leadA, LeadActivityTypeEnum::Email, $fleissig->getKey(), $now->addHours(2));
    activityBy($leadA, LeadActivityTypeEnum::Note, $fleissig->getKey(), $now->addHours(3));
    // Fleissig gewinnt Lead A: Move in Won-Phase (Ergebnis zählt auf assigned_to = Fleissig).
    activityBy($leadA, LeadActivityTypeEnum::Moved, $fleissig->getKey(), $now->addHours(4), ['new_phase' => $won->getKey()]);
    // Außerhalb des Fensters: zählt nicht.
    activityBy($leadB, LeadActivityTypeEnum::Call, $faul->getKey(), CarbonImmutable::parse('2026-01-01'));

    $matrix = app(LeadActivityMetricsService::class)->advisorActivityMatrix(
        scopedLeads(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    $rows = collect($matrix['rows'])->keyBy('advisor_name');

    expect($rows['Fleissig']['calls'])->toBe(2)
        ->and($rows['Fleissig']['emails'])->toBe(1)
        ->and($rows['Fleissig']['notes'])->toBe(1)
        ->and($rows['Fleissig']['moves'])->toBe(1)
        ->and($rows['Fleissig']['won'])->toBe(1)
        ->and($rows['Fleissig']['conversion'])->toBe(100.0)
        ->and($rows['Faul']['calls'])->toBe(0)       // im Fenster nichts getan …
        ->and($rows['Faul']['won'])->toBe(0)
        ->and($matrix['team']['calls'])->toBe(2);     // … erscheint aber (zugewiesener Berater)
});

it('normalises activities per assigned lead', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $advisor = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Norm', 'last_name' => '']);
    $board   = LeadBoard::factory()->create();
    $open    = LeadPhase::factory()->for($board, 'board')->open()->create();

    $leads = Lead::factory()->count(4)->for($board, 'board')->for($open, 'phase')
        ->create(['assigned_to' => $advisor->getKey()]);
    activityBy($leads[0], LeadActivityTypeEnum::Call, $advisor->getKey(), CarbonImmutable::parse('2026-06-14'));
    activityBy($leads[1], LeadActivityTypeEnum::Note, $advisor->getKey(), CarbonImmutable::parse('2026-06-14'));

    $row = collect(app(LeadActivityMetricsService::class)->advisorActivityMatrix(
        scopedLeads(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    )['rows'])->firstWhere('advisor_name', 'Norm');

    expect($row['activities_per_lead'])->toBe(0.5); // 2 Aktivitäten / 4 zugewiesene Leads
});

it('excludes non-responding advisors from the team sla average and surfaces first-contact-only advisors', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');

    $responder  = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Flink', 'last_name' => '']);
    $idle       = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Passiv', 'last_name' => '']);
    $firstOnly  = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Erstheld', 'last_name' => '']);
    $assignOnly = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Zugeteilt', 'last_name' => '']);

    $board = LeadBoard::factory()->create();
    $open  = LeadPhase::factory()->for($board, 'board')->open()->create();

    // Flink antwortet in 30 min (SLA-konform) — einziger Responder im Fenster.
    Lead::factory()->for($board, 'board')->for($open, 'phase')->create([
        'assigned_to'       => $responder->getKey(),
        'created_at'        => CarbonImmutable::parse('2026-06-10 09:00:00'),
        'first_response_at' => CarbonImmutable::parse('2026-06-10 09:30:00'),
        'first_response_by' => $responder->getKey(),
    ]);

    // Passiv: nur zugewiesen, keine Antwort im Fenster → darf den Team-SLA nicht verwässern.
    Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $idle->getKey()]);

    // Erstheld taucht NUR über first_response_by auf (weder Causer noch aktuell zugewiesen).
    $orphan = Lead::factory()->for($board, 'board')->for($open, 'phase')->create([
        'assigned_to'       => null,
        'first_response_at' => CarbonImmutable::parse('2026-06-11 10:00:00'),
        'first_response_by' => $firstOnly->getKey(),
    ]);

    // Zugeteilt taucht NUR über eine Assignment-Activity auf (Causer war Flink;
    // Assignment zählt nicht in die Causer-Universum-Query).
    activityBy(
        $orphan,
        LeadActivityTypeEnum::Assignment,
        $responder->getKey(),
        CarbonImmutable::parse('2026-06-11'),
        ['assigned_to' => $assignOnly->getKey()]
    );

    $matrix = app(LeadActivityMetricsService::class)->advisorActivityMatrix(
        scopedLeads(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    $rows = collect($matrix['rows'])->keyBy('advisor_name');

    expect($matrix['team']['sla_pct'])->toBe(100.0)          // nur Flink zählt (Passiv: avg_response_minutes null)
        ->and($rows['Passiv']['sla_pct'])->toBe(0.0)         // Zeile bleibt, verwässert aber nicht
        ->and($rows['Erstheld']['first_contacts'])->toBe(1)  // first-contact-only Berater erscheint
        ->and($rows['Zugeteilt']['assigned_new'])->toBe(1);  // assignment-only Berater erscheint
});

it('counts first contacts and new assignments in the window', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $advisor = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Erst', 'last_name' => '']);
    $board   = LeadBoard::factory()->create();
    $open    = LeadPhase::factory()->for($board, 'board')->open()->create();

    $lead = Lead::factory()->for($board, 'board')->for($open, 'phase')->create([
        'assigned_to'       => $advisor->getKey(),
        'first_response_at' => CarbonImmutable::parse('2026-06-10 09:00:00'),
        'first_response_by' => $advisor->getKey(),
    ]);
    activityBy(
        $lead,
        LeadActivityTypeEnum::Assignment,
        $advisor->getKey(),
        CarbonImmutable::parse('2026-06-09'),
        ['assigned_to' => $advisor->getKey()]
    );

    $row = collect(app(LeadActivityMetricsService::class)->advisorActivityMatrix(
        scopedLeads(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    )['rows'])->firstWhere('advisor_name', 'Erst');

    expect($row['first_contacts'])->toBe(1)
        ->and($row['assigned_new'])->toBe(1);
});
