<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Filament\Resources\LeadBoardResource\Pages\EditLeadBoard;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
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

it('shows the editing hint when board has leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertSee(__('lead-pipeline::lead-pipeline.board.has_leads_hint'));
});

it('does not show the editing hint when board has no leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    livewire(EditLeadBoard::class, ['record' => $board->getKey()])
        ->assertDontSee(__('lead-pipeline::lead-pipeline.board.has_leads_hint'));
});

it('returns true from hasLeads when board has leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create();
    Lead::factory()->for($phase, 'phase')->for($board, 'board')->create();

    expect($board->hasLeads())->toBeTrue();
});

it('returns false from hasLeads when board has no leads', function (): void {
    $board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);

    expect($board->hasLeads())->toBeFalse();
});
