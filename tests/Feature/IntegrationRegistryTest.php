<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\DTOs\IntegrationActionData;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Integrations\FakeIntegration;

it('has no integrations by default', function (): void {
    expect(FilamentLeadPipelinePlugin::make()->getIntegrations())->toBe([]);
});

it('registers integrations fluently and resolves them keyed by their key', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make()->integrations([FakeIntegration::class]);

    expect($plugin->getIntegrations())->toHaveKey('fake')
        ->and($plugin->getIntegrations()['fake'])->toBeInstanceOf(FakeIntegration::class)
        ->and($plugin->getIntegration('fake'))->toBeInstanceOf(FakeIntegration::class)
        ->and($plugin->getIntegration('missing'))->toBeNull();
});

it('memoizes resolved integration instances', function (): void {
    $plugin = FilamentLeadPipelinePlugin::make()->integrations([FakeIntegration::class]);

    expect($plugin->getIntegration('fake'))->toBe($plugin->getIntegration('fake'));
});

it('exposes action metadata with defaults through the data object', function (): void {
    $action = new IntegrationActionData(key: 'ping', label: 'Ping', icon: 'heroicon-o-phone');

    expect($action->color)->toBe('gray')
        ->and($action->requiresConfirmation)->toBeFalse()
        ->and($action->confirmText)->toBeNull();
});

it('maps action colors to pre-compiled button classes', function (): void {
    expect((new IntegrationActionData(key: 'k', label: 'L', icon: 'i', color: 'success'))->buttonClasses())->toContain('border-green-300')
        ->and((new IntegrationActionData(key: 'k', label: 'L', icon: 'i', color: 'danger'))->buttonClasses())->toContain('border-red-300')
        ->and((new IntegrationActionData(key: 'k', label: 'L', icon: 'i', color: 'warning'))->buttonClasses())->toContain('border-amber-300')
        ->and((new IntegrationActionData(key: 'k', label: 'L', icon: 'i', color: 'primary'))->buttonClasses())->toContain('border-blue-300')
        ->and((new IntegrationActionData(key: 'k', label: 'L', icon: 'i'))->buttonClasses())->toContain('border-gray-300');
});
