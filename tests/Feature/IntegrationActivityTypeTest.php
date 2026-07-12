<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;

it('exposes the integration activity type', function (): void {
    $case = LeadActivityTypeEnum::Integration;

    expect($case->value)->toBe('integration')
        ->and($case->getColor())->toBe('info')
        ->and($case->getIcon())->toBe('heroicon-o-puzzle-piece');
});

it('translates the integration activity label in every locale', function (): void {
    foreach (['de', 'en', 'fr'] as $locale) {
        expect(trans('lead-pipeline::lead-pipeline.activity.integration', [], $locale))
            ->not->toBe('lead-pipeline::lead-pipeline.activity.integration');
    }
});

it('translates the integrations ui group in every locale', function (): void {
    foreach (['de', 'en', 'fr'] as $locale) {
        foreach (['title', 'active', 'inactive', 'action_success', 'action_failed', 'confirm_action'] as $key) {
            expect(trans("lead-pipeline::lead-pipeline.integrations.{$key}", [], $locale))
                ->not->toBe("lead-pipeline::lead-pipeline.integrations.{$key}");
        }
    }
});
