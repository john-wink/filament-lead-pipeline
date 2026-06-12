<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->board    = LeadBoard::factory()->create();
    $this->open     = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
    $this->progress = LeadPhase::factory()->for($this->board, 'board')->create(['sort' => 1]);
});

it('computes board stats with a single aggregate query instead of per-phase counts', function (): void {
    Lead::factory()->count(2)->create(['lead_board_uuid' => $this->board->uuid, 'lead_phase_uuid' => $this->open->uuid, 'value' => 100]);
    Lead::factory()->count(3)->create(['lead_board_uuid' => $this->board->uuid, 'lead_phase_uuid' => $this->progress->uuid, 'value' => 50]);

    $component = Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board]);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $stats = $component->instance()->boardStats;

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($stats['leads'])->toBe(5)
        ->and($stats['value'])->toBe(350.0)
        ->and($queryCount)->toBeLessThanOrEqual(2);
});

it('renders the aggregated stats in the page header', function (): void {
    Lead::factory()->count(2)->create(['lead_board_uuid' => $this->board->uuid, 'lead_phase_uuid' => $this->open->uuid, 'value' => 1000]);

    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board])
        ->assertSee('2.000');
});
