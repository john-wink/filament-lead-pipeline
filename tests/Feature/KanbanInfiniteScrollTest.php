<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Livewire\LeadCard;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::kanban-board', KanbanBoard::class);
    Livewire::component('lead-pipeline::kanban-phase-column', KanbanPhaseColumn::class);
    Livewire::component('lead-pipeline::lead-card', LeadCard::class);
    Livewire::component('lead-pipeline::lead-detail-modal', LeadDetailModal::class);

    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

it('shows remaining count when more leads exist', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 5);

    Lead::factory()->count(12)->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])->call('init')
        ->assertSee('7 weitere');
});

it('loads more leads when loadMore is called', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 5);

    Lead::factory()->count(12)->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])->call('init')
        ->assertSee('7 weitere')
        ->call('loadMore')
        ->assertSee('2 weitere')
        ->call('loadMore')
        ->assertDontSee('weitere');
});

it('hides load more when all leads are loaded', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 20);

    Lead::factory()->count(3)->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])->call('init')
        ->assertDontSee('weitere');
});
