<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->board = LeadBoard::factory()->create();
    LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

it('polls only while the board is visible in the viewport', function (): void {
    config()->set('lead-pipeline.kanban.auto_refresh_interval', 30);

    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->assertSeeHtml('wire:poll.30s.visible');
});

it('does not poll at all when the interval is disabled', function (): void {
    config()->set('lead-pipeline.kanban.auto_refresh_interval', 0);

    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->assertDontSeeHtml('wire:poll');
});
