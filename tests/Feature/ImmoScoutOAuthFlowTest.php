<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService;
use JohnWink\FilamentLeadPipeline\Support\ImmoScoutOAuthSigner;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    config([
        'lead-pipeline.immoscout.consumer_key'    => 'test-consumer-key',
        'lead-pipeline.immoscout.consumer_secret' => 'test-consumer-secret',
        'lead-pipeline.immoscout.environment'     => 'sandbox',
    ]);
});

it('signs extra oauth parameters like the callback into the signature', function (): void {
    $header = (new ImmoScoutOAuthSigner())->authorizationHeader(
        method: 'POST',
        url: 'https://rest.sandbox-immobilienscout24.de/restapi/security/oauth/request_token',
        queryParams: [],
        consumerKey: 'test-consumer-key',
        consumerSecret: 'test-consumer-secret',
        nonce: 'fixed-nonce-123',
        timestamp: 1783100000,
        extraOauth: ['oauth_callback' => 'https://app.test/lead-pipeline/immoscout/callback'],
    );

    expect($header)
        ->toContain('oauth_callback="' . rawurlencode('https://app.test/lead-pipeline/immoscout/callback') . '"')
        ->toContain('oauth_signature="' . rawurlencode('n/2jRc5tB1KApqBWH0In145pS2w=') . '"');
});

it('fetches a request token with the callback url', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/restapi/security/oauth/request_token' => Http::response(
            'oauth_token=rt-1&oauth_token_secret=rts-1&oauth_callback_confirmed=true',
        ),
    ]);

    $result = app(ImmoScoutApiService::class)->fetchRequestToken(
        ImmoScoutEnvironmentEnum::Sandbox,
        'https://app.test/callback',
    );

    expect($result)->toBe(['token' => 'rt-1', 'secret' => 'rts-1']);

    Http::assertSent(fn (Request $request): bool => 'POST' === $request->method()
        && str_contains($request->header('Authorization')[0], 'oauth_callback=')
        && ! str_contains($request->header('Authorization')[0], 'oauth_token='));
});

it('exchanges a request token and verifier for an access token', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/restapi/security/oauth/access_token' => Http::response(
            'oauth_token=at-1&oauth_token_secret=ats-1',
        ),
    ]);

    $result = app(ImmoScoutApiService::class)->exchangeAccessToken(
        ImmoScoutEnvironmentEnum::Sandbox,
        'rt-1',
        'rts-1',
        'verifier-1',
    );

    expect($result)->toBe(['token' => 'at-1', 'secret' => 'ats-1']);

    Http::assertSent(fn (Request $request): bool => str_contains($request->header('Authorization')[0], 'oauth_verifier="verifier-1"')
        && str_contains($request->header('Authorization')[0], 'oauth_token="rt-1"'));
});

it('builds the confirm access url for the environment', function (): void {
    expect(app(ImmoScoutApiService::class)->confirmAccessUrl(ImmoScoutEnvironmentEnum::Sandbox, 'rt-1'))
        ->toBe('https://rest.sandbox-immobilienscout24.de/restapi/security/oauth/confirm_access?oauth_token=rt-1');
});

it('redirects to the immoscout confirm page and caches the flow context', function (): void {
    Http::fake([
        'rest.sandbox-immobilienscout24.de/restapi/security/oauth/request_token' => Http::response(
            'oauth_token=rt-9&oauth_token_secret=rts-9&oauth_callback_confirmed=true',
        ),
    ]);

    $response = $this->actingAs($this->user)
        ->get(route('lead-pipeline.immoscout.redirect', ['team' => $this->team->uuid]));

    $response->assertRedirect('https://rest.sandbox-immobilienscout24.de/restapi/security/oauth/confirm_access?oauth_token=rt-9');

    $context = Cache::get('lead-pipeline:immoscout-oauth:rt-9');

    expect($context['team'])->toBe($this->team->uuid)
        ->and($context['user'])->toBe($this->user->getKey())
        ->and($context['secret'])->toBe('rts-9')
        ->and($context['environment'])->toBe('sandbox');
});

it('rejects the redirect when app credentials are missing', function (): void {
    config(['lead-pipeline.immoscout.consumer_key' => null]);

    $this->actingAs($this->user)
        ->get(route('lead-pipeline.immoscout.redirect', ['team' => $this->team->uuid]))
        ->assertStatus(422);
});

it('creates a connected team connection on callback', function (): void {
    Cache::put('lead-pipeline:immoscout-oauth:rt-9', [
        'team'        => $this->team->uuid,
        'user'        => $this->user->getKey(),
        'secret'      => 'rts-9',
        'environment' => 'sandbox',
    ], 600);

    Http::fake([
        'rest.sandbox-immobilienscout24.de/restapi/security/oauth/access_token' => Http::response(
            'oauth_token=at-9&oauth_token_secret=ats-9',
        ),
    ]);

    $response = $this->actingAs($this->user)->get(route('lead-pipeline.immoscout.callback', [
        'oauth_token'    => 'rt-9',
        'oauth_verifier' => 'v-9',
    ]));

    $response->assertOk();
    expect($response->getContent())->toContain('immoscout-connected');

    $connection = ImmoScoutConnection::query()->firstWhere('team_uuid', $this->team->uuid);

    expect($connection)->not->toBeNull()
        ->and($connection->user_uuid)->toBe($this->user->getKey())
        ->and($connection->access_token)->toBe('at-9')
        ->and($connection->access_token_secret)->toBe('ats-9')
        ->and($connection->consumer_key)->toBe('test-consumer-key')
        ->and($connection->consumer_secret)->toBe('test-consumer-secret')
        ->and($connection->environment)->toBe(ImmoScoutEnvironmentEnum::Sandbox)
        ->and($connection->status)->toBe(ImmoScoutConnectionStatusEnum::Connected)
        ->and(Cache::has('lead-pipeline:immoscout-oauth:rt-9'))->toBeFalse();
});

it('reuses the existing connection on reconnect instead of duplicating', function (): void {
    $existing = ImmoScoutConnection::factory()->create([
        'team_uuid'    => $this->team->uuid,
        'user_uuid'    => $this->user->getKey(),
        'environment'  => ImmoScoutEnvironmentEnum::Sandbox,
        'status'       => ImmoScoutConnectionStatusEnum::Error,
        'last_error'   => 'expired',
        'access_token' => 'old-token',
    ]);

    Cache::put('lead-pipeline:immoscout-oauth:rt-9', [
        'team'        => $this->team->uuid,
        'user'        => $this->user->getKey(),
        'secret'      => 'rts-9',
        'environment' => 'sandbox',
    ], 600);

    Http::fake([
        'rest.sandbox-immobilienscout24.de/restapi/security/oauth/access_token' => Http::response(
            'oauth_token=at-new&oauth_token_secret=ats-new',
        ),
    ]);

    $this->actingAs($this->user)->get(route('lead-pipeline.immoscout.callback', [
        'oauth_token'    => 'rt-9',
        'oauth_verifier' => 'v-9',
    ]))->assertOk();

    expect(ImmoScoutConnection::query()->count())->toBe(1)
        ->and($existing->fresh()->access_token)->toBe('at-new')
        ->and($existing->fresh()->status)->toBe(ImmoScoutConnectionStatusEnum::Connected)
        ->and($existing->fresh()->last_error)->toBeNull();
});

it('rejects a callback without a cached flow context', function (): void {
    $this->actingAs($this->user)->get(route('lead-pipeline.immoscout.callback', [
        'oauth_token'    => 'unknown',
        'oauth_verifier' => 'v-9',
    ]))->assertStatus(403);
});

it('shows the connect button in the driver schema when app credentials exist', function (): void {
    $components = app(JohnWink\FilamentLeadPipeline\Drivers\ImmoScoutDriver::class)->getConfigFormSchema();

    $names = collect($components)->map(fn ($component): string => $component->getName());

    expect($names)->toContain('immoscout_connect');
});
