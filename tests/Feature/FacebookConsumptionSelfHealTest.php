<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionNeedsReauth;
use JohnWink\FilamentLeadPipeline\Jobs\SyncFacebookPages;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team       = Team::query()->firstWhere('slug', 'test');
    $this->user       = $this->team->users->first();
    $this->connection = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id, 'team_uuid' => $this->team->uuid,
    ]);
});

it('marks the connection needs-reauth when sync hits a dead token', function (): void {
    Event::fake([FacebookConnectionNeedsReauth::class]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['error' => ['code' => 190, 'message' => 'dead']], 400),
    ]);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect($this->connection->fresh()->status)->toBe(FacebookConnectionStatusEnum::NeedsReauth);
    Event::assertDispatched(FacebookConnectionNeedsReauth::class);
});
