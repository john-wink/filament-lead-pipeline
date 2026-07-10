<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadPhase;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

// activityByProto(): file-local re-declaration of the activityBy()-style
// helper used by AdvisorMatrixTest/AdvisorScoreTest/AdvisorScorecardTest —
// Pest test files share one PHP process, so a same-named global function
// would fatal with "Cannot redeclare".
function activityByProto(Lead $lead, LeadActivityTypeEnum $type, int|string $causerId, CarbonImmutable $at, array $properties = []): void
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

it('groups the advisor protocol by day, newest first, with pagination', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $advisor = config('lead-pipeline.user_model')::factory()->create();
    $board   = LeadBoard::factory()->create();
    $open    = LeadPhase::factory()->for($board, 'board')->open()->create();
    $lead    = Lead::factory()->for($board, 'board')->for($open, 'phase')->create(['name' => 'Musterlead']);

    activityByProto($lead, LeadActivityTypeEnum::Call, $advisor->getKey(), CarbonImmutable::parse('2026-06-14 09:00:00'));
    activityByProto($lead, LeadActivityTypeEnum::Note, $advisor->getKey(), CarbonImmutable::parse('2026-06-14 11:00:00'));
    activityByProto($lead, LeadActivityTypeEnum::Email, $advisor->getKey(), CarbonImmutable::parse('2026-06-13 08:00:00'));

    $protocol = app(LeadActivityMetricsService::class)->advisorProtocol(
        $advisor->getKey(),
        Lead::query(),
        CarbonImmutable::parse('2026-06-01'),
        CarbonImmutable::parse('2026-06-30'),
    );

    expect($protocol['total'])->toBe(3)
        ->and($protocol['has_more'])->toBeFalse()
        ->and($protocol['days'][0]['date'])->toBe('2026-06-14')
        ->and($protocol['days'][0]['items'])->toHaveCount(2)
        ->and($protocol['days'][0]['items'][0]['time'])->toBe('11:00')   // neueste zuerst
        ->and($protocol['days'][0]['items'][0]['lead_name'])->toBe('Musterlead')
        ->and($protocol['days'][1]['date'])->toBe('2026-06-13');
});

it('paginates with has_more and never leaks another causer', function (): void {
    Carbon::setTestNow('2026-06-15 12:00:00');
    $advisor = config('lead-pipeline.user_model')::factory()->create();
    $other   = config('lead-pipeline.user_model')::factory()->create();
    $board   = LeadBoard::factory()->create();
    $open    = LeadPhase::factory()->for($board, 'board')->open()->create();
    $lead    = Lead::factory()->for($board, 'board')->for($open, 'phase')->create();

    foreach (range(1, 3) as $i) {
        activityByProto($lead, LeadActivityTypeEnum::Call, $advisor->getKey(), CarbonImmutable::parse('2026-06-10')->addHours($i));
    }
    activityByProto($lead, LeadActivityTypeEnum::Call, $other->getKey(), CarbonImmutable::parse('2026-06-10 08:00:00'));

    $page1 = app(LeadActivityMetricsService::class)->advisorProtocol(
        $advisor->getKey(),
        Lead::query(),
        null,
        null,
        limit: 2,
        offset: 0,
    );

    expect($page1['total'])->toBe(3)
        ->and($page1['has_more'])->toBeTrue()
        ->and(collect($page1['days'])->flatMap(fn ($d) => $d['items'])->count())->toBe(2);
});
