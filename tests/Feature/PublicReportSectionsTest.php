<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;

it('shows kpi tiles with comparison deltas', function (): void {
    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report     = LeadReport::factory()->create(['team_uuid' => $team->uuid]);
    $connection = JohnWink\FilamentLeadPipeline\Models\FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);
    $report->adSources()->create(['facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_9']);

    MetaInsightSnapshot::factory()->create([
        'team_uuid' => $team->uuid, 'ad_account_id' => 'act_9',
        'date'      => now()->subDays(3)->toDateString(), 'impressions' => 4200, 'spend' => '100.00',
    ]);

    $this->get("/reports/{$report->share_token}")
        ->assertOk()
        ->assertSee('4.200')
        ->assertSee(__('lead-pipeline::reports.kpis.impressions'));
});

it('hides the gender section without gender data and shows it with data', function (): void {
    $team       = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report     = LeadReport::factory()->create(['team_uuid' => $team->uuid]);
    $connection = JohnWink\FilamentLeadPipeline\Models\FacebookConnection::factory()->create(['team_uuid' => $team->uuid, 'user_uuid' => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id]);
    $report->adSources()->create(['facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_9']);

    $this->get("/reports/{$report->share_token}")
        ->assertOk()
        ->assertDontSee(__('lead-pipeline::reports.sections.gender'));

    MetaInsightSnapshot::factory()->gender('male')->create([
        'team_uuid' => $team->uuid, 'ad_account_id' => 'act_9',
        'date'      => now()->subDays(2)->toDateString(), 'impressions' => 500,
    ]);

    $this->get("/reports/{$report->share_token}")
        ->assertSee(__('lead-pipeline::reports.sections.gender'));
});

it('hides disabled sections', function (): void {
    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid, 'sections' => ['kpis']]);

    $this->get("/reports/{$report->share_token}")
        ->assertOk()
        ->assertDontSee(__('lead-pipeline::reports.sections.funnel'));
});

it('renders a qr code svg for the share url', function (): void {
    $svg = JohnWink\FilamentLeadPipeline\Support\QrCodeSvg::make('https://example.test/reports/abc');

    expect($svg)->toContain('<svg');
});
