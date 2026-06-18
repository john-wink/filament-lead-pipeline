<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

beforeEach(function (): void {
    config()->set('lead-pipeline.facebook.client_secret', 'test-app-secret');
    config()->set('lead-pipeline.facebook.verify_token', 'verify-me');

    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Open, 'sort' => 0]);

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

    $this->base = '/' . config('lead-pipeline.webhooks.prefix');
});

it('logs an incoming meta lead as created', function (): void {
    Http::fake([
        'graph.facebook.com/*/lead-789*' => Http::response([
            'id'         => 'lead-789',
            'form_id'    => 'form-1',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Anna Beispiel']],
                ['name' => 'email', 'values' => ['anna@example.com']],
            ],
        ]),
    ]);

    $payload = ['entry' => [[
        'id'      => 'page-100',
        'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => 'lead-789', 'form_id' => 'form-1', 'page_id' => 'page-100']]],
    ]]];
    $content = json_encode($payload);

    $this->withHeader('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $content, 'test-app-secret'))
        ->postJson($this->base . '/meta', $payload)
        ->assertOk();

    $log = LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Incoming)->where('outcome', 'created')->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->lead_source_uuid)->toBe($this->source->getKey())
        ->and($log->lead_uuid)->not->toBeNull();
});

it('logs a verify handshake for the central endpoint', function (): void {
    $this->get($this->base . '/meta?hub_verify_token=verify-me&hub_challenge=PING')
        ->assertOk()
        ->assertSee('PING');

    expect(LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Verify)->where('outcome', 'verified')->exists())->toBeTrue();
});

it('logs a failed verify handshake', function (): void {
    $this->get($this->base . '/meta?hub_verify_token=wrong&hub_challenge=PING')
        ->assertForbidden();

    expect(LeadWebhookLog::query()->where('event_type', WebhookLogEventType::Verify)->where('outcome', 'verify_failed')->exists())->toBeTrue();
});
