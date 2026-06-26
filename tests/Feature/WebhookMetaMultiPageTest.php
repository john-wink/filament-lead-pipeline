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
    config()->set('lead-pipeline.facebook.client_secret', 'multi-page-secret');

    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);
});

function makeConn(Team $team, $user, string $fbUserId): FacebookConnection
{
    return FacebookConnection::query()->create([
        'user_uuid'          => $user->id,
        'team_uuid'          => $team->uuid,
        'facebook_user_id'   => $fbUserId,
        'facebook_user_name' => 'Tester ' . $fbUserId,
        'access_token'       => 'token-' . $fbUserId,
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);
}

function postMetaCentral(array $payload): Illuminate\Testing\TestResponse
{
    $content = json_encode($payload);
    $sig     = 'sha256=' . hash_hmac('sha256', $content, config('lead-pipeline.facebook.client_secret'));
    $url     = '/' . config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks') . '/meta';

    return test()->withHeader('X-Hub-Signature-256', $sig)->postJson($url, $payload);
}

it('creates a lead when the source is bound to a non-first page row sharing the page_id', function (): void {
    // First-inserted page row (what ->first() returns) — NO source maps the form here.
    $pageFirst = FacebookPage::query()->create([
        'facebook_connection_uuid' => makeConn($this->team, $this->user, 'fb-A')->uuid,
        'page_id'                  => 'dup-page',
        'page_name'                => 'Dup Page A',
        'page_access_token'        => 'token-A',
        'is_webhooks_subscribed'   => true,
    ]);

    // Second page row (same page_id) — the source with the mapped form is bound HERE.
    $pageSecond = FacebookPage::query()->create([
        'facebook_connection_uuid' => makeConn($this->team, $this->user, 'fb-B')->uuid,
        'page_id'                  => 'dup-page',
        'page_name'                => 'Dup Page B',
        'page_access_token'        => 'token-B',
        'is_webhooks_subscribed'   => true,
    ]);

    $source = LeadSource::query()->create([
        'name'                             => 'Bound to second page',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $pageSecond->uuid,
        'facebook_form_ids'                => ['form-X'],
    ]);

    Http::fake([
        'graph.facebook.com/*/lead-multi*' => Http::response([
            'id'         => 'lead-multi',
            'form_id'    => 'form-X',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Max Mustermann']],
                ['name' => 'email', 'values' => ['max@example.com']],
            ],
        ]),
    ]);

    postMetaCentral([
        'object' => 'page',
        'entry'  => [[
            'id'      => 'dup-page',
            'time'    => 123,
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => 'lead-multi', 'form_id' => 'form-X', 'page_id' => 'dup-page'],
            ]],
        ]],
    ])->assertOk();

    expect(
        Lead::query()
            ->where('external_id', 'lead-multi')
            ->where(Lead::fkColumn('lead_source'), $source->getKey())
            ->exists()
    )->toBeTrue();
});

it('creates one independent lead per board when the same form is mapped across multiple teams', function (): void {
    // Same form, two teams, two Facebook connections (two page rows, same page_id).
    // Each board must receive its own standalone lead for the same leadgen event.
    $boardB = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($boardB, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);

    $pageA = FacebookPage::query()->create([
        'facebook_connection_uuid' => makeConn($this->team, $this->user, 'fb-team-a')->uuid,
        'page_id'                  => 'shared-page',
        'page_name'                => 'Shared Page (Team A)',
        'page_access_token'        => 'token-a',
        'is_webhooks_subscribed'   => true,
    ]);
    $pageB = FacebookPage::query()->create([
        'facebook_connection_uuid' => makeConn($this->team, $this->user, 'fb-team-b')->uuid,
        'page_id'                  => 'shared-page',
        'page_name'                => 'Shared Page (Team B)',
        'page_access_token'        => 'token-b',
        'is_webhooks_subscribed'   => true,
    ]);

    $sourceA = LeadSource::query()->create([
        'name'                             => 'Team A source',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $pageA->uuid,
        'facebook_form_ids'                => ['shared-form'],
    ]);
    $sourceB = LeadSource::query()->create([
        'name'                             => 'Team B source',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $boardB->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $pageB->uuid,
        'facebook_form_ids'                => ['shared-form'],
    ]);

    Http::fake([
        'graph.facebook.com/*/lead-shared*' => Http::response([
            'id'         => 'lead-shared',
            'form_id'    => 'shared-form',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Lara Beispiel']],
                ['name' => 'email', 'values' => ['lara@example.com']],
            ],
        ]),
    ]);

    postMetaCentral([
        'object' => 'page',
        'entry'  => [[
            'id'      => 'shared-page',
            'time'    => 123,
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => 'lead-shared', 'form_id' => 'shared-form', 'page_id' => 'shared-page'],
            ]],
        ]],
    ])->assertOk();

    // Each board got its own standalone lead with the same external id.
    expect(Lead::query()->where('external_id', 'lead-shared')->count())->toBe(2)
        ->and(Lead::query()->where('external_id', 'lead-shared')->where(Lead::fkColumn('lead_source'), $sourceA->getKey())->exists())->toBeTrue()
        ->and(Lead::query()->where('external_id', 'lead-shared')->where(Lead::fkColumn('lead_source'), $sourceB->getKey())->exists())->toBeTrue()
        ->and(Lead::query()->where('external_id', 'lead-shared')->where(Lead::fkColumn('lead_board'), $boardB->getKey())->exists())->toBeTrue();
});

it('maps a non-auto-detected facebook field to the core email field via custom_field_mapping', function (): void {
    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => makeConn($this->team, $this->user, 'fb-core')->uuid,
        'page_id'                  => 'core-page',
        'page_name'                => 'Core Page',
        'page_access_token'        => 'token-core',
        'is_webhooks_subscribed'   => true,
    ]);

    $source = LeadSource::query()->create([
        'name'                             => 'Core field mapping',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $page->uuid,
        'facebook_form_ids'                => ['form-core'],
        'config'                           => [
            'custom_field_mapping' => [
                ['facebook_key' => 'business_email', 'board_field_key' => '__core_email__'],
            ],
        ],
    ]);

    Http::fake([
        'graph.facebook.com/*/lead-core*' => Http::response([
            'id'         => 'lead-core',
            'form_id'    => 'form-core',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Erika Muster']],
                ['name' => 'business_email', 'values' => ['biz@example.com']],
            ],
        ]),
    ]);

    postMetaCentral([
        'object' => 'page',
        'entry'  => [[
            'id'      => 'core-page',
            'time'    => 123,
            'changes' => [[
                'field' => 'leadgen',
                'value' => ['leadgen_id' => 'lead-core', 'form_id' => 'form-core', 'page_id' => 'core-page'],
            ]],
        ]],
    ])->assertOk();

    $lead = Lead::query()->where('external_id', 'lead-core')->first();

    expect($lead)->not->toBeNull()
        ->and($lead->email)->toBe('biz@example.com')
        ->and($lead->name)->toBe('Erika Muster');
});
