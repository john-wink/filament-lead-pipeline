<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ReportLeadOutcomeToMeta;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    config()->set('lead-pipeline.meta.conversions.enabled', true);
    config()->set('lead-pipeline.meta.conversions.dataset_id', 'ds-123');
    config()->set('lead-pipeline.meta.conversions.access_token', 'tok-abc');

    $this->board     = LeadBoard::factory()->create();
    $this->openPhase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

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
        fn (ReportLeadOutcomeToMeta $job): bool => 'fb-leadgen-987' === $job->leadgenId
            && 'closed_lost' === $job->eventName
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

it('posts the Conversions API event when handled with the feature enabled', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['events_received' => 1], 200),
    ]);

    (new ReportLeadOutcomeToMeta('lead-1', 'fb-leadgen-987', 'closed_won'))->handle();

    Http::assertSent(function ($request): bool {
        $body = $request->data();

        return str_contains($request->url(), '/v21.0/ds-123/events')
            && 'tok-abc' === ($body['access_token'] ?? null)
            && 'closed_won' === ($body['data'][0]['event_name'] ?? null)
            && 'system_generated' === ($body['data'][0]['action_source'] ?? null)
            && 'fb-leadgen-987' === ($body['data'][0]['user_data']['lead_id'] ?? null);
    });
});

it('no-ops the job when the feature is disabled', function (): void {
    config()->set('lead-pipeline.meta.conversions.enabled', false);

    Http::fake();

    (new ReportLeadOutcomeToMeta('lead-1', 'fb-leadgen-987', 'closed_won'))->handle();

    Http::assertNothingSent();
});

it('no-ops the job when dataset id or access token is missing', function (): void {
    config()->set('lead-pipeline.meta.conversions.dataset_id', null);

    Http::fake();

    (new ReportLeadOutcomeToMeta('lead-1', 'fb-leadgen-987', 'closed_won'))->handle();

    Http::assertNothingSent();
});
