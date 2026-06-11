<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JohnWink\FilamentLeadPipeline\Http\Controllers\FunnelController;
use JohnWink\FilamentLeadPipeline\Http\Controllers\ReportPdfController;
use JohnWink\FilamentLeadPipeline\Livewire\PublicReportPage;

Route::prefix((string) config('lead-pipeline.reports.route_prefix', 'reports'))
    ->middleware(config('lead-pipeline.reports.middleware', ['web']))
    ->group(function (): void {
        Route::get('{token}', PublicReportPage::class)
            ->name('lead-pipeline.reports.show')
            ->where('token', '[A-Za-z0-9]{32,64}');
        Route::get('{token}/pdf', [ReportPdfController::class, 'download'])
            ->name('lead-pipeline.reports.pdf')
            ->where('token', '[A-Za-z0-9]{32,64}');
    });

$prefix = config('lead-pipeline.funnel.route_prefix', 'funnel');

Route::prefix($prefix)
    ->middleware(config('lead-pipeline.funnel.middleware', ['web']))
    ->group(function (): void {
        Route::get('{slug}', [FunnelController::class, 'show'])
            ->name('lead-pipeline.funnel.show')
            ->where('slug', '[a-z0-9][a-z0-9\-]*');
    });
