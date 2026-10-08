<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use JohnWink\FilamentLeadPipeline\Concerns\MarksConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\LeadCreated;
use JohnWink\FilamentLeadPipeline\Jobs\ImportFacebookLeadsJob;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Livewire\FunnelWizard;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStepField;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;
use JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService;
use Livewire\Livewire;

beforeEach(function (): void {
    config()->set('lead-pipeline.facebook.client_secret', 'ended-intake-secret');

    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);

    $this->webhookBase = '/' . config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks');
});

function endedIntakeFacebookPage(string $pageId = 'ended-page'): FacebookPage
{
    $connection = FacebookConnection::query()->create([
        'user_uuid'          => test()->user->id,
        'team_uuid'          => test()->team->uuid,
        'facebook_user_id'   => 'fb-' . $pageId,
        'facebook_user_name' => 'Tester',
        'access_token'       => 'token',
        'token_expires_at'   => now()->addDays(30),
        'scopes'             => ['leads_retrieval'],
        'status'             => 'connected',
    ]);

    return FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => $pageId,
        'page_name'                => 'Ended Page',
        'page_access_token'        => 'page-token',
        'is_webhooks_subscribed'   => true,
    ]);
}

function endedIntakeMetaSource(FacebookPage $page, LeadSourceStatusEnum $status, array $formIds = ['form-ended']): LeadSource
{
    return LeadSource::query()->create([
        'name'                             => 'Meta ' . $status->value,
        'driver'                           => 'meta',
        'status'                           => $status,
        LeadSource::fkColumn('lead_board') => test()->board->getKey(),
        'team_uuid'                        => test()->team->uuid,
        'created_by'                       => test()->user->getKey(),
        'facebook_page_uuid'               => $page->uuid,
        'facebook_form_ids'                => $formIds,
    ]);
}

function endedIntakeImmoScoutSource(LeadSourceStatusEnum $status, array $config = []): LeadSource
{
    $connection = ImmoScoutConnection::factory()->create([
        'team_uuid' => test()->team->uuid,
        'user_uuid' => test()->user->getKey(),
    ]);

    return LeadSource::query()->create([
        'name'                             => 'IS24 ' . $status->value,
        'driver'                           => 'immoscout24',
        'status'                           => $status,
        LeadSource::fkColumn('lead_board') => test()->board->getKey(),
        'team_uuid'                        => test()->team->uuid,
        'created_by'                       => test()->user->getKey(),
        'config'                           => ['immoscout_connection_uuid' => $connection->uuid, ...$config],
    ]);
}

function endedIntakePostMetaCentral(string $pageId, string $leadgenId, string $formId): TestResponse
{
    $payload = ['object' => 'page', 'entry' => [[
        'id'      => $pageId,
        'changes' => [['field' => 'leadgen', 'value' => ['leadgen_id' => $leadgenId, 'form_id' => $formId, 'page_id' => $pageId]]],
    ]]];

    $signature = 'sha256=' . hash_hmac('sha256', (string) json_encode($payload), config('lead-pipeline.facebook.client_secret'));

    return test()->withHeader('X-Hub-Signature-256', $signature)->postJson(test()->webhookBase . '/meta', $payload);
}

function endedIntakeFakeLeadgen(string $leadgenId): void
{
    Http::fake([
        "graph.facebook.com/*/{$leadgenId}*" => Http::response([
            'id'         => $leadgenId,
            'form_id'    => 'form-ended',
            'field_data' => [
                ['name' => 'full_name', 'values' => ['Erika Beispiel']],
                ['name' => 'email', 'values' => ['erika@example.com']],
            ],
        ]),
    ]);
}

function endedIntakeFunnel(LeadSourceStatusEnum $status, string $slug): LeadFunnel
{
    $source = LeadSource::factory()->for(test()->board, 'board')->funnel()->create(['status' => $status]);

    $funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => test()->board->getKey(),
        'slug'                              => $slug,
        'is_active'                         => true,
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $funnel->getKey(),
        'sort'                                  => 0,
        'name'                                  => 'Kontakt',
    ]);

    foreach (['name', 'email'] as $sort => $key) {
        LeadFunnelStepField::factory()->create([
            LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
            LeadFunnelStepField::fkColumn('lead_field_definition') => test()->board->fieldDefinitions()->where('key', $key)->first()->getKey(),
            'sort'                                                 => $sort,
            'is_required'                                          => false,
        ]);
    }

    return $funnel;
}

it('rejects the generic webhook of an ended source and logs it like a paused one', function (string $driver): void {
    $source = LeadSource::factory()
        ->for($this->board, 'board')
        ->withApiToken()
        ->create(['driver' => $driver, 'status' => LeadSourceStatusEnum::Ended]);

    $this->withHeader('Authorization', 'Bearer ' . $source->api_token)
        ->postJson($this->webhookBase . '/' . $source->getKey(), ['name' => 'Blocked', 'email' => 'blocked@example.com'])
        ->assertNotFound();

    expect(Lead::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and(LeadWebhookLog::query()
            ->where('event_type', WebhookLogEventType::Incoming)
            ->where('outcome', 'source_inactive')
            ->where('http_status', 404)
            ->exists())->toBeTrue();
})->with(['api', 'zapier', 'funnel', 'meta']);

it('rejects the per-source meta webhook of an ended source', function (): void {
    $source = endedIntakeMetaSource(endedIntakeFacebookPage(), LeadSourceStatusEnum::Ended);

    $this->postJson($this->webhookBase . '/meta/' . $source->getKey(), ['entry' => []])->assertNotFound();

    expect(Lead::query()->count())->toBe(0)
        ->and(LeadWebhookLog::query()->where('outcome', 'source_inactive')->exists())->toBeTrue();
});

it('skips an ended source on the central meta webhook and logs it like a paused one', function (LeadSourceStatusEnum $status): void {
    $source = endedIntakeMetaSource(endedIntakeFacebookPage(), $status);
    endedIntakeFakeLeadgen('lead-ended-1');

    endedIntakePostMetaCentral('ended-page', 'lead-ended-1', 'form-ended')
        ->assertOk()
        ->assertJson(['leads_created' => 0]);

    expect(Lead::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe($status)
        ->and(LeadWebhookLog::query()
            ->where('event_type', WebhookLogEventType::Incoming)
            ->where('outcome', 'skipped')
            ->where('message', 'form_not_mapped:form-ended')
            ->exists())->toBeTrue();

    Http::assertNothingSent();
})->with([
    'ended'  => [LeadSourceStatusEnum::Ended],
    'paused' => [LeadSourceStatusEnum::Paused],
]);

it('keeps delivering central meta leads to draft and failed sources', function (LeadSourceStatusEnum $status): void {
    $source = endedIntakeMetaSource(endedIntakeFacebookPage(), $status);
    endedIntakeFakeLeadgen('lead-ended-2');

    endedIntakePostMetaCentral('ended-page', 'lead-ended-2', 'form-ended')
        ->assertOk()
        ->assertJson(['leads_created' => 1]);

    expect(Lead::query()->where(Lead::fkColumn('lead_source'), $source->getKey())->count())->toBe(1);
})->with([
    'draft' => [LeadSourceStatusEnum::Draft],
    'error' => [LeadSourceStatusEnum::Error],
]);

it('delivers a central meta lead only to the source that has not been ended', function (): void {
    $page    = endedIntakeFacebookPage();
    $ended   = endedIntakeMetaSource($page, LeadSourceStatusEnum::Ended);
    $running = endedIntakeMetaSource($page, LeadSourceStatusEnum::Active);
    endedIntakeFakeLeadgen('lead-ended-3');

    endedIntakePostMetaCentral('ended-page', 'lead-ended-3', 'form-ended')
        ->assertOk()
        ->assertJson(['leads_created' => 1]);

    expect(Lead::query()->where(Lead::fkColumn('lead_source'), $running->getKey())->count())->toBe(1)
        ->and(Lead::query()->where(Lead::fkColumn('lead_source'), $ended->getKey())->count())->toBe(0);
});

it('does not serve the funnel of an ended source', function (): void {
    $funnel = endedIntakeFunnel(LeadSourceStatusEnum::Ended, 'fn-ended');

    $this->get('/funnel/fn-ended')->assertNotFound();

    expect($funnel->fresh()->views_count)->toBe(0);
});

it('keeps serving the funnel of a paused source', function (): void {
    endedIntakeFunnel(LeadSourceStatusEnum::Paused, 'fn-paused');

    $this->get('/funnel/fn-paused')->assertOk();
});

it('does not create a lead when the funnel of an ended source is submitted', function (): void {
    Event::fake([LeadCreated::class]);

    $funnel = endedIntakeFunnel(LeadSourceStatusEnum::Active, 'fn-submit');

    $wizard = Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Spät Dran')
        ->set('formData.email', 'spaet@example.com');

    $funnel->source->end();

    $wizard->call('submit')->assertNotFound();

    expect(Lead::query()->count())->toBe(0)
        ->and($funnel->fresh()->submissions_count)->toBe(0);

    Event::assertNotDispatched(LeadCreated::class);
});

it('does not mount the funnel wizard of an ended source', function (): void {
    $funnel = endedIntakeFunnel(LeadSourceStatusEnum::Ended, 'fn-mount');

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])->assertNotFound();
});

it('still creates a lead when the funnel of a running source is submitted', function (): void {
    Event::fake([LeadCreated::class]);

    $funnel = endedIntakeFunnel(LeadSourceStatusEnum::Active, 'fn-running');

    Livewire::test(FunnelWizard::class, ['funnelId' => $funnel->getKey()])
        ->set('formData.name', 'Recht Zeitig')
        ->set('formData.email', 'zeitig@example.com')
        ->call('submit')
        ->assertOk();

    expect(Lead::query()->where('email', 'zeitig@example.com')->exists())->toBeTrue();
});

it('does not import facebook leads for an ended source', function (): void {
    $source = endedIntakeMetaSource(endedIntakeFacebookPage(), LeadSourceStatusEnum::Ended);

    Http::fake([
        'graph.facebook.com/*/form-ended/leads*' => Http::response([
            'data' => [[
                'id'         => 'fb-ended-import',
                'field_data' => [
                    ['name' => 'full_name', 'values' => ['Import Person']],
                    ['name' => 'email', 'values' => ['import@example.com']],
                ],
            ]],
            'paging' => [],
        ]),
    ]);

    ImportFacebookLeadsJob::dispatchSync($source);

    expect(Lead::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($source->fresh()->last_received_at)->toBeNull();

    Http::assertNothingSent();
});

it('does not import facebook leads through a stale instance of an ended source', function (): void {
    $source = endedIntakeMetaSource(endedIntakeFacebookPage(), LeadSourceStatusEnum::Active);

    LeadSource::query()->whereKey($source->getKey())->update(['status' => LeadSourceStatusEnum::Ended]);

    Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);

    (new ImportFacebookLeadsJob($source))->handle(app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class));

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($source->fresh()->error_message)->toBeNull();

    Http::assertNothingSent();
});

it('does not import immoscout leads for an ended source and keeps it ended', function (): void {
    $source = endedIntakeImmoScoutSource(LeadSourceStatusEnum::Ended);

    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(
            json_decode((string) file_get_contents(__DIR__ . '/../Fixtures/immoscout/test-leads.json'), true),
        ),
    ]);

    (new ImportImmoScoutLeadsJob($source, testMode: true))->handle(app(ImmoScoutApiService::class));

    expect(Lead::query()->count())->toBe(0)
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended);

    Http::assertNothingSent();
});

it('keeps an ended immoscout source ended when its connection is gone', function (): void {
    $source = endedIntakeImmoScoutSource(LeadSourceStatusEnum::Ended, ['immoscout_connection_uuid' => null]);

    (new ImportImmoScoutLeadsJob($source))->handle(app(ImmoScoutApiService::class));

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($source->fresh()->error_message)->toBeNull();
});

it('still reports a failing immoscout import on a source that has not been ended', function (): void {
    $source = endedIntakeImmoScoutSource(LeadSourceStatusEnum::Active, ['immoscout_connection_uuid' => null]);

    (new ImportImmoScoutLeadsJob($source))->handle(app(ImmoScoutApiService::class));

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Error);
});

it('does not dispatch the scheduled immoscout sync for an ended source', function (): void {
    Queue::fake();

    endedIntakeImmoScoutSource(LeadSourceStatusEnum::Ended);

    $this->artisan('lead-pipeline:sync-immoscout-leads')->assertSuccessful();

    Queue::assertNotPushed(ImportImmoScoutLeadsJob::class);
});

it('keeps an ended source ended when its facebook connection needs a new login', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    $page    = endedIntakeFacebookPage();
    $ended   = endedIntakeMetaSource($page, LeadSourceStatusEnum::Ended);
    $running = endedIntakeMetaSource($page, LeadSourceStatusEnum::Active);

    $marker = new class() {
        use MarksConnectionNeedsReauth;

        public function mark(FacebookConnection $connection): void
        {
            $this->markConnectionNeedsReauth($connection, 'token dead');
        }
    };

    $marker->mark($page->connection);

    expect($ended->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($ended->fresh()->error_message)->toBeNull()
        ->and($running->fresh()->status)->toBe(LeadSourceStatusEnum::Error)
        ->and($running->fresh()->error_message)->not->toBeNull();
});
