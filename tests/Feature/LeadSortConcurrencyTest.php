<?php

declare(strict_types=1);

use App\Models\Team;
use JohnWink\FilamentLeadPipeline\Enums\LeadPhaseTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;

it('assigns sequential sort values via nextSortForPhase', function (): void {
    $team  = Team::query()->firstWhere('slug', 'test');
    $board = LeadBoard::factory()->create(['team_uuid' => $team->uuid]);
    $phase = LeadPhase::factory()->for($board, 'board')->create([
        'type' => LeadPhaseTypeEnum::Open, 'sort' => 0,
    ]);

    $first = Lead::nextSortForPhase($phase->getKey());
    Lead::factory()->for($board, 'board')->for($phase, 'phase')->create(['sort' => $first]);
    $second = Lead::nextSortForPhase($phase->getKey());

    expect($first)->toBe(1)->and($second)->toBe(2);
});
