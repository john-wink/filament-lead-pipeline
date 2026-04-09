<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Livewire\FunnelWizard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
use JohnWink\FilamentLeadPipeline\Models\LeadFunnel;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use Livewire\Livewire;

// === Board & Phase Tests ===

it('can create a board', function (): void {
    $board = LeadBoard::factory()->create(['name' => 'Sales Pipeline']);

    expect($board)->toBeInstanceOf(LeadBoard::class)
        ->and($board->name)->toBe('Sales Pipeline');
});

it('creates default phases with board', function (): void {
    $board = LeadBoard::factory()->withDefaultPhases()->create();

    expect($board->phases)->toHaveCount(6);
});

it('creates system fields with board', function (): void {
    $board = LeadBoard::factory()->withSystemFields()->create();

    expect($board->fieldDefinitions)->toHaveCount(3);
});

it('phases are ordered by sort', function (): void {
    $board = LeadBoard::factory()->create();
    LeadPhase::factory()->for($board, 'board')->create(['name' => 'Second', 'sort' => 2]);
    LeadPhase::factory()->for($board, 'board')->create(['name' => 'First', 'sort' => 1]);

    $phases = $board->phases()->ordered()->get();

    expect($phases->first()->name)->toBe('First')
        ->and($phases->last()->name)->toBe('Second');
});

it('board soft deletes', function (): void {
    $board = LeadBoard::factory()->create();
    $board->delete();

    expect($board->trashed())->toBeTrue()
        ->and(LeadBoard::withTrashed()->find($board->getKey()))->not->toBeNull();
});

// === Lead Tests ===

it('can create a lead with required fields', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    $lead  = Lead::factory()->for($board, 'board')->for($phase, 'phase')->create([
        'name'  => 'Max Mustermann',
        'email' => 'max@test.de',
    ]);

    expect($lead->name)->toBe('Max Mustermann')
        ->and($lead->email)->toBe('max@test.de')
        ->and($lead->status)->toBe(LeadStatusEnum::Active);
});

it('can set and get custom field values', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    $lead  = Lead::factory()->for($board, 'board')->for($phase, 'phase')->create();

    $fieldDef = LeadFieldDefinition::factory()->for($board, 'board')->create([
        'name' => 'Firma',
        'key'  => 'company',
        'type' => 'string',
    ]);

    $lead->setFieldValue($fieldDef, 'ACME GmbH');

    expect($lead->getFieldValue('company'))->toBe('ACME GmbH');
});

it('can move lead between phases', function (): void {
    $board  = LeadBoard::factory()->create();
    $phaseA = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Neu']);
    $phaseB = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Kontaktiert']);
    $lead   = Lead::factory()->for($board, 'board')->for($phaseA, 'phase')->create();

    $lead->moveToPhase($phaseB);

    $lead->refresh();

    expect($lead->phase->name)->toBe('Kontaktiert');
    expect($lead->activities()->where('type', LeadActivityTypeEnum::Moved)->count())->toBe(1);
});

it('lead has active scope', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['status' => LeadStatusEnum::Active]);
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['status' => LeadStatusEnum::Lost]);

    expect(Lead::query()->active()->count())->toBe(1);
});

// === Source Tests ===

it('can create a source in draft status', function (): void {
    $source = LeadSource::factory()->create(['status' => LeadSourceStatusEnum::Draft]);

    expect($source->isDraft())->toBeTrue()
        ->and($source->isActive())->toBeFalse();
});

it('source with board can be set to draft status', function (): void {
    $board  = LeadBoard::factory()->create();
    $source = LeadSource::factory()->create([
        'lead_board_uuid' => $board->uuid,
        'status'          => LeadSourceStatusEnum::Draft,
    ]);

    expect($source->isDraft())->toBeTrue()
        ->and($source->board)->not->toBeNull();
});

// === Webhook Tests ===

it('accepts a valid API webhook and creates a lead', function (): void {
    $board  = LeadBoard::factory()->withDefaultPhases()->create();
    $source = LeadSource::factory()->for($board, 'board')->active()->create([
        'api_token' => 'test-secret-token',
    ]);

    $response = $this->postJson(
        config('lead-pipeline.webhooks.prefix') . '/' . $source->getKey(),
        ['name'          => 'Webhook Lead', 'email' => 'webhook@test.de'],
        ['Authorization' => 'Bearer test-secret-token'],
    );

    $response->assertCreated();
    expect(Lead::query()->where('name', 'Webhook Lead')->exists())->toBeTrue();
});

it('rejects webhook with invalid token', function (): void {
    $board  = LeadBoard::factory()->withDefaultPhases()->create();
    $source = LeadSource::factory()->for($board, 'board')->active()->create([
        'api_token' => 'valid-token',
    ]);

    $response = $this->postJson(
        config('lead-pipeline.webhooks.prefix') . '/' . $source->getKey(),
        ['name'          => 'Test'],
        ['Authorization' => 'Bearer wrong-token'],
    );

    $response->assertForbidden();
});

it('rejects webhook for inactive source', function (): void {
    $board  = LeadBoard::factory()->withDefaultPhases()->create();
    $source = LeadSource::factory()->for($board, 'board')->create([
        'status'    => LeadSourceStatusEnum::Draft,
        'api_token' => 'token',
    ]);

    $response = $this->postJson(
        config('lead-pipeline.webhooks.prefix') . '/' . $source->getKey(),
        ['name'          => 'Test'],
        ['Authorization' => 'Bearer token'],
    );

    $response->assertNotFound();
});

it('webhook creates activity log', function (): void {
    $board  = LeadBoard::factory()->withDefaultPhases()->create();
    $source = LeadSource::factory()->for($board, 'board')->active()->create([
        'api_token' => 'token',
    ]);

    $this->postJson(
        config('lead-pipeline.webhooks.prefix') . '/' . $source->getKey(),
        ['name'          => 'Activity Lead', 'email' => 'activity@test.de'],
        ['Authorization' => 'Bearer token'],
    );

    $lead = Lead::query()->where('name', 'Activity Lead')->first();

    expect($lead)->not->toBeNull()
        ->and($lead->activities()->where('type', LeadActivityTypeEnum::Created)->count())->toBe(1);
});

it('webhook updates source last_received_at', function (): void {
    $board  = LeadBoard::factory()->withDefaultPhases()->create();
    $source = LeadSource::factory()->for($board, 'board')->active()->create([
        'api_token'        => 'token',
        'last_received_at' => null,
    ]);

    $this->postJson(
        config('lead-pipeline.webhooks.prefix') . '/' . $source->getKey(),
        ['name'          => 'Timestamp Lead', 'phone' => '+49 170 1234567'],
        ['Authorization' => 'Bearer token'],
    );

    expect($source->fresh()->last_received_at)->not->toBeNull();
});

// === Funnel Tests ===

it('funnel page loads for active funnel', function (): void {
    // Sicherstellen, dass die Livewire-Komponente registriert ist
    Livewire::component('lead-pipeline::funnel-wizard', FunnelWizard::class);

    $board  = LeadBoard::factory()->withDefaultPhases()->create();
    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    LeadFunnel::factory()->for($source, 'source')->for($board, 'board')->create([
        'slug'      => 'test-funnel',
        'is_active' => true,
    ]);

    $prefix   = config('lead-pipeline.funnel.route_prefix', 'funnel');
    $response = $this->get('/' . ($prefix ? $prefix . '/' : '') . 'test-funnel');

    $response->assertOk();
});

it('funnel page returns 404 for inactive funnel', function (): void {
    $board  = LeadBoard::factory()->create();
    $source = LeadSource::factory()->for($board, 'board')->funnel()->create();
    LeadFunnel::factory()->for($source, 'source')->for($board, 'board')->create([
        'slug'      => 'inactive-funnel',
        'is_active' => false,
    ]);

    $prefix   = config('lead-pipeline.funnel.route_prefix', 'funnel');
    $response = $this->get('/' . ($prefix ? $prefix . '/' : '') . 'inactive-funnel');

    $response->assertNotFound();
});

it('funnel view increments view count', function (): void {
    $board  = LeadBoard::factory()->withDefaultPhases()->create();
    $source = LeadSource::factory()->for($board, 'board')->funnel()->active()->create();
    $funnel = LeadFunnel::factory()->for($source, 'source')->for($board, 'board')->create([
        'slug'        => 'view-count-test',
        'is_active'   => true,
        'views_count' => 0,
    ]);

    $prefix = config('lead-pipeline.funnel.route_prefix', 'funnel');
    $url    = '/' . ($prefix ? $prefix . '/' : '') . 'view-count-test';

    $this->get($url);
    expect($funnel->fresh()->views_count)->toBe(1);

    $this->get($url);
    expect($funnel->fresh()->views_count)->toBe(2);
});

// === Enum Tests ===

it('LeadPhaseTypeEnum identifies terminal phases', function (): void {
    expect(LeadPhaseTypeEnum::Won->isTerminal())->toBeTrue()
        ->and(LeadPhaseTypeEnum::Lost->isTerminal())->toBeTrue()
        ->and(LeadPhaseTypeEnum::Open->isTerminal())->toBeFalse()
        ->and(LeadPhaseTypeEnum::InProgress->isTerminal())->toBeFalse();
});

it('LeadStatusEnum has all expected cases', function (): void {
    expect(LeadStatusEnum::cases())->toHaveCount(4);
});

// === Relationship Tests ===

it('board has many leads through phases', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    Lead::factory()->count(3)->for($board, 'board')->for($phase, 'phase')->create();

    expect($board->leads)->toHaveCount(3);
});

it('lead belongs to source', function (): void {
    $board  = LeadBoard::factory()->create();
    $phase  = LeadPhase::factory()->for($board, 'board')->create();
    $source = LeadSource::factory()->for($board, 'board')->create();
    $lead   = Lead::factory()->for($board, 'board')->for($phase, 'phase')->for($source, 'source')->create();

    expect($lead->source)->toBeInstanceOf(LeadSource::class);
});

it('field values are linked to definitions', function (): void {
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    $lead  = Lead::factory()->for($board, 'board')->for($phase, 'phase')->create();
    $def   = LeadFieldDefinition::factory()->for($board, 'board')->create(['key' => 'test_field', 'type' => 'string']);

    $lead->setFieldValue($def, 'Test Value');

    $fieldValue = $lead->fieldValues()->first();

    expect($fieldValue)->not->toBeNull()
        ->and($fieldValue->definition->key)->toBe('test_field')
        ->and($fieldValue->value)->toBe('Test Value');
});
