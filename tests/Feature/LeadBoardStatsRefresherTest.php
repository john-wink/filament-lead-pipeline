<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Jobs\LeadBoardStatsRefresher;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardStat;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

it('writes a stats snapshot per active board for the current day', function (): void {
    $boardA = LeadBoard::factory()->create(['is_active' => true]);
    $boardB = LeadBoard::factory()->create(['is_active' => true]);
    LeadBoard::factory()->create(['is_active' => false]);

    (new LeadBoardStatsRefresher())->handle();

    expect(LeadBoardStat::query()->count())->toBe(2)
        ->and(LeadBoardStat::query()->where('lead_board_uuid', $boardA->uuid)->exists())->toBeTrue()
        ->and(LeadBoardStat::query()->where('lead_board_uuid', $boardB->uuid)->exists())->toBeTrue();
});

it('aggregates counts using the default aggregator', function (): void {
    $board    = LeadBoard::factory()->create();
    $wonPhase = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Won]);

    Lead::factory()->count(3)->for($board, 'board')->for($wonPhase, 'phase')->create();

    (new LeadBoardStatsRefresher())->handle();

    $stat = LeadBoardStat::query()->where('lead_board_uuid', $board->uuid)->first();

    expect($stat)->not->toBeNull()
        ->and($stat->counts['won'])->toBe(3)
        ->and($stat->counts['transferred'])->toBe(3);
});

it('updates an existing snapshot for the same board and date', function (): void {
    $board = LeadBoard::factory()->create();

    LeadBoardStat::query()->create([
        'lead_board_uuid' => $board->uuid,
        'period_date'     => Carbon::today()->toDateString(),
        'counts'          => ['total' => 999],
    ]);

    (new LeadBoardStatsRefresher())->handle();

    $stat = LeadBoardStat::query()->where('lead_board_uuid', $board->uuid)->first();

    expect($stat->counts['total'])->toBe(0)
        ->and(LeadBoardStat::query()->where('lead_board_uuid', $board->uuid)->count())->toBe(1);
});
