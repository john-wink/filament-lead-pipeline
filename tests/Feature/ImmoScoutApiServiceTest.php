<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Exceptions\ImmoScoutAuthException;
use JohnWink\FilamentLeadPipeline\Exceptions\ImmoScoutTransientException;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    $this->connection = ImmoScoutConnection::factory()->create([
        'team_uuid'   => $this->team->uuid,
        'user_uuid'   => $this->user->getKey(),
        'scout_id'    => '19003525',
        'environment' => ImmoScoutEnvironmentEnum::Sandbox,
    ]);
});

it('fetches leads with a signed request against the environment host', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(['lead' => [['id' => 1]]]),
    ]);

    $service = app(ImmoScoutApiService::class);
    $leads   = $service->fetchLeads(
        $this->connection,
        now()->parse('2026-06-01 00:00:00'),
        now()->parse('2026-07-01 12:30:45'),
    );

    expect($leads)->toBe([['id' => 1]]);

    Http::assertSent(function (Request $request): bool {
        return str_starts_with($request->url(), 'https://rest.sandbox-immobilienscout24.de/restapi/api/financing/construction/v2/lead')
            && 'GET' === $request->method()
            && str_contains($request->url(), 'from=2026-06-01T00%3A00%3A00')
            && str_contains($request->url(), 'to=2026-07-01T12%3A30%3A45')
            && str_contains($request->url(), 'scoutid=19003525')
            && 'application/json' === $request->header('Accept')[0]
            && str_starts_with($request->header('Authorization')[0] ?? '', 'OAuth ')
            && str_contains($request->header('Authorization')[0], 'oauth_token=');
    });
});

it('fetches test leads with test=true and no window parameters', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(['lead' => [['id' => 7]]]),
    ]);

    $leads = app(ImmoScoutApiService::class)->fetchTestLeads($this->connection);

    expect($leads)->toBe([['id' => 7]]);

    Http::assertSent(fn (Request $request): bool => str_contains($request->url(), 'test=true')
        && ! str_contains($request->url(), 'from='));
});

it('normalizes a single lead object into a list', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(['lead' => ['id' => 42, 'requestType' => 'FINANCING_REQUEST']]),
    ]);

    $leads = app(ImmoScoutApiService::class)->fetchTestLeads($this->connection);

    expect($leads)->toBe([['id' => 42, 'requestType' => 'FINANCING_REQUEST']]);
});

it('returns an empty list when no leads are present', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(['lead' => []]),
    ]);

    expect(app(ImmoScoutApiService::class)->fetchTestLeads($this->connection))->toBe([]);
});

it('signs without an oauth token when the connection has none', function (): void {
    $twoLegged = ImmoScoutConnection::factory()->twoLegged()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
    ]);

    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response(['lead' => []]),
    ]);

    app(ImmoScoutApiService::class)->fetchTestLeads($twoLegged);

    Http::assertSent(fn (Request $request): bool => ! str_contains($request->header('Authorization')[0], 'oauth_token='));
});

it('throws an auth exception on 401 and 403 responses', function (int $status): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response([
            'message' => [['messageCode' => 'ERROR_COMMON_ACCESS_DENIED', 'message' => 'No authorization for this operation.']],
        ], $status),
    ]);

    app(ImmoScoutApiService::class)->fetchTestLeads($this->connection);
})->with([401, 403])->throws(ImmoScoutAuthException::class);

it('throws a transient exception on rate limits and server errors', function (int $status): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/*' => Http::response([], $status),
    ]);

    app(ImmoScoutApiService::class)->fetchTestLeads($this->connection);
})->with([429, 500, 503])->throws(ImmoScoutTransientException::class);

it('targets the production host for production connections', function (): void {
    $production = ImmoScoutConnection::factory()->production()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->getKey(),
    ]);

    Http::fake([
        'rest.immobilienscout24.de/*' => Http::response(['lead' => []]),
    ]);

    app(ImmoScoutApiService::class)->fetchTestLeads($production);

    Http::assertSent(fn (Request $request): bool => str_starts_with($request->url(), 'https://rest.immobilienscout24.de/restapi/api/financing/construction/v2/lead'));
});
