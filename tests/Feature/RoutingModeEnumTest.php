<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\RoutingModeEnum;

it('exposes manual, fixed and open cases', function (): void {
    expect(RoutingModeEnum::cases())->toHaveCount(3)
        ->and(RoutingModeEnum::Manual->value)->toBe('manual')
        ->and(RoutingModeEnum::Fixed->value)->toBe('fixed')
        ->and(RoutingModeEnum::Open->value)->toBe('open');
});

it('provides a non-empty label per case', function (): void {
    foreach (RoutingModeEnum::cases() as $case) {
        expect($case->getLabel())->toBeString()->not->toBeEmpty();
    }
});

it('flags fixed mode as auto-routing', function (): void {
    expect(RoutingModeEnum::Fixed->isAutoRouting())->toBeTrue()
        ->and(RoutingModeEnum::Manual->isAutoRouting())->toBeFalse()
        ->and(RoutingModeEnum::Open->isAutoRouting())->toBeFalse();
});
