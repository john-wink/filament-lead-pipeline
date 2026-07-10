<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use JohnWink\FilamentLeadPipeline\Livewire\AdvisorScorecardPanel;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::advisor-scorecard-panel', AdvisorScorecardPanel::class);
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('opens on the event and shows scorecard plus protocol', function (): void {
    $advisor = User::factory()->create(['first_name' => 'Panel', 'last_name' => 'Berater']);
    $board   = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->user->id]);
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    $lead  = Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $advisor->id]);
    $lead->activities()->create([
        'type'        => JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Call->value,
        'description' => 'Testanruf',
        'causer_type' => config('lead-pipeline.user_model'),
        'causer_id'   => $advisor->id,
    ]);

    Livewire::test(AdvisorScorecardPanel::class, ['boardId' => (string) $board->getKey(), 'preset' => 'all'])
        ->dispatch('open-advisor-scorecard', advisorId: (string) $advisor->id)
        ->assertSet('isOpen', true)
        ->assertSee('Panel Berater')
        ->assertSee('Testanruf');
});

it('closes and resets', function (): void {
    // Self-open (not a foreign id): non-leadership self-access must stay allowed
    // by the Task 12 guard — this test only verifies close()/reset behaviour.
    Livewire::test(AdvisorScorecardPanel::class, ['preset' => 'all'])
        ->dispatch('open-advisor-scorecard', advisorId: (string) $this->user->id)
        ->call('close')
        ->assertSet('isOpen', false);
});
