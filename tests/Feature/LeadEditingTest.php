<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
    $this->lead  = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
    ]);
});

it('can update core lead fields', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->call('updateField', 'name', 'New Name')
        ->call('updateField', 'email', 'new@test.com')
        ->call('updateField', 'value', 5000);

    $this->lead->refresh();
    expect($this->lead->name)->toBe('New Name')
        ->and($this->lead->email)->toBe('new@test.com')
        ->and((float) $this->lead->value)->toBe(5000.0);
});

it('validates core field updates', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->call('updateField', 'email', 'invalid-email')
        ->assertHasErrors();
});

it('can update custom field values', function (): void {
    $def = LeadFieldDefinition::factory()
        ->for($this->board, 'board')
        ->create(['key' => 'test_budget', 'type' => LeadFieldTypeEnum::Currency]);

    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->call('updateCustomField', $def->getKey(), '25000');

    expect($this->lead->getFieldValue('test_budget'))->toBe(25000.0);
});

it('can mark lead as won', function (): void {
    $wonPhase = LeadPhase::factory()->for($this->board, 'board')->won()->create();

    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->call('markAsWon');

    $this->lead->refresh();
    expect($this->lead->status)->toBe(LeadStatusEnum::Won);
});

it('can change lead phase', function (): void {
    $newPhase = LeadPhase::factory()->for($this->board, 'board')->create(['name' => 'Neuer Schritt', 'sort' => 1]);

    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $this->lead->getKey())
        ->call('changePhase', $newPhase->getKey());

    expect($this->lead->refresh()->phase->getKey())->toBe($newPhase->getKey());
});
