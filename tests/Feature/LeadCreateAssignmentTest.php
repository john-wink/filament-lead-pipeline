<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use JohnWink\FilamentLeadPipeline\Livewire\KanbanBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::kanban-board', KanbanBoard::class);

    $this->team    = Team::query()->firstWhere('slug', 'test');
    $this->admin   = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Min']);
    $this->advisor = User::factory()->create(['first_name' => 'Ava', 'last_name' => 'Visor']);
    $this->team->users()->syncWithoutDetaching([$this->admin->id, $this->advisor->id]);

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);

    $this->board->admins()->syncWithoutDetaching([$this->admin->id]);
});

it('auto-assigns the creating user when they are not a board admin', function (): void {
    $this->actingAs($this->advisor);

    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Max Mustermann')
        ->call('createLead')
        ->assertOk();

    $lead = Lead::query()->firstWhere('name', 'Max Mustermann');
    expect($lead)->not->toBeNull()
        ->and($lead->assigned_to)->toBe($this->advisor->id);
});

it('uses the selected advisor when a board admin creates a lead', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Lisa Lead')
        ->set('newLeadAssignedUserId', $this->advisor->id)
        ->call('createLead')
        ->assertOk();

    $lead = Lead::query()->firstWhere('name', 'Lisa Lead');
    expect($lead)->not->toBeNull()
        ->and($lead->assigned_to)->toBe($this->advisor->id);
});

it('leaves the lead unassigned when a board admin creates without selecting an advisor', function (): void {
    $this->actingAs($this->admin);

    Livewire::test(KanbanBoard::class, ['board' => $this->board])
        ->call('openCreateModal')
        ->set('newLeadName', 'Kai Kunde')
        ->call('createLead')
        ->assertOk();

    $lead = Lead::query()->firstWhere('name', 'Kai Kunde');
    expect($lead)->not->toBeNull()
        ->and($lead->assigned_to)->toBeNull();
});

it('lets a board admin pick themselves as lead advisor', function (): void {
    $this->actingAs($this->admin);

    $component = Livewire::test(KanbanBoard::class, ['board' => $this->board]);

    expect($component->instance()->advisorOptions)->toHaveKey($this->admin->id);

    $component
        ->call('openCreateModal')
        ->set('newLeadName', 'Olga Owner')
        ->set('newLeadAssignedUserId', $this->admin->id)
        ->call('createLead')
        ->assertOk();

    $lead = Lead::query()->firstWhere('name', 'Olga Owner');
    expect($lead->assigned_to)->toBe($this->admin->id);
});

it('does not offer other board admins as lead advisors', function (): void {
    $secondAdmin = User::factory()->create(['first_name' => 'Alternative', 'last_name' => 'Admin']);
    $this->team->users()->syncWithoutDetaching([$secondAdmin->id]);
    $this->board->admins()->syncWithoutDetaching([$secondAdmin->id]);

    $this->actingAs($this->admin);

    $options = Livewire::test(KanbanBoard::class, ['board' => $this->board])->instance()->advisorOptions;

    expect($options)
        ->toHaveKey($this->admin->id)
        ->and($options)->toHaveKey($this->advisor->id)
        ->and($options)->not->toHaveKey($secondAdmin->id);
});
