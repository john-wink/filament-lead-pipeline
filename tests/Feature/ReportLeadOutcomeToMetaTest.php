<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ReportLeadOutcomeToMeta;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    config()->set('lead-pipeline.meta.conversions.enabled', true);

    $this->board     = LeadBoard::factory()->create();
    $this->openPhase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

/**
 * Builds a Meta lead source whose FacebookPage's connection carries an access token,
 * so the resolver can read the per-lead token.
 */
function metaSourceWithToken(LeadBoard $board, string $token = 'conn-token'): LeadSource
{
    $team       = Team::query()->firstWhere('slug', 'test');
    $connection = FacebookConnection::factory()->create([
        'access_token' => $token,
        'user_uuid'    => $team->users->first()->getKey(),
        'team_uuid'    => $team->getKey(),
    ]);

    $page = FacebookPage::factory()->create([
        'facebook_connection_uuid' => $connection->uuid,
    ]);

    return LeadSource::factory()
        ->meta()
        ->for($board, 'board')
        ->create(['facebook_page_uuid' => $page->uuid]);
}

it('dispatches the Meta feedback job when a Meta-sourced lead enters a terminal phase', function (): void {
    Queue::fake();

    $source    = LeadSource::factory()->meta()->for($this->board, 'board')->create();
    $lostPhase = LeadPhase::factory()->for($this->board, 'board')->lost()->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-987',
    ]);

    $lead->update([Lead::fkColumn('lead_phase') => $lostPhase->getKey()]);

    Queue::assertPushed(
        ReportLeadOutcomeToMeta::class,
        fn (ReportLeadOutcomeToMeta $job): bool => 'closed_lost' === $job->eventName
            && $job->leadId === (string) $lead->getKey(),
    );
});

it('maps each terminal phase type to its configured event name', function (): void {
    Queue::fake();

    $source = LeadSource::factory()->meta()->for($this->board, 'board')->create();

    $disqualifiedPhase = $this->board->phases()
        ->where('type', LeadPhaseTypeEnum::Disqualified->value)
        ->firstOrFail();

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-555',
    ]);

    $lead->update([Lead::fkColumn('lead_phase') => $disqualifiedPhase->getKey()]);

    Queue::assertPushed(
        ReportLeadOutcomeToMeta::class,
        fn (ReportLeadOutcomeToMeta $job): bool => 'disqualified' === $job->eventName,
    );
});

it('does not dispatch for a non-Meta lead', function (): void {
    Queue::fake();

    $source    = LeadSource::factory()->for($this->board, 'board')->create(); // default driver = api
    $lostPhase = LeadPhase::factory()->for($this->board, 'board')->lost()->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'whatever',
    ]);

    $lead->update([Lead::fkColumn('lead_phase') => $lostPhase->getKey()]);

    Queue::assertNotPushed(ReportLeadOutcomeToMeta::class);
});

it('does not dispatch for a Meta lead without a leadgen id', function (): void {
    Queue::fake();

    $source    = LeadSource::factory()->meta()->for($this->board, 'board')->create();
    $lostPhase = LeadPhase::factory()->for($this->board, 'board')->lost()->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => null,
    ]);

    $lead->update([Lead::fkColumn('lead_phase') => $lostPhase->getKey()]);

    Queue::assertNotPushed(ReportLeadOutcomeToMeta::class);
});

it('does not dispatch when the config feature is disabled', function (): void {
    config()->set('lead-pipeline.meta.conversions.enabled', false);

    Queue::fake();

    $source    = LeadSource::factory()->meta()->for($this->board, 'board')->create();
    $lostPhase = LeadPhase::factory()->for($this->board, 'board')->lost()->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-987',
    ]);

    $lead->update([Lead::fkColumn('lead_phase') => $lostPhase->getKey()]);

    Queue::assertNotPushed(ReportLeadOutcomeToMeta::class);
});

it('posts the Conversions API event to the per-lead resolved dataset and token', function (): void {
    Http::fake([
        'graph.facebook.com/*/ad-42*'            => Http::response(['adset' => ['promoted_object' => ['pixel_id' => 'DATASET123']]], 200),
        'graph.facebook.com/*/DATASET123/events' => Http::response(['events_received' => 1], 200),
    ]);

    $source = metaSourceWithToken($this->board, 'conn-token');

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-987',
        'source_ad_id'                => 'ad-42',
    ]);

    (new ReportLeadOutcomeToMeta((string) $lead->getKey(), 'closed_won'))
        ->handle(app(JohnWink\FilamentLeadPipeline\Services\MetaConversionsDatasetResolver::class));

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/DATASET123/events')
            && 'conn-token' === ($body['access_token'] ?? null)
            && 'closed_won' === ($body['data'][0]['event_name'] ?? null)
            && 'system_generated' === ($body['data'][0]['action_source'] ?? null)
            && 'fb-leadgen-987' === ($body['data'][0]['user_data']['lead_id'] ?? null);
    });
});

it('does not POST an event for an organic lead without a source ad id', function (): void {
    Http::fake();

    $source = metaSourceWithToken($this->board, 'conn-token');

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-987',
        'source_ad_id'                => null,
    ]);

    (new ReportLeadOutcomeToMeta((string) $lead->getKey(), 'closed_won'))
        ->handle(app(JohnWink\FilamentLeadPipeline\Services\MetaConversionsDatasetResolver::class));

    Http::assertNothingSent();
});

it('no-ops the job when the feature is disabled', function (): void {
    config()->set('lead-pipeline.meta.conversions.enabled', false);

    Http::fake();

    $source = metaSourceWithToken($this->board, 'conn-token');

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-987',
        'source_ad_id'                => 'ad-42',
    ]);

    (new ReportLeadOutcomeToMeta((string) $lead->getKey(), 'closed_won'))
        ->handle(app(JohnWink\FilamentLeadPipeline\Services\MetaConversionsDatasetResolver::class));

    Http::assertNothingSent();
});
