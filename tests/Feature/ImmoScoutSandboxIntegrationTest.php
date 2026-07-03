<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService;

/*
 * Live integration against the ImmoScout24 sandbox. Skipped unless sandbox
 * credentials are provided via environment variables:
 *
 *   IS24_SANDBOX_CONSUMER_KEY=... IS24_SANDBOX_CONSUMER_SECRET=... \
 *   [IS24_SANDBOX_ACCESS_TOKEN=... IS24_SANDBOX_ACCESS_TOKEN_SECRET=...] \
 *   vendor/bin/pest tests/Feature/ImmoScoutSandboxIntegrationTest.php
 */

it('imports real test leads end-to-end from the sandbox', function (): void {
    $team = Team::query()->firstWhere('slug', 'test');
    $user = $team->users->first();

    $board = LeadBoard::factory()->create(['team_uuid' => $team->uuid]);
    LeadPhase::factory()->for($board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);

    $connection = ImmoScoutConnection::query()->create([
        'user_uuid'           => $user->getKey(),
        'team_uuid'           => $team->uuid,
        'name'                => 'Sandbox Live',
        'consumer_key'        => (string) getenv('IS24_SANDBOX_CONSUMER_KEY'),
        'consumer_secret'     => (string) getenv('IS24_SANDBOX_CONSUMER_SECRET'),
        'access_token'        => getenv('IS24_SANDBOX_ACCESS_TOKEN') ?: null,
        'access_token_secret' => getenv('IS24_SANDBOX_ACCESS_TOKEN_SECRET') ?: null,
        'environment'         => ImmoScoutEnvironmentEnum::Sandbox,
    ]);

    $source = LeadSource::query()->create([
        'name'                             => 'IS24 Sandbox Live',
        'driver'                           => 'immoscout24',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid'                        => $team->uuid,
        'created_by'                       => $user->getKey(),
        'config'                           => ['immoscout_connection_uuid' => $connection->uuid],
    ]);

    $api = app(ImmoScoutApiService::class);

    (new ImportImmoScoutLeadsJob($source, testMode: true))->handle($api);

    $count = Lead::query()->count();

    expect($count)->toBeGreaterThanOrEqual(1)
        ->and($source->fresh()->last_received_at)->not->toBeNull();

    $lead = Lead::query()->whereNotNull('external_id')->first();

    expect($lead->name)->not->toBeEmpty()
        ->and($lead->getFieldValue('is24_financing_type'))->not->toBeNull();

    // Second run must not duplicate anything
    (new ImportImmoScoutLeadsJob($source->fresh(), testMode: true))->handle($api);

    expect(Lead::query()->count())->toBe($count);
})->skip(
    fn (): bool => '' === (string) getenv('IS24_SANDBOX_CONSUMER_KEY'),
    'IS24 sandbox credentials not provided',
);
