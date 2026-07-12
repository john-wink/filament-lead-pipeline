<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\LeadDetailModal;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use Livewire\Livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $this->lead = Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);
});

it('shows integration action buttons for activated integrations', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee('Fake anrufen');
});

it('hides integration action buttons when the integration is not activated', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertDontSee('Fake anrufen');
});

it('renders wire:confirm when the action requires confirmation', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_confirm', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSeeHtml('wire:confirm');
});

it('runs an integration action, logs the activity and notifies success', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('runIntegrationAction', 'fake', 'ping')
        ->assertOk()
        ->assertNotified(__('lead-pipeline::lead-pipeline.integrations.action_success'));

    $activity = $this->lead->activities()->latest('id')->first();

    expect($activity)->not->toBeNull()
        ->and($activity->type)->toBe(LeadActivityTypeEnum::Integration)
        ->and($activity->properties['integration'])->toBe('fake')
        ->and($activity->properties['action'])->toBe('ping');
});

it('surfaces integration failures as a danger notification', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_throws', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('runIntegrationAction', 'fake', 'ping')
        ->assertOk()
        ->assertNotified(__('lead-pipeline::lead-pipeline.integrations.action_failed'));

    expect($this->lead->activities()->where('type', LeadActivityTypeEnum::Integration->value)->count())->toBe(0);
});

it('ignores actions of deactivated or unknown integrations', function (): void {
    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('runIntegrationAction', 'fake', 'ping')
        ->call('runIntegrationAction', 'unknown', 'ping')
        ->assertOk();

    expect($this->lead->activities()->where('type', LeadActivityTypeEnum::Integration->value)->count())->toBe(0);
});

it('keeps the modal rendering when an integration isActivatedFor check throws', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_activation_throws', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertOk()
        ->assertDontSee('Fake anrufen');
});

it('keeps the modal rendering when an integration leadModalActions call throws', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_actions_throws', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertOk()
        ->assertDontSee('Fake anrufen');
});

it('rejects an actionKey that is not offered by the integration', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->call('runIntegrationAction', 'fake', 'not-offered')
        ->assertOk()
        ->assertNotified(__('lead-pipeline::lead-pipeline.integrations.action_failed'));

    expect($this->lead->activities()->where('type', LeadActivityTypeEnum::Integration->value)->count())->toBe(0);
});

it('hides the integration block entirely when the integration offers no actions', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_active', true);
    config()->set('lead-pipeline.testing.fake_integration_no_actions', true);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertOk()
        ->assertDontSee('Fake anrufen');
});
