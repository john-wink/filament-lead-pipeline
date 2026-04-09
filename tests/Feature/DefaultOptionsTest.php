<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\DTOs\FieldPresetData;
use JohnWink\FilamentLeadPipeline\DTOs\PhasePresetData;
use JohnWink\FilamentLeadPipeline\DTOs\SourcePresetData;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;

it('stores and retrieves default phases', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make()
        ->defaultPhases([
            PhasePresetData::from([
                'name'         => 'Neu',
                'type'         => LeadPhaseTypeEnum::Open,
                'display_type' => LeadPhaseDisplayTypeEnum::Kanban,
            ]),
        ]);

    expect($plugin->getDefaultPhases())->toHaveCount(1);
    expect($plugin->getDefaultPhases()[0]->name)->toBe('Neu');
});

it('stores and retrieves default fields', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make()
        ->defaultFields([
            FieldPresetData::from([
                'name' => 'Budget',
                'key'  => 'budget',
                'type' => LeadFieldTypeEnum::Currency,
            ]),
        ]);

    expect($plugin->getDefaultFields())->toHaveCount(1);
    expect($plugin->getDefaultFields()[0]->key)->toBe('budget');
});

it('stores and retrieves default sources', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make()
        ->defaultSources([
            SourcePresetData::from([
                'name'   => 'Website Funnel',
                'driver' => 'funnel',
            ]),
        ]);

    expect($plugin->getDefaultSources())->toHaveCount(1);
    expect($plugin->getDefaultSources()[0]->driver)->toBe('funnel');
});
