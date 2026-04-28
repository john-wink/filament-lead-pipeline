<?php

declare(strict_types=1);

use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadBoardStat;

it('casts counts to array and period_date to a date', function (): void {
    $board = LeadBoard::factory()->create();

    $stat = LeadBoardStat::query()->create([
        'lead_board_uuid' => $board->uuid,
        'period_date'     => '2026-04-28',
        'counts'          => ['total' => 10, 'won' => 2],
    ]);

    expect($stat->counts)->toBe(['total' => 10, 'won' => 2])
        ->and($stat->period_date)->toBeInstanceOf(Carbon::class)
        ->and($stat->period_date->toDateString())->toBe('2026-04-28');
});

it('belongs to a lead board', function (): void {
    $board = LeadBoard::factory()->create();

    $stat = LeadBoardStat::query()->create([
        'lead_board_uuid' => $board->uuid,
        'period_date'     => '2026-04-28',
        'counts'          => ['total' => 0],
    ]);

    expect($stat->board)->toBeInstanceOf(LeadBoard::class)
        ->and($stat->board->uuid)->toBe($board->uuid);
});
