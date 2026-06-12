<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->user = App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail();
    $this->actingAs($this->user);

    $this->board = LeadBoard::factory()->create();
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);

    Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        'name'                       => 'Maria Weber',
        'email'                      => 'maria@example.de',
        'phone'                      => '+49 170 1234567',
    ]);
});

it('finds duplicates by email or phone scoped to the board', function (): void {
    expect($this->board->findDuplicateLead('maria@example.de', null)?->name)->toBe('Maria Weber')
        ->and($this->board->findDuplicateLead(null, '+49 170 1234567')?->name)->toBe('Maria Weber')
        ->and($this->board->findDuplicateLead('andere@example.de', '+49 9999'))->toBeNull()
        ->and($this->board->findDuplicateLead(null, null))->toBeNull()
        ->and(LeadBoard::factory()->create()->findDuplicateLead('maria@example.de', null))->toBeNull();
});

it('warns instead of creating when the email already exists on the board', function (): void {
    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Maria W. (neu)')
        ->set('newLeadEmail', 'maria@example.de')
        ->call('createLead')
        ->assertSet('duplicateLeadName', 'Maria Weber')
        ->assertSet('showCreateModal', true)
        ->assertSee(__('lead-pipeline::lead-pipeline.lead.duplicate_warning', ['name' => 'Maria Weber']))
        ->assertSee(__('lead-pipeline::lead-pipeline.lead.create_anyway'));

    expect(Lead::query()->where('name', 'Maria W. (neu)')->exists())->toBeFalse();
});

it('creates the lead anyway after explicit confirmation', function (): void {
    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Maria W. (neu)')
        ->set('newLeadPhone', '+49 170 1234567')
        ->call('createLead')
        ->assertSet('duplicateLeadName', 'Maria Weber')
        ->call('createLead', true)
        ->assertSet('showCreateModal', false);

    expect(Lead::query()->where('name', 'Maria W. (neu)')->exists())->toBeTrue();
});

it('creates without warning when contact data is unique', function (): void {
    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Neuer Lead')
        ->set('newLeadEmail', 'neu@example.de')
        ->call('createLead')
        ->assertSet('duplicateLeadName', null)
        ->assertSet('showCreateModal', false);

    expect(Lead::query()->where('name', 'Neuer Lead')->exists())->toBeTrue();
});

it('warns on the filament page component as well', function (): void {
    Livewire::test(JohnWink\FilamentLeadPipeline\Filament\Pages\KanbanBoard::class, ['board' => $this->board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Maria W. (neu)')
        ->set('newLeadEmail', 'maria@example.de')
        ->call('createLead')
        ->assertSet('duplicateLeadName', 'Maria Weber')
        ->call('createLead', true)
        ->assertSet('showCreateModal', false);

    expect(Lead::query()->where('name', 'Maria W. (neu)')->exists())->toBeTrue();
});
