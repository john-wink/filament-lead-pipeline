<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

beforeEach(function (): void {
    $this->team    = Team::query()->firstWhere('slug', 'test');
    $this->admin   = User::factory()->create();
    $this->advisor = User::factory()->create();
    $this->team->users()->syncWithoutDetaching([$this->admin->id, $this->advisor->id]);

    $this->board = LeadBoard::factory()->create(['team_uuid' => $this->team->uuid]);
    $this->board->admins()->syncWithoutDetaching([$this->admin->id]);

    $this->phase = LeadPhase::factory()->for($this->board, 'board')->create();

    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create(['assigned_to' => $this->advisor->id, 'name' => 'Own']);
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create(['assigned_to' => $this->admin->id, 'name' => 'Admin Lead']);
    Lead::factory()->for($this->phase, 'phase')->for($this->board, 'board')->create(['assigned_to' => null, 'name' => 'Floating']);
});

it('returns all board leads for board admins', function (): void {
    $count = Lead::query()->visibleTo($this->admin, $this->board)->count();

    expect($count)->toBe(3);
});

it('limits non-admin users to leads assigned to themselves', function (): void {
    $names = Lead::query()->visibleTo($this->advisor, $this->board)->pluck('name')->all();

    expect($names)->toBe(['Own']);
});

it('returns no leads for a user with neither assignments nor admin rights', function (): void {
    $stranger = User::factory()->create();

    $count = Lead::query()->visibleTo($stranger, $this->board)->count();

    expect($count)->toBe(0);
});
