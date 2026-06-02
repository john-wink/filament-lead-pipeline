<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookGraphException;
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
    } catch (FacebookGraphException $e) {
        expect($e)->toBeInstanceOf(FacebookGraphException::class)
            ->and($e->getMessage())->not->toContain('SUPERSECRET123');
    }
});

it('throws the base exception for an unclassified 400', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'Bad', 'code' => 100]], 400),
    ]);

    try {
        app(FacebookGraphService::class)->getMe('token');
        $this->fail('Expected exception');
    } catch (FacebookGraphException $e) {
        expect($e)->not->toBeInstanceOf(FacebookTokenInvalidException::class)
            ->and($e)->not->toBeInstanceOf(FacebookTransientException::class);
    }
});

it('classifies Graph code 17 as transient', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'User request limit reached', 'code' => 17]], 400),
    ]);

    app(FacebookGraphService::class)->getMe('token');
})->throws(FacebookTransientException::class);

it('handles a non-JSON 5xx body as transient without a parse error', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response('<html>502 Bad Gateway</html>', 502),
    ]);

    app(FacebookGraphService::class)->getMe('token');
})->throws(FacebookTransientException::class);

it('does not retry a terminal 401 (single request)', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response(['error' => ['code' => 190]], 401),
    ]);

    try {
        app(FacebookGraphService::class)->getMe('token');
    } catch (FacebookTokenInvalidException) {
        // expected
    }

    Http::assertSentCount(1);
});

it('retries a transient 500 (multiple requests)', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'oops']], 500),
    ]);

    try {
        app(FacebookGraphService::class)->getMe('token');
    } catch (FacebookTransientException) {
        // expected
    }

    Http::assertSentCount(2); // 1 initial + 1 retry (retry(2) = 2 total attempts)
});
