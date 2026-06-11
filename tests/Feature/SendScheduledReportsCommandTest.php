<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Mail;
use JohnWink\FilamentLeadPipeline\Mail\ScheduledReportMail;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportSchedule;

function dueSchedule(array $attributes = []): LeadReportSchedule
{
    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid]);

    return LeadReportSchedule::factory()->create([
        'report_uuid' => $report->uuid,
        'next_run_at' => now()->subMinutes(5),
        'recipients'  => ['kunde@example.com'],
        ...$attributes,
    ]);
}

it('sends due schedules with link, recomputes next_run_at and stores last_sent_at', function (): void {
    $schedule = dueSchedule(['attach_pdf' => false]);

    $this->artisan('lead-pipeline:send-scheduled-reports')->assertSuccessful();

    Mail::assertQueued(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => $mail->hasTo('kunde@example.com'));
    $schedule->refresh();
    expect($schedule->last_sent_at)->not->toBeNull()
        ->and($schedule->next_run_at->isFuture())->toBeTrue();

    // Sendeverlauf (Spec §8): pro Versand ein Log-Eintrag
    $send = JohnWink\FilamentLeadPipeline\Models\LeadReportSend::query()->where('schedule_uuid', $schedule->uuid)->first();
    expect($send)->not->toBeNull()
        ->and($send->pdf_attached)->toBeFalse()
        ->and($send->recipients)->toBe(['kunde@example.com']);
});

it('runs a fresh sync per ad source before sending and still sends when the sync fails', function (): void {
    Illuminate\Support\Facades\Http::fake([
        'graph.facebook.com/*' => Illuminate\Support\Facades\Http::response(['error' => ['code' => 1, 'message' => 'boom']], 500),
    ]);

    $schedule   = dueSchedule(['attach_pdf' => false]);
    $report     = $schedule->report;
    $connection = JohnWink\FilamentLeadPipeline\Models\FacebookConnection::factory()->create([
        'team_uuid'    => $report->team_uuid,
        'access_token' => 'tok',
        'user_uuid'    => App\Models\User::query()->where('email', 'admin@test.com')->firstOrFail()->id,
    ]);
    $report->adSources()->create(['facebook_connection_uuid' => $connection->uuid, 'ad_account_id' => 'act_1']);

    $this->artisan('lead-pipeline:send-scheduled-reports')->assertSuccessful();

    // Sync ist gescheitert (500) — der Versand läuft trotzdem mit Bestandsdaten (Spec §11)
    Mail::assertQueued(ScheduledReportMail::class);
});

it('skips inactive and not-yet-due schedules', function (): void {
    dueSchedule(['is_active' => false]);
    dueSchedule(['next_run_at' => now()->addDay()]);

    $this->artisan('lead-pipeline:send-scheduled-reports')->assertSuccessful();

    Mail::assertNothingOutgoing();
});

it('falls back to link-only mail when pdf rendering fails', function (): void {
    $schedule = dueSchedule(['attach_pdf' => true]); // NullRenderer wirft

    $this->artisan('lead-pipeline:send-scheduled-reports')->assertSuccessful();

    Mail::assertQueued(ScheduledReportMail::class, fn (ScheduledReportMail $mail): bool => null === $mail->pdfContents);
});

it('initializes next_run_at for schedules created without one', function (): void {
    $schedule = dueSchedule(['next_run_at' => null]);

    $this->artisan('lead-pipeline:send-scheduled-reports')->assertSuccessful();

    expect($schedule->refresh()->next_run_at)->not->toBeNull();
    Mail::assertNothingOutgoing();
});
