<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Jobs\SyncFacebookPages;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();

    $this->connection = FacebookConnection::factory()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);
});

it('syncs pages for all connected Facebook connections', function (): void {
    $synchronizer = Mockery::mock(FacebookPageSynchronizer::class);
    $synchronizer->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn ($connection): bool => $connection->uuid === $this->connection->uuid))
        ->andReturn(['added' => 1, 'updated' => 0, 'removed' => 0, 'forms_synced' => 0]);
    app()->instance(FacebookPageSynchronizer::class, $synchronizer);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect(true)->toBeTrue();
});

it('skips needs-reauth connections and continues despite sync failures', function (): void {
    FacebookConnection::factory()->needsReauth()->create([
        'user_uuid' => $this->user->id,
        'team_uuid' => $this->team->uuid,
    ]);

    $synchronizer = Mockery::mock(FacebookPageSynchronizer::class);
    $synchronizer->shouldReceive('sync')
        ->once()
        ->with(Mockery::on(fn ($connection): bool => $connection->uuid === $this->connection->uuid))
        ->andThrow(new RuntimeException('boom'));
    app()->instance(FacebookPageSynchronizer::class, $synchronizer);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect(true)->toBeTrue();
});

it('logs a network failure during the page sync with its class and connection but without the access token', function (): void {
    $accessToken = $this->connection->access_token;

    Http::fake(['graph.facebook.com/*/me/accounts*' => Http::failedConnection()]);

    $logged = collect();
    Event::listen(MessageLogged::class, fn (MessageLogged $entry) => $logged->push($entry));

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect($logged->map(fn (MessageLogged $logEntry): string => $logEntry->message . json_encode($logEntry->context))->implode("\n"))
        ->not->toContain($accessToken);

    $entry = $logged->firstWhere('message', 'SyncFacebookPages: sync failed');

    expect($entry)->not->toBeNull()
        ->and($entry->level)->toBe('warning')
        ->and($entry->context)->toMatchArray([
            'connection'      => $this->connection->uuid,
            'exception_class' => ConnectionException::class,
        ])
        ->and($entry->context['error'])->toContain('access_token=[REDACTED]');
});
