<?php

declare(strict_types=1);

use Filament\Forms\Components\Section;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;

it('returns empty extension components when no extension is registered', function (): void {
    expect(LeadBoardResource::getBoardFormExtensionComponents())->toBe([]);
});

it('flattens registered extension closures into a component list', function (): void {
    /** @var FilamentLeadPipelinePlugin $plugin */
    $plugin = filament()->getPlugin('filament-lead-pipeline');

    $sectionA = Section::make('extension-a');
    $sectionB = Section::make('extension-b');

    $plugin->extendBoardForm(fn (): array => [$sectionA]);
    $plugin->extendBoardForm(fn (): array => [$sectionB]);

    $components = LeadBoardResource::getBoardFormExtensionComponents();

    expect($components)->toHaveCount(2)
        ->and($components[0])->toBe($sectionA)
        ->and($components[1])->toBe($sectionB);
});
