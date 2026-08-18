<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;

beforeEach(function (): void {
    $this->team       = Team::query()->firstWhere('slug', 'test');
    $this->user       = $this->team->users->first();
    $this->connection = FacebookConnection::factory()->create([
        'team_uuid' => $this->team->uuid,
        'user_uuid' => $this->user->id,
    ]);
});

/** Page-Fixture mit den Tasks, die die Pipeline voraussetzt. */
function selfHealPage(string $id, string $name): array
{
    return [
        'id'           => $id,
        'name'         => $name,
        'access_token' => 'page-token',
        'tasks'        => ['MANAGE', 'ADVERTISE'],
    ];
}

it('stamps the external facebook page id when a lead source is linked to a page', function (): void {
    $page = FacebookPage::factory()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-42',
    ]);

    $source = LeadSource::factory()->create(['facebook_page_uuid' => $page->uuid]);

    expect($source->refresh()->facebook_page_id)->toBe('page-42');
});

it('clears the external page id when the page link is removed deliberately', function (): void {
    $page = FacebookPage::factory()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-42',
    ]);

    $source = LeadSource::factory()->create(['facebook_page_uuid' => $page->uuid]);
    $source->update(['facebook_page_uuid' => null]);

    expect($source->refresh()->facebook_page_id)->toBeNull();
});

it('relinks an orphaned lead source of the same team when the page reappears', function (): void {
    $board  = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $source = LeadSource::factory()->create([
        'lead_board_uuid'    => $board->uuid,
        'facebook_page_uuid' => null,
        'facebook_page_id'   => 'page-1',
    ]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*'   => Http::response(['data' => [selfHealPage('page-1', 'Page One')]]),
        'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => []]),
    ]);

    app(FacebookPageSynchronizer::class)->sync($this->connection);

    $newPage = FacebookPage::query()
        ->where('facebook_connection_uuid', $this->connection->uuid)
        ->where('page_id', 'page-1')
        ->firstOrFail();

    expect($source->refresh()->facebook_page_uuid)->toBe($newPage->uuid);
});

it('does not relink lead sources of foreign teams', function (): void {
    $foreignTeam  = Team::factory()->create();
    $foreignBoard = LeadBoard::factory()->create(['team_uuid' => $foreignTeam->uuid]);
    $source       = LeadSource::factory()->create([
        'lead_board_uuid'    => $foreignBoard->uuid,
        'facebook_page_uuid' => null,
        'facebook_page_id'   => 'page-1',
    ]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*'   => Http::response(['data' => [selfHealPage('page-1', 'Page One')]]),
        'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => []]),
    ]);

    app(FacebookPageSynchronizer::class)->sync($this->connection);

    expect($source->refresh()->facebook_page_uuid)->toBeNull();
});

it('does not steal the link of a lead source that already points to a page', function (): void {
    $existingPage = FacebookPage::factory()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-1',
    ]);

    $board  = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $source = LeadSource::factory()->create([
        'lead_board_uuid'    => $board->uuid,
        'facebook_page_uuid' => $existingPage->uuid,
    ]);

    Http::fake([
        'graph.facebook.com/*/me/accounts*'   => Http::response(['data' => [selfHealPage('page-1', 'Page One')]]),
        'graph.facebook.com/*/leadgen_forms*' => Http::response(['data' => []]),
    ]);

    app(FacebookPageSynchronizer::class)->sync($this->connection);

    expect($source->refresh()->facebook_page_uuid)->toBe($existingPage->uuid);
});

it('backfills the external page id for already linked lead sources', function (): void {
    $page = FacebookPage::factory()->create([
        'facebook_connection_uuid' => $this->connection->uuid,
        'page_id'                  => 'page-77',
    ]);

    $source = LeadSource::factory()->create(['facebook_page_uuid' => $page->uuid]);

    DB::table('lead_sources')->where('uuid', $source->uuid)->update(['facebook_page_id' => null]);

    $migration = require dirname(__DIR__, 2) . '/database/migrations/0038_add_facebook_page_id_to_lead_sources_table.php';
    $migration->up();

    expect($source->refresh()->facebook_page_id)->toBe('page-77');
});
