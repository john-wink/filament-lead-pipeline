<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Drivers\MetaDriver;
use JohnWink\FilamentLeadPipeline\DTOs\LeadData;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

it('exposes attribution fields on the LeadData dto', function (): void {
    $data = new LeadData(
        name: 'Test',
        source_campaign_id: '23',
        source_campaign_name: 'Campaign',
        source_adgroup_id: '34',
        source_adgroup_name: 'Adset',
        source_ad_id: '45',
        source_ad_name: 'Ad',
        source_channel: 'instagram',
    );

    expect($data->source_campaign_id)->toBe('23')
        ->and($data->source_campaign_name)->toBe('Campaign')
        ->and($data->source_adgroup_id)->toBe('34')
        ->and($data->source_adgroup_name)->toBe('Adset')
        ->and($data->source_ad_id)->toBe('45')
        ->and($data->source_ad_name)->toBe('Ad')
        ->and($data->source_channel)->toBe('instagram');
});

it('extracts attribution from a webhook-shaped payload', function (): void {
    $payload = new WebhookPayloadData(
        driver: 'meta',
        source_id: 'src-1',
        raw_payload: [
            'entry' => [[
                'changes' => [[
                    'field' => 'leadgen',
                    'value' => [
                        'leadgen_id' => 'lead-1',
                        'form_id'    => 'form-1',
                        'ad_id'      => '45678901234',
                        'adgroup_id' => '34567890123',
                        'field_data' => [
                            ['name' => 'full_name', 'values' => ['Anna Beispiel']],
                            ['name' => 'email',     'values' => ['anna@example.com']],
                        ],
                    ],
                ]],
            ]],
        ],
    );

    $source         = new LeadSource();
    $source->id     = 'src-1';
    $source->config = [];

    $leadData = (new MetaDriver())->processWebhook($payload, $source);

    expect($leadData->source_ad_id)->toBe('45678901234')
        ->and($leadData->source_adgroup_id)->toBe('34567890123')
        ->and($leadData->source_campaign_id)->toBeNull()
        ->and($leadData->source_driver)->toBe('meta');
});

it('extracts attribution from a graph-api shaped payload (top-level keys)', function (): void {
    $payload = new WebhookPayloadData(
        driver: 'meta',
        source_id: 'src-2',
        raw_payload: [
            'id'            => 'lead-2',
            'form_id'       => 'form-2',
            'ad_id'         => '45',
            'ad_name'       => 'KFW40 Bonn',
            'adset_id'      => '34',
            'adset_name'    => '40-65',
            'campaign_id'   => '23',
            'campaign_name' => 'Sommer 2026',
            'platform'      => 'instagram',
            'field_data'    => [
                ['name' => 'full_name', 'values' => ['Max']],
            ],
        ],
    );

    $source         = new LeadSource();
    $source->id     = 'src-2';
    $source->config = [];

    $leadData = (new MetaDriver())->processWebhook($payload, $source);

    expect($leadData->source_campaign_id)->toBe('23')
        ->and($leadData->source_campaign_name)->toBe('Sommer 2026')
        ->and($leadData->source_adgroup_id)->toBe('34')
        ->and($leadData->source_adgroup_name)->toBe('40-65')
        ->and($leadData->source_ad_id)->toBe('45')
        ->and($leadData->source_ad_name)->toBe('KFW40 Bonn')
        ->and($leadData->source_channel)->toBe('instagram');
});

it('returns null attribution for organic or unattributed leads', function (): void {
    $payload = new WebhookPayloadData(
        driver: 'meta',
        source_id: 'src-3',
        raw_payload: [
            'id'         => 'lead-organic',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Organic User']],
            ],
        ],
    );

    $source         = new LeadSource();
    $source->id     = 'src-3';
    $source->config = [];

    $leadData = (new MetaDriver())->processWebhook($payload, $source);

    expect($leadData->source_campaign_id)->toBeNull()
        ->and($leadData->source_adgroup_id)->toBeNull()
        ->and($leadData->source_ad_id)->toBeNull()
        ->and($leadData->source_channel)->toBeNull();
});
