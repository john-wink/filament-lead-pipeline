<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookRefreshHealthCheckFailed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenExpiringSoon;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
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
