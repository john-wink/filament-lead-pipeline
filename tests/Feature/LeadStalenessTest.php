<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

function stalenessLead(LeadPhase $phase, array $attributes = []): Lead
{
    return Lead::factory()->create([
        Lead::fkColumn('lead_board') => $phase->{LeadPhase::fkColumn('lead_board')},
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        ...$attributes,
    ]);
}

// === MODEL ===

it('is fresh when the last activity is recent', function (): void {
    $lead                   = stalenessLead($this->phase, ['created_at' => now()->subDays(40)]);
    $lead->last_activity_at = now()->subDays(2)->toDateTimeString();

    expect($lead->staleness())->toBe('fresh');
});

it('is aging after the warning threshold', function (): void {
    $lead                   = stalenessLead($this->phase, ['created_at' => now()->subDays(40)]);
    $lead->last_activity_at = now()->subDays(10)->toDateTimeString();

    expect($lead->staleness())->toBe('aging');
});

it('is stale after the critical threshold', function (): void {
    $lead                   = stalenessLead($this->phase, ['created_at' => now()->subDays(40)]);
    $lead->last_activity_at = now()->subDays(31)->toDateTimeString();

    expect($lead->staleness())->toBe('stale');
});

it('falls back to created_at when there is no activity', function (): void {
    $lead = stalenessLead($this->phase, ['created_at' => now()->subDays(31)]);

    expect($lead->staleness())->toBe('stale')
        ->and($lead->daysSinceLastActivity())->toBe(31);
});

it('respects configured thresholds', function (): void {
    config()->set('lead-pipeline.kanban.stale_warning_days', 2);
    config()->set('lead-pipeline.kanban.stale_critical_days', 5);

    $lead = stalenessLead($this->phase, ['created_at' => now()->subDays(3)]);

    expect($lead->staleness())->toBe('aging');
});

// === KARTE ===

it('shows a red stale badge with the lead age on the card', function (): void {
    stalenessLead($this->phase, ['name' => 'Alter Lead', 'created_at' => now()->subDays(35)]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])
        ->call('init')
        ->assertSeeHtml('lead-age-badge')
        ->assertSee('35d')
        ->assertSeeHtml('text-red-600');
});

it('shows a neutral age badge for recently worked leads', function (): void {
    $lead = stalenessLead($this->phase, ['name' => 'Frischer Lead', 'created_at' => now()->subDays(35)]);
    $lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Note->value,
        'description' => 'Gerade telefoniert',
    ]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $this->phase->getKey()])
        ->call('init')
        ->assertSeeHtml('lead-age-badge')
        ->assertSee('35d')
        ->assertDontSeeHtml('text-red-600');
});
