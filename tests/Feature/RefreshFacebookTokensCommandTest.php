<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Exceptions;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookRefreshHealthCheckFailed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshFailed;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use Mockery\MockInterface;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    config()->set('lead-pipeline.facebook.client_id', 'app-123');
    config()->set('lead-pipeline.facebook.client_secret', 'secret-xyz');
});

it('refreshes due connections and skips far-future ones', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'new', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
    ]);

    $soon = FacebookConnection::factory()->create([
        'user_uuid'        => $this->user->id, 'team_uuid' => $this->team->uuid,
        'token_expires_at' => now()->addDays(3),
    ]);
    $far = FacebookConnection::factory()->create([
        'user_uuid'        => $this->user->id, 'team_uuid' => $this->team->uuid,
        'token_expires_at' => now()->addDays(40), 'access_token' => 'far',
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();

    expect($soon->fresh()->access_token)->toBe('new')
        ->and($far->fresh()->access_token)->toBe('far');
});

it('fires the expiring-soon event once per window', function (): void {
    Event::fake([FacebookTokenExpiringSoon::class]);
    // Refresh fails transiently so the token stays in the warning window across runs.
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 4, 'message' => 'rate']], 429),
    ]);

    $conn = FacebookConnection::factory()->create([
        'user_uuid'        => $this->user->id, 'team_uuid' => $this->team->uuid,
        'token_expires_at' => now()->addDays(5),
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();
    expect($conn->fresh()->expiring_soon_notified_at)->not->toBeNull();
    Event::assertDispatchedTimes(FacebookTokenExpiringSoon::class, 1);

    // Second run must not re-fire (still in window, flag already set).
    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();
    Event::assertDispatchedTimes(FacebookTokenExpiringSoon::class, 1);
});

it('emits a health-check-failed event when a connected token is long past expiry', function (): void {
    Event::fake([FacebookRefreshHealthCheckFailed::class]);
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 190, 'message' => 'dead']], 400),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
    ]);

    // Connected but expired 10h ago and stuck (no recent refresh) → health alarm.
    FacebookConnection::factory()->create([
        'user_uuid'         => $this->user->id, 'team_uuid' => $this->team->uuid,
        'status'            => FacebookConnectionStatusEnum::Connected,
        'token_expires_at'  => now()->subHours(10),
        'last_refreshed_at' => now()->subDays(5),
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();

    Event::assertDispatched(FacebookRefreshHealthCheckFailed::class);
});

it('keeps refreshing the remaining connections when one throws unexpectedly and reports it', function (): void {
    Exceptions::fake();

    $connections = FacebookConnection::factory()->count(2)->expiringSoon()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);

    $this->mock(FacebookGraphService::class, function (MockInterface $mock): void {
        $mock->shouldReceive('refreshLongLivedToken')
            ->twice()
            ->andReturnUsing(
                fn (): array => throw new ConnectionException('cURL error 28: Operation timed out'),
                fn (): array => ['access_token' => 'new', 'expires_in' => 5_184_000],
            );
        $mock->shouldReceive('getUserPages')->andReturn([]);
    });

    $this->artisan('lead-pipeline:facebook:refresh-tokens')
        ->expectsOutputToContain(ConnectionException::class)
        ->doesntExpectOutputToContain('cURL error 28')
        ->assertFailed();

    $tokens = $connections->map(fn (FacebookConnection $connection): string => $connection->fresh()->access_token);

    expect($tokens->filter(fn (string $token): bool => 'new' === $token))->toHaveCount(1);

    Exceptions::assertReported(ConnectionException::class);
});

it('succeeds when a meta rejection is captured on the connection', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'error' => ['message' => 'Missing client_id parameter.', 'code' => 100],
        ], 400),
    ]);

    $connection = FacebookConnection::factory()->expiringSoon()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();

    $fresh = $connection->fresh();
    expect($fresh->refresh_failed_at)->not->toBeNull()
        ->and($fresh->last_error)->toContain('Missing client_id parameter.');
});

it('touches no connection and fails with an actionable message when app credentials are missing', function (string $configKey, string $envName): void {
    Event::fake([FacebookTokenExpiringSoon::class, FacebookTokenRefreshFailed::class, FacebookConnectionNeedsReauth::class]);
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'error' => ['message' => 'Missing client_id parameter.', 'code' => 100],
        ], 400),
    ]);

    config()->set($configKey, null);

    $connection = FacebookConnection::factory()->expiringSoon()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);
    $before = $connection->fresh()->getAttributes();

    $this->artisan('lead-pipeline:facebook:refresh-tokens')
        ->expectsOutputToContain("{$configKey} is not configured (set {$envName}")
        ->assertFailed();

    Http::assertNothingSent();
    expect($connection->fresh()->getAttributes())->toBe($before);

    Event::assertNotDispatched(FacebookTokenExpiringSoon::class);
    Event::assertNotDispatched(FacebookTokenRefreshFailed::class);
    Event::assertNotDispatched(FacebookConnectionNeedsReauth::class);
})->with([
    'client id'     => ['lead-pipeline.facebook.client_id', 'FACEBOOK_CLIENT_ID'],
    'client secret' => ['lead-pipeline.facebook.client_secret', 'FACEBOOK_CLIENT_SECRET'],
]);

it('stays successful without app credentials when no connection is due', function (): void {
    Http::fake();

    config()->set('lead-pipeline.facebook.client_id', null);
    config()->set('lead-pipeline.facebook.client_secret', null);

    FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')->assertSuccessful();

    Http::assertNothingSent();
});

it('reports an unreachable token refresh without the app secret or the user token', function (): void {
    Exceptions::fake();

    Http::fake(['graph.facebook.com/*/oauth/access_token*' => Http::failedConnection()]);

    $connection = FacebookConnection::factory()->expiringSoon()->create([
        'user_uuid'    => $this->user->id,
        'team_uuid'    => $this->team->uuid,
        'access_token' => 'user-token-refresh-network',
    ]);

    $this->artisan('lead-pipeline:facebook:refresh-tokens')
        ->expectsOutputToContain(ConnectionException::class)
        ->doesntExpectOutputToContain('secret-xyz')
        ->doesntExpectOutputToContain('user-token-refresh-network')
        ->assertFailed();

    Exceptions::assertReported(fn (ConnectionException $exception): bool => ! str_contains($exception->getMessage(), 'secret-xyz')
        && ! str_contains($exception->getMessage(), 'user-token-refresh-network')
        && str_contains($exception->getMessage(), 'client_secret=[REDACTED]'));

    expect($connection->fresh()->access_token)->toBe('user-token-refresh-network');
});
