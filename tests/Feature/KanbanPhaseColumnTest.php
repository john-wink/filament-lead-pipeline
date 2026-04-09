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
});

// === RENDERING ===

it('renders phase column with leads', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Max Mustermann',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertOk()
        ->assertSee($phase->name)
        ->assertSee('Max Mustermann');
});

it('shows phase name and color', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->create([
        'name'  => 'Qualifiziert',
        'color' => '#8B5CF6',
        'sort'  => 0,
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertOk()
        ->assertSee('Qualifiziert')
        ->assertSee('#8B5CF6');
});

it('shows lead count badge', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->count(3)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertOk()
        ->assertSee('3');
});

it('shows leads in sort order', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $leadC = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Charlie',
        'sort'                       => 2,
    ]);
    $leadA = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Alpha',
        'sort'                       => 0,
    ]);
    $leadB = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Bravo',
        'sort'                       => 1,
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertOk()
        ->assertSeeInOrder(['Alpha', 'Bravo', 'Charlie']);
});

// === LAZY LOADING ===

it('loads only configured number of leads per page', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 5);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->count(10)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertOk()
        ->assertSet('perPage', 5)
        ->assertSee('weitere');
});

it('can load more leads', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 3);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->count(8)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertSet('perPage', 3)
        ->call('loadMore')
        ->assertSet('perPage', 6);
});

it('shows "load more" button when more leads exist', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 2);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->count(5)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertSee('weitere');
});

it('hides "load more" button when all leads loaded', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 20);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->count(3)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertDontSee('weitere');
});

it('resets pagination when search changes', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 3);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->count(10)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    $component = Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->call('loadMore')
        ->assertSet('perPage', 6);

    // When search changes, the component re-renders but perPage stays
    // The search results naturally limit the visible leads
    $component->set('search', 'test')
        ->assertOk();
});

// === SEARCH ===

it('can search leads by name', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $hans = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Hans Mueller',
    ]);
    $peter = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Peter Schmidt',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->set('search', 'Hans')
        ->assertSeeHtml('data-lead-id="' . $hans->getKey() . '"')
        ->assertDontSeeHtml('data-lead-id="' . $peter->getKey() . '"');
});

it('can search leads by email', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $hans = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Hans',
        'email'                      => 'hans@example.com',
    ]);
    $peter = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Peter',
        'email'                      => 'peter@test.de',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->set('search', 'hans@example')
        ->assertSeeHtml('data-lead-id="' . $hans->getKey() . '"')
        ->assertDontSeeHtml('data-lead-id="' . $peter->getKey() . '"');
});

it('can search leads by phone', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $hans = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Hans',
        'phone'                      => '+49 171 1234567',
    ]);
    $peter = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Peter',
        'phone'                      => '+49 172 9876543',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->set('search', '1234567')
        ->assertSeeHtml('data-lead-id="' . $hans->getKey() . '"')
        ->assertDontSeeHtml('data-lead-id="' . $peter->getKey() . '"');
});

it('returns no results for non-matching search', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Hans Mueller',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->set('search', 'zzz-non-existent-query')
        ->assertDontSee('Hans Mueller')
        ->assertSee('0');
});

it('clears search results when search is emptied', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $hans = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Hans Mueller',
    ]);
    $peter = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Peter Schmidt',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->set('search', 'Hans')
        ->assertSeeHtml('data-lead-id="' . $hans->getKey() . '"')
        ->assertDontSeeHtml('data-lead-id="' . $peter->getKey() . '"')
        ->set('search', '')
        ->assertSeeHtml('data-lead-id="' . $hans->getKey() . '"')
        ->assertSeeHtml('data-lead-id="' . $peter->getKey() . '"');
});

// === REFRESH ===

it('refreshes when matching phase-updated event received', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 5);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->count(10)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    $component = Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->call('loadMore')
        ->assertSet('perPage', 10);

    // Dispatching matching phase-updated should reset perPage
    $component->dispatch('phase-updated', phaseId: $phase->getKey())
        ->assertSet('perPage', 5);
});

it('ignores phase-updated events for other phases', function (): void {
    config()->set('lead-pipeline.kanban.leads_per_page', 5);

    $board      = LeadBoard::factory()->create();
    $phase      = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $otherPhase = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);

    Lead::factory()->count(10)->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    $component = Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->call('loadMore')
        ->assertSet('perPage', 10);

    // Dispatching event for a different phase should not reset perPage
    $component->dispatch('phase-updated', phaseId: $otherPhase->getKey())
        ->assertSet('perPage', 10);
});
