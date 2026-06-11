<?php

declare(strict_types=1);

it('requests the ads_read scope for facebook oauth', function (): void {
    expect(config('lead-pipeline.facebook.scopes'))->toContain('ads_read');
});

it('exposes report defaults', function (): void {
    expect(config('lead-pipeline.reports.route_prefix'))->toBe('reports')
        ->and(config('lead-pipeline.reports.permissions.view'))->toBe('view_reports')
        ->and(config('lead-pipeline.reports.defaults.accent_color'))->toBe('#0f766e');
});
