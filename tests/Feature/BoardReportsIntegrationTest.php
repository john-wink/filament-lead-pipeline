<?php

declare(strict_types=1);

use App\Models\Team;
use Illuminate\Support\Facades\Gate;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\RelationManagers\ReportsRelationManager;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadReportResource;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);

    Gate::before(fn ($user, string $ability): ?bool => in_array($ability, [
        'view_reports', 'create_reports', 'update_reports', 'delete_reports', 'manage_sharing',
    ], true) ? true : null);

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->board->admins()->syncWithoutDetaching([$this->user->getKey()]);
});

it('hides the report resource from the navigation', function (): void {
    expect(LeadReportResource::shouldRegisterNavigation())->toBeFalse();
});

it('exposes reports as a relation on the board', function (): void {
    $report = LeadReport::factory()->create(['team_uuid' => $this->team->uuid]);
    $report->boards()->attach($this->board->uuid);

    expect($this->board->reports)->toHaveCount(1)
        ->and($this->board->reports->first()->getKey())->toBe($report->getKey());
});

it('lists the board reports in the relation manager on the edit page', function (): void {
    $attached = LeadReport::factory()->create(['team_uuid' => $this->team->uuid, 'name' => 'Bergheim Report']);
    $attached->boards()->attach($this->board->uuid);
    $other = LeadReport::factory()->create(['team_uuid' => $this->team->uuid, 'name' => 'Anderes Board Report']);

    livewire(ReportsRelationManager::class, [
        'ownerRecord' => $this->board,
        'pageClass'   => EditLeadBoard::class,
    ])
        ->assertCanSeeTableRecords([$attached])
        ->assertCanNotSeeTableRecords([$other]);
});

it('prefills the board when creating a report from the board context', function (): void {
    livewire(LeadReportResource\Pages\CreateLeadReport::class, ['board' => $this->board->uuid])
        ->assertFormSet(['boards' => [$this->board->uuid]]);
});
