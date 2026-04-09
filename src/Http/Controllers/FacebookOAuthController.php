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
use Throwable;

class FacebookOAuthController
{
    public function __construct(
        private FacebookGraphService $facebook,
    ) {}

    public function redirect(Request $request): RedirectResponse
    {
        $state = Str::random(40);

        $request->session()->put('facebook_oauth_state', $state);
        $request->session()->put('facebook_oauth_team', filament()->getTenant()?->getKey());

        return redirect()->away($this->facebook->getOAuthRedirectUrl($state));
    }

    public function callback(Request $request): RedirectResponse|Response
    {
        $expectedState = $request->session()->pull('facebook_oauth_state');
        $teamId        = $request->session()->pull('facebook_oauth_team');

        if ( ! $expectedState || $request->query('state') !== $expectedState) {
            return response('Invalid OAuth state.', 403);
        }

        $code = $request->query('code');

        if ( ! $code) {
            return response('Missing authorization code.', 400);
        }

        $shortLived = $this->facebook->exchangeCodeForToken($code);
        $longLived  = $this->facebook->exchangeForLongLivedToken($shortLived['access_token']);
        $me         = $this->facebook->getMe($longLived['access_token']);
        $pages      = $this->facebook->getUserPages($longLived['access_token']);

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

        foreach ($pages as $page) {
            FacebookPage::query()->updateOrCreate(
                [
                    'facebook_connection_uuid' => $connection->uuid,
                    'page_id'                  => $page['id'],
                ],
                [
                    'page_name'         => $page['name'],
                    'page_access_token' => $page['access_token'],
                ],
            );
        }

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

        // Close popup and notify parent window
        return response(<<<'HTML'
            <!DOCTYPE html>
            <html>
            <head><title>Facebook verbunden</title></head>
            <body>
                <p>Verbindung erfolgreich. Dieses Fenster wird geschlossen...</p>
                <script>
                    if (window.opener) {
                        window.close();
                    } else {
                        window.location.href = '/';
                    }
                </script>
            </body>
            </html>
        HTML);
    }
}
