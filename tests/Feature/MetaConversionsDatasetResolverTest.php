<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\MetaConversionsDatasetResolver;

beforeEach(function (): void {
    $this->team      = Team::query()->firstWhere('slug', 'test');
    $this->user      = $this->team->users->first();
    $this->board     = LeadBoard::factory()->create();
    $this->openPhase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
    $this->resolver  = app(MetaConversionsDatasetResolver::class);
});

function leadWithAd(LeadBoard $board, LeadPhase $phase, ?string $adId, string $token = 'conn-token'): Lead
{
    $team       = Team::query()->firstWhere('slug', 'test');
    $connection = FacebookConnection::factory()->create([
        'access_token' => $token,
        'user_uuid'    => $team->users->first()->getKey(),
        'team_uuid'    => $team->getKey(),
    ]);
    $page   = FacebookPage::factory()->create(['facebook_connection_uuid' => $connection->uuid]);
    $source = LeadSource::factory()->meta()->for($board, 'board')->create(['facebook_page_uuid' => $page->uuid]);

    return Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $board->getKey(),
        Lead::fkColumn('lead_phase')  => $phase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-1',
        'source_ad_id'                => $adId,
    ]);
}

it('resolves the dataset id from the ad adset promoted_object pixel', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['adset' => ['promoted_object' => ['pixel_id' => 'DATASET123']]], 200),
    ]);

    $lead = leadWithAd($this->board, $this->openPhase, 'ad-42');

    expect($this->resolver->resolve($lead))->toBe('DATASET123');
});

it('caches the resolved dataset and only hits Graph once', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['adset' => ['promoted_object' => ['pixel_id' => 'DATASET123']]], 200),
    ]);

    $lead = leadWithAd($this->board, $this->openPhase, 'ad-42');

    expect($this->resolver->resolve($lead))->toBe('DATASET123')
        ->and($this->resolver->resolve($lead))->toBe('DATASET123');

    Http::assertSentCount(1);
});

it('returns null and caches the miss for a lead without a source ad id', function (): void {
    Http::fake();

    $lead = leadWithAd($this->board, $this->openPhase, null);

    expect($this->resolver->resolve($lead))->toBeNull();

    Http::assertNothingSent();
});

it('returns null when the ad payload has no promoted_object pixel', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['adset' => ['id' => 'as-1']], 200),
    ]);

    $lead = leadWithAd($this->board, $this->openPhase, 'ad-99');

    expect($this->resolver->resolve($lead))->toBeNull();
});

it('returns null on a Graph HTTP error', function (): void {
    Http::fake([
        'graph.facebook.com/*' => Http::response(['error' => ['message' => 'nope']], 400),
    ]);

    $lead = leadWithAd($this->board, $this->openPhase, 'ad-err');

    expect($this->resolver->resolve($lead))->toBeNull();
});

it('returns null when the source connection has no token', function (): void {
    Http::fake();

    $source = LeadSource::factory()->meta()->for($this->board, 'board')->create(); // no facebook page

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $this->board->getKey(),
        Lead::fkColumn('lead_phase')  => $this->openPhase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
        'external_id'                 => 'fb-leadgen-1',
        'source_ad_id'                => 'ad-42',
    ]);

    expect($this->resolver->resolve($lead))->toBeNull();

    Http::assertNothingSent();
});
