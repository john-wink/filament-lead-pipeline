<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Jobs\RefreshFacebookConnection;
use JohnWink\FilamentLeadPipeline\Livewire\FacebookConnectionStatus;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

function statusConnection(array $attributes = []): FacebookConnection
{
    $team = Team::query()->firstWhere('slug', 'test');

    return FacebookConnection::factory()->create([
        'team_uuid'          => $team->uuid,
        'user_uuid'          => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id,
        'facebook_user_name' => 'Zachary Marref',
        'status'             => FacebookConnectionStatusEnum::Connected,
        'token_expires_at'   => now()->addDays(60),
        'scopes'             => ['ads_read'],
        ...$attributes,
    ]);
}

it('lists the team connections with their health state', function (): void {
    statusConnection();
    statusConnection(['facebook_user_name' => 'Kaputte Verbindung', 'status' => FacebookConnectionStatusEnum::NeedsReauth, 'facebook_user_id' => 'fb-2']);

    Livewire::test(FacebookConnectionStatus::class)
        ->assertSee('Zachary Marref')
        ->assertSee('Kaputte Verbindung')
        ->assertSee(__('lead-pipeline::lead-pipeline.connection_status.reasons.needs_reauth'));
});

it('dispatches the refresh job for a connection', function (): void {
    Queue::fake();
    $connection = statusConnection();

    Livewire::test(FacebookConnectionStatus::class)
        ->call('refreshToken', $connection->uuid);

    Queue::assertPushed(RefreshFacebookConnection::class, fn (RefreshFacebookConnection $job): bool => $job->facebookConnection->is($connection));
});

it('does not refresh connections of foreign teams', function (): void {
    Queue::fake();
    $foreign           = Team::factory()->create();
    $foreignConnection = statusConnection(['team_uuid' => $foreign->uuid, 'facebook_user_id' => 'fb-x']);

    Livewire::test(FacebookConnectionStatus::class)
        ->call('refreshToken', $foreignConnection->uuid);

    Queue::assertNothingPushed();
});

it('shows the health banner on the kanban page when a board source has errors', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $board->sources()->create([
        'name'          => 'FB Ads',
        'driver'        => 'meta',
        'status'        => LeadSourceStatusEnum::Error,
        'error_message' => 'Facebook-Verbindung erfordert einen erneuten Login.',
    ]);

    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $board])
        ->assertSee(__('lead-pipeline::lead-pipeline.connection_status.banner_sources_error', ['count' => 1]));
});

it('shows no banner when sources and connections are healthy', function (): void {
    statusConnection();
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $board])
        ->assertDontSee(__('lead-pipeline::lead-pipeline.connection_status.banner_sources_error', ['count' => 1]));
});
