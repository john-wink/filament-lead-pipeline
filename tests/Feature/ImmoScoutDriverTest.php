<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Drivers\ImmoScoutDriver;
use JohnWink\FilamentLeadPipeline\DTOs\WebhookPayloadData;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\LeadSourceManager;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->user  = $this->team->users->first();
    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    $this->source = LeadSource::query()->create([
        'name'                             => 'IS24 Leads',
        'driver'                           => 'immoscout24',
        'status'                           => LeadSourceStatusEnum::Active,
        LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        'team_uuid'                        => $this->team->uuid,
        'created_by'                       => $this->user->getKey(),
        'config'                           => ['immoscout_connection_uuid' => 'some-uuid'],
    ]);
});

it('registers an immoscout24 source type that resolves to the driver', function (): void {
    $case = LeadSourceTypeEnum::from('immoscout24');

    expect($case)->toBe(LeadSourceTypeEnum::ImmoScout24)
        ->and($case->getDriverClass())->toBe(ImmoScoutDriver::class)
        ->and($case->getLabel())->toBe('ImmoScout24')
        ->and(app(LeadSourceManager::class)->getDriver('immoscout24'))->toBeInstanceOf(ImmoScoutDriver::class);
});

it('requires a connection in its config', function (): void {
    $driver = app(ImmoScoutDriver::class);

    expect($driver->validateConfig([]))->toBeFalse()
        ->and($driver->validateConfig(['immoscout_connection_uuid' => 'abc']))->toBeTrue();
});

it('offers import table actions instead of webhooks', function (): void {
    $driver = app(ImmoScoutDriver::class);

    $actionNames = collect($driver->getTableActions($this->source))
        ->map(fn ($action): string => $action->getName());

    expect($actionNames)->toContain('import_leads')
        ->and($actionNames)->toContain('import_test_leads')
        ->and($driver->getWebhookUrl($this->source))->toBe('')
        ->and($driver->verifySignature('payload', 'sig', $this->source))->toBeFalse();
});

it('exposes a connection select in its config form schema', function (): void {
    $components = app(ImmoScoutDriver::class)->getConfigFormSchema();

    $names = collect($components)->map(fn ($component): string => $component->getName());

    expect($names)->toContain('config.immoscout_connection_uuid');
});

it('processes a raw lead payload through the mapper', function (): void {
    $payload = json_decode(
        (string) file_get_contents(__DIR__ . '/../Fixtures/immoscout/test-leads.json'),
        true,
    )['lead'][0];

    $data = app(ImmoScoutDriver::class)->processWebhook(
        new WebhookPayloadData(driver: 'immoscout24', source_id: (string) $this->source->getKey(), raw_payload: $payload),
        $this->source,
    );

    expect($data->name)->toBe('Max Mustermann')
        ->and($data->source_driver)->toBe('immoscout24')
        ->and($data->custom_fields['is24_financing_type'])->toBe('Kauffinanzierung');
});
