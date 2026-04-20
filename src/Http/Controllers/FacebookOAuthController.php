<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Str;
use JohnWink\FilamentLeadPipeline\Models\FacebookConnection;
use JohnWink\FilamentLeadPipeline\Models\FacebookPage;
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
        $state  = base64_encode(json_encode(['nonce' => $nonce, 'team' => $teamId]));

        $request->session()->put('facebook_oauth_nonce', $nonce);

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
        $longLived  = $this->facebook->exchangeForLongLivedToken($shortLived['access_token']);
        $me         = $this->facebook->getMe($longLived['access_token']);

        $connection = FacebookConnection::query()->updateOrCreate(
            [
                'user_uuid'        => auth()->id(),
                'facebook_user_id' => $me['id'],
            ],
            [
                'team_uuid'          => $teamId,
                'facebook_user_name' => $me['name'] ?? null,
                'access_token'       => $longLived['access_token'],
                'token_expires_at'   => now()->addSeconds($longLived['expires_in'] ?? 5184000),
                'scopes'             => config('lead-pipeline.facebook.scopes'),
                'status'             => 'connected',
            ],
        );

        $this->synchronizer->sync($connection);

        $pages = $this->facebook->getUserPages($longLived['access_token']);

        // Also load forms for each page
        foreach ($pages as $pageData) {
            $fbPage = FacebookPage::query()
                ->where('facebook_connection_uuid', $connection->uuid)
                ->where('page_id', $pageData['id'])
                ->first();

            if ($fbPage) {
                try {
                    $forms = $this->facebook->getPageLeadForms($pageData['id'], $pageData['access_token']);
                    foreach ($forms as $formData) {
                        \JohnWink\FilamentLeadPipeline\Models\FacebookForm::query()->updateOrCreate(
                            [
                                'facebook_page_uuid' => $fbPage->uuid,
                                'form_id'            => $formData['id'],
                            ],
                            [
                                'form_name' => $formData['name'] ?? "Form {$formData['id']}",
                                'cached_at' => now(),
                            ],
                        );
                    }

                    // Subscribe to leadgen webhook
                    if ( ! $fbPage->is_webhooks_subscribed) {
                        $this->facebook->subscribePageToLeadgen($pageData['id'], $pageData['access_token']);
                        $fbPage->update(['is_webhooks_subscribed' => true]);
                    }
                } catch (Throwable) {
                    // Non-critical — forms and webhook can be set up later
                }
            }
        }

        $targetOrigin = json_encode(rtrim((string) config('app.url'), '/'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);

        return response(<<<HTML
            <!DOCTYPE html>
            <html>
            <head><title>Facebook verbunden</title></head>
            <body>
                <p>Verbindung erfolgreich. Dieses Fenster wird geschlossen...</p>
                <script>
                    (function () {
                        var targetOrigin = {$targetOrigin};
                        if (window.opener) {
                            try {
                                window.opener.postMessage({ type: 'facebook-connected' }, targetOrigin);
                            } catch (e) { /* ignore */ }
                            window.close();
                        } else {
                            window.location.href = '/';
                        }
                    })();
                </script>
            </body>
            </html>
        HTML);
    }
}
