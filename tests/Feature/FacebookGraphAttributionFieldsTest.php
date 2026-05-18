<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

it('requests attribution fields when fetching a single lead', function (): void {
    Http::fake([
        'graph.facebook.com/*/lead-123*' => Http::response([
            'id'            => 'lead-123',
            'form_id'       => 'form-1',
            'ad_id'         => '45',
            'campaign_id'   => '23',
            'campaign_name' => 'Sommer 2026',
            'platform'      => 'instagram',
            'field_data'    => [],
        ]),
    ]);

    app(FacebookGraphService::class)->getLeadData('lead-123', 'token-abc');

    Http::assertSent(function ($request) {
        $fields = $request['fields'] ?? '';

        return str_contains((string) $request->url(), '/lead-123')
            && str_contains($fields, 'ad_id')
            && str_contains($fields, 'ad_name')
            && str_contains($fields, 'adset_id')
            && str_contains($fields, 'adset_name')
            && str_contains($fields, 'campaign_id')
            && str_contains($fields, 'campaign_name')
            && str_contains($fields, 'platform');
    });
});

it('requests attribution fields when paging form leads', function (): void {
    Http::fake([
        'graph.facebook.com/*/form-1/leads*' => Http::response([
            'data'   => [],
            'paging' => [],
        ]),
    ]);

    app(FacebookGraphService::class)->getFormLeads('form-1', 'token-abc');

    Http::assertSent(function ($request) {
        $fields = $request['fields'] ?? '';

        return str_contains((string) $request->url(), '/form-1/leads')
            && str_contains($fields, 'ad_id')
            && str_contains($fields, 'campaign_id')
            && str_contains($fields, 'campaign_name')
            && str_contains($fields, 'platform');
    });
});
