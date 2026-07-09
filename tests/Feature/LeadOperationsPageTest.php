<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Filament\Pages\LeadOperations;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('renders the lead operations page', function (): void {
    LeadBoard::factory()->create();

    livewire(LeadOperations::class)->assertSuccessful();
});

it('switches preset and board through the lifecycle', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(LeadOperations::class)
        ->call('setPreset', '7')
        ->assertSet('preset', '7')
        ->call('setBoard', (string) $board->getKey())
        ->assertSet('boardId', (string) $board->getKey())
        ->assertSuccessful();
});

it('renders successfully without any tenant-visible boards', function (): void {
    livewire(LeadOperations::class)->assertSuccessful();
});
