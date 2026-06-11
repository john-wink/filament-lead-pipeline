<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;
use JohnWink\FilamentLeadPipeline\Models\MetaReachRange;
use JohnWink\FilamentLeadPipeline\Services\ReportMetricsService;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;

beforeEach(function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00'));
    $this->team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $this->report = LeadReport::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->board  = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->report->boards()->attach($this->board->uuid);

    $this->report->adSources()->create([
        'facebook_connection_uuid' => JohnWink\FilamentLeadPipeline\Models\FacebookConnection::factory()
            ->create([
                'team_uuid'    => $this->team->uuid,
                'access_token' => 'tok',
                'user_uuid'    => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id,
            ])->uuid,
        'ad_account_id' => 'act_123',
    ]);

    // 2 Tage im Zeitraum, 1 Tag davor (Vorzeitraum)
    foreach ([['2026-06-01', 1000, '40.00', 4], ['2026-06-02', 500, '20.00', 2], ['2026-04-20', 300, '10.00', 1]] as [$date, $impressions, $spend, $leads]) {
        MetaInsightSnapshot::factory()->create([
            'team_uuid' => $this->team->uuid, 'ad_account_id' => 'act_123', 'campaign_id' => 'c1',
            'date'      => $date, 'impressions' => $impressions, 'spend' => $spend, 'leads' => $leads,
            'clicks'    => 10, 'link_clicks' => 8,
        ]);
    }

    MetaReachRange::query()->create([
        'ad_account_id' => 'act_123', 'campaign_key' => '', 'preset' => 'last30days',
        'date_from'     => '2026-05-11', 'date_till' => '2026-06-09', 'reach' => 20181, 'fetched_at' => now(),
    ]);
});

afterEach(fn () => CarbonImmutable::setTestNow());

it('aggregates KPIs from snapshots and own lead counts', function (): void {
    // 3 Leads im Zeitraum auf dem Board (eigene DB schlägt Meta-Zahl)
    JohnWink\FilamentLeadPipeline\Models\Lead::factory()->count(3)->create([
        'lead_board_uuid' => $this->board->uuid,
        'created_at'      => '2026-06-01 10:00:00',
    ]);

    $metrics = app(ReportMetricsService::class)->metrics(
        $this->report,
        ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days),
    );

    expect($metrics->impressions)->toBe(1500)
        ->and($metrics->spend)->toBe(60.0)
        ->and($metrics->inquiries)->toBe(3)
        ->and($metrics->costPerInquiry)->toBe(20.0)
        ->and($metrics->reach)->toBe(20181)
        ->and($metrics->deltas)->toHaveKey('impressions');
});

it('builds a daily trend of inquiries and link clicks', function (): void {
    JohnWink\FilamentLeadPipeline\Models\Lead::factory()->create([
        'lead_board_uuid' => $this->board->uuid, 'created_at' => '2026-06-02 09:00:00',
    ]);

    $trend = app(ReportMetricsService::class)->trend(
        $this->report,
        ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days),
    );

    $june2 = collect($trend)->firstWhere('date', '2026-06-02');
    expect($june2['inquiries'])->toBe(1)
        ->and($june2['link_clicks'])->toBe(8)
        ->and(count($trend))->toBe(30);
});

it('returns gender breakdown only when gender rows exist', function (): void {
    $service = app(ReportMetricsService::class);
    $range   = ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days);

    expect($service->genderBreakdown($this->report, $range))->toBeNull();

    MetaInsightSnapshot::factory()->gender('male')->create([
        'team_uuid' => $this->team->uuid, 'ad_account_id' => 'act_123',
        'date'      => '2026-06-01', 'impressions' => 800,
    ]);
    MetaInsightSnapshot::factory()->gender('female')->create([
        'team_uuid' => $this->team->uuid, 'ad_account_id' => 'act_123',
        'date'      => '2026-06-01', 'impressions' => 200,
    ]);

    expect($service->genderBreakdown($this->report, $range))->toBe(['male' => 800, 'female' => 200, 'unknown' => 0]);
});

it('respects the campaign filter of ad sources', function (): void {
    $this->report->adSources()->first()->update(['campaign_ids' => ['c2']]);

    $metrics = app(ReportMetricsService::class)->metrics(
        $this->report->refresh(),
        ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days),
    );

    expect($metrics->impressions)->toBe(0);
});

it('fetches custom range reach on demand and caches it', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['data' => [['reach' => '4321']]]),
    ]);

    $service = app(ReportMetricsService::class);
    $range   = ReportDateRange::fromPreset(
        ReportDatePresetEnum::Custom,
        CarbonImmutable::parse('2026-05-01'),
        CarbonImmutable::parse('2026-05-15'),
    );

    expect($service->reach($this->report, $range))->toBe(4321);

    // Zweiter Aufruf: abgeschlossener Zeitraum (date_till < heute) → Cache-Treffer, KEIN weiterer API-Call
    expect($service->reach($this->report, $range))->toBe(4321);
    Http::assertSentCount(1);
});

it('counts funnel stages with a custom funnel mapping', function (): void {
    $this->report->update(['funnel_mapping' => ['qualified' => ['won'], 'won' => ['won']]]);

    $wonPhase = JohnWink\FilamentLeadPipeline\Models\LeadPhase::factory()->create([
        'lead_board_uuid' => $this->board->uuid,
        'type'            => 'won',
    ]);

    JohnWink\FilamentLeadPipeline\Models\Lead::factory()->count(2)->create([
        'lead_board_uuid' => $this->board->uuid,
        'lead_phase_uuid' => $wonPhase->uuid,
        'created_at'      => '2026-06-01 10:00:00',
    ]);

    $funnel = app(ReportMetricsService::class)->funnel(
        $this->report->refresh(),
        ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days),
    );

    $byKey = collect($funnel)->keyBy('key');
    expect($byKey['qualified']['value'])->toBe(2)
        ->and($byKey['won']['value'])->toBe(2);
});
