<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;

beforeEach(fn () => CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-06-10 12:00:00')));
afterEach(fn () => CarbonImmutable::setTestNow());

it('resolves last30days to the 30 days before today', function (): void {
    $range = ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days);

    expect($range->from->toDateString())->toBe('2026-05-11')
        ->and($range->till->toDateString())->toBe('2026-06-09')
        ->and($range->days())->toBe(30);
});

it('resolves last_month to the previous calendar month', function (): void {
    $range = ReportDateRange::fromPreset(ReportDatePresetEnum::LastMonth);

    expect($range->from->toDateString())->toBe('2026-05-01')
        ->and($range->till->toDateString())->toBe('2026-05-31');
});

it('computes the previous range with equal length ending right before from', function (): void {
    $range    = ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days);
    $previous = $range->previous();

    expect($previous->till->toDateString())->toBe('2026-05-10')
        ->and($previous->days())->toBe(30);
});

it('builds custom ranges from explicit dates', function (): void {
    $range = ReportDateRange::fromPreset(
        ReportDatePresetEnum::Custom,
        CarbonImmutable::parse('2026-04-01'),
        CarbonImmutable::parse('2026-04-15'),
    );

    expect($range->days())->toBe(15);
});

it('falls back to last30days when custom dates are missing', function (): void {
    $range = ReportDateRange::fromPreset(ReportDatePresetEnum::Custom);

    expect($range->preset)->toBe(ReportDatePresetEnum::Last30Days);
});

it('clamps meta api ranges to the 37 month insights window', function (): void {
    $range = ReportDateRange::fromPreset(ReportDatePresetEnum::AllTime);

    $clamped = $range->clampForMetaApi();

    // Meta-Fehler 3018: start darf nicht weiter als 37 Monate zurückliegen — wir klemmen auf 36 Monate (Puffer)
    expect($clamped->from->toDateString())->toBe(CarbonImmutable::parse('2026-06-10')->subMonthsNoOverflow(36)->toDateString())
        ->and($clamped->till)->toEqual($range->till);
});

it('leaves ranges inside the meta window untouched when clamping', function (): void {
    $range = ReportDateRange::fromPreset(ReportDatePresetEnum::Last30Days);

    expect($range->clampForMetaApi())->toEqual($range);
});
