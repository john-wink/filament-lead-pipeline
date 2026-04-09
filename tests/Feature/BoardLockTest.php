<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('shows lock banner when board has leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertSee('Phasen und Felder können nicht mehr bearbeitet werden');
});

it('does not show lock banner when board has no leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertDontSee('Phasen und Felder können nicht mehr bearbeitet werden');
});

it('returns true from hasLeads when board has leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();

    expect($board->hasLeads())->toBeTrue();
});

it('returns false from hasLeads when board has no leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    expect($board->hasLeads())->toBeFalse();
});
