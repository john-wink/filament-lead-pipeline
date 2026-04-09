<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Event;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadMoved;
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

it('renders kanban board with phases', function (): void {
    $board  = LeadBoard::factory()->create();
    $phase1 = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phase2 = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->assertOk()
        ->assertSee($phase1->name)
        ->assertSee($phase2->name);
});

it('renders board title from board name', function (): void {
    $board = LeadBoard::factory()->create(['name' => 'Mein Sales Board']);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->assertOk()
        ->assertSet('board.name', 'Mein Sales Board');
});

it('shows all phases in correct order', function (): void {
    $board = LeadBoard::factory()->create();
    LeadPhase::factory()->for($board, 'board')->create(['name' => 'Dritte', 'sort' => 2]);
    LeadPhase::factory()->for($board, 'board')->create(['name' => 'Erste', 'sort' => 0]);
    LeadPhase::factory()->for($board, 'board')->create(['name' => 'Zweite', 'sort' => 1]);

    $component = Livewire::test(KanbanBoard::class, ['board' => $board]);

    $phases = $board->phases()->ordered()->get();
    expect($phases->pluck('name')->toArray())->toBe(['Erste', 'Zweite', 'Dritte']);

    $component->assertOk();
});

// === MOVE LEAD ===

it('can move a lead from one phase to another', function (): void {
    Event::fake([LeadMoved::class]);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
        'sort'                       => 0,
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseB->getKey(), 0)
        ->assertOk();

    $lead->refresh();
    expect($lead->{Lead::fkColumn('lead_phase')})->toBe($phaseB->getKey())
        ->and($lead->sort)->toBe(0);
});

it('creates activity log when lead is moved', function (): void {
    Event::fake([LeadMoved::class]);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['name' => 'Offen', 'sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Kontaktiert', 'sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseB->getKey(), 0);

    $activity = $lead->activities()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(LeadActivityTypeEnum::Moved)
        ->and($activity->description)->toContain('Offen')
        ->and($activity->description)->toContain('Kontaktiert');
});

it('dispatches LeadMoved event when phase changes', function (): void {
    Event::fake([LeadMoved::class]);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseB->getKey(), 0);

    Event::assertDispatched(LeadMoved::class, function (LeadMoved $event) use ($lead, $phaseA, $phaseB): bool {
        return $event->lead->getKey() === $lead->getKey()
            && $event->fromPhase->getKey() === $phaseA->getKey()
            && $event->toPhase->getKey() === $phaseB->getKey();
    });
});

it('updates lead sort position after move', function (): void {
    Event::fake([LeadMoved::class]);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
        'sort'                       => 5,
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseB->getKey(), 3);

    expect($lead->refresh()->sort)->toBe(3);
});

it('does not create activity when moved within same phase (just reorder)', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'sort'                       => 0,
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phase->getKey(), 2);

    expect($lead->activities()->count())->toBe(0)
        ->and($lead->refresh()->sort)->toBe(2);
});

it('dispatches phase-updated events for both old and new phase', function (): void {
    Event::fake([LeadMoved::class]);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseB->getKey(), 0)
        ->assertDispatched('phase-updated', phaseId: $phaseA->getKey())
        ->assertDispatched('phase-updated', phaseId: $phaseB->getKey());
});

it('handles moving lead to terminal (won) phase', function (): void {
    Event::fake([LeadMoved::class]);

    $board    = LeadBoard::factory()->create();
    $phaseA   = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseWon = LeadPhase::factory()->for($board, 'board')->won()->create(['sort' => 1, 'auto_convert' => false]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseWon->getKey(), 0)
        ->assertNotDispatched('lead-conversion-needed');

    expect($lead->refresh()->{Lead::fkColumn('lead_phase')})->toBe($phaseWon->getKey());
});

it('dispatches lead-conversion-needed when auto_convert is enabled on terminal phase', function (): void {
    Event::fake([LeadMoved::class]);

    $board    = LeadBoard::factory()->create();
    $phaseA   = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseWon = LeadPhase::factory()->for($board, 'board')->won()->create(['sort' => 1, 'auto_convert' => true]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseWon->getKey(), 0)
        ->assertDispatched('lead-conversion-needed', leadId: $lead->getKey());
});

// === REORDER ===

it('can reorder leads within a phase', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $leadA = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'sort'                       => 0,
    ]);
    $leadB = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'sort'                       => 1,
    ]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('reorderLeads', $phase->getKey(), [$leadB->getKey(), $leadA->getKey()])
        ->assertDispatched('phase-updated', phaseId: $phase->getKey());

    expect($leadA->refresh()->sort)->toBe(1)
        ->and($leadB->refresh()->sort)->toBe(0);
});

it('updates sort values correctly for all leads', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $leads = collect();
    for ($i = 0; $i < 5; $i++) {
        $leads->push(Lead::factory()->create([
            Lead::fkColumn('lead_board') => $board->getKey(),
            Lead::fkColumn('lead_phase') => $phase->getKey(),
            'sort'                       => $i,
        ]));
    }

    $reversed = $leads->reverse()->pluck(Lead::pkColumn())->values()->toArray();

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('reorderLeads', $phase->getKey(), $reversed);

    foreach ($leads as $index => $lead) {
        expect($lead->refresh()->sort)->toBe(4 - $index);
    }
});

// === EDGE CASES ===

it('handles board with no phases gracefully', function (): void {
    $board = LeadBoard::factory()->create();

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->assertOk();
});

it('handles board with no leads gracefully', function (): void {
    $board = LeadBoard::factory()->create();
    LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->assertOk();
});

it('handles moving non-existent lead (should fail gracefully)', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', 'non-existent-uuid', $phase->getKey(), 0)
        ->assertStatus(500);
})->throws(ModelNotFoundException::class);

it('handles concurrent moves (lead already moved by another user)', function (): void {
    Event::fake([LeadMoved::class]);

    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['sort' => 1]);
    $phaseC = LeadPhase::factory()->for($board, 'board')->create(['sort' => 2]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phaseA->getKey(),
    ]);

    // Simulate another user already moved the lead to phaseB
    $lead->update([Lead::fkColumn('lead_phase') => $phaseB->getKey()]);

    // Now this user tries to move from phaseA to phaseC (stale data)
    Livewire::test(KanbanBoard::class, ['board' => $board])
        ->call('moveLeadToPhase', $lead->getKey(), $phaseC->getKey(), 0)
        ->assertOk();

    // Lead should be in phaseC (the last move wins)
    expect($lead->refresh()->{Lead::fkColumn('lead_phase')})->toBe($phaseC->getKey());

    // Activity should reference phaseB as old phase (since that's where it was)
    $lastActivity = $lead->activities()->latest('id')->first();
    expect($lastActivity->properties['old_phase'])->toBe($phaseB->getKey())
        ->and($lastActivity->properties['new_phase'])->toBe($phaseC->getKey());
});
