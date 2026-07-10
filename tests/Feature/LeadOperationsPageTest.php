<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Filament\Pages\LeadOperations;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('renders the lead operations page', function (): void {
    LeadBoard::factory()->create();

    livewire(LeadOperations::class)->assertSuccessful();
});

it('switches preset and board through the lifecycle', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(LeadOperations::class)
        ->call('setPreset', '7')
        ->assertSet('preset', '7')
        ->call('setBoard', (string) $board->getKey())
        ->assertSet('boardId', (string) $board->getKey())
        ->assertSuccessful();
});

it('renders successfully without any tenant-visible boards', function (): void {
    livewire(LeadOperations::class)->assertSuccessful();
});

it('restricts a non-admin advisor to their own leads across all boards when no board is selected', function (): void {
    Carbon::setTestNow(now());

    $admin   = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Min']);
    $advisor = User::factory()->create(['first_name' => 'Avi', 'last_name' => 'Sor']);
    $this->team->users()->syncWithoutDetaching([$admin->id, $advisor->id]);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$admin->id]); // advisor is NOT a board admin

    $phase  = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Won]);
    $source = LeadSource::factory()->for($board, 'board')->create(['name' => 'Colleague-Only-Source']);

    // Colleague's lead: would leak cross-board ops data if the fix regressed.
    Lead::factory()
        ->for($phase, 'phase')
        ->for($board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $source->getKey(),
            'assigned_to'                 => $admin->id,
            'status'                      => LeadStatusEnum::Won,
        ]);

    $ownLead = Lead::factory()
        ->for($phase, 'phase')
        ->for($board, 'board')
        ->create([
            'assigned_to' => $advisor->id,
            'status'      => LeadStatusEnum::Won,
        ]);

    $this->actingAs($advisor);
    filament()->setTenant($this->team);

    $instance = livewire(LeadOperations::class)->instance();

    $scopedLeads = (new ReflectionMethod($instance, 'scopedLeads'))->invoke($instance);
    expect($scopedLeads->pluck(Lead::pkColumn())->all())->toBe([$ownLead->getKey()]);

    $viewData         = (new ReflectionMethod($instance, 'getViewData'))->invoke($instance);
    $matrixAdvisorIds = collect($viewData['matrix']['rows'])->pluck('advisor_id')->all();

    expect($matrixAdvisorIds)->toBe([(string) $advisor->id])
        ->and($matrixAdvisorIds)->not->toContain((string) $admin->id);

    $sourceNames = collect($viewData['sources'])->pluck('source')->all();
    expect($sourceNames)->not->toContain('Colleague-Only-Source');
});

it('renders ad-cost columns and the funnel section when a funded board is selected', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->user->id]);

    LeadPhase::factory()->for($board, 'board')->create(['name' => 'Neu']);
    $wonPhase = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Won, 'name' => 'Gewonnen']);

    $source = LeadSource::factory()->for($board, 'board')->create(['name' => 'Funded-Source']);
    Lead::factory()
        ->for($wonPhase, 'phase')
        ->for($board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $source->getKey(),
            'status'                      => LeadStatusEnum::Won,
            'source_campaign_id'          => 'c-page-1',
        ]);

    MetaInsightSnapshot::factory()->create([
        'team_uuid'      => $this->team->uuid,
        'campaign_id'    => 'c-page-1',
        'breakdown_type' => 'none',
        'spend'          => 100,
    ]);

    livewire(LeadOperations::class)
        ->call('setBoard', (string) $board->getKey())
        ->assertSee(__('lead-pipeline::lead-pipeline.operations.cost_per_lead'))
        ->assertSee(__('lead-pipeline::lead-pipeline.operations.cost_per_acquisition'))
        ->assertSee(__('lead-pipeline::lead-pipeline.operations.funnel'))
        ->assertSee('Funded-Source')
        ->assertSee('100,00');
});

it('aborts with 403 when a forged board id is not accessible on the tenant', function (): void {
    $otherTeam    = Team::factory()->create();
    $foreignBoard = LeadBoard::factory()->create(['team_uuid' => $otherTeam->uuid]);

    livewire(LeadOperations::class)
        ->call('setBoard', (string) $foreignBoard->getKey())
        ->assertForbidden();
});

it('nests under the Leads navigation item instead of adding a top-level entry', function (): void {
    // Parent label must match LeadBoardResource (the "Leads" nav item) exactly,
    // and share its group, so Filament renders it as a Leads sub-item.
    expect(LeadOperations::getNavigationParentItem())
        ->toBe(JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource::getNavigationLabel())
        ->and(LeadOperations::getNavigationGroup())
        ->toBe(JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource::getNavigationGroup());
});

it('supports the all preset and a custom date range with custom taking precedence', function (): void {
    $component = livewire(LeadOperations::class)
        ->call('setPreset', 'all')
        ->assertSet('preset', 'all');

    $component->set('dateFrom', '2026-03-01')
        ->assertSet('preset', 'custom')
        ->set('dateTo', '2026-03-31')
        ->assertSuccessful();

    $instance    = $component->instance();
    [$from, $to] = (new ReflectionMethod($instance, 'range'))->invoke($instance);

    expect($from->toDateString())->toBe('2026-03-01')
        ->and($to->toDateString())->toBe('2026-03-31');
});

it('returns an unbounded range for the all preset', function (): void {
    $instance = livewire(LeadOperations::class)->call('setPreset', 'all')->instance();

    expect((new ReflectionMethod($instance, 'range'))->invoke($instance))->toBe([null, null]);
});

it('clears custom dates when a preset pill is clicked', function (): void {
    livewire(LeadOperations::class)
        ->set('dateFrom', '2026-03-01')
        ->call('setPreset', '7')
        ->assertSet('dateFrom', null)
        ->assertSet('dateTo', null)
        ->assertSet('preset', '7');
});

it('keeps all advisors selectable after one advisor is chosen', function (): void {
    $advisorA = User::factory()->create(['first_name' => 'Berater', 'last_name' => 'Alpha']);
    $advisorB = User::factory()->create(['first_name' => 'Berater', 'last_name' => 'Beta']);
    $this->team->users()->syncWithoutDetaching([$advisorA->id, $advisorB->id]);

    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->user->id]);
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();

    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $advisorA->id]);
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $advisorB->id]);

    livewire(LeadOperations::class)
        ->call('setBoard', (string) $board->getKey())
        ->call('setAdvisor', (string) $advisorA->id)
        ->assertSee('Berater Alpha')
        ->assertSee('Berater Beta'); // must still be offered in the select
});

it('renders the advisor matrix with resolved names instead of raw ids', function (): void {
    // name is a computed accessor (first_name . ' ' . last_name), not a fillable
    // column — the factory must set the underlying columns, not 'name'.
    $advisor = User::factory()->create(['first_name' => 'Maria', 'last_name' => 'Muster']);
    $this->team->users()->syncWithoutDetaching([$advisor->id]);
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->user->id]);
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create(['assigned_to' => $advisor->id]);

    livewire(LeadOperations::class)
        ->call('setBoard', (string) $board->getKey())
        ->assertSee(__('lead-pipeline::lead-pipeline.operations.matrix_title'))
        ->assertSee('Maria Muster');
    // No assertDontSee((string) $advisor->id) here: the matrix row's wire:key
    // ("matrix-row-{advisor_id}") legitimately contains the raw id in markup,
    // so asserting its absence would collide with that attribute.
});

it('labels snapshot metrics as as-of-today', function (): void {
    LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(LeadOperations::class)
        ->assertSee(__('lead-pipeline::lead-pipeline.operations.as_of_today'));
});

it('includes custom dates in the export url', function (): void {
    $instance = livewire(LeadOperations::class)
        ->set('dateFrom', '2026-03-01')
        ->set('dateTo', '2026-03-31')
        ->instance();

    expect($instance->getExportUrl())
        ->toContain('dateFrom=2026-03-01')
        ->toContain('dateTo=2026-03-31');
});

it('includes the current tenant in the export url so the out-of-panel route can resolve it', function (): void {
    $instance = livewire(LeadOperations::class)->instance();

    expect($instance->getExportUrl())->toContain('tenant=' . $this->team->getKey());
});

it('does not throw when dateFrom is unparseable', function (): void {
    $this->withoutExceptionHandling();

    livewire(LeadOperations::class)
        ->set('dateFrom', 'garbage')
        ->assertSuccessful();
});

it('resets the dangling custom preset back to 30 once both custom dates are cleared', function (): void {
    livewire(LeadOperations::class)
        ->set('dateFrom', '2026-03-01')
        ->assertSet('preset', 'custom')
        ->set('dateFrom', '')
        ->assertSet('preset', '30')
        ->assertSet('dateTo', null);
});
