<?php

declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;
use JohnWink\FilamentLeadPipeline\Services\WebhookLogger;

it('records an incoming event and redacts sensitive keys', function (): void {
    $request = Request::create('/x', 'POST', ['name' => 'Test', 'api_token' => 'secret123']);

    app(WebhookLogger::class)->recordIncoming(null, $request, 'src-1', 'source_inactive', 404);

    $log = LeadWebhookLog::query()->latest('created_at')->first();

    expect($log)->not->toBeNull()
        ->and($log->outcome)->toBe('source_inactive')
        ->and($log->http_status)->toBe(404)
        ->and($log->request['payload']['name'])->toBe('Test')
        ->and($log->request['payload']['api_token'])->toBe('[redacted]');
});

it('omits payloads when store_payload is disabled', function (): void {
    config()->set('lead-pipeline.webhooks.logging.store_payload', false);

    app(WebhookLogger::class)->recordIncoming(null, Request::create('/x', 'POST', ['a' => 'b']), 'src-1', 'created', 201);

    expect(LeadWebhookLog::query()->latest('created_at')->first()->request)->toBeNull();
});

it('is a no-op when logging is disabled', function (): void {
    config()->set('lead-pipeline.webhooks.logging.enabled', false);

    app(WebhookLogger::class)->recordIncoming(null, Request::create('/x', 'POST'), 'src-1', 'created', 201);

    expect(LeadWebhookLog::query()->count())->toBe(0);
});

it('never throws even if the log table is missing', function (): void {
    Schema::drop('lead_webhook_logs');

    app(WebhookLogger::class)->recordIncoming(null, Request::create('/x', 'POST'), 'src-1', 'created', 201);

    expect(true)->toBeTrue();
});
