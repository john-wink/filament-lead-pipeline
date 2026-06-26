<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

beforeEach(function (): void {
    config([
        'lead-pipeline.facebook.client_id'     => 'app-123',
        'lead-pipeline.facebook.client_secret' => 'secret-xyz',
    ]);
});

it('reads the app-level webhook subscriptions using an app access token', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => Http::response([
            'data' => [
                [
                    'object'       => 'page',
                    'callback_url' => 'https://funnel.example.test/api/lead-pipeline/webhooks/meta',
                    'active'       => true,
                    'fields'       => [
                        ['name' => 'leadgen', 'version' => 'v25.0'],
                    ],
                ],
            ],
        ]),
    ]);

    $subscriptions = app(FacebookGraphService::class)->getAppSubscriptions();

    expect($subscriptions)->toHaveCount(1)
        ->and($subscriptions[0]['object'])->toBe('page');

    Http::assertSent(fn ($request) => str_contains($request->url(), '/app-123/subscriptions')
        && 'GET' === $request->method()
        && 'app-123|secret-xyz' === $request['access_token']);
});

it('subscribes the app to the page leadgen field with callback url and verify token', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => Http::response(['success' => true]),
    ]);

    $result = app(FacebookGraphService::class)->subscribeAppToLeadgen(
        'https://funnel.example.test/api/lead-pipeline/webhooks/meta',
        'verify-token-secret',
    );

    expect($result)->toBeTrue();

    Http::assertSent(function ($request): bool {
        return str_contains($request->url(), '/app-123/subscriptions')
            && 'POST' === $request->method()
            && 'page' === $request['object']
            && 'leadgen' === $request['fields']
            && 'https://funnel.example.test/api/lead-pipeline/webhooks/meta' === $request['callback_url']
            && 'verify-token-secret' === $request['verify_token']
            && 'true' === $request['include_values']
            && 'app-123|secret-xyz' === $request['access_token'];
    });
});

it('throws with the meta error message when the app subscription is rejected', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => Http::response([
            'error' => ['message' => '(#2200) callback verification failed', 'code' => 2200],
        ], 400),
    ]);

    app(FacebookGraphService::class)->subscribeAppToLeadgen('https://x.test/cb', 'tok');
})->throws(RuntimeException::class, 'callback verification failed');

it('detects an active app-level leadgen subscription', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => Http::response([
            'data' => [
                ['object' => 'page', 'active' => true, 'fields' => [['name' => 'leadgen', 'version' => 'v25.0']]],
            ],
        ]),
    ]);

    expect(app(FacebookGraphService::class)->isAppSubscribedToLeadgen())->toBeTrue();
});

it('reports no app-level leadgen subscription when subscriptions are empty', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => Http::response(['data' => []]),
    ]);

    expect(app(FacebookGraphService::class)->isAppSubscribedToLeadgen())->toBeFalse();
});

it('reports no app-level leadgen subscription when the page object lacks leadgen or is inactive', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => Http::response([
            'data' => [
                ['object' => 'page', 'active' => false, 'fields' => [['name' => 'leadgen', 'version' => 'v25.0']]],
                ['object' => 'page', 'active' => true, 'fields' => [['name' => 'messages', 'version' => 'v25.0']]],
            ],
        ]),
    ]);

    expect(app(FacebookGraphService::class)->isAppSubscribedToLeadgen())->toBeFalse();
});
