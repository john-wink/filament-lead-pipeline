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
