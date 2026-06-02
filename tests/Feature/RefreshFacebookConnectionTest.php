<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshed;
use JohnWink\FilamentLeadPipeline\Events\FacebookTokenRefreshFailed;
use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    $this->connection = FacebookConnection::factory()->expiringSoon()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);
});

function runRefresh(FacebookConnection $connection): void
{
    (new RefreshFacebookConnection($connection))->handle(
        app(FacebookGraphService::class),
        app(FacebookPageSynchronizer::class),
    );
}

it('refreshes the token and resets failure state on success', function (): void {
    Event::fake([FacebookTokenRefreshed::class]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'access_token' => 'fresh-token',
            'expires_in'   => 5_184_000,
        ]),
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
    ]);

    $this->connection->update(['refresh_attempts' => 2, 'refresh_failed_at' => now()->subDay()]);

    runRefresh($this->connection->fresh());

    $fresh = $this->connection->fresh();
    expect($fresh->access_token)->toBe('fresh-token')
        ->and($fresh->refresh_attempts)->toBe(0)
        ->and($fresh->refresh_failed_at)->toBeNull()
        ->and($fresh->last_refreshed_at)->not->toBeNull()
        ->and($fresh->status)->toBe(FacebookConnectionStatusEnum::Connected);

    Event::assertDispatched(FacebookTokenRefreshed::class);
});

it('records a transient failure without marking needs-reauth', function (): void {
    Event::fake([FacebookTokenRefreshFailed::class, FacebookConnectionNeedsReauth::class]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 4, 'message' => 'rate']], 429),
    ]);

    runRefresh($this->connection->fresh());

    $fresh = $this->connection->fresh();
    expect($fresh->status)->toBe(FacebookConnectionStatusEnum::Connected)
        ->and($fresh->refresh_attempts)->toBe(1)
        ->and($fresh->refresh_failed_at)->not->toBeNull();

    Event::assertDispatched(FacebookTokenRefreshFailed::class);
    Event::assertNotDispatched(FacebookConnectionNeedsReauth::class);
});

it('marks needs-reauth and errors lead sources on a terminal 190', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-x',
        'page_name'                => 'Page X',
        'page_access_token'        => 'pt',
    ]);
    $source = LeadSource::factory()->meta()->active()->create(['facebook_page_uuid' => $page->uuid]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response([
            'error' => ['code' => 190, 'message' => 'expired'],
        ], 400),
    ]);

    runRefresh($this->connection->fresh());

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth)
        ->and($source->fresh()->status)->toBe(LeadSourceStatusEnum::Error);

    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});

it('escalates to needs-reauth after max attempts when the token is already expired', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    config()->set('lead-pipeline.facebook.refresh.max_attempts', 5);
    $this->connection->update([
        'token_expires_at'  => now()->subDay(),
        'refresh_attempts'  => 4,
        'refresh_failed_at' => now()->subHours(10),
    ]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['error' => ['code' => 2, 'message' => 'temp']], 500),
    ]);

    runRefresh($this->connection->fresh());

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth);
    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});

it('skips while inside the backoff window', function (): void {
    Http::fake();

    $this->connection->update(['refresh_attempts' => 1, 'refresh_failed_at' => now()]);

    runRefresh($this->connection->fresh());

    Http::assertNothingSent();

    expect($this->connection->fresh()->refresh_attempts)->toBe(1)
        ->and($this->connection->fresh()->last_refreshed_at)->toBeNull();
});

it('treats a malformed refresh response as a transient failure', function (): void {
    Event::fake([FacebookTokenRefreshFailed::class]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['unexpected' => true]),
    ]);

    runRefresh($this->connection->fresh());

    $fresh = $this->connection->fresh();
    expect($fresh->status)->toBe(FacebookConnectionStatusEnum::Connected)
        ->and($fresh->refresh_attempts)->toBe(1)
        ->and($fresh->access_token)->not->toBe('');

    Event::assertDispatched(FacebookTokenRefreshFailed::class);
});

it('marks needs-reauth when page sync hits a dead token after a successful refresh', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class, FacebookTokenRefreshed::class]);

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'fresh', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['error' => ['code' => 190, 'message' => 'dead']], 400),
    ]);

    runRefresh($this->connection->fresh());

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth);
    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
    Event::assertNotDispatched(FacebookTokenRefreshed::class);
});
