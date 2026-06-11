<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use JohnWink\FilamentLeadPipeline\Contracts\ReportPdfRenderer;
use JohnWink\FilamentLeadPipeline\Jobs\SyncMetaInsightsJob;
use JohnWink\FilamentLeadPipeline\Mail\ScheduledReportMail;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Models\LeadReportSchedule;
use JohnWink\FilamentLeadPipeline\Models\LeadReportSend;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;
use Throwable;

class SendScheduledReportsCommand extends Command
{
    protected $signature = 'lead-pipeline:send-scheduled-reports';

    protected $description = 'Versendet fällige Report-Schedules per E-Mail';

    public function handle(): int
    {
        LeadReportSchedule::query()
            ->where('is_active', true)
            ->whereNull('next_run_at')
            ->each(fn (LeadReportSchedule $schedule) => $schedule->update(['next_run_at' => $schedule->computeNextRunAt()]));

        $due = LeadReportSchedule::query()
            ->where('is_active', true)
            ->where('next_run_at', '<=', now())
            ->with('report')
            ->get();

        foreach ($due as $schedule) {
            $report = $schedule->report;

            if (null === $report || ! $report->isAccessible()) {
                $schedule->update(['next_run_at' => $schedule->computeNextRunAt()]);

                continue;
            }

            // Frischer Sync vor dem Versand (Spec §11) — Fehler stoppen den Versand nicht (Bestandsdaten)
            foreach ($report->adSources as $source) {
                try {
                    SyncMetaInsightsJob::dispatchSync($source->facebook_connection_uuid, $source->ad_account_id, $source->campaign_ids, 7);
                } catch (Throwable $exception) {
                    report($exception);
                }
            }

            $pdf = null;

            if ($schedule->attach_pdf) {
                try {
                    $pdf = app(ReportPdfRenderer::class)->render($report, ReportDateRange::fromPreset($report->datePresetDefault()));
                } catch (Throwable) {
                    $pdf = null; // Link-only-Fallback laut Spec
                }
            }

            try {
                foreach ($schedule->recipients as $recipient) {
                    Mail::to($recipient)->queue(new ScheduledReportMail($report, $pdf));
                }

                LeadReportSend::query()->create([
                    'schedule_uuid' => $schedule->uuid,
                    'sent_at'       => now(),
                    'recipients'    => $schedule->recipients,
                    'pdf_attached'  => null !== $pdf,
                    'status'        => 'sent',
                ]);

                $schedule->update([
                    'last_sent_at'  => now(),
                    'next_run_at'   => $schedule->computeNextRunAt(),
                    'failure_count' => 0,
                ]);
            } catch (Throwable $exception) {
                // Backoff an failure_count gekoppelt: 60/120/240, ab 3 Fehlschlägen gedeckelt (240 min)
                $schedule->increment('failure_count');
                $schedule->update(['next_run_at' => now()->addMinutes(30 * 2 ** min($schedule->failure_count, 3))]);

                if ($schedule->failure_count >= 3) {
                    $this->notifyCreator($report);
                }

                report($exception);
            }
        }

        $this->info("Processed {$due->count()} due schedules.");

        return self::SUCCESS;
    }

    /** Panel-Benachrichtigung an den Report-Ersteller nach wiederholtem Versand-Fehlschlag (Spec §11). */
    private function notifyCreator(LeadReport $report): void
    {
        $userFk    = config('lead-pipeline.user_foreign_key', 'user_uuid');
        $userModel = config('auth.providers.users.model');
        $creator   = null === $report->{$userFk} ? null : $userModel::query()->find($report->{$userFk});

        if (null === $creator) {
            return;
        }

        \Filament\Notifications\Notification::make()
            ->danger()
            ->title(__('lead-pipeline::reports.mail.failed_title', ['name' => $report->name]))
            ->sendToDatabase($creator);
    }
}
