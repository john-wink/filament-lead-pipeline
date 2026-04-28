<?php

declare(strict_types=1);

use Carbon\Carbon;
use JohnWink\FilamentLeadPipeline\Aggregators\DefaultStatsAggregator;
use JohnWink\FilamentLeadPipeline\Contracts\StatsAggregatorContract;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

it('implements the StatsAggregatorContract', function (): void {
    expect(new DefaultStatsAggregator())->toBeInstanceOf(StatsAggregatorContract::class);
});

it('returns zero counts for an empty board', function (): void {
    $board = LeadBoard::factory()->create();

    $counts = (new DefaultStatsAggregator())->aggregate($board, Carbon::today());

    expect($counts)->toMatchArray([
        'total'       => 0,
        'new'         => 0,
        'qualified'   => 0,
        'transferred' => 0,
        'won'         => 0,
        'lost'        => 0,
    ]);
});

it('counts leads by phase-type bucket', function (): void {
    $board = LeadBoard::factory()->create();

    $openPhase = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Open]);
    $progPhase = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::InProgress]);
    $wonPhase  = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Won]);
    $lostPhase = LeadPhase::factory()->for($board, 'board')->create(['type' => LeadPhaseTypeEnum::Lost]);

    Lead::factory()->count(2)->for($board, 'board')->for($openPhase, 'phase')->create();
    Lead::factory()->count(3)->for($board, 'board')->for($progPhase, 'phase')->create();
    Lead::factory()->count(1)->for($board, 'board')->for($wonPhase, 'phase')->create();
    Lead::factory()->count(4)->for($board, 'board')->for($lostPhase, 'phase')->create();

    $counts = (new DefaultStatsAggregator())->aggregate($board, Carbon::today());

    expect($counts['total'])->toBe(10)
        ->and($counts['qualified'])->toBe(3)
        ->and($counts['transferred'])->toBe(1)
        ->and($counts['won'])->toBe(1)
        ->and($counts['lost'])->toBe(4);
});
