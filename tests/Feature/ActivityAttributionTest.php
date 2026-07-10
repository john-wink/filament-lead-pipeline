<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::kanban-board', KanbanBoard::class);
    Livewire::component('lead-pipeline::kanban-phase-column', KanbanPhaseColumn::class);
});

it('attributes drag-and-drop moves on the Livewire board to the acting user', function (): void {
    $user = config('lead-pipeline.user_model')::factory()->create();
    $this->actingAs($user);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);
    $lead   = Lead::factory()->for($board, 'board')->for($phaseA, 'phase')->create();

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseB->getKey(), 0)
        ->assertOk();

    $activity = $lead->activities()->where('type', LeadActivityTypeEnum::Moved->value)->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and((string) $activity->causer_id)->toBe((string) $user->getKey())
        ->and($activity->causer_type)->toBe(config('lead-pipeline.user_model'));
});

it('attributes drag-and-drop moves on the Filament page board to the acting user', function (): void {
    $user = config('lead-pipeline.user_model')::factory()->create();
    $this->actingAs($user);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);
    $lead   = Lead::factory()->for($board, 'board')->for($phaseA, 'phase')->create();

    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseB->getKey(), 0)
        ->assertOk();

    $activity = $lead->activities()->where('type', LeadActivityTypeEnum::Moved->value)->latest('id')->first();

    expect((string) $activity->causer_id)->toBe((string) $user->getKey());
});

it('stores the assignee id in assignment activity properties', function (): void {
    $actor    = config('lead-pipeline.user_model')::factory()->create();
    $assignee = config('lead-pipeline.user_model')::factory()->create();
    $this->actingAs($actor);

    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    $lead  = Lead::factory()->for($board, 'board')->for($phase, 'phase')->create();

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])
        ->call('assignUser', $lead->getKey(), (string) $assignee->getKey());

    $activity = $lead->activities()->where('type', LeadActivityTypeEnum::Assignment->value)->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and((string) ($activity->properties['assigned_to'] ?? ''))->toBe((string) $assignee->getKey())
        ->and((string) $activity->causer_id)->toBe((string) $actor->getKey());
});
