<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
    LeadBoard::created(function (LeadBoard $board): void {
        $board->admins()->syncWithoutDetaching([$this->user->getKey()]);
    });

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $this->phase = LeadPhase::factory()->for($this->board, 'board')->create();

    $this->source = $this->board->fieldDefinitions()->create([
        'key'  => 'branche',
        'name' => 'Branche',
        'type' => LeadFieldTypeEnum::Select,
        'sort' => 3,
    ]);

    $this->target = $this->board->fieldDefinitions()->create([
        'key'  => 'contact_team_type',
        'name' => 'Team-Typ',
        'type' => LeadFieldTypeEnum::Select,
        'sort' => 10,
    ]);
});

it('merges two fields via the board editor action', function (): void {
    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();
    $lead->setFieldValue($this->source, 'Bauträger');

    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->callAction('mergeFields', data: [
            'source'    => $this->source->getKey(),
            'target'    => $this->target->getKey(),
            'value_map' => ['Bauträger' => 'Immo'],
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($lead->refresh()->getFieldValue('contact_team_type'))->toBe('Immo')
        ->and($this->source->refresh()->trashed())->toBeTrue();
});

it('requires source and target selections', function (): void {
    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->callAction('mergeFields', data: [])
        ->assertHasActionErrors(['source', 'target']);
});

it('rejects merging a field into itself via form validation', function (): void {
    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->callAction('mergeFields', data: [
            'source' => $this->source->getKey(),
            'target' => $this->source->getKey(),
        ])
        ->assertHasActionErrors(['target']);
});

it('does not offer system fields as merge options', function (): void {
    $component = livewire(EditLeadBoard::class, ['record' => $this->board->getKey()]);

    $options = $component->instance()->mergeableFieldOptions();

    expect($options)->toHaveCount(2)
        ->and($options)->toHaveKeys([$this->source->getKey(), $this->target->getKey()]);
});

it('offers system fields as merge targets', function (): void {
    $component = livewire(EditLeadBoard::class, ['record' => $this->board->getKey()]);

    $emailDefinition = $this->board->fieldDefinitions()->where('key', 'email')->firstOrFail();

    $options = $component->instance()->mergeTargetOptions();

    expect($options)->toHaveKey($emailDefinition->getKey())
        ->and($options)->toHaveKey($this->target->getKey());
});

it('merges a custom field into a system field via the action', function (): void {
    $companyMail = $this->board->fieldDefinitions()->create([
        'key'  => 'company_mail',
        'name' => 'Company Mail',
        'type' => LeadFieldTypeEnum::Email,
        'sort' => 5,
    ]);

    $emailDefinition = $this->board->fieldDefinitions()->where('key', 'email')->firstOrFail();

    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create(['email' => null]);
    $lead->setFieldValue($companyMail, 'info@firma.de');

    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->callAction('mergeFields', data: [
            'source' => $companyMail->getKey(),
            'target' => $emailDefinition->getKey(),
        ])
        ->assertHasNoActionErrors()
        ->assertNotified();

    expect($lead->refresh()->email)->toBe('info@firma.de')
        ->and($companyMail->refresh()->trashed())->toBeTrue();
});

it('shows the datapoint count per field definition in the board editor', function (): void {
    $leadA = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();
    $leadB = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();

    $leadA->setFieldValue($this->source, 'Makler');
    $leadB->setFieldValue($this->source, 'Bank');

    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->assertSee(__('lead-pipeline::lead-pipeline.field.datapoints', ['count' => 2]));
});

it('shows phase name and color in the collapsed phase item label', function (): void {
    $this->phase->update(['name' => 'Erstkontakt', 'color' => '#ABCDEF']);

    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->assertSeeHtml('background-color: #ABCDEF');
});

it('shows field name and datapoint count in the field item label', function (): void {
    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();
    $lead->setFieldValue($this->source, 'Makler');

    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->assertSee(sprintf(
            'Branche — %s',
            __('lead-pipeline::lead-pipeline.field.datapoints', ['count' => 1]),
        ));
});

it('merges a field via the repeater item action', function (): void {
    $lead = Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create();
    $lead->setFieldValue($this->source, 'Bauträger');

    livewire(EditLeadBoard::class, ['record' => $this->board->getKey()])
        ->callFormComponentAction('fieldDefinitions', 'mergeInto', [
            'target'    => $this->target->getKey(),
            'value_map' => ['Bauträger' => 'Immo'],
        ], [
            'item' => 'record-' . $this->source->getKey(),
        ])
        ->assertHasNoFormComponentActionErrors()
        ->assertNotified();

    expect($lead->refresh()->getFieldValue('contact_team_type'))->toBe('Immo')
        ->and($this->source->refresh()->trashed())->toBeTrue();
});
