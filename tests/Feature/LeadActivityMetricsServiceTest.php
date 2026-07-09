<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use JohnWink\FilamentLeadPipeline\Enums\LeadActivityTypeEnum;
use JohnWink\FilamentLeadPipeline\Enums\LeadStatusEnum;
use JohnWink\FilamentLeadPipeline\Models\Lead;
use JohnWink\FilamentLeadPipeline\Services\LeadActivityMetricsService;

function scoped(): Illuminate\Database\Eloquent\Builder
{
    return Lead::query();
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('computes response average, buckets and SLA', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');

    // responded in 30 min (under_1h, within 60-min SLA)
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => $now->subDays(1)->addMinutes(30)]);
    // responded in 5 hours (h1_24, breaches SLA)
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => $now->subDays(1)->addHours(5)]);
    // never responded
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => null]);

    $stats = app(LeadActivityMetricsService::class)->responseStats(scoped(), $now->subDays(7), $now, 60);

    expect($stats['total'])->toBe(3)
        ->and($stats['responded'])->toBe(2)
        ->and($stats['buckets']['under_1h'])->toBe(1)
        ->and($stats['buckets']['h1_24'])->toBe(1)
        ->and($stats['avg_minutes'])->toBe(165.0)   // (30 + 300) / 2
        ->and($stats['sla_pct'])->toBe(50.0);       // 1 of 2 responded within SLA
});

it('treats a backdated first_response_at by magnitude, never as negative', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');

    // Data anomaly: response stamped 90 min BEFORE the lead's created_at.
    // Signed diff would be -90 → silently under_1h + SLA-compliant. Absolute → 90 min.
    Lead::factory()->create(['created_at' => $now->subDays(1), 'first_response_at' => $now->subDays(1)->subMinutes(90)]);

    $stats = app(LeadActivityMetricsService::class)->responseStats(scoped(), $now->subDays(7), $now, 60);

    expect($stats['responded'])->toBe(1)
        ->and($stats['buckets']['under_1h'])->toBe(0)   // NOT under_1h
        ->and($stats['buckets']['h1_24'])->toBe(1)      // 90 min → h1_24
        ->and($stats['sla_pct'])->toBe(0.0)             // 90 > 60 → NOT SLA-compliant
        ->and($stats['avg_minutes'])->toBe(90.0);
});

it('computes follow-up, untouched and contact-attempt stats (snapshot)', function (): void {
    $now = CarbonImmutable::parse('2026-03-15 12:00:00');
    CarbonImmutable::setTestNow($now);

    // A: active, OVERDUE reminder, old, 2 contact attempts (Call + Email)
    $a = Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => $now->subDay(), 'created_at' => $now->subDays(3)]);
    $a->activities()->create(['type' => LeadActivityTypeEnum::Call->value, 'created_at' => $now->subDays(2)]);
    $a->activities()->create(['type' => LeadActivityTypeEnum::Email->value, 'created_at' => $now->subDay()]);

    // B: active, old (20d), NO activity → untouched
    Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => null, 'created_at' => $now->subDays(20)]);

    // C: active, FUTURE reminder, YOUNG (2d), no activity → NOT overdue, NOT untouched, but has a next step
    Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => $now->addDays(2), 'created_at' => $now->subDays(2)]);

    // D: active, old (20d), only a NOTE activity → NOT untouched (Note is a touch), Note NOT a contact attempt
    $d = Lead::factory()->create(['status' => LeadStatusEnum::Active, 'reminder_at' => null, 'created_at' => $now->subDays(20)]);
    $d->activities()->create(['type' => LeadActivityTypeEnum::Note->value, 'created_at' => $now->subDays(10)]);

    $stats = app(LeadActivityMetricsService::class)->operationsStats(scoped());

    expect($stats['overdue_followups'])->toBe(1)          // A only (C is future)
        ->and($stats['untouched'])->toBe(1)               // B only (C young, D has a Note touch)
        ->and($stats['next_step_rate'])->toBe(50.0)       // A + C have reminders, of 4 active
        ->and($stats['avg_contact_attempts'])->toBe(0.5); // 2 Call/Email attempts / 4 active (D's Note excluded)
});
