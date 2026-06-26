<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Enums\FacebookConnectionStatusEnum;
use JohnWink\FilamentLeadPipeline\Events\FacebookConnectionReconnected;
use JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Services\FacebookGraphService;
use JohnWink\FilamentLeadPipeline\Services\FacebookPageSynchronizer;
use Throwable;

class FacebookOAuthController
{
    public function __construct(
        private FacebookGraphService $facebook,
        private FacebookPageSynchronizer $synchronizer,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $nonce  = Str::random(40);
        $teamId = filament()->getTenant()?->getKey() ?? $request->query('team');
        $origin = $this->resolveTrustedOrigin($request->query('opener_origin'));

        $request->session()->put('facebook_oauth_nonce', $nonce);

        $state = base64_encode(json_encode([
            'nonce'  => $nonce,
            'team'   => $teamId,
            'origin' => $origin,
        ]));

        return redirect()->away($this->facebook->getOAuthRedirectUrl($state));
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        $stateData     = json_decode(base64_decode($request->query('state', '')), true) ?? [];
        $expectedNonce = $request->session()->pull('facebook_oauth_nonce');
        $teamId        = $stateData['team'] ?? auth()->user()?->teams()->first()?->getKey();
        $returnedNonce = $stateData['nonce'] ?? null;

        if ( ! $expectedNonce || $returnedNonce !== $expectedNonce) {
            return response('Invalid OAuth state.', 403);
        }

        if ( ! $teamId) {
            return response('No team context available for Facebook connection.', 422);
        }

        $code = $request->query('code');

        if ( ! $code) {
            return response('Missing authorization code.', 400);
        }

        $shortLived = $this->facebook->exchangeCodeForToken($code);
        $longLived  = $this->facebook->exchangeForLongLivedToken($shortLived['access_token'] ?? '');
        $me         = $this->facebook->getMe($longLived['access_token'] ?? '');

        if (empty($longLived['access_token']) || empty($me['id'])) {
            return response('Facebook returned an incomplete response.', 502);
        }

        $expiresIn = is_int($longLived['expires_in'] ?? null) && $longLived['expires_in'] > 0
            ? $longLived['expires_in']
            : 5184000;

        $connection = FacebookConnection::query()->updateOrCreate(
            [
                'user_uuid'        => auth()->id(),
                'facebook_user_id' => $me['id'],
            ],
            [
                'team_uuid'                 => $teamId,
                'facebook_user_name'        => $me['name'] ?? null,
                'access_token'              => $longLived['access_token'],
                'token_expires_at'          => now()->addSeconds($expiresIn),
                'acquired_at'               => now(),
                'last_refreshed_at'         => now(),
                'refresh_attempts'          => 0,
                'refresh_failed_at'         => null,
                'last_error'                => null,
                'expiring_soon_notified_at' => null,
                'scopes'                    => config('lead-pipeline.facebook.scopes'),
                'status'                    => FacebookConnectionStatusEnum::Connected,
            ],
        );

        $wasReconnect = ! $connection->wasRecentlyCreated;

        $this->synchronizer->sync($connection);

        if ($wasReconnect) {
            FacebookConnectionReconnected::dispatch($connection);
        }

        foreach ($connection->pages()->where('is_webhooks_subscribed', false)->get() as $fbPage) {
            try {
                $this->facebook->subscribePageToLeadgen($fbPage->page_id, $fbPage->page_access_token);
                $fbPage->update(['is_webhooks_subscribed' => true]);
            } catch (Throwable $e) {
                \Illuminate\Support\Facades\Log::warning('Leadgen webhook subscription failed', [
                    'page_id' => $fbPage->page_id,
                    'error'   => $e->getMessage(),
                ]);
            }
        }

        $this->ensureAppLevelLeadgenSubscription();

        $targetOrigin = json_encode(
            $this->resolveTrustedOrigin($stateData['origin'] ?? null),
            JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP | JSON_UNESCAPED_SLASHES,
        );

        return response(<<<HTML
            <!DOCTYPE html>
            <html>
            <head><title>Facebook verbunden</title></head>
            <body>
                <p>Verbindung erfolgreich. Dieses Fenster wird geschlossen...</p>
                <script>
                    (function () {
                        var targetOrigin = {$targetOrigin};
                        var timestamp = Date.now().toString();

                        // Cross-tab signal via localStorage (robust against COOP / tab-mode)
                        try {
                            localStorage.setItem('lead-pipeline:facebook-connected', timestamp);
                        } catch (e) { /* storage disabled */ }

                        // Same-window opener notification (when popup mode is available)
                        if (window.opener) {
                            try {
                                window.opener.postMessage({ type: 'facebook-connected', timestamp: timestamp }, targetOrigin);
                            } catch (e) { /* ignore */ }
                            window.close();
                        } else {
                            // Opener unavailable (tab mode / COOP blocked) — give the parent tab a moment to react to the storage event before navigating away.
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

    /**
     * Ensure the global app-level leadgen Webhook subscription exists (idempotent self-heal).
     * The page-level `subscribed_apps` alone is not enough: without the app-level subscription
     * (callback URL registered at `/{app-id}/subscriptions`) Meta delivers no webhooks at all.
     * Best-effort — never breaks the connect flow.
     */
    private function ensureAppLevelLeadgenSubscription(): void
    {
        $verifyToken = config('lead-pipeline.facebook.verify_token');

        if ( ! config('lead-pipeline.facebook.client_id')
            || ! config('lead-pipeline.facebook.client_secret')
            || ! $verifyToken) {
            return;
        }

        try {
            if ($this->facebook->isAppSubscribedToLeadgen()) {
                return;
            }

            $prefix      = config('lead-pipeline.webhooks.prefix', 'api/lead-pipeline/webhooks');
            $callbackUrl = FilamentLeadPipelinePlugin::publicUrl(mb_rtrim((string) $prefix, '/') . '/meta');

            $this->facebook->subscribeAppToLeadgen($callbackUrl, (string) $verifyToken);
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::warning('App-level leadgen webhook subscription failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validate a candidate window origin (from the OAuth flow) against the
     * application's trusted hosts. Falls back to the app URL when the candidate
     * is missing, malformed, or untrusted — so postMessage never targets an
     * arbitrary origin.
     */
    private function resolveTrustedOrigin(?string $candidate): string
    {
        $fallback = mb_rtrim((string) config('app.url'), '/');

        if ( ! is_string($candidate) || '' === $candidate) {
            return $fallback;
        }

        $parts = parse_url($candidate);

        if ( ! isset($parts['scheme'], $parts['host']) || ! in_array($parts['scheme'], ['http', 'https'], true)) {
            return $fallback;
        }

        $host   = mb_strtolower($parts['host']);
        $origin = $parts['scheme'] . '://' . $host . (isset($parts['port']) ? ':' . $parts['port'] : '');

        return $this->isTrustedHost($host) ? $origin : $fallback;
    }

    private function isTrustedHost(string $host): bool
    {
        $trustedHosts = array_filter([
            parse_url((string) config('app.url'), PHP_URL_HOST),
            parse_url((string) config('lead-pipeline.public_url'), PHP_URL_HOST),
        ]);

        foreach ($trustedHosts as $trusted) {
            $trusted = mb_strtolower((string) $trusted);

            if ($host === $trusted || $this->registrableDomain($host) === $this->registrableDomain($trusted)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Approximates the registrable domain as the last two labels (e.g.
     * "admin.finance-estate.test" -> "finance-estate.test"), which lets sibling
     * panel subdomains be recognised as same-app origins. This assumes the
     * trusted hosts (app.url, public_url) share one apex domain and a single-label
     * public suffix; it is intentionally not a full Public Suffix List lookup.
     */
    private function registrableDomain(string $host): string
    {
        return implode('.', array_slice(explode('.', $host), -2));
    }
}
