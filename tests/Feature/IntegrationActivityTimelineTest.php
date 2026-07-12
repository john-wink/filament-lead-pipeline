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

it('renders integration activities through the integration hook', function (): void {
    $this->lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Integration->value,
        'description' => 'Generische Beschreibung',
        'properties'  => ['integration' => 'fake', 'result' => 'geplant'],
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee('FAKE-INTEGRATION-AKTIVITÄT')
        ->assertSee('Fake-Anruf: geplant')
        ->assertDontSee('Generische Beschreibung');
});

it('falls back to the generic entry for unknown integration keys', function (): void {
    $this->lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Integration->value,
        'description' => 'Unbekannte Integration',
        'properties'  => ['integration' => 'missing'],
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee('Unbekannte Integration')
        ->assertSee(LeadActivityTypeEnum::Integration->getLabel())
        ->assertDontSee('FAKE-INTEGRATION-AKTIVITÄT');
});

it('falls back to the generic entry when the integration renders nothing', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_renders', false);

    $this->lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Integration->value,
        'description' => 'Ohne eigene Darstellung',
        'properties'  => ['integration' => 'fake'],
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee('Ohne eigene Darstellung')
        ->assertDontSee('FAKE-INTEGRATION-AKTIVITÄT');
});

it('falls back to the generic entry when renderActivity throws', function (): void {
    config()->set('lead-pipeline.testing.fake_integration_render_throws', true);

    $this->lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Integration->value,
        'description' => 'Integration mit Fehler beim Rendern',
        'properties'  => ['integration' => 'fake'],
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee('Integration mit Fehler beim Rendern')
        ->assertSee(LeadActivityTypeEnum::Integration->getLabel())
        ->assertDontSee('FAKE-INTEGRATION-AKTIVITÄT');
});

it('falls back to the generic entry when properties carry no integration key', function (): void {
    $this->lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Integration->value,
        'description' => 'Integration ohne Key',
        'properties'  => [],
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee('Integration ohne Key');
});

it('does not touch non-integration activities', function (): void {
    $this->lead->activities()->create([
        'type'        => LeadActivityTypeEnum::Note->value,
        'description' => 'Ganz normale Notiz',
        'properties'  => ['integration' => 'fake'],
    ]);

    Livewire::test(LeadDetailModal::class)
        ->dispatch('open-lead-detail', leadId: $this->lead->getKey())
        ->assertSee('Ganz normale Notiz')
        ->assertDontSee('FAKE-INTEGRATION-AKTIVITÄT');
});
