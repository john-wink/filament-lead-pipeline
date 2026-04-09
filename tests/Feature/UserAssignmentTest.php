<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Livewire\LeadCard;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
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

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->open()->create(['sort' => 0]);
});

it('returns assignable users based on configured query', function (): void {
    $result = FilamentLeadPipelinePlugin::getAssignableUsers();

    expect($result)->toBeInstanceOf(Illuminate\Support\Collection::class)
        ->and($result->count())->toBeGreaterThanOrEqual(1)
        ->and($result->first()->display_label)->toBeString()
        ->and($result->first()->display_label)->toContain('(');
});

it('filters users through assignable query modifier', function (): void {
    $included = User::factory()->create(['first_name' => 'Included', 'last_name' => 'User']);
    $excluded = User::factory()->create(['first_name' => 'Excluded', 'last_name' => 'User']);

    $plugin = FilamentLeadPipelinePlugin::get();
    $plugin->assignableUsersQuery(fn (Builder $query) => $query->where('first_name', 'Included'));

    $result = FilamentLeadPipelinePlugin::getAssignableUsers();

    expect($result)->toHaveCount(1)
        ->and($result->first()->first_name)->toBe('Included');

    // Clean up: reset the modifier
    $plugin->assignableUsersQuery(null);
});

it('can assign a user to a lead via card', function (): void {
    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
    ]);
    $assignee = User::factory()->create();

    Livewire::test(LeadCard::class, ['leadId' => $lead->getKey()])
        ->call('assignUser', $assignee->getKey());

    expect($lead->refresh()->assigned_to)->toBe($assignee->getKey());
});

it('can assign a user via detail modal', function (): void {
    $lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
    ]);
    $assignee = User::factory()->create();

    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $lead->getKey())
        ->call('assignUser', $assignee->getKey());

    expect($lead->refresh()->assigned_to)->toBe($assignee->getKey());
});

it('can remove user assignment via detail modal', function (): void {
    $assignee = User::factory()->create();
    $lead     = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $this->board->getKey(),
        Lead::fkColumn('lead_phase') => $this->phase->getKey(),
        'assigned_to'                => $assignee->getKey(),
    ]);

    Livewire::test(LeadDetailModal::class)
        ->call('openModal', $lead->getKey())
        ->call('assignUser', '');

    expect($lead->refresh()->assigned_to)->toBeNull();
});
