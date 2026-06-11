<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Support\Facades\Storage;
use JohnWink\FilamentLeadPipeline\Contracts\ResolvesReportBranding;
use JohnWink\FilamentLeadPipeline\DTOs\ReportBrandingData;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

class ConfigReportBrandingResolver implements ResolvesReportBranding
{
    public function resolve(LeadReport $report): ReportBrandingData
    {
        $overrides = $report->branding_settings ?? [];
        $defaults  = config('lead-pipeline.reports.defaults', []);
        $disk      = config('lead-pipeline.reports.media_disk', 'public');

        $pathToUrl = fn (?string $path): ?string => null === $path ? null : Storage::disk($disk)->url($path);

        return new ReportBrandingData(
            logoUrl: $pathToUrl($overrides['logo_path'] ?? null) ?? $defaults['logo_url'] ?? null,
            coLogoUrl: $pathToUrl($overrides['co_logo_path'] ?? null),
            accentColor: $overrides['accent_color'] ?? $defaults['accent_color'] ?? '#0f766e',
            claimHtml: $overrides['claim_html'] ?? null,
            footerText: $overrides['footer_text'] ?? $defaults['footer_text'] ?? null,
            contact: $overrides['contact'] ?? $defaults['contact'] ?? null,
            imprintUrl: $overrides['imprint_url'] ?? $defaults['imprint_url'] ?? null,
        );
    }
}
