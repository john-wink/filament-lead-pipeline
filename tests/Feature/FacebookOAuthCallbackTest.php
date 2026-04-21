<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);

    config()->set('lead-pipeline.facebook.client_id', 'test-client-id');
    config()->set('lead-pipeline.facebook.client_secret', 'test-client-secret');
    config()->set('lead-pipeline.facebook.scopes', ['pages_show_list']);
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
