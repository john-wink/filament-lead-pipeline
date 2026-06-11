<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

beforeEach(function (): void {
    config()->set('lead-pipeline.facebook.graph_version', 'v25.0');
});

it('lists ad accounts for a token', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/me/adaccounts*' => Http::response([
            'data' => [
                ['id' => 'act_123', 'account_id' => '123', 'name' => 'X Capital GmbH'],
            ],
        ]),
    ]);

    $accounts = app(FacebookGraphService::class)->getAdAccounts('token-1');

    expect($accounts)->toHaveCount(1)
        ->and($accounts[0]['id'])->toBe('act_123');
});

it('fetches daily campaign insights with optional breakdown and campaign filter', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/act_123/insights*' => Http::response([
            'data' => [[
                'campaign_id'        => 'c1',
                'campaign_name'      => 'Kampagne 1',
                'date_start'         => '2026-06-01',
                'date_stop'          => '2026-06-01',
                'impressions'        => '1500',
                'reach'              => '900',
                'spend'              => '45.67',
                'clicks'             => '80',
                'inline_link_clicks' => '60',
                'actions'            => [['action_type' => 'lead', 'value' => '3']],
            ]],
            'paging' => [],
        ]),
    ]);

    $result = app(FacebookGraphService::class)->getAdAccountInsights(
        'act_123',
        'token-1',
        ['since' => '2026-06-01', 'until' => '2026-06-08'],
        breakdown: 'gender',
        campaignIds: ['c1'],
    );

    expect($result['data'][0]['campaign_id'])->toBe('c1');

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), 'breakdowns=gender')
            && str_contains($request->url(), 'time_increment=1')
            && str_contains($request->url(), 'level=campaign')
            && str_contains((string) urldecode($request->url()), '"campaign.id"');
    });
});

it('fetches deduplicated reach for a whole range', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/act_123/insights*' => Http::response([
            'data' => [['reach' => '20181', 'date_start' => '2026-05-10', 'date_stop' => '2026-06-08']],
        ]),
    ]);

    $reach = app(FacebookGraphService::class)->getAdAccountReach(
        'act_123',
        'token-1',
        ['since' => '2026-05-10', 'until' => '2026-06-08'],
    );

    expect($reach)->toBe(20181);
});

it('fetches ads with creative image urls', function (): void {
    Http::fake([
        'graph.facebook.com/v25.0/act_123/ads*' => Http::response([
            'data' => [[
                'id'          => 'ad_1',
                'name'        => 'Anzeige 1',
                'status'      => 'ACTIVE',
                'campaign_id' => 'c1',
                'creative'    => ['thumbnail_url' => 'https://cdn.fb/x.jpg', 'image_url' => 'https://cdn.fb/full.jpg'],
            ]],
        ]),
    ]);

    $ads = app(FacebookGraphService::class)->getAdsWithCreatives('act_123', 'token-1');

    expect($ads[0]['creative']['image_url'])->toBe('https://cdn.fb/full.jpg');
});
