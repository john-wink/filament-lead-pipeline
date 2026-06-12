<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

it('polls per column while visible — board level polling never refreshed child components', function (): void {
    config()->set('lead-pipeline.kanban.auto_refresh_interval', 30);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->uuid, 'filters' => []])
        ->call('init')
        ->assertSeeHtml('wire:poll.30s.visible');

    $boardHtmlBeforeColumns = Illuminate\Support\Str::before(
        Livewire::test(KanbanBoard::class, ['board' => $this->board])->html(),
        'lead-phase-column',
    );

    expect($boardHtmlBeforeColumns)->not->toContain('wire:poll');
});

it('does not poll at all when the interval is disabled', function (): void {
    config()->set('lead-pipeline.kanban.auto_refresh_interval', 0);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->uuid, 'filters' => []])
        ->call('init')
        ->assertDontSeeHtml('wire:poll');
});
