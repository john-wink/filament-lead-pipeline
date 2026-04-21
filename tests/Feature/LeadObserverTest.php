<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadAssigned;
use JohnWink\FilamentLeadPipeline\Events\LeadMoved;
use JohnWink\FilamentLeadPipeline\Events\LeadStatusChanged;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Open]);
    $this->lead  = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();
});

it('dispatches LeadMoved when the phase changes', function (): void {
    $newPhase = LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::InProgress]);

    Event::fake([LeadMoved::class]);

    $this->lead->update(['lead_phase_uuid' => $newPhase->uuid]);

    Event::assertDispatched(LeadMoved::class, fn (LeadMoved $e): bool => $e->lead->is($this->lead) && $e->toPhase->is($newPhase));
});

it('dispatches LeadStatusChanged when status changes', function (): void {
    Event::fake([LeadStatusChanged::class]);

    $this->lead->update(['status' => LeadStatusEnum::Converted]);

    Event::assertDispatched(LeadStatusChanged::class, fn (LeadStatusChanged $e): bool => LeadStatusEnum::Converted === $e->newStatus);
});

it('dispatches LeadAssigned when assigned_to is set', function (): void {
    Event::fake([LeadAssigned::class]);

    $this->lead->update(['assigned_to' => $this->user->id]);

    Event::assertDispatched(LeadAssigned::class, fn (LeadAssigned $e): bool => $e->lead->is($this->lead) && $e->assignedUser?->getKey() === $this->user->id);
});

it('does not dispatch LeadAssigned when assigned_to is cleared (blank)', function (): void {
    $this->lead->update(['assigned_to' => $this->user->id]);

    Event::fake([LeadAssigned::class]);

    $this->lead->update(['assigned_to' => null]);

    Event::assertNotDispatched(LeadAssigned::class);
});

it('does not dispatch on update when no observed columns changed', function (): void {
    Event::fake([LeadMoved::class, LeadStatusChanged::class, LeadAssigned::class]);

    $this->lead->update(['name' => 'Renamed Only']);

    Event::assertNotDispatched(LeadMoved::class);
    Event::assertNotDispatched(LeadStatusChanged::class);
    Event::assertNotDispatched(LeadAssigned::class);
});
