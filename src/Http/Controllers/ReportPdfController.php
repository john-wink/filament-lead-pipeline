<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Contracts\ReportPdfRenderer;
use JohnWink\FilamentLeadPipeline\Enums\ReportDatePresetEnum;
use JohnWink\FilamentLeadPipeline\Exceptions\ReportPdfNotConfiguredException;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;

class ReportPdfController
{
    public function download(Request $request, string $token): Response
    {
        $report = LeadReport::query()->where('share_token', $token)->first();
        abort_if(null === $report || ! $report->isAccessible(), 404);

        abort_if(
            $report->requiresPassword() && true !== $request->session()->get("lead-report-unlocked:{$token}", false),
            403,
        );

        $preset = ReportDatePresetEnum::tryFrom((string) $request->query('zeitraum')) ?? $report->datePresetDefault();
        $range  = ReportDateRange::fromPreset(
            $report->date_locked ? $report->datePresetDefault() : $preset,
            CarbonImmutable::make($request->query('von')),
            CarbonImmutable::make($request->query('bis')),
        );

        try {
            // Bewusst synchron + kurzer Ergebnis-Cache (statt Queue-Job, siehe Plan „Offene Punkte")
            $pdf = Cache::remember(
                "lead-report-pdf:{$report->uuid}:{$range->from->toDateString()}:{$range->till->toDateString()}",
                now()->addMinutes(15),
                fn (): string => app(ReportPdfRenderer::class)->render($report, $range),
            );
        } catch (ReportPdfNotConfiguredException) {
            abort(503, 'PDF-Export ist nicht konfiguriert.');
        }

        $filename = Str::slug($report->name) . '-' . $range->from->format('Ymd') . '-' . $range->till->format('Ymd') . '.pdf';

        return response($pdf, 200, [
            'Content-Type'        => 'application/pdf',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }
}
