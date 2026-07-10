<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Carbon\CarbonImmutable;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadActivity;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;
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

it('treats null response-stats bounds as unbounded', function (): void {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');
    Lead::factory()->create([
        'created_at'        => CarbonImmutable::parse('2020-01-01'),
        'first_response_at' => CarbonImmutable::parse('2020-01-01 00:30:00'),
    ]);

    $stats = app(LeadActivityMetricsService::class)->responseStats(scoped(), null, null);

    expect($stats['total'])->toBe(1)
        ->and($stats['buckets']['under_1h'])->toBe(1);
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

it('windows loss reasons to leads moved into a lost phase within the range', function (): void {
    CarbonImmutable::setTestNow('2026-06-15 12:00:00');
    $board = LeadBoard::factory()->create();
    $lost  = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Lost]);

    $inRange = Lead::factory()->for($board, 'board')->for($lost, 'phase')
        ->create(['status' => LeadStatusEnum::Lost, 'lost_reason' => 'Preis']);
    movedActivity($inRange, ['new_phase' => $lost->getKey()], CarbonImmutable::parse('2026-06-10'));

    $outOfRange = Lead::factory()->for($board, 'board')->for($lost, 'phase')
        ->create(['status' => LeadStatusEnum::Lost, 'lost_reason' => 'Kein Bedarf']);
    movedActivity($outOfRange, ['new_phase' => $lost->getKey()], CarbonImmutable::parse('2026-01-10'));

    $svc = app(LeadActivityMetricsService::class);

    $windowed = $svc->lossReasons(scoped(), CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));
    expect(collect($windowed)->pluck('reason')->all())->toBe(['Preis']);

    // Unbounded ('Gesamt') zählt beide.
    expect(collect($svc->lossReasons(scoped()))->pluck('reason')->sort()->values()->all())
        ->toBe(['Kein Bedarf', 'Preis']);
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

it('windows stage dwell to moves inside the range', function (): void {
    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Alt']);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Neu']);
    $lead   = Lead::factory()->for($board, 'board')->for($phaseB, 'phase')->create();

    movedActivity($lead, ['new_phase' => $phaseA->getKey()], CarbonImmutable::parse('2026-01-01'));
    movedActivity($lead, ['new_phase' => $phaseB->getKey()], CarbonImmutable::parse('2026-01-05'));

    $windowed = app(LeadActivityMetricsService::class)
        ->stageDwell(scoped(), CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));

    expect($windowed)->toBe([]);
});

// Charakterisierungs-Test: dokumentiert den vertraglichen Fenster-Randfall aus
// dem stageDwell()-PHPDoc — Paare, die die Fenstergrenze überspannen, werden
// bewusst verworfen (kein Clipping), nicht anteilig gezählt.
it('drops dwell pairs that straddle the window boundary (documented contract)', function (): void {
    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Rand']);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Ziel']);
    $lead   = Lead::factory()->for($board, 'board')->for($phaseB, 'phase')->create();

    // Eintritt VOR dem Fenster, Austritt IM Fenster → Paar überspannt die Grenze → verworfen.
    movedActivity($lead, ['new_phase' => $phaseA->getKey()], CarbonImmutable::parse('2026-05-28'));
    movedActivity($lead, ['new_phase' => $phaseB->getKey()], CarbonImmutable::parse('2026-06-05'));

    $windowed = app(LeadActivityMetricsService::class)
        ->stageDwell(scoped(), CarbonImmutable::parse('2026-06-01'), CarbonImmutable::parse('2026-06-30'));

    expect($windowed)->toBe([]);

    // Unbounded zählt das Paar (8 Tage in Phase 'Rand').
    $all = app(LeadActivityMetricsService::class)->stageDwell(scoped());
    expect(collect($all)->firstWhere('label', 'Rand')['avg_days'])->toBe(8.0);
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

it('summarises economics per source', function (): void {
    $s1 = LeadSource::factory()->create(['name' => 'Website']);
    Lead::factory()->count(2)->create([Lead::fkColumn('lead_source') => $s1->getKey(), 'status' => LeadStatusEnum::Won, 'value' => 100]);
    Lead::factory()->create([Lead::fkColumn('lead_source') => $s1->getKey(), 'status' => LeadStatusEnum::Lost, 'value' => 0]);

    $rows    = app(LeadActivityMetricsService::class)->sourceEconomics(scoped());
    $website = collect($rows)->firstWhere('source', 'Website');

    expect($website['leads'])->toBe(3)
        ->and($website['won'])->toBe(2)
        ->and($website['conversion'])->toBe(66.7); // 2 won of 3 leads
});

it('excludes a leadless source entirely (INNER JOIN, never divides by zero)', function (): void {
    LeadSource::factory()->create(['name' => 'Empty']);

    $rows = app(LeadActivityMetricsService::class)->sourceEconomics(scoped());

    expect(collect($rows)->firstWhere('source', 'Empty'))->toBeNull();
});

it('computes cost_per_lead and cost_per_acquisition from Meta ad spend attributed via source_campaign_id', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $team = Team::query()->firstWhere('slug', 'test');
    $this->actingAs(User::factory()->create());
    Filament::setTenant($team);

    $facebook = LeadSource::factory()->create(['name' => 'Facebook Ads']);
    Lead::factory()->count(2)->create([
        Lead::fkColumn('lead_source') => $facebook->getKey(),
        'status'                      => LeadStatusEnum::Won,
        'source_campaign_id'          => 'c-100',
    ]);
    Lead::factory()->create([
        Lead::fkColumn('lead_source') => $facebook->getKey(),
        'status'                      => LeadStatusEnum::Lost,
        'source_campaign_id'          => 'c-100',
    ]);

    MetaInsightSnapshot::factory()->create([
        'team_uuid'      => $team->uuid,
        'campaign_id'    => 'c-100',
        'breakdown_type' => 'none',
        'spend'          => 300,
    ]);

    // Breakdown-Zeile (z. B. Gender-Split) für dieselbe Kampagne — muss NICHT
    // mitgezählt werden, sonst würde derselbe Spend doppelt erfasst.
    MetaInsightSnapshot::factory()->gender('female')->create([
        'team_uuid'   => $team->uuid,
        'campaign_id' => 'c-100',
        'spend'       => 999,
    ]);

    $rows        = app(LeadActivityMetricsService::class)->sourceEconomics(scoped());
    $facebookRow = collect($rows)->firstWhere('source', 'Facebook Ads');

    expect($facebookRow['leads'])->toBe(3)
        ->and($facebookRow['won'])->toBe(2)
        ->and($facebookRow['cost_per_lead'])->toBe(100.0)       // 300 / 3 leads
        ->and($facebookRow['cost_per_acquisition'])->toBe(150.0); // 300 / 2 won
});

it('returns null ad-cost when a source has leads but no matching Meta insight snapshot', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $team = Team::query()->firstWhere('slug', 'test');
    $this->actingAs(User::factory()->create());
    Filament::setTenant($team);

    // Campaign id present on the lead, but no snapshot row exists for it.
    $orphan = LeadSource::factory()->create(['name' => 'Orphan Campaign']);
    Lead::factory()->create([
        Lead::fkColumn('lead_source') => $orphan->getKey(),
        'status'                      => LeadStatusEnum::Won,
        'source_campaign_id'          => 'c-999',
    ]);

    // No campaign attribution at all.
    $noAttribution = LeadSource::factory()->create(['name' => 'No Attribution']);
    Lead::factory()->create([
        Lead::fkColumn('lead_source') => $noAttribution->getKey(),
        'status'                      => LeadStatusEnum::Won,
        'source_campaign_id'          => null,
    ]);

    $rows = app(LeadActivityMetricsService::class)->sourceEconomics(scoped());

    expect(collect($rows)->firstWhere('source', 'Orphan Campaign')['cost_per_lead'])->toBeNull()
        ->and(collect($rows)->firstWhere('source', 'Orphan Campaign')['cost_per_acquisition'])->toBeNull()
        ->and(collect($rows)->firstWhere('source', 'No Attribution')['cost_per_lead'])->toBeNull()
        ->and(collect($rows)->firstWhere('source', 'No Attribution')['cost_per_acquisition'])->toBeNull();
});

it('does not query ad spend and returns null cost columns when there is no current tenant', function (): void {
    $website = LeadSource::factory()->create(['name' => 'Untenanted']);
    Lead::factory()->create([
        Lead::fkColumn('lead_source') => $website->getKey(),
        'status'                      => LeadStatusEnum::Won,
        'source_campaign_id'          => 'c-100',
    ]);

    $rows = app(LeadActivityMetricsService::class)->sourceEconomics(scoped());
    $row  = collect($rows)->firstWhere('source', 'Untenanted');

    expect($row['cost_per_lead'])->toBeNull()
        ->and($row['cost_per_acquisition'])->toBeNull();
});

it('does not throw and returns null ad-cost when the meta_insight_snapshots table is absent', function (): void {
    // Reproduces the production-eota 500: a deployment that never provisioned
    // the Meta insights table must not take down the whole operations page.
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $team = Team::query()->firstWhere('slug', 'test');
    $this->actingAs(User::factory()->create());
    Filament::setTenant($team);

    $facebook = LeadSource::factory()->create(['name' => 'No-Table Ads']);
    Lead::factory()->create([
        Lead::fkColumn('lead_source') => $facebook->getKey(),
        'status'                      => LeadStatusEnum::Won,
        'source_campaign_id'          => 'c-100', // has campaign attribution → would query the table
    ]);

    Schema::drop('meta_insight_snapshots'); // SQLite DDL is transactional → rolled back after the test

    $rows = app(LeadActivityMetricsService::class)->sourceEconomics(scoped());
    $row  = collect($rows)->firstWhere('source', 'No-Table Ads');

    expect($row['leads'])->toBe(1)
        ->and($row['cost_per_lead'])->toBeNull()
        ->and($row['cost_per_acquisition'])->toBeNull();
});

it('scopes ad spend to the requested date range, each bound applied independently', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $team = Team::query()->firstWhere('slug', 'test');
    $this->actingAs(User::factory()->create());
    Filament::setTenant($team);

    $src = LeadSource::factory()->create(['name' => 'Range Src']);
    Lead::factory()->create([
        Lead::fkColumn('lead_source') => $src->getKey(),
        'status'                      => LeadStatusEnum::Won,
        'source_campaign_id'          => 'c-range',
    ]);

    // In-range spend (100, March) + out-of-range spend (500, January) for the same campaign.
    MetaInsightSnapshot::factory()->create(['team_uuid' => $team->uuid, 'campaign_id' => 'c-range', 'breakdown_type' => 'none', 'spend' => 100, 'date' => '2026-03-10']);
    MetaInsightSnapshot::factory()->create(['team_uuid' => $team->uuid, 'campaign_id' => 'c-range', 'breakdown_type' => 'none', 'spend' => 500, 'date' => '2026-01-01']);

    $svc = app(LeadActivityMetricsService::class);

    // Full range → only March spend counts.
    $full = collect($svc->sourceEconomics(scoped(), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-31')))->firstWhere('source', 'Range Src');
    expect($full['cost_per_lead'])->toBe(100.0);

    // Partial range (only lower bound Feb 1) → excludes Jan spend, keeps March.
    // Without the independent-bound fix this would silently sum all-time spend (600).
    $partial = collect($svc->sourceEconomics(scoped(), CarbonImmutable::parse('2026-02-01'), null))->firstWhere('source', 'Range Src');
    expect($partial['cost_per_lead'])->toBe(100.0);
});

it('ranks advisors by a composite ops score', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');

    // Strong advisor: fast response (10 min) + won + 1 contact attempt.
    $ace = Lead::factory()->create(['assigned_to' => 'ace', 'status' => LeadStatusEnum::Won, 'created_at' => $now->subDay(), 'first_response_at' => $now->subDay()->addMinutes(10)]);
    $ace->activities()->create(['type' => LeadActivityTypeEnum::Call->value]);

    // Weak advisor: slow response (30h) + lost + no contact attempts.
    Lead::factory()->create(['assigned_to' => 'slow', 'status' => LeadStatusEnum::Lost, 'created_at' => $now->subDay(), 'first_response_at' => $now->subDay()->addHours(30)]);

    $rows = app(LeadActivityMetricsService::class)->advisorOps(scoped(), $now->subDays(7), $now);
    $byId = collect($rows)->keyBy('advisor_id');

    // ace: sla_pct=100 (10min <= 60min SLA), winRate=1 (won, no lost),
    //      responseScore=1-10/1440=0.9930556
    //   -> ops_score = round((1*0.4 + 1*0.4 + 0.9930556*0.2)*100, 1) = 99.9
    // slow: sla_pct=0 (1800min > 60min SLA), winRate=0 (lost, no won),
    //      responseScore=max(0, 1-min(1800/1440,1))=0
    //   -> ops_score = round((0*0.4 + 0*0.4 + 0*0.2)*100, 1) = 0.0
    expect($rows)->toHaveCount(2)
        ->and($rows[0]['advisor_id'])->toBe('ace')
        ->and($rows[0]['ops_score'])->toBe(99.9)
        ->and($rows[0]['contact_attempts'])->toBe(1)
        ->and($byId['slow']['ops_score'])->toBe(0.0)
        ->and($byId['slow']['contact_attempts'])->toBe(0)
        ->and($rows[0]['ops_score'])->toBeGreaterThan($rows[1]['ops_score']);
});

it('guards advisor ops against an empty advisor set', function (): void {
    Lead::factory()->create(['assigned_to' => null]);

    $rows = app(LeadActivityMetricsService::class)->advisorOps(scoped(), CarbonImmutable::parse('2026-03-01'), CarbonImmutable::parse('2026-03-15'));

    expect($rows)->toBe([]);
});

it('guards advisor ops win rate against division by zero when won+lost is empty', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');

    // "idle" has an assigned lead that is still Active — no won, no lost.
    Lead::factory()->create(['assigned_to' => 'idle', 'status' => LeadStatusEnum::Active, 'created_at' => $now->subDay(), 'first_response_at' => null]);

    $rows = app(LeadActivityMetricsService::class)->advisorOps(scoped(), $now->subDays(7), $now);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['advisor_id'])->toBe('idle')
        ->and($rows[0]['won'])->toBe(0)
        // winRate=0 (guarded), sla_pct=0 (never responded), responseScore=0 (avg_minutes null) -> ops_score=0.0
        ->and($rows[0]['ops_score'])->toBe(0.0);
});
