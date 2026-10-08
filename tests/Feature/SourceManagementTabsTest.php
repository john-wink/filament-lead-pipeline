<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\Queue;
use JohnWink\FilamentLeadPipeline\Enums\LeadSourceStatusEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\SourceManagement;
use JohnWink\FilamentLeadPipeline\Jobs\ImportImmoScoutLeadsJob;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    app()->setLocale('de');

    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->board->admins()->syncWithoutDetaching([$this->user->getKey()]);
});

function tabSource(LeadSourceStatusEnum $status, ?LeadBoard $board = null, array $attributes = []): LeadSource
{
    return LeadSource::factory()
        ->for($board ?? test()->board, 'board')
        ->create(['team_uuid' => test()->team->uuid, 'status' => $status, ...$attributes]);
}

function tabForeignBoard(): LeadBoard
{
    return LeadBoard::factory()->create(['team_uuid' => test()->team->uuid]);
}

it('opens on the active tab and shows only sources of the active group', function (): void {
    $draft  = tabSource(LeadSourceStatusEnum::Draft);
    $active = tabSource(LeadSourceStatusEnum::Active);
    $paused = tabSource(LeadSourceStatusEnum::Paused);
    $error  = tabSource(LeadSourceStatusEnum::Error);
    $ended  = tabSource(LeadSourceStatusEnum::Ended);

    livewire(SourceManagement::class)
        ->assertSet('activeTab', 'active')
        ->assertCanSeeTableRecords([$draft, $active])
        ->assertCanNotSeeTableRecords([$paused, $error, $ended]);
});

it('shows paused, failed and ended sources on the inactive tab', function (): void {
    $draft  = tabSource(LeadSourceStatusEnum::Draft);
    $active = tabSource(LeadSourceStatusEnum::Active);
    $paused = tabSource(LeadSourceStatusEnum::Paused);
    $error  = tabSource(LeadSourceStatusEnum::Error);
    $ended  = tabSource(LeadSourceStatusEnum::Ended);

    livewire(SourceManagement::class)
        ->set('activeTab', 'inactive')
        ->assertCanSeeTableRecords([$paused, $error, $ended])
        ->assertCanNotSeeTableRecords([$draft, $active]);
});

it('falls back to the active tab for an unknown tab', function (): void {
    $active = tabSource(LeadSourceStatusEnum::Active);

    livewire(SourceManagement::class)
        ->set('activeTab', 'bogus')
        ->assertSet('activeTab', 'active')
        ->assertCanSeeTableRecords([$active]);
});

it('resets the pagination and the selection when the tab changes', function (): void {
    tabSource(LeadSourceStatusEnum::Active);

    livewire(SourceManagement::class)
        ->call('gotoPage', 3)
        ->assertSet('paginators.page', 3)
        ->set('activeTab', 'inactive')
        ->assertSet('paginators.page', 1)
        ->assertDispatched('deselectAllTableRecords');
});

it('renders both tabs with their labels', function (): void {
    livewire(SourceManagement::class)
        ->assertSeeHtml('fi-tabs')
        ->assertSeeHtml('fi-lead-pipeline-source-management')
        ->assertSee('Aktiv')
        ->assertSee('Inaktiv');
});

it('counts the tab badges over the sources visible to the user', function (): void {
    tabSource(LeadSourceStatusEnum::Draft);
    tabSource(LeadSourceStatusEnum::Active);
    tabSource(LeadSourceStatusEnum::Paused);
    tabSource(LeadSourceStatusEnum::Error);
    tabSource(LeadSourceStatusEnum::Ended);

    $foreign = tabForeignBoard();
    tabSource(LeadSourceStatusEnum::Active, $foreign, ['created_by' => User::factory()->create()->getKey()]);
    tabSource(LeadSourceStatusEnum::Ended, $foreign, ['created_by' => User::factory()->create()->getKey()]);
    tabSource(LeadSourceStatusEnum::Ended, $foreign, ['created_by' => $this->user->getKey()]);

    $tabs = livewire(SourceManagement::class)->instance()->getTabs();

    expect(array_keys($tabs))->toBe(['active', 'inactive'])
        ->and($tabs['active']['label'])->toBe('Aktiv')
        ->and($tabs['active']['badge'])->toBe(2)
        ->and($tabs['inactive']['label'])->toBe('Inaktiv')
        ->and($tabs['inactive']['badge'])->toBe(4);
});

it('offers ending for every source that has not been ended', function (LeadSourceStatusEnum $status): void {
    $source = tabSource($status);

    livewire(SourceManagement::class)
        ->set('activeTab', $status->isInActiveGroup() ? 'active' : 'inactive')
        ->assertTableActionVisible('end', $source)
        ->assertTableActionHidden('reactivate', $source);
})->with([
    'draft'  => [LeadSourceStatusEnum::Draft],
    'active' => [LeadSourceStatusEnum::Active],
    'paused' => [LeadSourceStatusEnum::Paused],
    'error'  => [LeadSourceStatusEnum::Error],
]);

it('offers only reactivating for an ended source', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Ended);

    livewire(SourceManagement::class)
        ->set('activeTab', 'inactive')
        ->assertTableActionVisible('reactivate', $source)
        ->assertTableActionHidden('end', $source);
});

it('ends a source without deactivating the board', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Active);

    livewire(SourceManagement::class)
        ->callTableAction('end', $source, data: ['deactivate_board' => false])
        ->assertHasNoTableActionErrors()
        ->assertNotified('Quelle beendet')
        ->assertCanNotSeeTableRecords([$source]);

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($this->board->fresh()->is_active)->toBeTrue();
});

it('ends a source and deactivates the board', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Active);

    livewire(SourceManagement::class)
        ->callTableAction('end', $source, data: ['deactivate_board' => true])
        ->assertHasNoTableActionErrors();

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($this->board->fresh()->is_active)->toBeFalse();
});

it('preselects the board deactivation when the last source of the active group is ended', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Active);
    tabSource(LeadSourceStatusEnum::Paused);

    livewire(SourceManagement::class)
        ->mountTableAction('end', $source)
        ->assertFormFieldIsVisible('deactivate_board', 'mountedTableActionForm')
        ->assertTableActionDataSet(['deactivate_board' => true]);
});

it('does not preselect the board deactivation while another source of the active group remains', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Active);
    tabSource(LeadSourceStatusEnum::Draft);

    livewire(SourceManagement::class)
        ->mountTableAction('end', $source)
        ->assertFormFieldIsVisible('deactivate_board', 'mountedTableActionForm')
        ->assertTableActionDataSet(['deactivate_board' => false]);
});

it('hides the board deactivation from a user who does not administer the board', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Active, tabForeignBoard(), ['created_by' => $this->user->getKey()]);

    livewire(SourceManagement::class)
        ->mountTableAction('end', $source)
        ->assertFormFieldIsHidden('deactivate_board', 'mountedTableActionForm')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($source->board->fresh()->is_active)->toBeTrue();
});

it('hides the board deactivation when the board is already inactive', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Active);
    $this->board->update(['is_active' => false]);

    livewire(SourceManagement::class)
        ->mountTableAction('end', $source)
        ->assertFormFieldIsHidden('deactivate_board', 'mountedTableActionForm');
});

it('reactivates an ended source without a form while its board is active', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Ended);

    livewire(SourceManagement::class)
        ->set('activeTab', 'inactive')
        ->mountTableAction('reactivate', $source)
        ->assertFormFieldIsHidden('activate_board', 'mountedTableActionForm')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors()
        ->assertNotified('Quelle reaktiviert');

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Active);
});

it('preselects the board activation when an ended source of an inactive board is reactivated', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Ended);
    $this->board->update(['is_active' => false]);

    livewire(SourceManagement::class)
        ->set('activeTab', 'inactive')
        ->mountTableAction('reactivate', $source)
        ->assertFormFieldIsVisible('activate_board', 'mountedTableActionForm')
        ->assertTableActionDataSet(['activate_board' => true])
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and($this->board->fresh()->is_active)->toBeTrue();
});

it('reactivates a source and keeps the board inactive on request', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Ended);
    $this->board->update(['is_active' => false]);

    livewire(SourceManagement::class)
        ->set('activeTab', 'inactive')
        ->callTableAction('reactivate', $source, data: ['activate_board' => false])
        ->assertHasNoTableActionErrors();

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and($this->board->fresh()->is_active)->toBeFalse();
});

it('hides the board activation from a user who does not administer the inactive board', function (): void {
    $board = tabForeignBoard();
    $board->update(['is_active' => false]);
    $source = tabSource(LeadSourceStatusEnum::Ended, $board, ['created_by' => $this->user->getKey()]);

    livewire(SourceManagement::class)
        ->set('activeTab', 'inactive')
        ->mountTableAction('reactivate', $source)
        ->assertFormFieldIsHidden('activate_board', 'mountedTableActionForm')
        ->callMountedTableAction()
        ->assertHasNoTableActionErrors();

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Active)
        ->and($board->fresh()->is_active)->toBeFalse();
});

it('ends sources in bulk and deactivates only the boards left without a source of the active group', function (): void {
    $emptied = $this->board;
    $kept    = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $kept->admins()->syncWithoutDetaching([$this->user->getKey()]);
    $foreign = tabForeignBoard();

    $first  = tabSource(LeadSourceStatusEnum::Active, $emptied);
    $second = tabSource(LeadSourceStatusEnum::Draft, $emptied);
    $third  = tabSource(LeadSourceStatusEnum::Active, $kept);
    $fourth = tabSource(LeadSourceStatusEnum::Active, $foreign, ['created_by' => $this->user->getKey()]);
    tabSource(LeadSourceStatusEnum::Draft, $kept);

    livewire(SourceManagement::class)
        ->mountTableBulkAction('end', [$first, $second, $third, $fourth])
        ->assertTableBulkActionDataSet(['deactivate_boards' => true])
        ->callMountedTableBulkAction()
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('4 Quellen beendet');

    expect(LeadSource::query()->where('status', LeadSourceStatusEnum::Ended)->count())->toBe(4)
        ->and($emptied->fresh()->is_active)->toBeFalse()
        ->and($kept->fresh()->is_active)->toBeTrue()
        ->and($foreign->fresh()->is_active)->toBeTrue();
});

it('ends sources in bulk without touching boards when the switch is off', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Active);

    livewire(SourceManagement::class)
        ->callTableBulkAction('end', [$source], data: ['deactivate_boards' => false])
        ->assertHasNoTableBulkActionErrors()
        ->assertNotified('1 Quelle beendet');

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Ended)
        ->and($this->board->fresh()->is_active)->toBeTrue();
});

it('lifts the ended status through the edit form', function (): void {
    $source = tabSource(LeadSourceStatusEnum::Ended);

    livewire(SourceManagement::class)
        ->set('activeTab', 'inactive')
        ->callTableAction('edit', $source, data: [
            'name'                             => $source->name,
            'status'                           => LeadSourceStatusEnum::Paused->value,
            LeadSource::fkColumn('lead_board') => $this->board->getKey(),
        ])
        ->assertHasNoTableActionErrors();

    expect($source->fresh()->status)->toBe(LeadSourceStatusEnum::Paused);
});

it('hides the manual import actions of an ended immoscout source', function (): void {
    Queue::fake();

    $running = tabSource(LeadSourceStatusEnum::Active, attributes: ['driver' => 'immoscout24']);
    $ended   = tabSource(LeadSourceStatusEnum::Ended, attributes: ['driver' => 'immoscout24']);

    livewire(SourceManagement::class)
        ->assertTableActionVisible('immoscout24_import_leads', $running)
        ->assertTableActionVisible('immoscout24_import_test_leads', $running)
        ->set('activeTab', 'inactive')
        ->assertTableActionHidden('immoscout24_import_leads', $ended)
        ->assertTableActionHidden('immoscout24_import_test_leads', $ended);

    Queue::assertNotPushed(ImportImmoScoutLeadsJob::class);
});

it('hides the manual import actions of an ended meta source', function (): void {
    $running = tabSource(LeadSourceStatusEnum::Active, attributes: ['driver' => 'meta']);
    $ended   = tabSource(LeadSourceStatusEnum::Ended, attributes: ['driver' => 'meta']);

    livewire(SourceManagement::class)
        ->assertTableActionVisible('meta_import_leads', $running)
        ->set('activeTab', 'inactive')
        ->assertTableActionHidden('meta_import_leads', $ended)
        ->assertTableActionHidden('meta_reimport_leads', $ended);
});
