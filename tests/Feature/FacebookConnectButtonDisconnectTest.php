<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

function ownConnection(array $attributes = []): FacebookConnection
{
    $team = Team::query()->firstWhere('slug', 'test');

    return FacebookConnection::factory()->create([
        'team_uuid'          => $team->uuid,
        'user_uuid'          => $team->users->first()->getKey(),
        'access_token'       => 'user-token-123',
        'facebook_user_name' => 'Villa Behr',
        'status'             => FacebookConnectionStatusEnum::Connected,
        ...$attributes,
    ]);
}

it('offers disconnect and connect-another-account actions in the source form when already connected', function (): void {
    $connection = ownConnection();

    $html = str_replace('\\/', '/', view('lead-pipeline::filament.components.facebook-connect-button')->render());

    expect($html)
        ->toContain(__('lead-pipeline::lead-pipeline.facebook.connected_as', ['name' => 'Villa Behr']))
        ->toContain(__('lead-pipeline::lead-pipeline.facebook.connect_other_account'))
        ->toContain(__('lead-pipeline::lead-pipeline.facebook.disconnect'))
        ->toContain(route('lead-pipeline.facebook.disconnect', $connection));
});

it('disconnects the current user\'s own connection via the disconnect route', function (): void {
    Http::fake(['graph.facebook.com/*' => Http::response(['success' => true])]);
    $connection = ownConnection();

    $this->deleteJson(route('lead-pipeline.facebook.disconnect', $connection))
        ->assertNoContent();

    expect(FacebookConnection::query()->whereKey($connection->uuid)->exists())->toBeFalse();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), '/me/permissions') && 'DELETE' === $request->method());
});

it('refuses to disconnect a connection that belongs to another user', function (): void {
    Http::fake();
    $foreign = ownConnection(['user_uuid' => (string) Str::uuid(), 'facebook_user_id' => 'fb-foreign']);

    $this->deleteJson(route('lead-pipeline.facebook.disconnect', $foreign))
        ->assertNotFound();

    expect(FacebookConnection::query()->whereKey($foreign->uuid)->exists())->toBeTrue();
    Http::assertNothingSent();
});
