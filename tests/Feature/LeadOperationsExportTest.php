<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Models\LeadSource;
use JohnWink\FilamentLeadPipeline\Models\MetaInsightSnapshot;

beforeEach(function (): void {
    $this->team  = Team::query()->firstWhere('slug', 'test');
    $this->admin = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Min']);
    $this->team->users()->syncWithoutDetaching([$this->admin->id]);

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->board->admins()->syncWithoutDetaching([$this->admin->id]);

    $this->wonPhase = LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Won]);
    $this->source   = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Webflow-Formular']);

    Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $this->source->getKey(),
            'assigned_to'                 => $this->admin->id,
            'status'                      => LeadStatusEnum::Won,
            'value'                       => 1234.5,
        ]);

    $this->exportUrl = '/lead-pipeline/operations/export?boardId=' . $this->board->getKey() . '&preset=365';
});

it('streams an operations CSV with BOM, semicolons, and German decimals', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $this->withoutExceptionHandling();

    $response = $this->get($this->exportUrl)->assertOk();
    $content  = $response->streamedContent();

    expect(str_starts_with($content, "\xEF\xBB\xBF"))->toBeTrue()
        ->and($response->headers->get('Content-Type'))->toContain('csv')
        ->and($content)->toContain(';')
        ->and($content)->toContain('Webflow-Formular')
        ->and($content)->toContain('1.234,50');
});

it('includes ad-cost columns in the source economics CSV section', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $costSource = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Cost-Source']);
    Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $costSource->getKey(),
            'assigned_to'                 => $this->admin->id,
            'status'                      => LeadStatusEnum::Won,
            'source_campaign_id'          => 'c-cost-1',
        ]);

    MetaInsightSnapshot::factory()->create([
        'team_uuid'      => $this->team->uuid,
        'campaign_id'    => 'c-cost-1',
        'breakdown_type' => 'none',
        'spend'          => 100,
    ]);

    $content = $this->get($this->exportUrl)->assertOk()->streamedContent();

    expect($content)->toContain('Kosten/Lead')
        ->and($content)->toContain('Kosten/Akquise')
        ->and($content)->toContain('Cost-Source')
        ->and($content)->toContain('100,00');
});

it('does not leak a colleague causer row into a non-admin advisor CSV export via activity causer_id', function (): void {
    $advisor   = User::factory()->create(['first_name' => 'Nur', 'last_name' => 'Meins']);
    $colleague = User::factory()->create(['first_name' => 'Fremder', 'last_name' => 'Causer']);
    $this->team->users()->syncWithoutDetaching([$advisor->id, $colleague->id]);
    // $advisor is NOT an admin of $this->board (only $this->admin is, from beforeEach).

    $ownLead = Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create(['assigned_to' => $advisor->id, 'status' => LeadStatusEnum::Won]);

    // $colleague logs a note on ADVISOR's own lead — advisorActivityMatrix() groups
    // activity counts by causer_id, so without the non-leadership row filter this
    // would surface $colleague as their own foreign matrix row in $advisor's export,
    // even though the lead itself is correctly scoped to $advisor.
    $ownLead->activities()->create([
        'type'        => JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum::Note->value,
        'description' => 'Fremde Notiz',
        'causer_type' => config('lead-pipeline.user_model'),
        'causer_id'   => $colleague->id,
    ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($advisor);
    Filament::setTenant($this->team);

    $csv = $this->get('/lead-pipeline/operations/export?preset=365')->assertOk()->streamedContent();

    expect($csv)->toContain('Nur Meins')
        ->and($csv)->not->toContain('Fremder Causer');
});

it('rejects boards the requesting user cannot access on their tenant', function (): void {
    $other     = User::factory()->create();
    $otherTeam = Team::factory()->create();
    $otherTeam->users()->syncWithoutDetaching([$other->id]);
    $foreignBoard = LeadBoard::factory()->create(['team_uuid' => $otherTeam->uuid]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $this->get('/lead-pipeline/operations/export?boardId=' . $foreignBoard->getKey() . '&preset=365')
        ->assertForbidden();
});

it('restricts a non-admin advisor to their own leads across all boards when the export has no board filter', function (): void {
    $advisor = User::factory()->create(['first_name' => 'Avi', 'last_name' => 'Sor']);
    $this->team->users()->syncWithoutDetaching([$advisor->id]);

    // $advisor is NOT an admin of $this->board (only $this->admin is, from beforeEach).
    $advisorSource = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Advisor-Only-Source']);
    Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $advisorSource->getKey(),
            'assigned_to'                 => $advisor->id,
            'status'                      => LeadStatusEnum::Won,
        ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($advisor);
    Filament::setTenant($this->team);

    $content = $this->get('/lead-pipeline/operations/export?preset=365')->assertOk()->streamedContent();

    // The beforeEach lead (Webflow-Formular) is assigned to $this->admin on the same
    // board — a board-admin would see it, but the advisor must not.
    expect($content)->toContain('Advisor-Only-Source')
        ->and($content)->not->toContain('Webflow-Formular');
});

it('lets a board admin see all sources across the tenant when the export has no board filter', function (): void {
    $advisor = User::factory()->create(['first_name' => 'Avi', 'last_name' => 'Sor']);
    $this->team->users()->syncWithoutDetaching([$advisor->id]);

    $advisorSource = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Advisor-Only-Source']);
    Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $advisorSource->getKey(),
            'assigned_to'                 => $advisor->id,
            'status'                      => LeadStatusEnum::Won,
        ]);

    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $content = $this->get('/lead-pipeline/operations/export?preset=365')->assertOk()->streamedContent();

    expect($content)->toContain('Advisor-Only-Source')
        ->and($content)->toContain('Webflow-Formular');
});

it('honours custom dateFrom/dateTo over the preset and supports the all preset', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $src = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Range-Quelle']);

    // Lead (Won) mit Kampagnen-Attribution; Kampagnen-Spend März 100 / Januar 500.
    Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $src->getKey(),
            'assigned_to'                 => $this->admin->id,
            'status'                      => LeadStatusEnum::Won,
            'source_campaign_id'          => 'c-exp',
        ]);
    MetaInsightSnapshot::factory()->create(['team_uuid' => $this->team->uuid, 'campaign_id' => 'c-exp', 'breakdown_type' => 'none', 'spend' => 100, 'date' => '2026-03-10']);
    MetaInsightSnapshot::factory()->create(['team_uuid' => $this->team->uuid, 'campaign_id' => 'c-exp', 'breakdown_type' => 'none', 'spend' => 500, 'date' => '2026-01-05']);

    // Custom-Datum gewinnt über das Preset → nur der März-Spend (100) zählt.
    $csv = $this->get(route('lead-pipeline.operations.export', [
        'boardId'  => $this->board->getKey(),
        'preset'   => '7',
        'dateFrom' => '2026-03-01',
        'dateTo'   => '2026-03-31',
    ]))->assertOk()->streamedContent();

    expect($csv)->toContain('Range-Quelle')
        ->and($csv)->toContain('100,00')
        ->and($csv)->not->toContain('600,00');

    // Diskriminanz-Check: dasselbe Preset OHNE Custom-Datum fenstert auf die
    // letzten 7 Tage → kein Snapshot im Fenster, keine Kostenwerte.
    $presetOnly = $this->get(route('lead-pipeline.operations.export', [
        'boardId' => $this->board->getKey(),
        'preset'  => '7',
    ]))->assertOk()->streamedContent();

    expect($presetOnly)->toContain('Range-Quelle')
        ->and($presetOnly)->not->toContain('100,00')
        ->and($presetOnly)->not->toContain('600,00');

    // 'all' → unbounded, summiert beide Snapshots (600).
    $all = $this->get(route('lead-pipeline.operations.export', [
        'boardId' => $this->board->getKey(),
        'preset'  => 'all',
    ]))->assertOk()->streamedContent();

    expect($all)->toContain('600,00');
});

it('exports the advisor matrix with resolved names and subscores', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $advisor = User::factory()->create(['first_name' => 'CSV', 'last_name' => 'Berater']);
    $board   = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $board->admins()->syncWithoutDetaching([$this->admin->id]);
    $phase = LeadPhase::factory()->for($board, 'board')->open()->create();
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['assigned_to' => $advisor->id]);

    $response = $this->get(route('lead-pipeline.operations.export', ['boardId' => $board->getKey(), 'preset' => 'all']));

    $response->assertOk();
    $csv = $response->streamedContent();

    expect($csv)->toContain('CSV Berater')
        ->toContain(__('lead-pipeline::lead-pipeline.operations.matrix_title'))
        ->not->toContain((string) $advisor->id)
        ->toContain('Score');
});

// --- Unmasked out-of-panel export tests ------------------------------------------
// Every test above calls Filament::setCurrentPanel()/setTenant() itself before
// hitting the route — that masks the real production shape: the export route is
// registered outside any panel (routes/api.php), so Filament's own tenancy
// middleware never runs and filament()->getTenant() is null. These tests
// deliberately skip those calls (auth only) to reproduce the real request.

it('resolves leadership from a valid tenant query param outside a panel request', function (): void {
    $advisor = User::factory()->create(['first_name' => 'Aussen', 'last_name' => 'Berater']);
    $this->team->users()->syncWithoutDetaching([$advisor->id]);

    $advisorSource = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Aussen-Quelle']);
    Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $advisorSource->getKey(),
            'assigned_to'                 => $advisor->id,
            'status'                      => LeadStatusEnum::Won,
        ]);

    // $this->admin is a board admin (leadership per beforeEach). No
    // Filament::setCurrentPanel()/setTenant() call here — only auth plus the
    // tenant query param LeadOperations::getExportUrl() now sends.
    $this->actingAs($this->admin);

    $csv = $this->get($this->exportUrl . '&tenant=' . $this->team->getKey())
        ->assertOk()
        ->streamedContent();

    expect($csv)->toContain('Ada Min')
        ->and($csv)->toContain('Aussen Berater');
});

it('scopes a non-admin advisor to their own row when exporting with a tenant param outside a panel request', function (): void {
    $advisor = User::factory()->create(['first_name' => 'Nur', 'last_name' => 'Eigene']);
    $this->team->users()->syncWithoutDetaching([$advisor->id]);
    // $advisor is NOT an admin of $this->board (only $this->admin is, from beforeEach).

    $advisorSource = LeadSource::factory()->for($this->board, 'board')->create(['name' => 'Eigene-Quelle']);
    Lead::factory()
        ->for($this->wonPhase, 'phase')
        ->for($this->board, 'board')
        ->create([
            Lead::fkColumn('lead_source') => $advisorSource->getKey(),
            'assigned_to'                 => $advisor->id,
            'status'                      => LeadStatusEnum::Won,
        ]);

    $this->actingAs($advisor);

    $csv = $this->get($this->exportUrl . '&tenant=' . $this->team->getKey())
        ->assertOk()
        ->streamedContent();

    // The beforeEach lead (Webflow-Formular) is assigned to $this->admin on the same
    // board — a leader would see it, but this non-admin advisor must not.
    expect($csv)->toContain('Nur Eigene')
        ->and($csv)->not->toContain('Webflow-Formular');
});

it('aborts with 403 when no tenant can be resolved outside a panel request', function (): void {
    $this->actingAs($this->admin);

    // No &tenant= query param, and nothing set Filament::setTenant() in-process.
    $this->get($this->exportUrl)->assertForbidden();
});

it('aborts with 403 for a forged tenant param the user cannot access', function (): void {
    $otherTeam = Team::factory()->create();

    $this->actingAs($this->admin);

    $this->get($this->exportUrl . '&tenant=' . $otherTeam->getKey())->assertForbidden();
});

it('does not 500 on an unparseable dateFrom outside a panel request with a valid tenant', function (): void {
    $this->actingAs($this->admin);

    $this->get('/lead-pipeline/operations/export?boardId=' . $this->board->getKey() . '&tenant=' . $this->team->getKey() . '&dateFrom=garbage')
        ->assertOk();
});
