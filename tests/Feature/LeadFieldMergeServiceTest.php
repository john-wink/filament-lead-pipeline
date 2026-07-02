<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadBoardStructureChanged;
use JohnWink\FilamentLeadPipeline\Exceptions\InvalidFieldMergeException;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Services\LeadFieldMergeService;

function createMergeBoard(): array
{
    $board = LeadBoard::factory()->create();
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create(['sort' => 0]);

    $source = $board->fieldDefinitions()->create([
        'key'  => 'branche',
        'name' => 'Branche',
        'type' => LeadFieldTypeEnum::Select,
        'sort' => 3,
    ]);

    $target = $board->fieldDefinitions()->create([
        'key'  => 'contact_team_type',
        'name' => 'Team-Typ',
        'type' => LeadFieldTypeEnum::Select,
        'sort' => 10,
    ]);

    return [$board, $phase, $source, $target];
}

function createMergeLead(LeadBoard $board, LeadPhase $phase): Lead
{
    return Lead::factory()->create([
        Lead::fkColumn('lead_board') => $board->getKey(),
        Lead::fkColumn('lead_phase') => $phase->getKey(),
    ]);
}

it('moves source values to the target when the lead has no target value', function (): void {
    [$board, $phase, $source, $target] = createMergeBoard();

    $lead = createMergeLead($board, $phase);
    $lead->setFieldValue($source, 'Makler');

    $result = app(LeadFieldMergeService::class)->merge($source, $target);

    expect($lead->refresh()->getFieldValue('contact_team_type'))->toBe('Makler')
        ->and($lead->getFieldValue('branche'))->toBeNull()
        ->and($result->moved)->toBe(1)
        ->and($result->conflicts)->toBe(0);
});

it('translates values through the optional value map while moving', function (): void {
    [$board, $phase, $source, $target] = createMergeBoard();

    $lead = createMergeLead($board, $phase);
    $lead->setFieldValue($source, 'Bauträger');

    app(LeadFieldMergeService::class)->merge($source, $target, ['Bauträger' => 'Immo']);

    expect($lead->refresh()->getFieldValue('contact_team_type'))->toBe('Immo');
});

it('keeps the target value on conflict and documents the dropped value as activity', function (): void {
    [$board, $phase, $source, $target] = createMergeBoard();

    $lead = createMergeLead($board, $phase);
    $lead->setFieldValue($source, 'Leadagentur');
    $lead->setFieldValue($target, 'Makler');

    $result = app(LeadFieldMergeService::class)->merge($source, $target);

    expect($lead->refresh()->getFieldValue('contact_team_type'))->toBe('Makler')
        ->and($result->conflicts)->toBe(1)
        ->and($lead->activities()->where('description', 'like', '%Leadagentur%')->exists())->toBeTrue();
});

it('deduplicates identical values without logging a conflict', function (): void {
    [$board, $phase, $source, $target] = createMergeBoard();

    $lead = createMergeLead($board, $phase);
    $lead->setFieldValue($source, 'Makler');
    $lead->setFieldValue($target, 'Makler');

    $result = app(LeadFieldMergeService::class)->merge($source, $target);

    expect($lead->refresh()->getFieldValue('contact_team_type'))->toBe('Makler')
        ->and($result->deduplicated)->toBe(1)
        ->and($result->conflicts)->toBe(0)
        ->and($lead->activities()->count())->toBe(0);
});

it('soft deletes the source definition after merging', function (): void {
    [, , $source, $target] = createMergeBoard();

    app(LeadFieldMergeService::class)->merge($source, $target);

    expect($source->refresh()->trashed())->toBeTrue()
        ->and($target->refresh()->trashed())->toBeFalse();
});

it('dispatches a structure change audit event', function (): void {
    [$board, $phase, $source, $target] = createMergeBoard();

    $lead = createMergeLead($board, $phase);
    $lead->setFieldValue($source, 'Makler');

    Event::fake([LeadBoardStructureChanged::class]);

    app(LeadFieldMergeService::class)->merge($source, $target);

    Event::assertDispatched(
        LeadBoardStructureChanged::class,
        fn (LeadBoardStructureChanged $event): bool => 'field.merged' === $event->change
            && 'branche' === $event->details['source_key'],
    );
});

it('rejects merging a definition into itself', function (): void {
    [, , $source] = createMergeBoard();

    app(LeadFieldMergeService::class)->merge($source, $source);
})->throws(InvalidFieldMergeException::class);

it('rejects merging definitions of different boards', function (): void {
    [, , $source]          = createMergeBoard();
    [, , , $foreignTarget] = createMergeBoard();

    app(LeadFieldMergeService::class)->merge($source, $foreignTarget);
})->throws(InvalidFieldMergeException::class);

it('rejects merging system fields', function (): void {
    [$board, , , $target] = createMergeBoard();

    $systemField = $board->fieldDefinitions()->where('key', 'name')->firstOrFail();

    app(LeadFieldMergeService::class)->merge($systemField, $target);
})->throws(InvalidFieldMergeException::class);

it('merges values across many leads in one call', function (): void {
    [$board, $phase, $source, $target] = createMergeBoard();

    $leadA = createMergeLead($board, $phase);
    $leadB = createMergeLead($board, $phase);
    $leadC = createMergeLead($board, $phase);

    $leadA->setFieldValue($source, 'Makler');
    $leadB->setFieldValue($source, 'Bank');
    $leadB->setFieldValue($target, 'Sales');
    $leadC->setFieldValue($target, 'Immo');

    $result = app(LeadFieldMergeService::class)->merge($source, $target);

    expect($leadA->refresh()->getFieldValue('contact_team_type'))->toBe('Makler')
        ->and($leadB->refresh()->getFieldValue('contact_team_type'))->toBe('Sales')
        ->and($leadC->refresh()->getFieldValue('contact_team_type'))->toBe('Immo')
        ->and($result->moved)->toBe(1)
        ->and($result->conflicts)->toBe(1);
});

// === MERGE IN SYSTEM-FELDER (Standard-Spalten name/email/phone) ===

function createSystemMergeSetup(string $systemKey = 'email'): array
{
    [$board, $phase] = createMergeBoard();

    $source = $board->fieldDefinitions()->create([
        'key'  => 'company_mail',
        'name' => 'Company Mail',
        'type' => LeadFieldTypeEnum::Email,
        'sort' => 5,
    ]);

    $systemTarget = $board->fieldDefinitions()->where('key', $systemKey)->firstOrFail();

    return [$board, $phase, $source, $systemTarget];
}

it('merges a custom field into the email system column', function (): void {
    [$board, $phase, $source, $systemTarget] = createSystemMergeSetup();

    $lead = createMergeLead($board, $phase);
    $lead->update(['email' => null]);
    $lead->setFieldValue($source, 'info@firma.de');

    $result = app(LeadFieldMergeService::class)->merge($source, $systemTarget);

    expect($lead->refresh()->email)->toBe('info@firma.de')
        ->and($lead->getFieldValue('company_mail'))->toBeNull()
        ->and($source->refresh()->trashed())->toBeTrue()
        ->and($result->moved)->toBe(1);
});

it('keeps the lead column on conflict and documents the dropped value', function (): void {
    [$board, $phase, $source, $systemTarget] = createSystemMergeSetup();

    $lead = createMergeLead($board, $phase);
    $lead->update(['email' => 'bestand@firma.de']);
    $lead->setFieldValue($source, 'anders@firma.de');

    $result = app(LeadFieldMergeService::class)->merge($source, $systemTarget);

    expect($lead->refresh()->email)->toBe('bestand@firma.de')
        ->and($result->conflicts)->toBe(1)
        ->and($lead->activities()->where('description', 'like', '%anders@firma.de%')->exists())->toBeTrue();
});

it('deduplicates identical system column values silently', function (): void {
    [$board, $phase, $source, $systemTarget] = createSystemMergeSetup();

    $lead = createMergeLead($board, $phase);
    $lead->update(['email' => 'info@firma.de']);
    $lead->setFieldValue($source, 'info@firma.de');

    $result = app(LeadFieldMergeService::class)->merge($source, $systemTarget);

    expect($result->deduplicated)->toBe(1)
        ->and($result->conflicts)->toBe(0)
        ->and($lead->activities()->count())->toBe(0);
});

it('truncates oversized values when merging into the phone column', function (): void {
    [$board, $phase, $source, $systemTarget] = createSystemMergeSetup('phone');

    $lead = createMergeLead($board, $phase);
    $lead->update(['phone' => null]);
    $lead->setFieldValue($source, mb_str_pad('+4915150275108', 60, '9'));

    app(LeadFieldMergeService::class)->merge($source, $systemTarget);

    expect(mb_strlen((string) $lead->refresh()->phone))->toBe(50);
});

it('still rejects a system field as merge source', function (): void {
    [$board, , , $systemTarget] = createSystemMergeSetup();

    $systemSource = $board->fieldDefinitions()->where('key', 'name')->firstOrFail();

    app(LeadFieldMergeService::class)->merge($systemSource, $systemTarget);
})->throws(InvalidFieldMergeException::class);

// === FACEBOOK/SOURCE-MAPPINGS ===

it('rewrites custom field mappings of board sources to the merge target', function (): void {
    [$board, , $source, $target] = createMergeBoard();

    $leadSource = LeadSource::factory()->create([
        LeadBoard::fkColumn('lead_board') => $board->getKey(),
        'config'                          => [
            'custom_field_mapping' => ['branche_auswahl' => 'branche', 'other' => 'plz'],
        ],
    ]);

    $result = app(LeadFieldMergeService::class)->merge($source, $target);

    $config = $leadSource->refresh()->config;

    expect($config['custom_field_mapping'])->toBe(['branche_auswahl' => 'contact_team_type', 'other' => 'plz'])
        ->and($result->sourcesUpdated)->toBe(1);
});

it('moves facebook mappings into the core field mapping when merging into a system field', function (): void {
    [$board, , $source, $systemTarget] = createSystemMergeSetup();

    $leadSource = LeadSource::factory()->create([
        LeadBoard::fkColumn('lead_board') => $board->getKey(),
        'config'                          => [
            'custom_field_mapping' => ['firmen_mail' => 'company_mail'],
        ],
    ]);

    app(LeadFieldMergeService::class)->merge($source, $systemTarget);

    $config = $leadSource->refresh()->config;

    expect($config['custom_field_mapping'])->toBe([])
        ->and($config['field_mapping']['email'])->toContain('firmen_mail')
        ->and($config['field_mapping']['email'])->toContain('email');
});

it('leaves sources of other boards untouched', function (): void {
    [$board, , $source, $target] = createMergeBoard();
    [$foreignBoard]              = createMergeBoard();

    $foreignSource = LeadSource::factory()->create([
        LeadBoard::fkColumn('lead_board') => $foreignBoard->getKey(),
        'config'                          => [
            'custom_field_mapping' => ['branche_auswahl' => 'branche'],
        ],
    ]);

    $result = app(LeadFieldMergeService::class)->merge($source, $target);

    expect($foreignSource->refresh()->config['custom_field_mapping'])->toBe(['branche_auswahl' => 'branche'])
        ->and($result->sourcesUpdated)->toBe(0);
});
