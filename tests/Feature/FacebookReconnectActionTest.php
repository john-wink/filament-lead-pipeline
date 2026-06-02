<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    LeadBoard::created(function (LeadBoard $board): void {
        $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    });
});

function reconnectMetaSource(Team $team, $user, FacebookConnectionStatusEnum $status): LeadSource
{
    $connection = FacebookConnection::factory()->create([
        'user_uuid' => $user->id, 'team_uuid' => $team->uuid, 'status' => $status,
    ]);
    $page = FacebookPage::query()->create([
        'facebook_connection_uuid' => $connection->uuid,
        'page_id'                  => 'page-' . uniqid(), 'page_name' => 'P', 'page_access_token' => 'pt',
    ]);
    $board = LeadBoard::factory()->create(['team_uuid' => $team->uuid]);

    return LeadSource::query()->create([
        'name'                             => 'Meta', 'driver' => 'meta', 'status' => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $board->getKey(),
        'team_uuid'                        => $team->uuid, 'created_by' => $user->getKey(),
        'facebook_page_uuid'               => $page->uuid,
    ]);
}

it('shows the reconnect action when the connection needs reauth', function (): void {
    $source = reconnectMetaSource($this->team, $this->user, FacebookConnectionStatusEnum::NeedsReauth);

    livewire(SourceManagement::class)
        ->assertTableActionVisible('meta_reconnect', $source);
});

it('hides the reconnect action when the connection is healthy', function (): void {
    $source = reconnectMetaSource($this->team, $this->user, FacebookConnectionStatusEnum::Connected);

    livewire(SourceManagement::class)
        ->assertTableActionHidden('meta_reconnect', $source);
});
