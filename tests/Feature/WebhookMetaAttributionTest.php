<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    config()->set('lead-pipeline.facebook.client_secret', 'test-app-secret');

    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);

    $connection = FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-1',
        'facebook_user_name' => 'Tester',
        'access_token'       => 'token',
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);

    $this->fbPage = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-100',
        'page_name'                => 'Test Page',
        'page_access_token'        => 'page-token',
        'is_webhooks_subscribed'   => true,
    ]);

    $this->source = LeadSource::query()->create([
        'name'                             => 'Meta Source',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $this->fbPage->uuid,
        'facebook_form_ids'                => ['form-1'],
    ]);
});

function metaCentralCall(array $payload): \Illuminate\Testing\TestResponse
{
    $url     = '/' . config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks') . '/meta';
    $content = json_encode($payload);
    $secret  = config('lead-pipeline.facebook.client_secret');

    return test()->withHeader('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $content, $secret))
        ->postJson($url, $payload);
}

it('persists attribution when a lead arrives via the central meta webhook', function (): void {
    Http::fake([
        'graph.facebook.com/*/lead-789*' => Http::response([
            'id'            => 'lead-789',
            'form_id'       => 'form-1',
            'created_time'  => '2026-05-18T19:00:00+0000',
            'ad_id'         => '45678901234',
            'ad_name'       => 'KFW40 Tag',
            'adset_id'      => '34567890123',
            'adset_name'    => '40-65 Jahre Bonn',
            'campaign_id'   => '23456789012',
            'campaign_name' => 'Sommer 2026 - Bonn',
            'platform'      => 'instagram',
            'field_data'    => [
                ['name' => 'full_name', 'values' => ['Anna Beispiel']],
                ['name' => 'email',     'values' => ['anna@example.com']],
            ],
        ]),
    ]);

    metaCentralCall([
        'entry' => [[
            'id'      => 'page-100',
            'changes' => [[
                'field' => 'leadgen',
                'value' => [
                    'leadgen_id' => 'lead-789',
                    'form_id'    => 'form-1',
                    'page_id'    => 'page-100',
                ],
            ]],
        ]],
    ])->assertOk();

    $lead = Lead::query()->where('email', 'anna@example.com')->first();

    expect($lead)->not->toBeNull()
        ->and($lead->source_campaign_id)->toBe('23456789012')
        ->and($lead->source_campaign_name)->toBe('Sommer 2026 - Bonn')
        ->and($lead->source_adgroup_id)->toBe('34567890123')
        ->and($lead->source_adgroup_name)->toBe('40-65 Jahre Bonn')
        ->and($lead->source_ad_id)->toBe('45678901234')
        ->and($lead->source_ad_name)->toBe('KFW40 Tag')
        ->and($lead->source_channel)->toBe('instagram');
});

it('handles organic leads gracefully (no attribution)', function (): void {
    Http::fake([
        'graph.facebook.com/*/lead-organic*' => Http::response([
            'id'           => 'lead-organic',
            'form_id'      => 'form-1',
            'created_time' => '2026-05-18T19:00:00+0000',
            'is_organic'   => true,
            'field_data'   => [
                ['name' => 'full_name', 'values' => ['Organic User']],
                ['name' => 'email',     'values' => ['organic@example.com']],
            ],
        ]),
    ]);

    metaCentralCall([
        'entry' => [[
            'id'      => 'page-100',
            'changes' => [[
                'field' => 'leadgen',
                'value' => [
                    'leadgen_id' => 'lead-organic',
                    'form_id'    => 'form-1',
                    'page_id'    => 'page-100',
                ],
            ]],
        ]],
    ])->assertOk();

    $lead = Lead::query()->where('email', 'organic@example.com')->first();

    expect($lead)->not->toBeNull()
        ->and($lead->source_campaign_id)->toBeNull()
        ->and($lead->source_ad_id)->toBeNull()
        ->and($lead->source_channel)->toBeNull();
});
