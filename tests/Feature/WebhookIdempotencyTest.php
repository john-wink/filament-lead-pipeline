<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
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
    LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Open, 'sort' => 0]);

    $this->connection = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
    ]);
    $this->fbPage = FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-100', 'page_name' => 'Test Page',
        'page_access_token'        => 'page-token', 'is_webhooks_subscribed' => true,
    ]);
    $this->source = LeadSource::query()->create([
        'name'                             => 'Meta Source', 'driver' => 'meta', 'status' => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid, 'created_by' => $this->user->getKey(),
        'facebook_page_uuid'               => $this->fbPage->uuid, 'facebook_form_ids' => ['form-1'],
    ]);
});

function idemMetaCall(array $payload): Illuminate\Testing\TestResponse
{
    $url     = '/' . config('lead-pipeline.webhooks.prefix') . '/meta';
    $content = json_encode($payload);
    $secret  = config('lead-pipeline.facebook.client_secret');

    return test()->withHeader('X-Hub-Signature-256', 'sha256=' . hash_hmac('sha256', $content, $secret))
        ->postJson($url, $payload);
}

function idemLeadgenPayload(string $leadgenId): array
{
    return ['entry' => [[
        'id'      => 'page-100',
        'changes' => [['field' => 'leadgen', 'value' => [
            'leadgen_id' => $leadgenId, 'form_id' => 'form-1', 'page_id' => 'page-100',
        ]]],
    ]]];
}

it('creates only one lead when the same leadgen webhook is delivered twice', function (): void {
    Http::fake([
        'graph.facebook.com/*/lead-dup*' => Http::response([
            'id'         => 'lead-dup', 'form_id' => 'form-1',
            'field_data' => [['name' => 'email', 'values' => ['dup@example.com']]],
        ]),
    ]);

    idemMetaCall(idemLeadgenPayload('lead-dup'))->assertOk();
    idemMetaCall(idemLeadgenPayload('lead-dup'))->assertOk();

    expect(Lead::query()->where('email', 'dup@example.com')->count())->toBe(1);
});

it('acks with 200 and flags needs-reauth when the page token is dead', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    Http::fake([
        'graph.facebook.com/*/lead-dead*' => Http::response(['error' => ['code' => 190, 'message' => 'dead']], 400),
    ]);

    idemMetaCall(idemLeadgenPayload('lead-dead'))->assertOk();

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth);
    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});
