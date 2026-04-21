<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Drivers\ApiDriver;
use JohnWink\FilamentLeadPipeline\Drivers\MetaDriver;
use JohnWink\FilamentLeadPipeline\Services\LeadSourceManager;

it('resolves a registered driver as the configured concrete class', function (): void {
    $manager = new LeadSourceManager();

    expect($manager->getDriver('api'))->toBeInstanceOf(ApiDriver::class)
        ->and($manager->getDriver('meta'))->toBeInstanceOf(MetaDriver::class);
});

it('memoizes the resolved driver instance', function (): void {
    $manager = new LeadSourceManager();

    expect($manager->getDriver('api'))->toBe($manager->getDriver('api'));
});

it('throws when an unknown driver name is requested', function (): void {
    expect(fn () => (new LeadSourceManager())->getDriver('does-not-exist'))
        ->toThrow(InvalidArgumentException::class, 'is not registered');
});

it('throws when a registered driver class does not exist', function (): void {
    $manager = new LeadSourceManager();
    $manager->registerDriver('ghost', 'App\\Drivers\\Ghost');

    expect(fn () => $manager->getDriver('ghost'))
        ->toThrow(InvalidArgumentException::class, 'does not exist');
});

it('lists all available drivers from config', function (): void {
    $drivers = (new LeadSourceManager())->getAvailableDrivers();

    expect($drivers)
        ->toHaveKey('api')
        ->toHaveKey('meta')
        ->toHaveKey('zapier')
        ->toHaveKey('funnel');
});
