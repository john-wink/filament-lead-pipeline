<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JohnWink\FilamentLeadPipeline\Http\Controllers\FunnelController;

$prefix = config('lead-pipeline.funnel.route_prefix', 'funnel');

Route::prefix($prefix)
    ->middleware(config('lead-pipeline.funnel.middleware', ['web']))
    ->group(function (): void {
        Route::get('{slug}', [FunnelController::class, 'show'])
            ->name('lead-pipeline.funnel.show')
            ->where('slug', '[a-z0-9][a-z0-9\-]*');
    });
