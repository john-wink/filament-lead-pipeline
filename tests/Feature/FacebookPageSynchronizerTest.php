<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team       = Team::query()->firstWhere('slug', 'test');
    $this->user       = $this->team->users->first();
    $this->connection = FacebookConnection::query()->create([
        'user_uuid'          => $this->user->id,
        'team_uuid'          => $this->team->uuid,
        'facebook_user_id'   => 'fb-user-1',
        'facebook_user_name' => 'Test User',
        'access_token'       => 'test-token',
        'scopes'             => ['pages_show_list'],
        'status'             => 'connected',
    ]);
});

/**
 * Facebook page fixture with the tasks that make the page usable
 * (MANAGE for webhook subscription + ADVERTISE for leads_retrieval).
 */
function usablePage(string $id, string $name, string $token): array
{
    return [
        'id'           => $id,
        'name'         => $name,
        'access_token' => $token,
        'tasks'        => ['MANAGE', 'ADVERTISE'],
    ];
}

it('creates new pages that do not exist yet', function (): void {
    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            usablePage('page-1', 'Page One', 'token-1'),
            usablePage('page-2', 'Page Two', 'token-2'),
        ]]),
    ]);

    $summary = app(FacebookPageSynchronizer::class)->sync($this->connection);

    expect($summary)->toMatchArray(['added' => 2, 'updated' => 0, 'removed' => 0])
        ->and(FacebookPage::query()->where('facebook_connection_uuid', $this->connection->uuid)->count())->toBe(2);
});

it('updates page names and tokens for already known pages', function (): void {
    FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-1',
        'page_name'                => 'Old Name',
        'page_access_token'        => 'old-token',
    ]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            usablePage('page-1', 'New Name', 'new-token'),
        ]]),
    ]);

    $summary = app(FacebookPageSynchronizer::class)->sync($this->connection);

    expect($summary)->toMatchArray(['added' => 0, 'updated' => 1, 'removed' => 0])
        ->and(FacebookPage::query()->where('page_id', 'page-1')->first())
        ->page_name->toBe('New Name');
});

it('soft-deletes pages that are no longer returned by facebook', function (): void {
    FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-removed',
        'page_name'                => 'Gone Page',
        'page_access_token'        => 'token-gone',
    ]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => []]),
    ]);

    $summary = app(FacebookPageSynchronizer::class)->sync($this->connection);

    expect($summary)->toMatchArray(['added' => 0, 'updated' => 0, 'removed' => 1])
        ->and(FacebookPage::query()->where('page_id', 'page-removed')->exists())->toBeFalse()
        ->and(FacebookPage::withTrashed()->where('page_id', 'page-removed')->first())->not->toBeNull();
});

it('restores a previously soft-deleted page when it reappears', function (): void {
    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-resurrected',
        'page_name'                => 'Old Name',
        'page_access_token'        => 'old-token',
    ]);
    $page->delete();

    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            usablePage('page-resurrected', 'Resurrected', 'fresh-token'),
        ]]),
    ]);

    $summary = app(FacebookPageSynchronizer::class)->sync($this->connection);

    expect($summary)->toMatchArray(['added' => 0, 'updated' => 1, 'removed' => 0])
        ->and(FacebookPage::query()->where('page_id', 'page-resurrected')->first())
        ->not->toBeNull()
        ->page_name->toBe('Resurrected');
});

it('ignores pages without the required tasks', function (): void {
    Http::fake([
        'graph.facebook.com/*/me/accounts*' => Http::response(['data' => [
            // Missing MANAGE → cannot subscribe leadgen webhook.
            ['id' => 'page-no-manage', 'name' => 'No Manage', 'access_token' => 't1', 'tasks' => ['ADVERTISE']],
            // Missing ADVERTISE/MANAGE_LEADS → cannot read leads.
            ['id' => 'page-no-leads', 'name' => 'No Leads', 'access_token' => 't2', 'tasks' => ['MANAGE', 'CREATE_CONTENT']],
            // Page fulfils both requirements via MANAGE_LEADS instead of ADVERTISE.
            ['id' => 'page-ok', 'name' => 'All Good', 'access_token' => 't3', 'tasks' => ['MANAGE', 'MANAGE_LEADS']],
        ]]),
    ]);

    $summary = app(FacebookPageSynchronizer::class)->sync($this->connection);

    expect($summary)->toMatchArray(['added' => 1, 'updated' => 0, 'removed' => 0])
        ->and(FacebookPage::query()->pluck('page_id')->all())->toBe(['page-ok']);
});
