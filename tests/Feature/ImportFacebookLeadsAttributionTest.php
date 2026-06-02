<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ImportFacebookLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
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
        'facebook_user_id'   => 'fb-import-1',
        'facebook_user_name' => 'Tester',
        'access_token'       => 'token',
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);

    $this->fbPage = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-import',
        'page_name'                => 'Import Page',
        'page_access_token'        => 'page-token',
    ]);

    $this->source = LeadSource::query()->create([
        'name'                             => 'Meta Import Source',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $this->fbPage->uuid,
        'facebook_form_ids'                => ['form-import-1'],
    ]);
});

it('imports attribution fields onto each newly imported lead', function (): void {
    Http::fake([
        'graph.facebook.com/*/form-import-1/leads*' => Http::response([
            'data' => [
                [
                    'id'            => 'lead-imp-1',
                    'form_id'       => 'form-import-1',
                    'created_time'  => '2026-04-01T10:00:00+0000',
                    'ad_id'         => '111',
                    'ad_name'       => 'Ad Frühling',
                    'adset_id'      => '222',
                    'adset_name'    => 'Bonn 30-50',
                    'campaign_id'   => '333',
                    'campaign_name' => 'Frühling 2026',
                    'platform'      => 'facebook',
                    'field_data'    => [
                        ['name' => 'full_name', 'values' => ['Erste Frau']],
                        ['name' => 'email',     'values' => ['erste@example.com']],
                    ],
                ],
                [
                    'id'           => 'lead-imp-2',
                    'form_id'      => 'form-import-1',
                    'created_time' => '2026-04-02T10:00:00+0000',
                    'is_organic'   => true,
                    'field_data'   => [
                        ['name' => 'full_name', 'values' => ['Zweiter Herr']],
                        ['name' => 'email',     'values' => ['zweiter@example.com']],
                    ],
                ],
            ],
            'paging' => [],
        ]),
    ]);

    (new ImportFacebookLeadsJob($this->source, 90))->handle(app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class));

    $lead1 = Lead::query()->where('email', 'erste@example.com')->first();
    $lead2 = Lead::query()->where('email', 'zweiter@example.com')->first();

    expect($lead1)->not->toBeNull()
        ->and($lead1->source_campaign_id)->toBe('333')
        ->and($lead1->source_campaign_name)->toBe('Frühling 2026')
        ->and($lead1->source_adgroup_id)->toBe('222')
        ->and($lead1->source_ad_id)->toBe('111')
        ->and($lead1->source_channel)->toBe('facebook');

    expect($lead2)->not->toBeNull()
        ->and($lead2->source_campaign_id)->toBeNull()
        ->and($lead2->source_ad_id)->toBeNull();
});
