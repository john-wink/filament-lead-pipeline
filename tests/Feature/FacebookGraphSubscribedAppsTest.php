<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

it('returns the list of subscribed apps for a page', function (): void {
    Http::fake([
        'graph.facebook.com/*/page-123/subscribed_apps*' => Http::response([
            'data' => [
                [
                    'id'                => 'app-1',
                    'name'              => 'Our App',
                    'subscribed_fields' => ['leadgen', 'messages'],
                ],
            ],
        ]),
    ]);

    $apps = app(FacebookGraphService::class)->getPageSubscribedApps('page-123', 'token-abc');

    expect($apps)->toBe([
        [
            'id'                => 'app-1',
            'name'              => 'Our App',
            'subscribed_fields' => ['leadgen', 'messages'],
        ],
    ]);
});

it('returns an empty array when no app is subscribed', function (): void {
    Http::fake([
        'graph.facebook.com/*/page-456/subscribed_apps*' => Http::response(['data' => []]),
    ]);

    $apps = app(FacebookGraphService::class)->getPageSubscribedApps('page-456', 'token-xyz');

    expect($apps)->toBe([]);
});

it('throws on a failed graph response', function (): void {
    Http::fake([
        'graph.facebook.com/*/page-789/subscribed_apps*' => Http::response(['error' => ['message' => 'Invalid OAuth access token.']], 401),
    ]);

    app(FacebookGraphService::class)->getPageSubscribedApps('page-789', 'expired-token');
})->throws(RuntimeException::class, 'Failed to fetch subscribed apps');

it('confirms a page is subscribed to leadgen', function (): void {
    Http::fake([
        'graph.facebook.com/*/page-ok/subscribed_apps*' => Http::response([
            'data' => [
                ['id' => 'app-1', 'subscribed_fields' => ['leadgen']],
            ],
        ]),
    ]);

    expect(app(FacebookGraphService::class)->isPageSubscribedToLeadgen('page-ok', 'token'))->toBeTrue();
});

it('detects a page that is not subscribed to leadgen', function (): void {
    Http::fake([
        'graph.facebook.com/*/page-msg-only/subscribed_apps*' => Http::response([
            'data' => [
                ['id' => 'app-1', 'subscribed_fields' => ['messages']],
            ],
        ]),
    ]);

    expect(app(FacebookGraphService::class)->isPageSubscribedToLeadgen('page-msg-only', 'token'))->toBeFalse();
});

it('detects a page with no subscribed apps at all', function (): void {
    Http::fake([
        'graph.facebook.com/*/page-empty/subscribed_apps*' => Http::response(['data' => []]),
    ]);

    expect(app(FacebookGraphService::class)->isPageSubscribedToLeadgen('page-empty', 'token'))->toBeFalse();
});
