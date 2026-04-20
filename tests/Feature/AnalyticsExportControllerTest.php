<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Filament\Facades\Filament;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

beforeEach(function (): void {
    $this->team    = Team::query()->firstWhere('slug', 'test');
    $this->admin   = User::factory()->create(['first_name' => 'Ada', 'last_name' => 'Min']);
    $this->advisor = User::factory()->create(['first_name' => 'Avi', 'last_name' => 'Sor']);
    $this->team->users()->syncWithoutDetaching([$this->admin->id, $this->advisor->id]);

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->board->admins()->syncWithoutDetaching([$this->admin->id]);

    $this->openPhase = LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Open]);
    $this->wonPhase  = LeadPhase::factory()->for($this->board, 'board')->create(['type' => LeadPhaseTypeEnum::Won]);

    $this->ownLead     = Lead::factory()->for($this->openPhase, 'phase')->for($this->board, 'board')->create(['assigned_to' => $this->advisor->id, 'name' => 'Own Lead']);
    $this->othersLead  = Lead::factory()->for($this->openPhase, 'phase')->for($this->board, 'board')->create(['assigned_to' => $this->admin->id, 'name' => 'Others Lead']);
    $this->unassigned  = Lead::factory()->for($this->openPhase, 'phase')->for($this->board, 'board')->create(['assigned_to' => null, 'name' => 'Floating']);

    $this->exportUrl = '/lead-pipeline/analytics/export?boardId=' . $this->board->getKey() . '&section=berater&preset=365';
});

it('streams a CSV with UTF-8 BOM for board admins', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $this->withoutExceptionHandling();

    $response = $this->get($this->exportUrl)->assertOk();

    $content = $response->streamedContent();

    expect(substr($content, 0, 3))->toBe("\xEF\xBB\xBF")
        ->and($response->headers->get('Content-Type'))->toContain('csv');
});

it('shows all board leads to admins in the advisor section', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->admin);
    Filament::setTenant($this->team);

    $content = $this->get($this->exportUrl)->assertOk()->streamedContent();

    // Both admin-assigned and advisor-assigned leads are counted in the advisor report.
    expect($content)->toContain($this->admin->name)
        ->and($content)->toContain($this->advisor->name);
});

it('restricts non-admin advisors to their own leads when querying the same board', function (): void {
    Filament::setCurrentPanel(Filament::getPanel('admin'));
    $this->actingAs($this->advisor);
    Filament::setTenant($this->team);

    $matrixUrl = '/lead-pipeline/analytics/export?boardId=' . $this->board->getKey() . '&section=matrix&preset=365';
    $content   = $this->get($matrixUrl)->assertOk()->streamedContent();

    // Own lead reachable, admin-only lead must not leak into the CSV.
    expect($content)->toContain('Avi Sor')
        ->and($content)->not->toContain('Others Lead');
});

