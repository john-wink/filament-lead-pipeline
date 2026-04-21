<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookTokens;
use JohnWink\FilamentLeadPipeline\Jobs\SyncFacebookPages;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Notifications\FacebookConnectionExpired;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    $this->connection = FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-user-1',
        'facebook_user_name' => 'Test User',
        'access_token'       => 'valid-token',
        'scopes'             => ['pages_show_list'],
        'status'             => 'connected',
        'token_expires_at'   => now()->addDays(30),
    ]);
});

/* =======================
 * SyncFacebookPages
 * ======================= */

it('syncs pages for all connected Facebook connections', function (): void {
    $synchronizer = Mockery::mock(FacebookPageSynchronizer::class);
    $synchronizer->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn ($connection): bool => $connection->uuid === $this->connection->uuid))
        ->andReturn(['added' => 1, 'updated' => 0, 'removed' => 0, 'forms_synced' => 0]);
    app()->instance(FacebookPageSynchronizer::class, $synchronizer);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    // Mockery assertion is verified at tearDown automatically.
    expect(true)->toBeTrue();
});

it('skips expired Facebook connections and continues despite sync failures', function (): void {
    FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-user-broken',
        'facebook_user_name' => 'Broken User',
        'access_token'       => 'broken-token',
        'scopes'             => ['pages_show_list'],
        'status'             => 'expired',
        'token_expires_at'   => now()->subDay(),
    ]);

    $synchronizer = Mockery::mock(FacebookPageSynchronizer::class);
    $synchronizer->shouldReceive('sync')
        ->once()
        ->andThrow(new RuntimeException('boom'));
    app()->instance(FacebookPageSynchronizer::class, $synchronizer);

    // Job must not bubble exceptions — catches per-connection failures.
    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect(true)->toBeTrue();
});

/* =======================
 * RefreshFacebookTokens
 * ======================= */

it('refreshes tokens only for connections expiring within 7 days', function (): void {
    FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-user-far',
        'facebook_user_name' => 'Far User',
        'access_token'       => 'far-token',
        'scopes'             => ['pages_show_list'],
        'status'             => 'connected',
        'token_expires_at'   => now()->addDays(30),
    ]);
    $expiringSoon = FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-user-soon',
        'facebook_user_name' => 'Soon User',
        'access_token'       => 'soon-token',
        'scopes'             => ['pages_show_list'],
        'status'             => 'connected',
        'token_expires_at'   => now()->addDays(3),
    ]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'access_token' => 'refreshed-token',
            'expires_in'   => 5_184_000,
        ]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
    ]);

    (new RefreshFacebookTokens())->handle(
        app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class),
        app(FacebookPageSynchronizer::class),
    );

    expect($expiringSoon->fresh()->access_token)->toBe('refreshed-token')
        ->and(FacebookConnection::query()
            ->where('facebook_user_id', 'fb-user-far')
            ->first()->access_token)->toBe('far-token');
});

it('marks a connection expired and notifies the user when refresh fails', function (): void {
    Notification::fake();

    $lead   = LeadSource::factory()->meta()->active()->create();
    $source = LeadSource::factory()->meta()->active()->create();
    $page   = FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-1',
        'page_name'                => 'Page One',
        'page_access_token'        => 'page-token',
    ]);
    $source->update(['facebook_page_uuid' => $page->uuid]);

    $this->connection->update(['token_expires_at' => now()->addDays(3)]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['message' => 'Invalid']], 400),
    ]);

    (new RefreshFacebookTokens())->handle(
        app(JohnWink\FilamentLeadPipeline\Services\FacebookGraphService::class),
        app(FacebookPageSynchronizer::class),
    );

    expect($this->connection->fresh()->status)->toBe('expired')
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Error);

    Notification::assertSentTo($this->connection->user, FacebookConnectionExpired::class);
});
