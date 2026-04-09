<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceTypeEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

// ==========================================
// PAGE RENDERING
// ==========================================

it('renders the source management page', function (): void {
    livewire(SourceManagement::class)
        ->assertSuccessful();
});

it('displays sources in table', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $sources = LeadSource::factory()
        ->count(3)
        ->for($board, 'board')
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->assertCanSeeTableRecords($sources);
});

// ==========================================
// CREATE SOURCE TESTS
// ==========================================

it('can create a new API source', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'                             => 'Meine API Quelle',
            'driver'                           => LeadSourceTypeEnum::Api->value,
            LeadSource::fkColumn('lead_board') => $board->getKey(),
        ])
        ->assertHasNoTableActionErrors();

    $source = LeadSource::query()->where('name', 'Meine API Quelle')->first();

    expect($source)->not->toBeNull()
        ->and($source->driver)->toBe(LeadSourceTypeEnum::Api->value)
        ->and($source->board->getKey())->toBe($board->getKey());
});

it('can create a new Zapier source', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'                             => 'Zapier Integration',
            'driver'                           => LeadSourceTypeEnum::Zapier->value,
            LeadSource::fkColumn('lead_board') => $board->getKey(),
        ])
        ->assertHasNoTableActionErrors();

    $source = LeadSource::query()->where('name', 'Zapier Integration')->first();

    expect($source)->not->toBeNull()
        ->and($source->driver)->toBe(LeadSourceTypeEnum::Zapier->value);
});

it('can create a new Meta source', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'                             => 'Facebook Leads',
            'driver'                           => LeadSourceTypeEnum::Meta->value,
            LeadSource::fkColumn('lead_board') => $board->getKey(),
        ])
        ->assertHasNoTableActionErrors();

    $source = LeadSource::query()->where('name', 'Facebook Leads')->first();

    expect($source)->not->toBeNull()
        ->and($source->driver)->toBe(LeadSourceTypeEnum::Meta->value);
});

it('can create a new Funnel source', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'                             => 'Landing Page Funnel',
            'driver'                           => LeadSourceTypeEnum::Funnel->value,
            LeadSource::fkColumn('lead_board') => $board->getKey(),
        ])
        ->assertHasNoTableActionErrors();

    $source = LeadSource::query()->where('name', 'Landing Page Funnel')->first();

    expect($source)->not->toBeNull()
        ->and($source->driver)->toBe(LeadSourceTypeEnum::Funnel->value);
});

it('requires board selection when creating a source', function (): void {
    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'                             => 'Draft Quelle',
            'driver'                           => LeadSourceTypeEnum::Api->value,
            LeadSource::fkColumn('lead_board') => null,
        ])
        ->assertHasTableActionErrors([LeadSource::fkColumn('lead_board') => 'required']);
});

it('can link source to existing board', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'                             => 'Verknuepfte Quelle',
            'driver'                           => LeadSourceTypeEnum::Api->value,
            LeadSource::fkColumn('lead_board') => $board->getKey(),
        ])
        ->assertHasNoTableActionErrors();

    $source = LeadSource::query()->where('name', 'Verknuepfte Quelle')->first();

    expect($source)->not->toBeNull()
        ->and($source->board)->not->toBeNull()
        ->and($source->board->getKey())->toBe($board->getKey());
});

// ==========================================
// EDIT SOURCE TESTS
// ==========================================

it('can edit source name and status', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $source = LeadSource::factory()
        ->for($board, 'board')
        ->create([
            'team_uuid' => $this->team->uuid,
            'name'      => 'Alte Quelle',
            'status'    => LeadSourceStatusEnum::Draft,
        ]);

    livewire(SourceManagement::class)
        ->callTableAction('edit', $source, data: [
            'name'   => 'Umbenannte Quelle',
            'status' => LeadSourceStatusEnum::Active->value,
        ])
        ->assertHasNoTableActionErrors();

    $source->refresh();

    expect($source->name)->toBe('Umbenannte Quelle')
        ->and($source->status)->toBe(LeadSourceStatusEnum::Active);
});

it('can delete a source', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $source = LeadSource::factory()
        ->for($board, 'board')
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->callTableAction('delete', $source);

    expect(LeadSource::query()->find($source->getKey()))->toBeNull()
        ->and(LeadSource::withTrashed()->find($source->getKey()))->not->toBeNull();
});

// ==========================================
// TABLE COLUMN TESTS
// ==========================================

it('shows source driver badge', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $source = LeadSource::factory()
        ->for($board, 'board')
        ->create([
            'team_uuid' => $this->team->uuid,
            'driver'    => LeadSourceTypeEnum::Zapier,
        ]);

    livewire(SourceManagement::class)
        ->assertCanSeeTableRecords([$source])
        ->assertTableColumnExists('driver');

    expect($source->refresh()->driver)->toBe('zapier');
});

it('shows source status badge', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $source = LeadSource::factory()
        ->for($board, 'board')
        ->active()
        ->create(['team_uuid' => $this->team->uuid]);

    livewire(SourceManagement::class)
        ->assertCanSeeTableRecords([$source])
        ->assertTableColumnExists('status');

    expect($source->status)->toBe(LeadSourceStatusEnum::Active);
});

it('shows last_received_at timestamp', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $receivedAt = now()->subHour();

    $source = LeadSource::factory()
        ->for($board, 'board')
        ->create([
            'team_uuid'        => $this->team->uuid,
            'last_received_at' => $receivedAt,
        ]);

    livewire(SourceManagement::class)
        ->assertTableColumnExists('last_received_at');
});

it('shows lead count for each source', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $phase = LeadPhase::factory()
        ->for($board, 'board')
        ->create();

    $source = LeadSource::factory()
        ->for($board, 'board')
        ->create(['team_uuid' => $this->team->uuid]);

    Lead::factory()
        ->count(4)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->for($source, 'source')
        ->create();

    livewire(SourceManagement::class)
        ->assertTableColumnStateSet('leads_count', 4, $source->getKey());
});

it('can change source status from draft to active', function (): void {
    $board = LeadBoard::factory()
        ->create(['team_uuid' => $this->team->uuid]);

    $source = LeadSource::factory()
        ->for($board, 'board')
        ->create([
            'team_uuid' => $this->team->uuid,
            'status'    => LeadSourceStatusEnum::Draft,
        ]);

    livewire(SourceManagement::class)
        ->callTableAction('edit', $source, data: [
            'name'                             => $source->name,
            'status'                           => LeadSourceStatusEnum::Active->value,
            LeadSource::fkColumn('lead_board') => $board->getKey(),
        ])
        ->assertHasNoTableActionErrors();

    expect($source->refresh()->status)->toBe(LeadSourceStatusEnum::Active);
});

it('validates board is required on create', function (): void {
    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'                             => 'Ohne Board Quelle',
            'driver'                           => LeadSourceTypeEnum::Api->value,
            LeadSource::fkColumn('lead_board') => null,
        ])
        ->assertHasTableActionErrors([LeadSource::fkColumn('lead_board') => 'required']);
});

// ==========================================
// EDGE CASES
// ==========================================

it('validates source name is required on create', function (): void {
    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'   => '',
            'driver' => LeadSourceTypeEnum::Api->value,
        ])
        ->assertHasTableActionErrors(['name' => 'required']);
});

it('validates driver selection is required on create', function (): void {
    livewire(SourceManagement::class)
        ->callTableAction('create', data: [
            'name'   => 'Test Quelle',
            'driver' => null,
        ])
        ->assertHasTableActionErrors(['driver' => 'required']);
});

it('shows source with board in management table', function (): void {
    $board  = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $source = LeadSource::factory()
        ->create([
            'team_uuid'       => $this->team->uuid,
            'lead_board_uuid' => $board->uuid,
            'status'          => LeadSourceStatusEnum::Active,
        ]);

    livewire(SourceManagement::class)
        ->assertCanSeeTableRecords([$source]);

    expect($source->status)->toBe(LeadSourceStatusEnum::Active);
});
