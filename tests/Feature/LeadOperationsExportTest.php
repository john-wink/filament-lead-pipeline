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
