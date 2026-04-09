<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

it('has kanban and list display types', function (): void {
    expect(LeadPhaseDisplayTypeEnum::cases())->toHaveCount(2);
    expect(LeadPhaseDisplayTypeEnum::Kanban->value)->toBe('kanban');
    expect(LeadPhaseDisplayTypeEnum::List->value)->toBe('list');
});

it('maps phase types to default display types', function (): void {
    expect(LeadPhaseTypeEnum::Open->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::Kanban);
    expect(LeadPhaseTypeEnum::InProgress->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::Kanban);
    expect(LeadPhaseTypeEnum::Won->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::List);
    expect(LeadPhaseTypeEnum::Lost->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::List);
});

it('filters phases by display type', function (): void {
    $board   = LeadBoard::factory()->create();
    $boardFk = LeadBoard::fkColumn('lead_board');

    LeadPhase::query()->create([
        $boardFk       => $board->getKey(),
        'name'         => 'Neu',
        'type'         => LeadPhaseTypeEnum::Open,
        'display_type' => LeadPhaseDisplayTypeEnum::Kanban,
        'sort'         => 0,
    ]);

    LeadPhase::query()->create([
        $boardFk       => $board->getKey(),
        'name'         => 'Gewonnen',
        'type'         => LeadPhaseTypeEnum::Won,
        'display_type' => LeadPhaseDisplayTypeEnum::List,
        'sort'         => 1,
    ]);

    expect($board->phases()->kanban()->count())->toBe(1);
    expect($board->phases()->list()->count())->toBe(1);
});
