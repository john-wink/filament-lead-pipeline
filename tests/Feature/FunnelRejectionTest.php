<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\FunnelFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Livewire\FunnelWizard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStep;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnelStepField;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Livewire\Livewire;

beforeEach(function (): void {
    Livewire::component('lead-pipeline::funnel-wizard', FunnelWizard::class);

    $this->board  = LeadBoard::factory()->create();
    $this->phase  = LeadPhase::factory()->for($this->board, 'board')->create();
    $this->source = LeadSource::factory()->for($this->board, 'board')->funnel()->active()->create();
    $this->funnel = LeadFunnel::factory()->create([
        LeadFunnel::fkColumn('lead_source') => $this->source->getKey(),
        LeadFunnel::fkColumn('lead_board')  => $this->board->getKey(),
        LeadFunnel::fkColumn('lead_phase')  => $this->phase->getKey(),
        'rejection_config'                  => [
            'heading' => 'Leider nicht möglich',
            'text'    => 'Wir können Ihnen kein Angebot machen.',
        ],
    ]);
});

it('rejects when less-than rule matches', function (): void {
    $def = LeadFieldDefinition::factory()->create([
        LeadFieldDefinition::fkColumn('lead_board') => $this->board->getKey(),
        'key'                                       => 'eigenkapital',
        'type'                                      => LeadFieldTypeEnum::Currency,
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $this->funnel->getKey(),
        'sort'                                  => 0,
        'step_type'                             => 'form',
        'settings'                              => [
            'show_name'        => true,
            'show_description' => true,
            'rejection_rules'  => [
                ['field_key' => 'eigenkapital', 'operator' => '<', 'value' => '3000'],
            ],
        ],
    ]);

    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $def->getKey(),
        'sort'                                                 => 0,
        'funnel_field_type'                                    => FunnelFieldTypeEnum::TextInput,
    ]);

    // Need a second step so nextStep has somewhere to go
    LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $this->funnel->getKey(),
        'sort'                                  => 1,
        'step_type'                             => 'form',
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $this->funnel->getKey()])
        ->set('formData.eigenkapital', '1500')
        ->call('nextStep')
        ->assertSet('rejected', true)
        ->assertSee('Leider nicht möglich');
});

it('does not reject when rule does not match', function (): void {
    $def = LeadFieldDefinition::factory()->create([
        LeadFieldDefinition::fkColumn('lead_board') => $this->board->getKey(),
        'key'                                       => 'eigenkapital',
        'type'                                      => LeadFieldTypeEnum::Currency,
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $this->funnel->getKey(),
        'sort'                                  => 0,
        'step_type'                             => 'form',
        'settings'                              => [
            'show_name'        => true,
            'show_description' => true,
            'rejection_rules'  => [
                ['field_key' => 'eigenkapital', 'operator' => '<', 'value' => '3000'],
            ],
        ],
    ]);

    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $def->getKey(),
        'sort'                                                 => 0,
        'funnel_field_type'                                    => FunnelFieldTypeEnum::TextInput,
    ]);

    LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $this->funnel->getKey(),
        'sort'                                  => 1,
        'step_type'                             => 'form',
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $this->funnel->getKey()])
        ->set('formData.eigenkapital', '5000')
        ->call('nextStep')
        ->assertSet('rejected', false)
        ->assertSet('currentStep', 1);
});

it('rejects with equals operator', function (): void {
    $def = LeadFieldDefinition::factory()->create([
        LeadFieldDefinition::fkColumn('lead_board') => $this->board->getKey(),
        'key'                                       => 'answer',
        'type'                                      => LeadFieldTypeEnum::String,
    ]);

    $step = LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $this->funnel->getKey(),
        'sort'                                  => 0,
        'step_type'                             => 'form',
        'settings'                              => [
            'show_name'        => true,
            'show_description' => true,
            'rejection_rules'  => [
                ['field_key' => 'answer', 'operator' => '=', 'value' => 'nein'],
            ],
        ],
    ]);

    LeadFunnelStepField::factory()->create([
        LeadFunnelStepField::fkColumn('lead_funnel_step')      => $step->getKey(),
        LeadFunnelStepField::fkColumn('lead_field_definition') => $def->getKey(),
        'sort'                                                 => 0,
        'funnel_field_type'                                    => FunnelFieldTypeEnum::TextInput,
    ]);

    LeadFunnelStep::factory()->create([
        LeadFunnelStep::fkColumn('lead_funnel') => $this->funnel->getKey(),
        'sort'                                  => 1,
        'step_type'                             => 'form',
    ]);

    Livewire::test(FunnelWizard::class, ['funnelId' => $this->funnel->getKey()])
        ->set('formData.answer', 'nein')
        ->call('nextStep')
        ->assertSet('rejected', true);
});
