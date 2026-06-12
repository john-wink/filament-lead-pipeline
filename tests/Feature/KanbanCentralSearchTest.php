<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

it('dispatches search-updated from the page when the central search changes', function (): void {
    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board])
        ->set('search', 'John')
        ->assertDispatched('search-updated', search: 'John');
});

it('filters column leads when receiving the central search-updated event', function (): void {
    Lead::factory()->create(['lead_board_uuid' => $this->board->uuid, 'lead_phase_uuid' => $this->phase->uuid, 'name' => 'John Doe']);
    Lead::factory()->create(['lead_board_uuid' => $this->board->uuid, 'lead_phase_uuid' => $this->phase->uuid, 'name' => 'Erika Muster']);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->uuid, 'filters' => []])
        ->call('init')
        ->dispatch('search-updated', search: 'John')
        ->assertSee('John Doe')
        ->assertDontSee('Erika Muster');
});

it('no longer renders a per-column search input', function (): void {
    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->uuid, 'filters' => []])
        ->call('init')
        ->assertDontSeeHtml('wire:model.live.debounce.300ms="search"');
});
