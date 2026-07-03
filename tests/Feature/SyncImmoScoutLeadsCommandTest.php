<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $this->connection = ImmoScoutConnection::factory()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
    ]);
});

function makeImmoScoutSource(array $attributes = []): LeadSource
{
    return LeadSource::query()->create(array_merge([
        'name'                             => 'IS24 ' . fake()->word(),
        'driver'                           => 'immoscout24',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => test()->board->getKey(),
        'team_uuid'                        => test()->team->uuid,
        'created_by'                       => test()->user->getKey(),
        'config'                           => ['immoscout_connection_uuid' => test()->connection->uuid],
    ], $attributes));
}

it('dispatches import jobs only for active auto-sync immoscout sources', function (): void {
    Queue::fake();

    $active = makeImmoScoutSource();
    makeImmoScoutSource(['status' => LeadSourceStatusEnum::Draft]);
    makeImmoScoutSource(['config' => ['immoscout_connection_uuid' => test()->connection->uuid, 'auto_sync' => false]]);
    makeImmoScoutSource(['driver' => 'api', 'config' => []]);

    $this->artisan('lead-pipeline:sync-immoscout-leads')->assertSuccessful();

    Queue::assertPushed(ImportImmoScoutLeadsJob::class, 1);
    Queue::assertPushed(fn (ImportImmoScoutLeadsJob $job): bool => $job->source->is($active));
});

it('passes an explicit window through to the job', function (): void {
    Queue::fake();

    makeImmoScoutSource();

    $this->artisan('lead-pipeline:sync-immoscout-leads', ['--days' => 14])->assertSuccessful();

    Queue::assertPushed(fn (ImportImmoScoutLeadsJob $job): bool => 14 === $job->days);
});

it('registers a config-gated polling schedule', function (): void {
    config(['lead-pipeline.immoscout.sync.enabled' => true]);

    $event = collect(app(Schedule::class)->events())->first(
        fn ($event): bool => str_contains((string) $event->command, 'sync-immoscout-leads'),
    );

    expect($event)->not->toBeNull();
});
