<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
});

it('stores secrets encrypted at rest', function (): void {
    $connection = ImmoScoutConnection::query()->create([
        'user_uuid'           => $this->user->getKey(),
        'team_uuid'           => $this->team->uuid,
        'name'                => 'IS24 Sandbox',
        'consumer_key'        => 'FinanceEstateKey',
        'consumer_secret'     => 'super-secret',
        'access_token'        => 'token-123',
        'access_token_secret' => 'token-secret-456',
        'scout_id'            => '19003525',
        'environment'         => ImmoScoutEnvironmentEnum::Sandbox,
        'status'              => ImmoScoutConnectionStatusEnum::Connected,
    ]);

    $raw = DB::table('immoscout_connections')
        ->where('uuid', $connection->uuid)
        ->first();

    expect($raw->consumer_secret)->not->toBe('super-secret')
        ->and($raw->access_token)->not->toBe('token-123')
        ->and($raw->access_token_secret)->not->toBe('token-secret-456')
        ->and($connection->fresh()->consumer_secret)->toBe('super-secret')
        ->and($connection->fresh()->access_token)->toBe('token-123')
        ->and($connection->fresh()->access_token_secret)->toBe('token-secret-456');
});

it('exposes the REST base url for its environment', function (): void {
    expect(ImmoScoutEnvironmentEnum::Production->baseUrl())
        ->toBe('https://rest.immobilienscout24.de/restapi')
        ->and(ImmoScoutEnvironmentEnum::Sandbox->baseUrl())
        ->toBe('https://rest.sandbox-immobilienscout24.de/restapi');
});

it('reports connectivity through status helpers', function (): void {
    $connection = ImmoScoutConnection::factory()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
    ]);

    expect($connection->isConnected())->toBeTrue();

    $connection->update(['status' => ImmoScoutConnectionStatusEnum::Error]);

    expect($connection->fresh()->isConnected())->toBeFalse();
});

it('scopes connections to a team', function (): void {
    $other = Team::factory()->create();

    ImmoScoutConnection::factory()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
        'name'      => 'Mine',
    ]);
    ImmoScoutConnection::factory()->create([
        'team_uuid' => $other->uuid,
        'user_uuid' => $this->user->getKey(),
        'name'      => 'Foreign',
    ]);

    $names = ImmoScoutConnection::query()
        ->where('team_uuid', $this->team->uuid)
        ->pluck('name');

    expect($names)->toContain('Mine')->not->toContain('Foreign');
});
