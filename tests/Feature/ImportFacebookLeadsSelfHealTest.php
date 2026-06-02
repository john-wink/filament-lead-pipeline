<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
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
        'facebook_user_id'   => 'fb-selfheal-1',
        'facebook_user_name' => 'SelfHealTester',
        'access_token'       => 'token',
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);

    $this->fbPage = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-selfheal',
        'page_name'                => 'SelfHeal Page',
        'page_access_token'        => 'page-token-selfheal',
    ]);

    $this->source = LeadSource::query()->create([
        'name'                             => 'Meta SelfHeal Source',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $this->fbPage->uuid,
        'facebook_form_ids'                => ['form-selfheal-1'],
    ]);
});

it('flags the connection needs-reauth when import hits a dead token', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    Http::fake([
        'graph.facebook.com/*/form-selfheal-1/leads*' => Http::response(
            ['error' => ['code' => 190, 'message' => 'dead']],
            400,
        ),
    ]);

    ImportFacebookLeadsJob::dispatchSync($this->source);

    expect($this->fbPage->connection->fresh()->status)
        ->toBe(FacebookConnectionStatusEnum::NeedsReauth)
        ->and($this->source->fresh()->status)
        ->toBe(LeadSourceStatusEnum::Error);

    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});

it('does not create duplicate leads when the same facebook lead is imported twice', function (): void {
    Http::fake([
        'graph.facebook.com/*/form-selfheal-1/leads*' => Http::response([
            'data' => [[
                'id'         => 'fb-lead-dup',
                'field_data' => [
                    ['name' => 'full_name', 'values' => ['Dup Person']],
                    ['name' => 'email', 'values' => ['dup-import@example.com']],
                ],
            ]],
            'paging' => [],
        ]),
    ]);

    ImportFacebookLeadsJob::dispatchSync($this->source);
    ImportFacebookLeadsJob::dispatchSync($this->source);

    expect(Lead::query()->where('email', 'dup-import@example.com')->count())->toBe(1);
});
