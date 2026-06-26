<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionReconnected;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);

    config()->set('lead-pipeline.facebook.client_id', 'test-client-id');
    config()->set('lead-pipeline.facebook.client_secret', 'test-client-secret');
    config()->set('lead-pipeline.facebook.scopes', ['pages_show_list']);
    config()->set('app.url', 'https://finance-estate.test');
});

it('falls back to the users first team when state carries a null team', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'access_token' => 'long-lived-token',
            'token_type'   => 'bearer',
            'expires_in'   => 5_184_000,
        ]),
        'graph.facebook.com/*/me*' => Http::response([
            'id'   => 'fb-user-42',
            'name' => 'Test User',
        ]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
    ]);

    $nonce = 'fallback-nonce-value';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => null]));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertOk();

    expect(FacebookConnection::query()->where('facebook_user_id', 'fb-user-42')->first())
        ->not->toBeNull()
        ->team_uuid->toBe($this->team->uuid);
});

it('rejects the callback with 422 when no team can be resolved', function (): void {
    $this->team->users()->detach($this->user->id);

    $nonce = 'no-team-nonce-value';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => null]));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertStatus(422);

    expect(FacebookConnection::query()->count())->toBe(0);
});

it('restores an expired connection and fires the reconnected event', function (): void {
    Event::fake([FacebookConnectionReconnected::class]);

    $existing = FacebookConnection::factory()->needsReauth()->create([
        'user_uuid'        => $this->user->id,
        'team_uuid'        => $this->team->uuid,
        'facebook_user_id' => 'fb-reconnect',
    ]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'restored', 'token_type' => 'bearer', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
        'graph.facebook.com/*/me*'                 => Http::response(['id' => 'fb-reconnect', 'name' => 'Reconnected User']),
    ]);

    $nonce = 'reconnect-nonce';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => $this->team->uuid]));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertOk();

    $fresh = $existing->fresh();
    expect($fresh->status)->toBe(FacebookConnectionStatusEnum::Connected)
        ->and($fresh->access_token)->toBe('restored')
        ->and($fresh->acquired_at)->not->toBeNull()
        ->and($fresh->refresh_attempts)->toBe(0);

    Event::assertDispatched(FacebookConnectionReconnected::class);
});

it('targets the opener origin from the OAuth state for postMessage', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'll', 'token_type' => 'bearer', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
        'graph.facebook.com/*/me*'                 => Http::response(['id' => 'fb-origin-1', 'name' => 'Origin User']),
    ]);

    $nonce = 'origin-nonce-1';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => $this->team->uuid, 'origin' => 'https://makler.finance-estate.test']));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertOk()
        ->assertSee('var targetOrigin = "https://makler.finance-estate.test"', false);
});

it('falls back to app.url when the state carries no origin', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'll', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
        'graph.facebook.com/*/me*'                 => Http::response(['id' => 'fb-origin-2', 'name' => 'No Origin']),
    ]);

    $nonce = 'origin-nonce-2';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => $this->team->uuid]));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertOk()
        ->assertSee('var targetOrigin = "https://finance-estate.test"', false);
});

it('rejects an untrusted opener origin and falls back to app.url', function (): void {
    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'll', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => []]),
        'graph.facebook.com/*/me*'                 => Http::response(['id' => 'fb-origin-3', 'name' => 'Evil']),
    ]);

    $nonce = 'origin-nonce-3';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => $this->team->uuid, 'origin' => 'https://evil.example.com']));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertOk()
        ->assertSee('var targetOrigin = "https://finance-estate.test"', false);
});

it('subscribes the app to leadgen at the app level after connect when it is missing', function (): void {
    config()->set('lead-pipeline.facebook.verify_token', 'verify-secret');
    config()->set('lead-pipeline.public_url', 'https://funnel.finance-estate.test');
    config()->set('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks');

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*'           => Http::response(['access_token' => 'll', 'token_type' => 'bearer', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'                  => Http::response(['data' => []]),
        'graph.facebook.com/*/me*'                           => Http::response(['id' => 'fb-app-sub-1', 'name' => 'App Sub User']),
        'graph.facebook.com/*/test-client-id/subscriptions*' => function ($request) {
            if ('POST' === $request->method()) {
                return Http::response(['success' => true]);
            }

            return Http::response(['data' => []]);
        },
    ]);

    $nonce = 'app-sub-nonce-1';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => $this->team->uuid]));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertOk();

    Http::assertSent(fn ($request) => 'POST' === $request->method()
        && str_contains($request->url(), '/test-client-id/subscriptions')
        && 'page' === $request['object']
        && 'leadgen' === $request['fields']
        && 'https://funnel.finance-estate.test/api/lead-pipeline/webhooks/meta' === $request['callback_url']
        && 'verify-secret' === $request['verify_token']);
});

it('does not re-subscribe at the app level when it is already active', function (): void {
    config()->set('lead-pipeline.facebook.verify_token', 'verify-secret');
    config()->set('lead-pipeline.public_url', 'https://funnel.finance-estate.test');

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*'           => Http::response(['access_token' => 'll', 'token_type' => 'bearer', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'                  => Http::response(['data' => []]),
        'graph.facebook.com/*/me*'                           => Http::response(['id' => 'fb-app-sub-2', 'name' => 'Already Active']),
        'graph.facebook.com/*/test-client-id/subscriptions*' => function ($request) {
            if ('POST' === $request->method()) {
                return Http::response(['success' => true]);
            }

            return Http::response(['data' => [
                ['object' => 'page', 'active' => true, 'fields' => [['name' => 'leadgen', 'version' => 'v25.0']]],
            ]]);
        },
    ]);

    $nonce = 'app-sub-nonce-2';
    $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => $this->team->uuid]));

    $this->withSession(['facebook_oauth_nonce' => $nonce])
        ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
        ->assertOk();

    Http::assertNotSent(fn ($request) => 'POST' === $request->method()
        && str_contains($request->url(), '/test-client-id/subscriptions'));
});
