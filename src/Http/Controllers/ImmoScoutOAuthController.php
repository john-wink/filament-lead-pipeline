<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Services\ImmoScoutApiService;

/**
 * Three-legged OAuth 1.0a flow for ImmoScout24. The customer clicks "connect",
 * logs in with their own IS24 account and confirms access; the resulting
 * long-lived access token is stored on a team-scoped connection.
 *
 * OAuth 1.0a has no state parameter — the request token itself correlates the
 * callback with the cached flow context (team, user, token secret).
 */
class ImmoScoutOAuthController
{
    private const CACHE_PREFIX = 'lead-pipeline:immoscout-oauth:';

    public function __construct(private readonly ImmoScoutApiService $api) {}

    public function redirect(Request $request): RedirectResponse|Response
    {
        if (blank(config('lead-pipeline.immoscout.consumer_key')) || blank(config('lead-pipeline.immoscout.consumer_secret'))) {
            return response('ImmoScout24 app credentials are not configured.', 422);
        }

        $teamId = filament()->getTenant()?->getKey() ?? $request->query('team');

        if (blank($teamId)) {
            return response('No team context available for the ImmoScout24 connection.', 422);
        }

        $environment = ImmoScoutEnvironmentEnum::tryFrom((string) config('lead-pipeline.immoscout.environment', 'production'))
            ?? ImmoScoutEnvironmentEnum::Production;

        $requestToken = $this->api->fetchRequestToken(
            $environment,
            route('lead-pipeline.immoscout.callback'),
        );

        Cache::put(self::CACHE_PREFIX . $requestToken['token'], [
            'team'        => $teamId,
            'user'        => auth()->id(),
            'secret'      => $requestToken['secret'],
            'environment' => $environment->value,
        ], now()->addMinutes(15));

        return redirect()->away($this->api->confirmAccessUrl($environment, $requestToken['token']));
    }

    public function callback(Request $request): Response
    {
        $token    = (string) $request->query('oauth_token');
        $verifier = (string) $request->query('oauth_verifier');

        $context = '' !== $token ? Cache::pull(self::CACHE_PREFIX . $token) : null;

        if ( ! is_array($context)) {
            return response('Invalid or expired ImmoScout24 OAuth flow.', 403);
        }

        if ('' === $verifier) {
            return response('Missing OAuth verifier.', 400);
        }

        $environment = ImmoScoutEnvironmentEnum::from($context['environment']);

        $access = $this->api->exchangeAccessToken($environment, $token, $context['secret'], $verifier);

        $connection = ImmoScoutConnection::query()->firstOrNew([
            'user_uuid'   => $context['user'],
            'team_uuid'   => $context['team'],
            'environment' => $environment->value,
        ]);

        if ( ! $connection->exists) {
            $connection->name = ImmoScoutEnvironmentEnum::Sandbox === $environment
                ? 'ImmoScout24 (Sandbox)'
                : 'ImmoScout24';
        }

        $connection->fill([
            'consumer_key'        => (string) config('lead-pipeline.immoscout.consumer_key'),
            'consumer_secret'     => (string) config('lead-pipeline.immoscout.consumer_secret'),
            'access_token'        => $access['token'],
            'access_token_secret' => $access['secret'],
            'status'              => ImmoScoutConnectionStatusEnum::Connected,
            'last_error'          => null,
        ])->save();

        $targetOrigin = json_encode(
            mb_rtrim((string) config('app.url'), '/'),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES,
        );

        return response(<<<HTML
            <!DOCTYPE html>
            <html>
            <head><title>ImmoScout24 verbunden</title></head>
            <body>
                <p>Verbindung erfolgreich. Dieses Fenster wird geschlossen...</p>
                <script>
                    (function () {
                        var targetOrigin = {$targetOrigin};
                        var timestamp = Date.now().toString();

                        try {
                            localStorage.setItem('lead-pipeline:immoscout-connected', timestamp);
                        } catch (e) {}

                        if (window.opener) {
                            try {
                                window.opener.postMessage({ type: 'immoscout-connected', timestamp: timestamp }, targetOrigin);
                            } catch (e) {}
                            window.close();
                        } else {
                            setTimeout(function () {
                                window.location.href = '/';
                            }, 500);
                        }
                    })();
                </script>
            </body>
            </html>
        HTML);
    }
}
