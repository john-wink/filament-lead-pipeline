<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Models\LeadBoard;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportView;

function makeReport(array $attributes = []): LeadReport
{
    $team = App\Models\Team::query()->where('slug', 'test')->firstOrFail();

    return LeadReport::factory()->create(['team_uuid' => $team->uuid, ...$attributes]);
}

it('generates a share token of at least 32 chars on creation', function (): void {
    $report = makeReport();

    expect(mb_strlen($report->share_token))->toBeGreaterThanOrEqual(32);
});

it('is accessible only when active and not expired', function (): void {
    expect(makeReport()->isAccessible())->toBeTrue()
        ->and(makeReport(['is_active' => false])->isAccessible())->toBeFalse()
        ->and(makeReport(['expires_at' => now()->subDay()])->isAccessible())->toBeFalse()
        ->and(makeReport(['expires_at' => now()->addDay()])->isAccessible())->toBeTrue();
});

it('hashes the password and verifies it', function (): void {
    $report = makeReport(['password' => 'geheim']);

    expect($report->requiresPassword())->toBeTrue()
        ->and($report->password)->not->toBe('geheim')
        ->and($report->passwordMatches('geheim'))->toBeTrue()
        ->and($report->passwordMatches('falsch'))->toBeFalse()
        ->and(makeReport()->requiresPassword())->toBeFalse();
});

it('records views as daily aggregates', function (): void {
    $report = makeReport();

    $report->recordView();
    $report->recordView();

    $aggregate = LeadReportView::query()->where('report_uuid', $report->uuid)->first();
    expect($aggregate->views)->toBe(2)
        ->and($aggregate->date->toDateString())->toBe(now()->toDateString())
        ->and($report->refresh()->views_count)->toBe(2)
        ->and($report->last_viewed_at)->not->toBeNull();
});

it('rotates the token to a new value', function (): void {
    $report = makeReport();
    $old    = $report->share_token;

    $report->rotateToken();

    expect($report->refresh()->share_token)->not->toBe($old);
});

it('links boards and exposes the date preset default', function (): void {
    $report = makeReport(['date_preset_default' => 'last7days']);
    $board  = LeadBoard::factory()->create(['team_uuid' => $report->team_uuid]);

    $report->boards()->attach($board->uuid);

    expect($report->boards)->toHaveCount(1)
        ->and($report->datePresetDefault())->toBe(ReportDatePresetEnum::Last7Days);
});

it('computes next run for weekly and monthly schedules', function (): void {
    Carbon\CarbonImmutable::setTestNow(Carbon\CarbonImmutable::parse('2026-06-10 12:00:00')); // Mittwoch

    $report = makeReport();
    $weekly = $report->schedules()->create([
        'frequency' => 'weekly', 'weekday' => 1, 'send_time' => '08:00', 'recipients' => ['a@b.de'], 'attach_pdf' => true, 'is_active' => true,
    ]);
    $monthly = $report->schedules()->create([
        'frequency' => 'monthly', 'day_of_month' => 1, 'send_time' => '08:00', 'recipients' => ['a@b.de'], 'attach_pdf' => false, 'is_active' => true,
    ]);

    expect($weekly->computeNextRunAt()->format('Y-m-d H:i'))->toBe('2026-06-15 08:00')
        ->and($monthly->computeNextRunAt()->format('Y-m-d H:i'))->toBe('2026-07-01 08:00');

    Carbon\CarbonImmutable::setTestNow();
});
