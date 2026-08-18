<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Livewire\FacebookConnectionStatus;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookForm;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

function disconnectableConnection(array $attributes = []): FacebookConnection
{
    $team = Team::query()->firstWhere('slug', 'test');

    return FacebookConnection::factory()->create([
        'team_uuid'    => $team->uuid,
        'user_uuid'    => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id,
        'access_token' => 'user-token-123',
        'status'       => FacebookConnectionStatusEnum::Connected,
        ...$attributes,
    ]);
}

it('deletes the connection with its pages and forms but keeps the lead source healable', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $connection = disconnectableConnection();
    $page       = FacebookPage::factory()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-1',
    ]);
    $form = FacebookForm::factory()->create(['facebook_page_uuid' => $page->uuid]);

    $board  = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $source = LeadSource::factory()->create([
        'lead_board_uuid'    => $board->uuid,
        'facebook_page_uuid' => $page->uuid,
    ]);

    Livewire::test(FacebookConnectionStatus::class)
        ->call('disconnect', $connection->uuid)
        ->assertNotified();

    expect(FacebookConnection::query()->find($connection->uuid))->toBeNull()
        ->and(FacebookPage::withTrashed()->find($page->uuid))->toBeNull()
        ->and(FacebookForm::query()->find($form->uuid))->toBeNull();

    $source->refresh();

    expect($source->facebook_page_uuid)->toBeNull()
        ->and($source->facebook_page_id)->toBe('page-1');
});

it('revokes the app authorization at Meta so a different account can be chosen', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);

    $connection = disconnectableConnection();

    Livewire::test(FacebookConnectionStatus::class)
        ->call('disconnect', $connection->uuid);

    Http::assertSent(fn ($request): bool => 'DELETE' === $request->method()
        && str_contains($request->url(), '/me/permissions')
        && str_contains($request->url(), 'user-token-123'));
});

it('deletes the connection even when the token revocation at Meta fails', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'token expired']], 400)]);

    $connection = disconnectableConnection();

    Livewire::test(FacebookConnectionStatus::class)
        ->call('disconnect', $connection->uuid)
        ->assertNotified();

    expect(FacebookConnection::query()->find($connection->uuid))->toBeNull();
});

it('does not disconnect connections of foreign teams', function (): void {
    Http::fake();

    $foreignTeam = Team::factory()->create();
    $connection  = disconnectableConnection(['team_uuid' => $foreignTeam->uuid]);

    Livewire::test(FacebookConnectionStatus::class)
        ->call('disconnect', $connection->uuid);

    expect(FacebookConnection::query()->find($connection->uuid))->not->toBeNull();
    Http::assertNothingSent();
});

it('shows a disconnect button for each connection', function (): void {
    disconnectableConnection();

    Livewire::test(FacebookConnectionStatus::class)
        ->assertSee(__('lead-pipeline::lead-pipeline.connection_status.disconnect'));
});

it('survives a full disconnect and same-account reconnect cycle with self-healing mappings', function (): void {
    config()->set('lead-pipeline.facebook.client_id', 'test-client-id');
    config()->set('lead-pipeline.facebook.client_secret', 'test-client-secret');
    config()->set('lead-pipeline.facebook.scopes', ['pages_show_list']);
    config()->set('app.url', 'https://finance-estate.test');

    Http::fake([
        'graph.facebook.com/*/oauth/access_token*' => Http::response(['access_token' => 'll-token', 'token_type' => 'bearer', 'expires_in' => 5_184_000]),
        'graph.facebook.com/*/me/accounts*'        => Http::response(['data' => [[
            'id'           => 'page-1',
            'name'         => 'Page One',
            'access_token' => 'page-token',
            'tasks'        => ['MANAGE', 'ADVERTISE'],
        ]]]),
        'graph.facebook.com/*/me/permissions*' => Http::response(['success' => true]),
        'graph.facebook.com/*/me*'             => Http::response(['id' => 'fb-cycle-user', 'name' => 'Cycle User']),
        'graph.facebook.com/*'                 => Http::response(['data' => [], 'success' => true]),
    ]);

    $callback = function (): void {
        $nonce = 'cycle-nonce';
        $state = base64_encode(json_encode(['nonce' => $nonce, 'team' => $this->team->uuid]));

        $this->withSession(['facebook_oauth_nonce' => $nonce])
            ->get(route('lead-pipeline.facebook.callback', ['code' => 'auth-code', 'state' => $state]))
            ->assertOk();
    };

    $callback();

    $firstPage = FacebookPage::query()->where('page_id', 'page-1')->firstOrFail();
    $board     = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $source    = LeadSource::factory()->create([
        'lead_board_uuid'    => $board->uuid,
        'facebook_page_uuid' => $firstPage->uuid,
    ]);

    $connection = FacebookConnection::query()->where('facebook_user_id', 'fb-cycle-user')->firstOrFail();

    Livewire::test(FacebookConnectionStatus::class)
        ->call('disconnect', $connection->uuid);

    expect(FacebookConnection::query()->where('facebook_user_id', 'fb-cycle-user')->exists())->toBeFalse()
        ->and(FacebookPage::withTrashed()->where('page_id', 'page-1')->exists())->toBeFalse();

    $callback();

    $newPage = FacebookPage::query()->where('page_id', 'page-1')->firstOrFail();

    expect($newPage->uuid)->not->toBe($firstPage->uuid)
        ->and(FacebookConnection::query()->where('facebook_user_id', 'fb-cycle-user')->count())->toBe(1)
        ->and($source->refresh()->facebook_page_uuid)->toBe($newPage->uuid);
});
