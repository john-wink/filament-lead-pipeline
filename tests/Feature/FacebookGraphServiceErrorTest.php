<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;

it('throws a token-invalid exception on Graph code 190', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response([
            'error' => ['message' => 'Session expired', 'code' => 190, 'type' => 'OAuthException'],
        ], 400),
    ]);

    app(FacebookGraphService::class)->getMe('dead-token');
})->throws(FacebookTokenInvalidException::class);

it('throws a token-invalid exception on HTTP 401', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'Unauthorized']], 401),
    ]);

    app(FacebookGraphService::class)->getMe('dead-token');
})->throws(FacebookTokenInvalidException::class);

it('throws a transient exception on HTTP 429', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response([
            'error' => ['message' => 'Rate limit', 'code' => 4],
        ], 429),
    ]);

    app(FacebookGraphService::class)->getMe('token');
})->throws(FacebookTransientException::class);

it('never leaks the access token in the exception message', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response([
            'error' => ['message' => 'failed for access_token=SUPERSECRET123'],
        ], 400),
    ]);

    try {
        app(FacebookGraphService::class)->getMe('SUPERSECRET123');
        $this->fail('Expected exception');
    } catch (Throwable $e) {
        expect($e->getMessage())->not->toContain('SUPERSECRET123');
    }
});
