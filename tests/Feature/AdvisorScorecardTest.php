<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

function activityByCard(Lead $lead, LeadActivityTypeEnum $type, int|string $causerId, CarbonImmutable $at, array $properties = []): void
{
    $activity = $lead->activities()->make([
        'type'        => $type->value,
        'properties'  => $properties,
        'causer_type' => config('lead-pipeline.user_model'),
        'causer_id'   => $causerId,
    ]);
    $activity->forceFill(['created_at' => $at]);
    $activity->save();
}

afterEach(function (): void {
    CarbonImmutable::setTestNow();
    Carbon::setTestNow();
});

it('returns the advisor row, team aggregate and rank', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $a     = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Eins', 'last_name' => '']);
    $b     = config('lead-pipeline.user_model')::factory()->create(['first_name' => 'Zwei', 'last_name' => '']);
    $board = LeadBoard::factory()->create();
    $open  = LeadPhase::factory()->for($board, 'board')->open()->create();
    $lead  = Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $a->getKey()]);
    Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['assigned_to' => $b->getKey()]);
    activityByCard($lead, LeadActivityTypeEnum::Call, $a->getKey(), CarbonImmutable::parse('2026-06-14'));

    $card = app(LeadActivityMetricsService::class)->advisorScorecard(
        $a->getKey(),
        Lead::query(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($card['row']['advisor_name'])->toBe('Eins')
        ->and($card['rank'])->toBe(1)
        ->and($card['total_advisors'])->toBe(2)
        ->and($card['team'])->toHaveKey('score_avg');
});

it('returns a null row for an advisor outside the scope', function (): void {
    $card = app(LeadActivityMetricsService::class)->advisorScorecard('does-not-exist', Lead::query(), null, null);

    expect($card['row'])->toBeNull()->and($card['rank'])->toBeNull();
});
