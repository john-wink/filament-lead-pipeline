<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\ListLeadBoards;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('shows the integrations header action when integrations are registered', function (): void {
    livewire(ListLeadBoards::class)
        ->assertOk()
        ->assertActionVisible('integrations');
});

it('hides the integrations header action without registered integrations', function (): void {
    FilamentLeadPipelinePlugin::get()->integrations([]);

    livewire(ListLeadBoards::class)
        ->assertOk()
        ->assertActionHidden('integrations');
});
