<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Contracts\ReportPdfRenderer;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;
use JohnWink\FilamentLeadPipeline\Support\ReportDateRange;

it('downloads a pdf via the bound renderer', function (): void {
    $this->app->bind(ReportPdfRenderer::class, fn () => new class() implements ReportPdfRenderer {
        public function render(LeadReport $report, ReportDateRange $range): string
        {
            return '%PDF-FAKE';
        }
    });

    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid]);

    $this->get("/reports/{$report->share_token}/pdf")
        ->assertOk()
        ->assertHeader('content-type', 'application/pdf')
        ->assertDownload();
});

it('returns 503 with a clear message when no renderer is configured', function (): void {
    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid]);

    $this->get("/reports/{$report->share_token}/pdf")->assertServiceUnavailable();
});

it('refuses pdf for password protected reports without unlocked session', function (): void {
    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid, 'password' => 'geheim']);

    $this->get("/reports/{$report->share_token}/pdf")->assertForbidden();
});
