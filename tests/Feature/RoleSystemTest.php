<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Livewire\KanbanPhaseColumn;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\User;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
});

it('can add admin to board', function (): void {
    $admin = User::factory()->create();
    $this->board->admins()->attach($admin->getKey());

    expect($this->board->isAdmin($admin))->toBeTrue();
});

it('returns false for non-admin user', function (): void {
    $user = User::factory()->create();

    expect($this->board->isAdmin($user))->toBeFalse();
});

it('identifies advisor as non-admin user', function (): void {
    $admin   = User::factory()->create();
    $advisor = User::factory()->create();
    $this->board->admins()->attach($admin->getKey());

    expect($this->board->isAdmin($advisor))->toBeFalse()
        ->and($this->board->isAdvisor($advisor))->toBeTrue();
});

it('does not count admin as advisor', function (): void {
    $admin = User::factory()->create();
    $this->board->admins()->attach($admin->getKey());

    expect($this->board->isAdvisor($admin))->toBeFalse();
});

it('admin sees all leads in phase', function (): void {
    $admin = User::factory()->create();
    $this->actingAs($admin);
    $this->board->admins()->attach($admin->getKey());

    $phase = LeadPhase::factory()->for($this->board, 'board')->create();
    Lead::factory()->for($phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Own Lead', 'assigned_to' => $admin->getKey()]);
    Lead::factory()->for($phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Other Lead', 'assigned_to' => User::factory()->create()->getKey()]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertSee('Own Lead')
        ->assertSee('Other Lead');
});

it('advisor sees only own leads, not unassigned or others', function (): void {
    $advisor = User::factory()->create();
    $this->actingAs($advisor);
    // Board must have at least one admin for visibility filter to activate
    $this->board->admins()->attach(User::factory()->create()->getKey());

    $phase = LeadPhase::factory()->for($this->board, 'board')->create();
    Lead::factory()->for($phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'My Lead', 'assigned_to' => $advisor->getKey()]);
    Lead::factory()->for($phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Not My Lead', 'assigned_to' => User::factory()->create()->getKey()]);
    Lead::factory()->for($phase, 'phase')->for($this->board, 'board')
        ->create(['name' => 'Unassigned Lead', 'assigned_to' => null]);

    Livewire::test(KanbanPhaseColumn::class, ['phaseId' => $phase->getKey()])->call('init')
        ->assertSee('My Lead')
        ->assertDontSee('Unassigned Lead')
        ->assertDontSee('Not My Lead');
});
