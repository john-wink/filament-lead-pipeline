<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

function immoscoutFixtureResponse(): array
{
    return json_decode(
        (string) file_get_contents(__DIR__ . '/../Fixtures/immoscout/test-leads.json'),
        true,
    );
}

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $this->openPhase = LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open,
        'sort' => 0,
    ]);

    $this->connection = ImmoScoutConnection::factory()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
        'scout_id'  => '19003525',
    ]);

    $this->source = LeadSource::query()->create([
        'name'                             => 'IS24 Import',
        'driver'                           => 'immoscout24',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'config'                           => ['immoscout_connection_uuid' => $this->connection->uuid],
    ]);
});

it('imports sandbox test leads with mapped fields and auto-created definitions', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(immoscoutFixtureResponse()),
    ]);

    (new ImportImmoScoutLeadsJob($this->source, testMode: true))->handle(app(JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService::class));

    $leads = Lead::query()->orderBy('external_id')->get();

    expect($leads)->toHaveCount(2);

    $first = $leads->first();

    expect($first->name)->toBe('Max Mustermann')
        ->and($first->email)->toBe('max.mustermann@is24.de')
        ->and($first->phone)->toBe('55501456789')
        ->and($first->external_id)->toBe('1')
        ->and((float) $first->value)->toBe(595000.0)
        ->and($first->source_channel)->toBe('immoscout24')
        ->and($first->raw_data['requestType'])->toBe('APPOINTMENT_REQUEST');

    $definition = $this->board->fieldDefinitions()->firstWhere('key', 'is24_purchase_price');

    expect($definition)->not->toBeNull()
        ->and($definition->type)->toBe(LeadFieldTypeEnum::Currency)
        ->and($first->getFieldValue('is24_purchase_price'))->not->toBeNull();

    $activity = $first->activities()->first();

    expect($activity->properties['source_driver'] ?? null)->toBe('immoscout24');
});

it('is idempotent across repeated runs', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(immoscoutFixtureResponse()),
    ]);

    $api = app(JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService::class);

    (new ImportImmoScoutLeadsJob($this->source, testMode: true))->handle($api);
    (new ImportImmoScoutLeadsJob($this->source, testMode: true))->handle($api);

    expect(Lead::query()->count())->toBe(2);
});

it('assigns the default assignee and prefers the in-progress phase', function (): void {
    $inProgress = LeadPhase::factory()->for($this->board, 'board')->create([
        'type' => LeadPhaseTypeEnum::InProgress,
        'sort' => 1,
    ]);

    $this->source->update(['default_assigned_to' => $this->user->getKey()]);

    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(immoscoutFixtureResponse()),
    ]);

    (new ImportImmoScoutLeadsJob($this->source->fresh(), testMode: true))->handle(app(JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService::class));

    $lead = Lead::query()->first();

    expect($lead->assigned_to)->toBe($this->user->getKey())
        ->and($lead->{Lead::fkColumn('lead_phase')})->toBe($inProgress->getKey());
});

it('marks the connection and source on auth errors', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response([
            'message' => [['messageCode' => 'ERROR_COMMON_ACCESS_DENIED', 'message' => 'No authorization for this operation.']],
        ], 403),
    ]);

    (new ImportImmoScoutLeadsJob($this->source, testMode: true))->handle(app(JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService::class));

    expect($this->connection->fresh()->status)->toBe(ImmoScoutConnectionStatusEnum::Error)
        ->and($this->connection->fresh()->last_error)->toContain('No authorization')
        ->and($this->source->fresh()->status)->toBe(LeadSourceStatusEnum::Error)
        ->and(Lead::query()->count())->toBe(0);
});

it('polls a from/to window with the scout id in normal mode', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(['lead' => []]),
    ]);

    (new ImportImmoScoutLeadsJob($this->source, days: 30))->handle(app(JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService::class));

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'from=')
        && str_contains($request->url(), 'to=')
        && str_contains($request->url(), 'scoutid=19003525')
        && ! str_contains($request->url(), 'test=true'));
});

it('updates sync bookkeeping and self-heals error states on success', function (): void {
    $this->connection->update(['status' => ImmoScoutConnectionStatusEnum::Error, 'last_error' => 'old']);
    $this->source->update(['status' => LeadSourceStatusEnum::Error, 'error_message' => 'old']);

    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(immoscoutFixtureResponse()),
    ]);

    (new ImportImmoScoutLeadsJob($this->source->fresh(), testMode: true))->handle(app(JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService::class));

    expect($this->source->fresh()->last_received_at)->not->toBeNull()
        ->and($this->source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and($this->connection->fresh()->status)->toBe(ImmoScoutConnectionStatusEnum::Connected)
        ->and($this->connection->fresh()->last_synced_at)->not->toBeNull();
});
