<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JohnWink\FilamentLeadPipeline\Http\Controllers\AnalyticsExportController;
use JohnWink\FilamentLeadPipeline\Http\Controllers\FacebookOAuthController;
use JohnWink\FilamentLeadPipeline\Http\Controllers\WebhookController;

/*
|--------------------------------------------------------------------------
| Lead Pipeline API Routes
|--------------------------------------------------------------------------
| Webhook endpoints for external lead sources.
*/

Route::middleware(['web', 'auth'])
    ->prefix('lead-pipeline/facebook')
    ->group(function (): void {
        Route::get('redirect', [FacebookOAuthController::class, 'redirect'])
            ->name('lead-pipeline.facebook.redirect');
        Route::get('callback', [FacebookOAuthController::class, 'callback'])
            ->name('lead-pipeline.facebook.callback');
    });

Route::middleware(['web', 'auth'])
    ->get('lead-pipeline/analytics/export', AnalyticsExportController::class)
    ->name('lead-pipeline.analytics.export');

Route::prefix(config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks'))
    ->middleware([...config('lead-pipeline.webhooks.middleware', ['api']), 'throttle:' . config('lead-pipeline.webhooks.rate_limit', 60) . ',1'])
    ->group(function (): void {
        Route::get('meta', [WebhookController::class, 'verifyMetaCentral']);
        Route::post('meta', [WebhookController::class, 'handleMetaCentral']);
        Route::get('meta/{sourceId}', [WebhookController::class, 'verifyMeta']);
        Route::post('{sourceId}', [WebhookController::class, 'handle']);
        Route::post('meta/{sourceId}', [WebhookController::class, 'handle']);
    });
