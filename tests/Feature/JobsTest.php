<?php

declare(strict_types=1);

use App\Models\Team;
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
        ->with(Mockery::on(fn ($c): bool => $c->uuid === $this->connection->uuid))
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
    $synchronizer->shouldReceive('sync')->once()->andThrow(new RuntimeException('boom'));
    app()->instance(FacebookPageSynchronizer::class, $synchronizer);

    (new SyncFacebookPages())->handle(app(FacebookPageSynchronizer::class));

    expect(true)->toBeTrue();
});
