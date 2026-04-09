<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Livewire\Livewire;

// === RENDERING ===

it('renders lead detail modal when opened', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Test Lead',
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertOk()
        ->assertSet('isOpen', true)
        ->assertSee('Test Lead');
});

it('shows lead name, email, phone', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'name'                       => 'Maria Weber',
        'email'                      => 'maria@example.de',
        'phone'                      => '+49 170 1234567',
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertSee('Maria Weber')
        ->assertSee('maria@example.de')
        ->assertSee('+49 170 1234567');
});

it('shows lead value formatted as currency', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->withValue()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'value'                      => 150000.00,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertSee('150.000');
});

it('shows lead status badge', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertSee(LeadStatusEnum::Active->getLabel());
});

it('shows source badge', function (): void {
    $board  = LeadBoard::factory()->create();
    $phase  = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);
    $source = LeadSource::factory()->for($board, 'board')->active()->create(['name' => 'Website Kontakt']);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board')  => $board->getKey(),
        Lead::fkColumn('lead_phase')  => $phase->getKey(),
        Lead::fkColumn('lead_source') => $source->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertOk();
});

it('shows custom field values', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $fieldDef = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Firma',
        'key'  => 'firma',
        'type' => LeadFieldTypeEnum::String,
    ]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    $lead->setFieldValue($fieldDef, 'Mustermann GmbH');

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertSee('Firma')
        ->assertSee('Mustermann GmbH');
});

it('shows activity timeline', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    $lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Created->value,
        'description' => 'Lead erstellt',
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertSee('Lead erstellt')
        ->assertSee(LeadActivityTypeEnum::Created->getLabel());
});

// === ADD NOTE ===

it('can add a note to a lead', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->set('newNote', 'Kunde hat angerufen')
        ->call('addNote')
        ->assertOk();

    expect($lead->activities()->count())->toBe(1);
    expect($lead->activities()->first()->description)->toBe('Kunde hat angerufen');
});

it('creates activity with type Note', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->set('newNote', 'Eine wichtige Notiz')
        ->call('addNote');

    $activity = $lead->activities()->first();
    expect($activity->type)->toBe(LeadActivityTypeEnum::Note);
});

it('clears note field after submission', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->set('newNote', 'Wird geloescht nach Submit')
        ->call('addNote')
        ->assertSet('newNote', '');
});

it('validates note is not empty', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->set('newNote', '')
        ->call('addNote');

    expect($lead->activities()->count())->toBe(0);
});

it('does not create activity for whitespace-only note', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->set('newNote', '   ')
        ->call('addNote');

    expect($lead->activities()->count())->toBe(0);
});

// === MARK AS LOST ===

it('can mark lead as lost with reason', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsLost', 'Kein Budget')
        ->assertOk();

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatusEnum::Lost)
        ->and($lead->lost_reason)->toBe('Kein Budget');
});

it('updates lead status to Lost', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsLost');

    expect($lead->refresh()->status)->toBe(LeadStatusEnum::Lost);
});

it('sets lost_at timestamp', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsLost', 'Reason');

    expect($lead->refresh()->lost_at)->not->toBeNull();
});

it('saves lost_reason', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsLost', 'Entscheidung fuer Mitbewerber');

    expect($lead->refresh()->lost_reason)->toBe('Entscheidung fuer Mitbewerber');
});

it('creates activity with type Updated when marking as lost', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsLost', 'Kein Budget');

    $activity = $lead->activities()->latest('id')->first();
    expect($activity->type)->toBe(LeadActivityTypeEnum::Updated)
        ->and($activity->description)->toContain('verloren')
        ->and($activity->description)->toContain('Kein Budget');
});

it('dispatches phase-updated after marking as lost', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsLost', 'Kein Budget')
        ->assertDispatched('phase-updated', phaseId: $phase->getKey());
});

// === CLOSE ===

it('can close the modal', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertSet('isOpen', true)
        ->call('closeModal')
        ->assertSet('isOpen', false);
});

it('resets state when closed', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->set('newNote', 'Partial note')
        ->call('closeModal')
        ->assertSet('leadId', null)
        ->assertSet('lead', null)
        ->assertSet('isOpen', false)
        ->assertSet('newNote', '');
});

// === EDGE CASES ===

it('handles lead with no email gracefully', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'email'                      => null,
        'phone'                      => '+49 170 1234567',
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertOk()
        ->assertSee($lead->name)
        ->assertSee('+49 170 1234567');
});

it('handles lead with no phone gracefully', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'email'                      => 'test@example.com',
        'phone'                      => null,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertOk()
        ->assertSee('test@example.com');
});

it('handles lead with no custom fields', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertOk()
        ->assertDontSee('Felder');
});

it('handles lead with no activities', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->assertOk()
        ->assertDontSee('Aktivitaeten');
});

it('handles lead with very long note text', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);

    $longNote = str_repeat('Dieser Text ist lang. ', 200);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->set('newNote', $longNote)
        ->call('addNote')
        ->assertSet('newNote', '');

    $activity = $lead->activities()->first();
    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(LeadActivityTypeEnum::Note)
        ->and($activity->description)->toBe($longNote);
});

it('handles marking as lost without reason', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
        'status'                     => LeadStatusEnum::Active,
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $lead->getKey())
        ->call('markAsLost')
        ->assertOk();

    $lead->refresh();
    expect($lead->status)->toBe(LeadStatusEnum::Lost)
        ->and($lead->lost_reason)->toBe('');
});

it('handles markAsLost when lead is null', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->call('markAsLost', 'Some reason')
        ->assertOk();
});
