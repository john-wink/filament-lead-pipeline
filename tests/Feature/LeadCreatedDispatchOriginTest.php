<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\LeadOriginEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Jobs\ImportFacebookLeadsJob;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Livewire\FunnelWizard;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStepField;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService;
use Livewire\Livewire;

beforeEach(function (): void {
    Event::fake([LeadCreated::class]);

    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
});

it('dispatches manual origin from the livewire kanban board', function (): void {
    Livewire::component('lead-pipeline::kanban-board', KanbanBoard::class);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $board->admins()->syncWithoutDetaching([$this->user->getKey()]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Manueller Kanban-Lead')
        ->call('createLead')
        ->assertOk();

    Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event): bool => LeadOriginEnum::Manual === $event->origin);
});

it('dispatches manual origin from the filament kanban page', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $board->admins()->syncWithoutDetaching([$this->user->getKey()]);

    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Manueller Page-Lead')
        ->call('createLead')
        ->assertOk();

    Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event): bool => LeadOriginEnum::Manual === $event->origin);
});

it('dispatches manual origin when the funnel wizard submits', function (): void {
    Livewire::component('lead-pipeline::funnel-wizard', FunnelWizard::class);

    $board = LeadBoard::factory()->create();
    LeadPhase::factory()->for($board, 'board')->open()->create(['name' => 'Neu', 'sort' => 0]);

    $nameField  = $board->fieldDefinitions()->where('key', 'name')->first();
    $emailField = $board->fieldDefinitions()->where('key', 'email')->first();

    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $board->getKey(),
    ]);

    $stepOne = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0,
        'name'                                  => 'Step 1',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $stepOne->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $nameField->getKey(),
        'sort'                                                 => 0,
        'is_required'                                          => true,
    ]);

    $stepTwo = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 1,
        'name'                                  => 'Step 2',
    ]);
    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $stepTwo->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $emailField->getKey(),
        'sort'                                                 => 0,
        'is_required'                                          => false,
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Funnel Lead')
        ->set('formData.email', 'funnel-lead@example.com')
        ->call('nextStep')
        ->call('submit')
        ->assertOk();

    Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event): bool => LeadOriginEnum::Manual === $event->origin);
});

it('dispatches import origin for every immoscout imported lead', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Open, 'sort' => 0]);

    $connection = ImmoScoutConnection::factory()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
        'scout_id'  => '19003525',
    ]);

    $source = LeadSource::query()->create([
        'name'                             => 'IS24 Origin Import',
        'driver'                           => 'immoscout24',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'config'                           => ['immoscout_connection_uuid' => $connection->uuid],
    ]);

    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(json_decode(
            (string) file_get_contents(__DIR__ . '/../Fixtures/immoscout/test-leads.json'),
            true,
        )),
    ]);

    (new ImportImmoScoutLeadsJob($source, testMode: true))->handle(app(ImmoScoutApiService::class));

    Event::assertDispatchedTimes(LeadCreated::class, 2);
    Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event): bool => LeadOriginEnum::Import === $event->origin);
});

it('dispatches import origin for every facebook imported lead', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Open, 'sort' => 0]);

    $connection = FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-origin-1',
        'facebook_user_name' => 'Tester',
        'access_token'       => 'token',
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);

    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-origin',
        'page_name'                => 'Origin Page',
        'page_access_token'        => 'page-token',
    ]);

    $source = LeadSource::query()->create([
        'name'                             => 'Meta Origin Import',
        'driver'                           => 'meta',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'facebook_page_uuid'               => $page->uuid,
        'facebook_form_ids'                => ['form-origin-1'],
    ]);

    Http::fake([
        'graph.facebook.com/*/form-origin-1/leads*' => Http::response([
            'data' => [
                [
                    'id'           => 'lead-origin-1',
                    'form_id'      => 'form-origin-1',
                    'created_time' => '2026-07-01T10:00:00+0000',
                    'field_data'   => [
                        ['name' => 'full_name', 'values' => ['Import Frau']],
                        ['name' => 'email',     'values' => ['import@example.com']],
                    ],
                ],
            ],
            'paging' => [],
        ]),
    ]);

    (new ImportFacebookLeadsJob($source, 90))->handle(app(FacebookGraphService::class));

    Event::assertDispatched(LeadCreated::class, fn (LeadCreated $event): bool => LeadOriginEnum::Import === $event->origin);
});
