<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Models\MetaAdCreative;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;
use JohnWink\FilamentLeadPipeline\Models\MetaReachRange;

it('stores daily insight snapshots with unique upsert key', function (): void {
    $team = App\Models\Team::query()->where('slug', 'test')->firstOrFail();

    MetaInsightSnapshot::query()->create([
        'team_uuid'       => $team->uuid,
        'ad_account_id'   => 'act_123',
        'campaign_id'     => 'c1',
        'campaign_name'   => 'Kampagne 1',
        'date'            => '2026-06-01',
        'breakdown_type'  => 'none',
        'breakdown_value' => null,
        'impressions'     => 100,
        'reach'           => 80,
        'spend'           => '12.34',
        'clicks'          => 9,
        'link_clicks'     => 7,
        'leads'           => 2,
    ]);

    MetaInsightSnapshot::query()->upsert([[
        'uuid'            => Illuminate\Support\Str::uuid7()->toString(),
        'team_uuid'       => $team->uuid,
        'ad_account_id'   => 'act_123',
        'campaign_id'     => 'c1',
        'campaign_name'   => 'Kampagne 1',
        'date'            => '2026-06-01',
        'breakdown_type'  => 'none',
        'breakdown_value' => '',
        'impressions'     => 150,
        'reach'           => 90,
        'spend'           => '15.00',
        'clicks'          => 12,
        'link_clicks'     => 9,
        'leads'           => 3,
    ]], ['ad_account_id', 'campaign_id', 'date', 'breakdown_type', 'breakdown_value'], ['impressions', 'reach', 'spend', 'clicks', 'link_clicks', 'leads']);

    expect(MetaInsightSnapshot::query()->count())->toBe(1)
        ->and((int) MetaInsightSnapshot::query()->first()->impressions)->toBe(150);
});

it('stores reach ranges per preset', function (): void {
    MetaReachRange::query()->create([
        'ad_account_id' => 'act_123',
        'campaign_key'  => '',
        'preset'        => 'last30days',
        'date_from'     => '2026-05-10',
        'date_till'     => '2026-06-08',
        'reach'         => 20181,
        'fetched_at'    => now(),
    ]);

    expect(MetaReachRange::query()->where('preset', 'last30days')->first()->reach)->toBe(20181);
});

it('stores ad creatives with cached image path', function (): void {
    $team = App\Models\Team::query()->where('slug', 'test')->firstOrFail();

    $creative = MetaAdCreative::factory()->create([
        'team_uuid'     => $team->uuid,
        'ad_account_id' => 'act_123',
        'image_path'    => 'lead-reports/creatives/act_123/ad1.jpg',
    ]);

    expect($creative->refresh()->image_path)->toContain('creatives');
});
