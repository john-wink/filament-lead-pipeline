<?php

declare(strict_types=1);

use JohnWink\FilamentLeadPipeline\Enums\WebhookLogEventType;
use JohnWink\FilamentLeadPipeline\Models\LeadWebhookLog;

it('persists a webhook log with enum and array casts', function (): void {
    $log = LeadWebhookLog::create([
        'event_type'  => WebhookLogEventType::Incoming,
        'outcome'     => 'created',
        'http_status' => 201,
        'request'     => ['payload' => ['name' => 'Test']],
        'response'    => ['id' => 'abc'],
    ]);

    $fresh = $log->fresh();

    expect($fresh->getKey())->not->toBeNull()
        ->and($fresh->event_type)->toBe(WebhookLogEventType::Incoming)
        ->and($fresh->request)->toBe(['payload' => ['name' => 'Test']])
        ->and($fresh->response)->toBe(['id' => 'abc'])
        ->and($fresh->created_at)->not->toBeNull();
});
