<?php

declare(strict_types=1);

use App\Models\User;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Livewire\LeadCard;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Livewire\Livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::kanban-board', KanbanBoard::class);
    Livewire::component('lead-pipeline::kanban-phase-column', KanbanPhaseColumn::class);
    Livewire::component('lead-pipeline::lead-card', LeadCard::class);
    Livewire::component('lead-pipeline::lead-detail-modal', LeadDetailModal::class);

    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

/**
 * Extract ordered lead IDs from the rendered HTML data-lead-id attributes.
 */
function extractLeadIdsFromHtml(string $html): array
{
    preg_match_all('/data-lead-id="([^"]+)"/', $html, $matches);

    return $matches[1] ?? [];
}

it('sorts leads by newest first', function (): void {
    $old = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'Old Lead',
        'sort'                       => 0,
        'created_at'                 => now()->subDays(5),
    ]);
    $new = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'New Lead',
        'sort'                       => 1,
        'created_at'                 => now(),
    ]);

    $component = Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])->call('init')
        ->set('sortBy', 'newest');

    $ids = extractLeadIdsFromHtml($component->html());
    expect($ids)->toBe([$new->getKey(), $old->getKey()]);
});

it('sorts leads by value descending', function (): void {
    $low = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'Low Value',
        'value'                      => 100,
        'sort'                       => 0,
    ]);
    $high = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'High Value',
        'value'                      => 50000,
        'sort'                       => 1,
    ]);

    $component = Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])->call('init')
        ->set('sortBy', 'value_desc');

    $ids = extractLeadIdsFromHtml($component->html());
    expect($ids)->toBe([$high->getKey(), $low->getKey()]);
});

it('sorts leads by name alphabetically', function (): void {
    $zebra = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'Zebra',
        'sort'                       => 0,
    ]);
    $alpha = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'Alpha',
        'sort'                       => 1,
    ]);

    $component = Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])->call('init')
        ->set('sortBy', 'name_asc');

    $ids = extractLeadIdsFromHtml($component->html());
    expect($ids)->toBe([$alpha->getKey(), $zebra->getKey()]);
});

it('defaults to manual sort order', function (): void {
    $second = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'Second',
        'sort'                       => 1,
    ]);
    $first = Lead::factory()->create([
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        'name'                       => 'First',
        'sort'                       => 0,
    ]);

    $component = Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])->call('init');

    $ids = extractLeadIdsFromHtml($component->html());
    expect($ids)->toBe([$first->getKey(), $second->getKey()]);
});

it('filters leads by source', function (): void {
    $source1 = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Instagram']);
    $source2 = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Website']);

    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->for($source1, 'source')
        ->create(['name' => 'From Instagram']);
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->for($source2, 'source')
        ->create(['name' => 'From Website']);

    Livewire::test(KanbanPhaseColumn::class, [
        'phaseId' => $this->phase->getKey(),
        'filters' => ['source_id' => $source1->getKey()],
    ])->call('init')
        ->assertSee('From Instagram')
        ->assertDontSee('From Website');
});

it('filters leads by assigned user', function (): void {
    $user1 = User::factory()->create(['first_name' => 'Alice', 'last_name' => 'Test']);
    $user2 = User::factory()->create(['first_name' => 'Bob', 'last_name' => 'Test']);

    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Alice Lead', 'assigned_to' => $user1->getKey()]);
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Bob Lead', 'assigned_to' => $user2->getKey()]);

    Livewire::test(KanbanPhaseColumn::class, [
        'phaseId' => $this->phase->getKey(),
        'filters' => ['assigned_to' => $user1->getKey()],
    ])->call('init')
        ->assertSee('Alice Lead')
        ->assertDontSee('Bob Lead');
});

it('filters leads by status', function (): void {
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Active Lead', 'status' => LeadStatusEnum::Active]);
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Lost Lead', 'status' => LeadStatusEnum::Lost]);

    Livewire::test(KanbanPhaseColumn::class, [
        'phaseId' => $this->phase->getKey(),
        'filters' => ['status' => 'active'],
    ])->call('init')
        ->assertSee('Active Lead')
        ->assertDontSee('Lost Lead');
});

it('filters leads by value range', function (): void {
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Small Lead', 'value' => 500]);
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Big Lead', 'value' => 50000]);

    Livewire::test(KanbanPhaseColumn::class, [
        'phaseId' => $this->phase->getKey(),
        'filters' => ['value_min' => 10000],
    ])->call('init')
        ->assertDontSee('Small Lead')
        ->assertSee('Big Lead');
});

it('filters leads by date range', function (): void {
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Old Lead', 'created_at' => now()->subMonths(3)]);
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Recent Lead', 'created_at' => now()->subDays(3)]);

    Livewire::test(KanbanPhaseColumn::class, [
        'phaseId' => $this->phase->getKey(),
        'filters' => ['created_from' => now()->subDays(7)->toDateString()],
    ])->call('init')
        ->assertDontSee('Old Lead')
        ->assertSee('Recent Lead');
});

it('inner KanbanBoard accepts filters and exposes them to phase columns', function (): void {
    $component = Livewire::test(KanbanBoard::class, [
        'board'   => $this->board,
        'filters' => ['source_id' => 'abc-123'],
    ]);

    $component->assertSet('filters', ['source_id' => 'abc-123']);
});

it('inner KanbanBoard updates filters when filters-updated event is dispatched', function (): void {
    $component = Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->dispatch('filters-updated', filters: ['status' => 'active']);

    $component->assertSet('filters', ['status' => 'active']);
});

it('Page KanbanBoard dispatches filters-updated event when filter property changes', function (): void {
    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board])
        ->set('filters.source_id', 'abc-123')
        ->assertDispatched('filters-updated');
});

it('Page KanbanBoard hydrates filters from session on mount', function (): void {
    session(["lead-pipeline.filters.{$this->board->getKey()}" => ['status' => 'lost']]);

    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board])
        ->assertSet('filters', ['status' => 'lost']);
});

it('Page KanbanBoard dispatches filters-updated targeted at KanbanPhaseColumn (so isolated children update without a reload)', function (): void {
    $component = Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board])
        ->set('filters.source_id', 'abc-123');

    $dispatches = data_get($component->effects, 'dispatches', []);
    $targeted   = collect($dispatches)->firstWhere(fn (array $d): bool => 'filters-updated' === ($d['name'] ?? null)
        && 'lead-pipeline::kanban-phase-column' === ($d['to'] ?? null));

    expect($targeted)->not->toBeNull('Page must dispatch filters-updated directly to KanbanPhaseColumn class');
});

it('clearFilters also dispatches event directly to KanbanPhaseColumn', function (): void {
    session(["lead-pipeline.filters.{$this->board->getKey()}" => ['status' => 'lost']]);

    $component = Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board])
        ->call('clearFilters');

    $dispatches = data_get($component->effects, 'dispatches', []);
    $targeted   = collect($dispatches)->firstWhere(fn (array $d): bool => 'filters-updated' === ($d['name'] ?? null)
        && 'lead-pipeline::kanban-phase-column' === ($d['to'] ?? null));

    expect($targeted)->not->toBeNull('clearFilters must dispatch filters-updated directly to KanbanPhaseColumn class');
});
