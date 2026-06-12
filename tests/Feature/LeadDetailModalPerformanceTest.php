<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
    $this->lead  = Lead::factory()->create([
        'lead_board_uuid' => $this->board->uuid,
        'lead_phase_uuid' => $this->phase->uuid,
        'name'            => 'Max Muster',
    ]);
});

function seedActivities(Lead $lead, int $count): void
{
    for ($i = 0; $i < $count; $i++) {
        $lead->activities()->create([
            'type'        => JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Note->value,
            'description' => "Notiz {$i}",
        ]);
    }
}

it('loads only the first batch of activities and exposes a load-more flag', function (): void {
    seedActivities($this->lead, 15);

    $component = Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->uuid);

    expect($component->instance()->lead->activities)->toHaveCount(10)
        ->and($component->instance()->hasMoreActivities())->toBeTrue();

    $component->call('loadMoreActivities');

    expect($component->instance()->lead->activities)->toHaveCount(15)
        ->and($component->instance()->hasMoreActivities())->toBeFalse();
});

it('updates a field without reloading the full relation graph', function (): void {
    seedActivities($this->lead, 10);

    $component = Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->uuid);

    DB::flushQueryLog();
    DB::enableQueryLog();

    $component->call('updateField', 'name', 'Neuer Name');

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($this->lead->refresh()->name)->toBe('Neuer Name')
        // Untergrenze = EIN Graph-Load pro Request (Computed-Lead) + Update.
        // Vorher: Hydration-Reload + expliziter 7-Relationen-Reload mit 50er-Activities ≈ 16+.
        ->and($queryCount)->toBeLessThanOrEqual(10);
});

it('adding a note reloads only the activity timeline', function (): void {
    $component = Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->uuid);

    $component->set('newNote', 'Anruf: nicht erreicht');

    DB::flushQueryLog();
    DB::enableQueryLog();

    $component->call('addNote');

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($this->lead->activities()->count())->toBe(1)
        ->and($queryCount)->toBeLessThanOrEqual(12);
});

it('caches board phases for the modal instead of querying inside the blade loop', function (): void {
    $component = Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->uuid);

    $first = $component->instance()->boardPhases();

    DB::flushQueryLog();
    DB::enableQueryLog();

    $second = $component->instance()->boardPhases();

    $queryCount = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($second)->toEqual($first)
        ->and($queryCount)->toBe(0);
});
