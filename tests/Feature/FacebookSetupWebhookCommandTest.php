<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'lead-pipeline.facebook.client_id'     => 'app-123',
        'lead-pipeline.facebook.client_secret' => 'secret-xyz',
        'lead-pipeline.facebook.verify_token'  => 'verify-secret',
        'lead-pipeline.public_url'             => 'https://funnel.example.test',
        'lead-pipeline.webhooks.prefix'        => 'api/lead-pipeline/webhooks',
    ]);
});

it('subscribes the app to leadgen and reports the callback url', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => function ($request) {
            if ('POST' === $request->method()) {
                return Http::response(['success' => true]);
            }

            return Http::response(['data' => [
                ['object' => 'page', 'active' => true, 'fields' => [['name' => 'leadgen', 'version' => 'v25.0']]],
            ]]);
        },
    ]);

    $this->artisan('lead-pipeline:facebook-setup-webhook')
        ->expectsOutputToContain('https://funnel.example.test/api/lead-pipeline/webhooks/meta')
        ->expectsOutputToContain('leadgen')
        ->assertSuccessful();

    Http::assertSent(fn ($request) => 'POST' === $request->method()
        && str_contains($request->url(), '/app-123/subscriptions')
        && 'https://funnel.example.test/api/lead-pipeline/webhooks/meta' === $request['callback_url']
        && 'verify-secret' === $request['verify_token']);
});

it('aborts without any http call when facebook credentials are missing', function (): void {
    config(['lead-pipeline.facebook.client_id' => null]);
    Http::fake();

    $this->artisan('lead-pipeline:facebook-setup-webhook')->assertFailed();

    Http::assertNothingSent();
});

it('aborts when no public verify token is configured', function (): void {
    config(['lead-pipeline.facebook.verify_token' => null]);
    Http::fake();

    $this->artisan('lead-pipeline:facebook-setup-webhook')->assertFailed();

    Http::assertNothingSent();
});

it('reports metas error message when meta rejects the subscription', function (): void {
    Http::fake([
        'graph.facebook.com/*/app-123/subscriptions*' => function ($request) {
            if ('POST' === $request->method()) {
                return Http::response(['error' => ['message' => '(#2200) callback verification failed', 'code' => 2200]], 400);
            }

            return Http::response(['data' => []]);
        },
    ]);

    $this->artisan('lead-pipeline:facebook-setup-webhook')
        ->expectsOutputToContain('callback verification failed')
        ->assertFailed();
});
