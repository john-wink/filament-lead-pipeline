<?php

declare(strict_types=1);

it('renders an area chart svg from a daily series', function (): void {
    $html = view('lead-pipeline::reports.charts.area', [
        'series' => [
            ['date' => '2026-06-01', 'value' => 0],
            ['date' => '2026-06-02', 'value' => 5],
            ['date' => '2026-06-03', 'value' => 3],
        ],
        'color' => '#0f766e',
    ])->render();

    expect($html)->toContain('<svg')->toContain('polyline')->toContain('#0f766e');
});

it('renders a pie chart svg with percentages', function (): void {
    $html = view('lead-pipeline::reports.charts.pie', [
        'slices' => ['male' => 860, 'female' => 130, 'unknown' => 10],
        'labels' => ['male' => 'Männer', 'female' => 'Frauen', 'unknown' => 'Unbekannt'],
        'color'  => '#0f766e',
    ])->render();

    expect($html)->toContain('<svg')->toContain('86,0')->toContain('Männer');
});

it('renders funnel stages as proportional bars', function (): void {
    $html = view('lead-pipeline::reports.charts.funnel', [
        'stages' => [
            ['key' => 'impressions', 'label' => 'Impressionen', 'value' => 1000, 'cost_per' => null],
            ['key' => 'inquiries', 'label' => 'Anfragen', 'value' => 10, 'cost_per' => 25.5],
        ],
        'color' => '#0f766e',
    ])->render();

    expect($html)->toContain('Impressionen')->toContain('Anfragen')->toContain('25,50');
});

it('renders gracefully with an all-zero series', function (): void {
    $html = view('lead-pipeline::reports.charts.area', [
        'series' => [['date' => '2026-06-01', 'value' => 0]],
        'color'  => '#0f766e',
    ])->render();

    expect($html)->toContain('<svg');
});
