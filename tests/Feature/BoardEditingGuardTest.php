<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Event;
use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseDisplayTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Events\LeadBoardStructureChanged;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadFieldDefinition;
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
});

it('allows adding a phase to a board that already has leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $phase = LeadPhase::factory()->for($board, 'board')->create(['name' => 'Bestehend', 'sort' => 0]);
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'phases' => [
                "record-{$phase->getKey()}" => [
                    'name'         => 'Bestehend',
                    'color'        => $phase->color,
                    'type'         => $phase->type?->value ?? LeadPhaseTypeEnum::InProgress->value,
                    'display_type' => $phase->display_type?->value ?? 'kanban',
                    'auto_convert' => false,
                ],
                'new-phase-1' => [
                    'name'         => 'Neue Phase',
                    'color'        => '#ABCDEF',
                    'type'         => LeadPhaseTypeEnum::InProgress->value,
                    'display_type' => 'kanban',
                    'auto_convert' => false,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    // existing + new phase, plus the mandatory auto-created "Nicht qualifiziert" terminal phase
    expect($board->refresh()->phases)->toHaveCount(3);
});

it('allows adding a custom field to a board that already has leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'fieldDefinitions' => [
                ['name' => 'Budget', 'key' => 'budget', 'type' => LeadFieldTypeEnum::Currency->value, 'show_in_card' => true],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($board->refresh()->fieldDefinitions->where('key', 'budget')->first())->not->toBeNull();
});

it('blocks key and type changes on a field that has values', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    $lead  = Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();
    $def   = LeadFieldDefinition::factory()->for($board, 'board')->create(['key' => 'budget', 'type' => LeadFieldTypeEnum::String]);
    $lead->setFieldValue($def, '1000');

    expect(fn () => $def->update(['key' => 'renamed']))->toThrow(RuntimeException::class);
    expect(fn () => $def->fresh()->update(['type' => LeadFieldTypeEnum::Number]))->toThrow(RuntimeException::class);
    expect($def->fresh()->key)->toBe('budget');
});

it('allows label change on a field that has values', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    $lead  = Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();
    $def   = LeadFieldDefinition::factory()->for($board, 'board')->create(['key' => 'budget']);
    $lead->setFieldValue($def, '1000');

    $def->update(['name' => 'Neues Label', 'show_in_card' => true]);

    expect($def->fresh()->name)->toBe('Neues Label');
});

it('blocks deleting a field that has values and protects system fields', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    $lead  = Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();
    $def   = LeadFieldDefinition::factory()->for($board, 'board')->create(['key' => 'budget']);
    $lead->setFieldValue($def, '1000');
    $system = $board->fieldDefinitions()->where('is_system', true)->first();

    expect(fn () => $def->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $system->delete())->toThrow(RuntimeException::class)
        ->and(fn () => $system->update(['key' => 'x']))->toThrow(RuntimeException::class);
});

it('allows key/type change and delete when the field has no values', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $def   = LeadFieldDefinition::factory()->for($board, 'board')->create(['key' => 'empty_field']);

    $def->update(['key' => 'renamed', 'type' => LeadFieldTypeEnum::Number]);
    expect($def->fresh()->key)->toBe('renamed');

    $def->delete();
    expect(LeadFieldDefinition::find($def->getKey()))->toBeNull();
});

it('blocks deleting a phase that has leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $phase = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::InProgress]);
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();

    expect(fn () => $phase->delete())->toThrow(RuntimeException::class);
});

it('enforces the won/lost invariant', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $won   = LeadPhase::factory()->for($board, 'board')->won()->create();
    LeadPhase::factory()->for($board, 'board')->lost()->create();

    expect(fn () => $won->delete())->toThrow(RuntimeException::class);
    expect(fn () => LeadPhase::factory()->for($board, 'board')->won()->create())->toThrow(RuntimeException::class);
});

it('forces list display for terminal phases', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $phase = LeadPhase::factory()->for($board, 'board')->create([
        'type'         => LeadPhaseTypeEnum::Won,
        'display_type' => LeadPhaseDisplayTypeEnum::Kanban,
    ]);

    expect($phase->fresh()->display_type)->toBe(LeadPhaseDisplayTypeEnum::List);
});

it('allows deleting a non-terminal phase without leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    LeadPhase::factory()->for($board, 'board')->won()->create();
    LeadPhase::factory()->for($board, 'board')->lost()->create();
    $extra = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::InProgress]);

    $extra->delete();
    expect(LeadPhase::find($extra->getKey()))->toBeNull();
});

it('requires a conversion target when auto_convert is on', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);
    $won   = LeadPhase::factory()->for($board, 'board')->won()->create();

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'phases' => [
                "record-{$won->getKey()}" => [
                    'name'         => $won->name,
                    'color'        => $won->color,
                    'type'         => LeadPhaseTypeEnum::Won->value,
                    'display_type' => 'list',
                    'auto_convert' => true,
                ],
            ],
        ])
        ->call('save')
        ->assertHasFormErrors();
});

it('fires a structure-changed event when a field is added by a user', function (): void {
    Event::fake([LeadBoardStructureChanged::class]);
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->getKey()]);

    LeadFieldDefinition::factory()->for($board, 'board')->create(['key' => 'budget']);

    Event::assertDispatched(LeadBoardStructureChanged::class);
});
