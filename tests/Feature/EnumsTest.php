<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;

/* LeadPhaseTypeEnum */

it('flags Won and Lost phases as terminal', function (): void {
    expect(LeadPhaseTypeEnum::Won->isTerminal())->toBeTrue()
        ->and(LeadPhaseTypeEnum::Lost->isTerminal())->toBeTrue()
        ->and(LeadPhaseTypeEnum::Open->isTerminal())->toBeFalse()
        ->and(LeadPhaseTypeEnum::InProgress->isTerminal())->toBeFalse();
});

it('maps terminal phase types to the list display as default', function (): void {
    expect(LeadPhaseTypeEnum::Won->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::List)
        ->and(LeadPhaseTypeEnum::Lost->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::List)
        ->and(LeadPhaseTypeEnum::Open->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::Kanban)
        ->and(LeadPhaseTypeEnum::InProgress->defaultDisplayType())->toBe(LeadPhaseDisplayTypeEnum::Kanban);
});

it('returns distinct colors per phase type', function (): void {
    $colors = collect(LeadPhaseTypeEnum::cases())->map(fn (LeadPhaseTypeEnum $c) => $c->getColor());

    expect($colors->unique()->count())->toBe(count(LeadPhaseTypeEnum::cases()));
    expect(LeadPhaseTypeEnum::Won->getColor())->toBe('success')
        ->and(LeadPhaseTypeEnum::Lost->getColor())->toBe('danger');
});

/* LeadStatusEnum */

it('assigns every LeadStatusEnum a non-empty label', function (): void {
    foreach (LeadStatusEnum::cases() as $status) {
        expect($status->getLabel())->toBeString()->not->toBeEmpty();
    }
});

/* LeadSourceStatusEnum */

it('assigns every LeadSourceStatusEnum a non-empty label and color', function (): void {
    foreach (LeadSourceStatusEnum::cases() as $status) {
        expect($status->getLabel())->toBeString()->not->toBeEmpty()
            ->and($status->getColor())->toBeString()->not->toBeEmpty();
    }
});
