<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardSharedTenant;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardTeamShare;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\Team;
use JohnWink\FilamentLeadPipeline\Tests\Fixtures\Models\User;

it('makes an individually shared board visible to the target tenant', function (): void {
    $owner  = Team::factory()->create();
    $target = Team::factory()->create();
    $board  = LeadBoard::factory()->create(['team_uuid' => $owner->getKey()]);

    LeadBoardSharedTenant::query()->create([
        'lead_board_uuid'  => $board->getKey(),
        'shared_with_type' => Team::class,
        'shared_with_id'   => $target->getKey(),
        'permissions'      => null,
    ]);

    expect($board->fresh()->isSharedWith($target))->toBeTrue()
        ->and(LeadBoard::visibleToTenant($target)->pluck(LeadBoard::pkColumn())->all())->toContain($board->getKey());
});

it('makes every board of a team visible through an all-board team share', function (): void {
    $owner  = Team::factory()->create();
    $target = Team::factory()->create();

    $firstBoard  = LeadBoard::factory()->create(['team_uuid' => $owner->getKey()]);
    $secondBoard = LeadBoard::factory()->create(['team_uuid' => $owner->getKey()]);

    LeadBoardTeamShare::query()->create([
        'owner_team_id'    => $owner->getKey(),
        'shared_with_type' => Team::class,
        'shared_with_id'   => $target->getKey(),
        'permissions'      => null,
    ]);

    $visibleBoardIds = LeadBoard::visibleToTenant($target)->pluck(LeadBoard::pkColumn())->all();

    expect($firstBoard->fresh()->isSharedWith($target))->toBeTrue()
        ->and($visibleBoardIds)->toContain($firstBoard->getKey())
        ->and($visibleBoardIds)->toContain($secondBoard->getKey());
});

it('shows all leads on a board shared with the current tenant', function (): void {
    $owner  = Team::factory()->create();
    $target = Team::factory()->create();
    $user   = User::factory()->create();
    $board  = LeadBoard::factory()->create(['team_uuid' => $owner->getKey()]);
    $phase  = LeadPhase::factory()->for($board, 'board')->create();
    $lead   = Lead::factory()->for($phase, 'phase')->for($board, 'board')->create(['assigned_to' => null]);

    LeadBoardTeamShare::query()->create([
        'owner_team_id'    => $owner->getKey(),
        'shared_with_type' => Team::class,
        'shared_with_id'   => $target->getKey(),
        'permissions'      => null,
    ]);

    $this->actingAs($user);
    filament()->setTenant($target);

    expect(Lead::query()->whereKey($lead->getKey())->visibleTo($user, $board)->exists())->toBeTrue();
});
