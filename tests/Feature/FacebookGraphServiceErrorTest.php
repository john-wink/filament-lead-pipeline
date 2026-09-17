<?php

declare(strict_types=1);

use Illuminate\Http\Client\ConnectionException;
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

it('redacts secrets from network failures while keeping the connection exception', function (Closure $call, string $secret): void {
    config()->set('lead-pipeline.facebook.client_id', 'app-id-network');
    config()->set('lead-pipeline.facebook.client_secret', 'app-secret-network');

    Http::fake(['graph.facebook.com/*' => Http::failedConnection()]);

    try {
        $call(app(FacebookGraphService::class));
        $this->fail('Expected a connection exception');
    } catch (ConnectionException $exception) {
        $messages = [];

        for ($current = $exception; null !== $current; $current = $current->getPrevious()) {
            $messages[] = $current->getMessage();
        }

        expect(implode("\n", $messages))->not->toContain($secret)
            ->and($exception::class)->toBe(ConnectionException::class)
            ->and($exception->getMessage())->toContain('=[REDACTED]');
    }
})->with([
    'code exchange'           => [fn (FacebookGraphService $graph) => $graph->exchangeCodeForToken('auth-code'), 'app-secret-network'],
    'long-lived app secret'   => [fn (FacebookGraphService $graph) => $graph->exchangeForLongLivedToken('short-token-network'), 'app-secret-network'],
    'long-lived user token'   => [fn (FacebookGraphService $graph) => $graph->refreshLongLivedToken('short-token-network'), 'short-token-network'],
    'user pages'              => [fn (FacebookGraphService $graph) => $graph->getUserPages('user-token-network'), 'user-token-network'],
    'page lead forms'         => [fn (FacebookGraphService $graph) => $graph->getPageLeadForms('page-1', 'page-token-network'), 'page-token-network'],
    'page subscribed apps'    => [fn (FacebookGraphService $graph) => $graph->isPageSubscribedToLeadgen('page-1', 'page-token-network'), 'page-token-network'],
    'app subscriptions'       => [fn (FacebookGraphService $graph) => $graph->isAppSubscribedToLeadgen(), 'app-secret-network'],
    'lead data'               => [fn (FacebookGraphService $graph) => $graph->getLeadData('lead-1', 'page-token-network'), 'page-token-network'],
    'form questions'          => [fn (FacebookGraphService $graph) => $graph->getFormQuestions('form-1', 'page-token-network'), 'page-token-network'],
    'form leads'              => [fn (FacebookGraphService $graph) => $graph->getFormLeads('form-1', 'page-token-network'), 'page-token-network'],
    'permission revocation'   => [fn (FacebookGraphService $graph) => $graph->revokePermissions('user-token-network'), 'user-token-network'],
    'me'                      => [fn (FacebookGraphService $graph) => $graph->getMe('user-token-network'), 'user-token-network'],
    'ad accounts'             => [fn (FacebookGraphService $graph) => $graph->getAdAccounts('user-token-network'), 'user-token-network'],
    'ad account insights'     => [fn (FacebookGraphService $graph) => $graph->getAdAccountInsights('act_1', 'user-token-network', ['since' => '2026-09-01', 'until' => '2026-09-16']), 'user-token-network'],
    'ad account reach'        => [fn (FacebookGraphService $graph) => $graph->getAdAccountReach('act_1', 'user-token-network', ['since' => '2026-09-01', 'until' => '2026-09-16']), 'user-token-network'],
    'ads with creatives'      => [fn (FacebookGraphService $graph) => $graph->getAdsWithCreatives('act_1', 'user-token-network'), 'user-token-network'],
    'ad image permanent urls' => [fn (FacebookGraphService $graph) => $graph->getAdImagePermanentUrls('act_1', 'user-token-network', ['hash-1']), 'user-token-network'],
    'campaigns'               => [fn (FacebookGraphService $graph) => $graph->getCampaigns('act_1', 'user-token-network'), 'user-token-network'],
]);

it('still retries a network failure before giving up', function (): void {
    $attempts = 0;

    Http::fake(function () use (&$attempts) {
        $attempts++;

        return Http::failedConnection();
    });

    expect(fn () => app(FacebookGraphService::class)->getMe('token'))->toThrow(ConnectionException::class)
        ->and($attempts)->toBe(2);
});

it('keeps graph rejections classified when the network is reachable', function (): void {
    Http::fake([
        'graph.facebook.com/*/me*' => Http::response(['error' => ['message' => 'Rate limit', 'code' => 4]], 429),
    ]);

    app(FacebookGraphService::class)->getMe('token');
})->throws(FacebookTransientException::class);
