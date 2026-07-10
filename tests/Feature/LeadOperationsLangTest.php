<?php

declare(strict_types=1);

it('exposes operations translation keys in all locales', function (string $locale): void {
    app()->setLocale($locale);

    expect(__('lead-pipeline::lead-pipeline.operations.title'))->not->toBe('lead-pipeline::lead-pipeline.operations.title')
        ->and(__('lead-pipeline::lead-pipeline.operations.avg_first_response'))->not->toContain('operations.');
})->with(['de', 'en', 'fr']);

it('has every operations key resolved in all locales', function (string $locale): void {
    app()->setLocale($locale);

    $keys = [
        'title',
        'nav',
        'avg_first_response',
        'overdue_followups',
        'untouched',
        'avg_contact_attempts',
        'next_step_rate',
        'sla',
        'speed_to_lead',
        'stage_dwell',
        'heatmap',
        'velocity',
        'funnel',
        'loss_reasons',
        'source_economics',
        'ops_ranking',
        'ops_score',
        'export',
        'cost_per_lead',
        'cost_per_acquisition',
        'all_advisors',
    ];

    foreach ($keys as $key) {
        $translated = __("lead-pipeline::lead-pipeline.operations.{$key}");

        expect($translated)->not->toContain('operations.')
            ->and($translated)->not->toBe("lead-pipeline::lead-pipeline.operations.{$key}");
    }
})->with(['de', 'en', 'fr']);
