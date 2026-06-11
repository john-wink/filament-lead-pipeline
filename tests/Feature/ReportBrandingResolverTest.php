<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Contracts\ResolvesReportBranding;
use JohnWink\FilamentLeadPipeline\Models\LeadReport;

it('resolves report overrides before config defaults', function (): void {
    config()->set('lead-pipeline.reports.defaults.accent_color', '#111111');
    config()->set('lead-pipeline.reports.media_disk', 'reports-test');
    Illuminate\Support\Facades\Storage::fake('reports-test');

    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create([
        'team_uuid'         => $team->uuid,
        'branding_settings' => ['accent_color' => '#ff0000', 'claim_html' => '<h3>Claim</h3>', 'logo_path' => 'lead-reports/branding/logo.png'],
    ]);

    $branding = app(ResolvesReportBranding::class)->resolve($report);

    expect($branding->accentColor)->toBe('#ff0000')
        ->and($branding->claimHtml)->toBe('<h3>Claim</h3>')
        ->and($branding->logoUrl)->toContain('lead-reports/branding/logo.png');
});

it('falls back to config defaults when the report has no overrides', function (): void {
    config()->set('lead-pipeline.reports.defaults.accent_color', '#111111');
    config()->set('lead-pipeline.reports.defaults.footer_text', 'Plattform-Footer');

    $team   = App\Models\Team::query()->where('slug', 'test')->firstOrFail();
    $report = LeadReport::factory()->create(['team_uuid' => $team->uuid]);

    $branding = app(ResolvesReportBranding::class)->resolve($report);

    expect($branding->accentColor)->toBe('#111111')
        ->and($branding->footerText)->toBe('Plattform-Footer')
        ->and($branding->logoUrl)->toBeNull();
});
