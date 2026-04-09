<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadFieldTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\CreateLeadBoard;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\ListLeadBoards;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use App\Models\Team;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

// ==========================================
// TABLE TESTS
// ==========================================

it('renders the list page', function (): void {
    livewire(ListLeadBoards::class)
        ->assertSuccessful();
});

it('displays boards in the table', function (): void {
    $boards = LeadBoard::factory()
        ->count(3)
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->assertCanSeeTableRecords($boards);
});

it('can search boards by name', function (): void {
    $matchingBoard = LeadBoard::factory()
        ->create(['name' => 'Vertrieb Pipeline', 'team_uuid' => $this->team->uuid]);

    $nonMatchingBoard = LeadBoard::factory()
        ->create(['name' => 'Marketing Board', 'team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->searchTable('Vertrieb')
        ->assertCanSeeTableRecords([$matchingBoard])
        ->assertCanNotSeeTableRecords([$nonMatchingBoard]);
});

it('shows board counts for phases leads and sources', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $phase = LeadPhase::factory()
        ->for($board, 'board')
        ->create();

    Lead::factory()
        ->count(2)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create();

    LeadSource::factory()
        ->for($board, 'board')
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->assertCanSeeTableRecords([$board])
        ->assertTableColumnStateSet('phases_count', 1, $board->getKey())
        ->assertTableColumnStateSet('leads_count', 2, $board->getKey())
        ->assertTableColumnStateSet('sources_count', 1, $board->getKey());
});

it('shows active icon column correctly for active board', function (): void {
    $activeBoard = LeadBoard::factory()
        ->create(['is_active' => true, 'team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->assertTableColumnStateSet('is_active', true, $activeBoard);
});

it('shows inactive icon column correctly for inactive board', function (): void {
    $inactiveBoard = LeadBoard::factory()
        ->inactive()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->assertTableColumnStateSet('is_active', false, $inactiveBoard);
});

it('sorts by created_at', function (): void {
    $olderBoard = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid, 'created_at' => now()->subDays(2)]);

    $newerBoard = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid, 'created_at' => now()]);

    livewire(ListLeadBoards::class)
        ->sortTable('created_at')
        ->assertCanSeeTableRecords([$olderBoard, $newerBoard], inOrder: true)
        ->sortTable('created_at', 'desc')
        ->assertCanSeeTableRecords([$newerBoard, $olderBoard], inOrder: true);
});

it('has Board oeffnen action that links to kanban', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->assertTableActionExists('kanban')
        ->assertTableActionHasLabel('kanban', 'Board öffnen');
});

it('has Quellenverwaltung header action', function (): void {
    livewire(ListLeadBoards::class)
        ->assertTableActionExists('sources')
        ->assertTableActionHasLabel('sources', 'Quellenverwaltung');
});

it('can delete a board from table', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->callTableAction('delete', $board)
        ->assertNotified();

    expect(LeadBoard::query()->find($board->getKey()))->toBeNull();
    expect(LeadBoard::withTrashed()->find($board->getKey()))->not->toBeNull();
});

it('can bulk delete boards', function (): void {
    $boards = LeadBoard::factory()
        ->count(3)
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(ListLeadBoards::class)
        ->callTableBulkAction('delete', $boards);

    foreach ($boards as $board) {
        expect(LeadBoard::query()->find($board->getKey()))->toBeNull();
        expect(LeadBoard::withTrashed()->find($board->getKey()))->not->toBeNull();
    }
});

// ==========================================
// CREATE TESTS
// ==========================================

it('renders the create page', function (): void {
    livewire(CreateLeadBoard::class)
        ->assertSuccessful();
});

it('can create a board with name and description', function (): void {
    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name'        => 'Neues Sales Board',
            'description' => 'Ein Test Board fuer den Vertrieb',
            'is_active'   => true,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $board = LeadBoard::query()->where('name', 'Neues Sales Board')->first();

    expect($board)->not->toBeNull()
        ->and($board->description)->toBe('Ein Test Board fuer den Vertrieb')
        ->and($board->is_active)->toBeTrue();
});

it('pre-fills default phases from plugin config', function (): void {
    $plugin        = filament()->getCurrentPanel()?->getPlugin('filament-lead-pipeline');
    $defaultPhases = $plugin instanceof FilamentLeadPipelinePlugin
        ? $plugin->getDefaultPhases()
        : [];

    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name' => 'Board mit Standardphasen',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $board = LeadBoard::query()->where('name', 'Board mit Standardphasen')->first();

    expect($board->phases)->toHaveCount(count($defaultPhases));
});

it('pre-fills default fields from plugin config', function (): void {
    $plugin        = filament()->getCurrentPanel()?->getPlugin('filament-lead-pipeline');
    $defaultFields = $plugin instanceof FilamentLeadPipelinePlugin
        ? $plugin->getDefaultFields()
        : [];

    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name' => 'Board mit Systemfeldern',
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $board = LeadBoard::query()->where('name', 'Board mit Systemfeldern')->first();

    // 3 system fields (name, email, phone) are always auto-created, plus the configured default fields
    expect($board->fieldDefinitions)->toHaveCount(count($defaultFields) + 3);
});

it('validates name is required', function (): void {
    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('can create a board with custom phases via repeater', function (): void {
    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name'   => 'Board mit Custom Phasen',
            'phases' => [
                [
                    'name'         => 'Erste Phase',
                    'color'        => '#FF0000',
                    'type'         => LeadPhaseTypeEnum::Open->value,
                    'display_type' => 'kanban',
                    'auto_convert' => false,
                ],
                [
                    'name'         => 'Zweite Phase',
                    'color'        => '#00FF00',
                    'type'         => LeadPhaseTypeEnum::InProgress->value,
                    'display_type' => 'kanban',
                    'auto_convert' => false,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $board = LeadBoard::query()->where('name', 'Board mit Custom Phasen')->first();

    // Custom phases were provided, so default phases should not be created
    expect($board->phases)->toHaveCount(2)
        ->and($board->phases->pluck('name')->toArray())->toContain('Erste Phase', 'Zweite Phase');
});

it('can create a board with custom field definitions via repeater', function (): void {
    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name'             => 'Board mit Feldern',
            'fieldDefinitions' => [
                [
                    'name'           => 'Firma',
                    'key'            => 'firma',
                    'type'           => LeadFieldTypeEnum::String->value,
                    'is_required'    => true,
                    'show_in_card'   => true,
                    'show_in_funnel' => true,
                ],
            ],
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $board = LeadBoard::query()->where('name', 'Board mit Feldern')->first();

    // 3 system fields (name, email, phone) are always auto-created, plus the 1 custom field
    expect($board->fieldDefinitions)->toHaveCount(4)
        ->and($board->fieldDefinitions->where('key', 'firma')->first()->name)->toBe('Firma');
});

it('toggles conversion_target visibility based on auto_convert', function (): void {
    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name'   => 'Test Toggle Board',
            'phases' => [
                [
                    'name'              => 'Gewonnen Phase',
                    'type'              => LeadPhaseTypeEnum::Won->value,
                    'auto_convert'      => true,
                    'conversion_target' => 'customer',
                ],
            ],
        ])
        ->assertFormFieldIsVisible('phases.0.conversion_target');
});

// ==========================================
// EDIT TESTS
// ==========================================

it('renders the edit page with existing data', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid, 'name' => 'Bestehendes Board']);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertSuccessful()
        ->assertFormSet([
            'name' => 'Bestehendes Board',
        ]);
});

it('can update board name and description', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid, 'name' => 'Alter Name']);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'name'        => 'Neuer Name',
            'description' => 'Neue Beschreibung',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $board->refresh();

    expect($board->name)->toBe('Neuer Name')
        ->and($board->description)->toBe('Neue Beschreibung');
});

it('can add new phases via repeater on edit', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $existingPhase = LeadPhase::factory()
        ->for($board, 'board')
        ->create(['name' => 'Bestehende Phase', 'sort' => 0]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'phases' => [
                "record-{$existingPhase->getKey()}" => [
                    'name'         => 'Bestehende Phase',
                    'color'        => $existingPhase->color,
                    'type'         => $existingPhase->type?->value,
                    'display_type' => $existingPhase->display_type?->value ?? 'kanban',
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

    $board->refresh();

    expect($board->phases)->toHaveCount(2);
});

it('can add custom field definitions on edit', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'fieldDefinitions' => [
                [
                    'name'           => 'Budget',
                    'key'            => 'budget',
                    'type'           => LeadFieldTypeEnum::Currency->value,
                    'is_required'    => false,
                    'show_in_card'   => true,
                    'show_in_funnel' => false,
                ],
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $board->refresh();

    // 3 system fields (name, email, phone) persist, plus the 1 custom budget field added
    expect($board->fieldDefinitions)->toHaveCount(4)
        ->and($board->fieldDefinitions->where('key', 'budget')->first()->key)->toBe('budget');
});

it('can toggle board active and inactive', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid, 'is_active' => true]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'is_active' => false,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($board->refresh()->is_active)->toBeFalse();

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'is_active' => true,
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($board->refresh()->is_active)->toBeTrue();
});

it('preserves existing phases when editing board metadata', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    LeadPhase::factory()
        ->count(3)
        ->for($board, 'board')
        ->create();

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->fillForm([
            'name' => 'Umbenanntes Board',
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $board->refresh();

    expect($board->name)->toBe('Umbenanntes Board')
        ->and($board->phases)->toHaveCount(3);
});

it('can delete a phase from repeater on edit', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $phaseA = LeadPhase::factory()
        ->for($board, 'board')
        ->create(['name' => 'Phase A', 'sort' => 0]);

    $phaseB = LeadPhase::factory()
        ->for($board, 'board')
        ->create(['name' => 'Phase B', 'sort' => 1]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->set('data.phases', [
            "record-{$phaseA->getKey()}" => [
                'name'         => 'Phase A',
                'color'        => $phaseA->color,
                'type'         => $phaseA->type?->value ?? LeadPhaseTypeEnum::InProgress->value,
                'display_type' => $phaseA->display_type?->value ?? 'kanban',
                'auto_convert' => false,
            ],
        ])
        ->call('save')
        ->assertHasNoFormErrors();

    $board->refresh();

    expect($board->phases)->toHaveCount(1)
        ->and($board->phases->first()->name)->toBe('Phase A');
});

// ==========================================
// EDGE CASES
// ==========================================

it('cannot create board with empty name', function (): void {
    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name' => '',
        ])
        ->call('create')
        ->assertHasFormErrors(['name' => 'required']);
});

it('handles very long board names at 255 chars', function (): void {
    $longName = str_repeat('A', 255);

    livewire(CreateLeadBoard::class)
        ->fillForm([
            'name' => $longName,
        ])
        ->call('create')
        ->assertHasNoFormErrors();

    $board = LeadBoard::query()->where('name', $longName)->first();

    expect($board)->not->toBeNull()
        ->and(mb_strlen($board->name))->toBe(255);
});

it('soft deletes board and cascades to phases and leads', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $phase = LeadPhase::factory()
        ->for($board, 'board')
        ->create();

    $lead = Lead::factory()
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create();

    $board->delete();

    expect($board->trashed())->toBeTrue()
        ->and(LeadBoard::withTrashed()->find($board->getKey()))->not->toBeNull();
});

it('shows correct counts after leads are added and removed', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $phase = LeadPhase::factory()
        ->for($board, 'board')
        ->create();

    Lead::factory()
        ->count(5)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create();

    livewire(ListLeadBoards::class)
        ->assertTableColumnStateSet('leads_count', 5, $board->getKey());

    // Delete 2 leads
    Lead::query()
        ->where(Lead::fkColumn('lead_board'), $board->getKey())
        ->limit(2)
        ->get()
        ->each->forceDelete();

    livewire(ListLeadBoards::class)
        ->assertTableColumnStateSet('leads_count', 3, $board->getKey());
});
