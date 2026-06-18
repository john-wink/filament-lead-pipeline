<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

it('logs a successful subscription with the full FB response', function (): void {
    Http::fake([
        'graph.facebook.com/*/subscribed_apps*' => Http::response(['success' => true]),
    ]);

    app(FacebookGraphService::class)->subscribePageToLeadgen('page-1', 'page-token');

    $log = LeadWebhookLog::query()
        ->where('event_type', WebhookLogEventType::Registration)
        ->latest('created_at')
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->outcome)->toBe('subscribed')
        ->and($log->page_id)->toBe('page-1')
        ->and($log->response)->toBe(['success' => true]);
});

it('logs a failed subscription with the FB error body', function (): void {
    Http::fake([
        'graph.facebook.com/*/subscribed_apps*' => Http::response([
            'error' => ['message' => 'No permission', 'code' => 200, 'type' => 'OAuthException'],
        ], 403),
    ]);

    try {
        app(FacebookGraphService::class)->subscribePageToLeadgen('page-2', 'page-token');
    } catch (Throwable) {
        // classifyError rethrows — expected
    }

    $log = LeadWebhookLog::query()->where('outcome', 'subscribe_failed')->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->page_id)->toBe('page-2')
        ->and($log->response['error']['message'])->toBe('No permission');
});

it('logs a live status check from getPageSubscribedApps', function (): void {
    Http::fake([
        'graph.facebook.com/*/subscribed_apps*' => Http::response([
            'data' => [['id' => 'app-1', 'subscribed_fields' => ['leadgen']]],
        ]),
    ]);

    app(FacebookGraphService::class)->getPageSubscribedApps('page-3', 'page-token');

    expect(
        LeadWebhookLog::query()
            ->where('event_type', WebhookLogEventType::StatusCheck)
            ->where('outcome', 'ok')
            ->where('page_id', 'page-3')
            ->exists()
    )->toBeTrue();
});
