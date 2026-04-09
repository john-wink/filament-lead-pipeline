<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Filament\Widgets\LeadStatsWidget;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use App\Models\Team;

use function Pest\Livewire\livewire;

beforeEach(function (): void {
    $this->team = Team::query()->firstWhere('slug', 'test');
    $this->user = $this->team->users->first();
    $this->actingAs($this->user);
    filament()->setCurrentPanel(filament()->getPanel('admin'));
    filament()->setTenant($this->team);
});

it('renders the stats widget', function (): void {
    livewire(LeadStatsWidget::class)
        ->assertSuccessful();
});

it('shows correct total lead count', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    Lead::factory()
        ->count(7)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee(number_format(7))
        ->assertSee('Gesamt Leads');
});

it('shows correct active lead count', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    Lead::factory()
        ->count(3)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create(['status' => LeadStatusEnum::Active]);

    Lead::factory()
        ->count(2)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->won()
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee('3 aktiv');
});

it('shows correct won count with win rate percentage', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    // 2 active + 3 won = 5 total -> 60% win rate
    Lead::factory()
        ->count(2)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create(['status' => LeadStatusEnum::Active]);

    Lead::factory()
        ->count(3)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->won()
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee('Gewonnen')
        ->assertSee(number_format(3))
        ->assertSee('60% Gewinnrate');
});

it('shows correct lost count with loss rate percentage', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    // 3 active + 2 lost = 5 total -> 40% loss rate
    Lead::factory()
        ->count(3)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create(['status' => LeadStatusEnum::Active]);

    Lead::factory()
        ->count(2)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->lost()
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee('Verloren')
        ->assertSee('40% Verlustrate');
});

it('shows correct converted count', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    Lead::factory()
        ->count(4)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->converted()
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee('Konvertiert')
        ->assertSee(number_format(4));
});

it('shows correct total value formatted as EUR', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    Lead::factory()
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create(['value' => 150000.50]);

    Lead::factory()
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create(['value' => 50000.00]);

    // Total: 200000.50 => "200.000,50 €"
    livewire(LeadStatsWidget::class)
        ->assertSee('Gesamtwert')
        ->assertSee('200.000,50');
});

it('shows correct won value', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    Lead::factory()
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->won()
        ->create(['value' => 75000.00]);

    Lead::factory()
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create(['value' => 50000.00, 'status' => LeadStatusEnum::Active]);

    // Won value: 75000.00 => "Gewonnen: 75.000,00 €"
    livewire(LeadStatsWidget::class)
        ->assertSee('Gewonnen: 75.000,00');
});

it('filters stats by board when boardId is set', function (): void {
    $board1 = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase1 = LeadPhase::factory()->for($board1, 'board')->create();

    $board2 = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase2 = LeadPhase::factory()->for($board2, 'board')->create();

    Lead::factory()
        ->count(5)
        ->for($board1, 'board')
        ->for($phase1, 'phase')
        ->create();

    Lead::factory()
        ->count(12)
        ->for($board2, 'board')
        ->for($phase2, 'phase')
        ->create();

    // When filtering by board1, should only see 5 leads
    livewire(LeadStatsWidget::class, ['boardId' => $board1->getKey()])
        ->assertSee(number_format(5))
        ->assertDontSee(number_format(17));
});

it('handles empty board with zero leads gracefully', function (): void {
    livewire(LeadStatsWidget::class)
        ->assertSuccessful()
        ->assertSee('Gesamt Leads')
        ->assertSee('0%');
});

it('calculates correct percentages with rounding', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    // 1 won out of 3 total = 33.3% win rate
    Lead::factory()
        ->count(2)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->create(['status' => LeadStatusEnum::Active]);

    Lead::factory()
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->won()
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee('33.3% Gewinnrate');
});

it('handles boards with only won leads showing 100 percent win rate', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    Lead::factory()
        ->count(3)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->won()
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee('100% Gewinnrate');
});

it('handles boards with only lost leads showing 100 percent loss rate', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();

    Lead::factory()
        ->count(2)
        ->for($board, 'board')
        ->for($phase, 'phase')
        ->lost()
        ->create();

    livewire(LeadStatsWidget::class)
        ->assertSee('100% Verlustrate');
});
