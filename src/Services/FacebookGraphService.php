<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class FacebookGraphService
{
    private string $graphVersion;

    private string $graphUrl = 'https://graph.facebook.com';

    public function __construct()
    {
        $this->graphVersion = config('lead-pipeline.facebook.graph_version', 'v21.0');
    }

    public function getRedirectUri(): string
    {
        $configured = config('lead-pipeline.facebook.redirect_uri');

        if ($configured) {
            return $configured;
        }

        return \JohnWink\FilamentLeadPipeline\FilamentLeadPipelinePlugin::publicUrl('lead-pipeline/facebook/callback');
    }

    public function getOAuthRedirectUrl(string $state): string
    {
        $params = http_build_query([
            'client_id'     => config('lead-pipeline.facebook.client_id'),
            'redirect_uri'  => $this->getRedirectUri(),
            'scope'         => implode(',', config('lead-pipeline.facebook.scopes', [])),
            'state'         => $state,
            'response_type' => 'code',
        ]);

        return "https://www.facebook.com/{$this->graphVersion}/dialog/oauth?{$params}";
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     *
     * @throws ConnectionException
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/oauth/access_token", [
            'client_id'     => config('lead-pipeline.facebook.client_id'),
            'client_secret' => config('lead-pipeline.facebook.client_secret'),
            'redirect_uri'  => $this->getRedirectUri(),
            'code'          => $code,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     *
     * @throws ConnectionException
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/oauth/access_token", [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('lead-pipeline.facebook.client_id'),
            'client_secret'     => config('lead-pipeline.facebook.client_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Facebook long-lived token exchange failed: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * @return array{access_token: string, expires_in: int}
     *
     * @throws ConnectionException
     */
    public function refreshLongLivedToken(string $longLivedToken): array
    {
        return $this->exchangeForLongLivedToken($longLivedToken);
    }

    /**
     * @return array<int, array{id: string, name: string, access_token: string}>
     *
     * @throws ConnectionException
     */
    public function getUserPages(string $userAccessToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/me/accounts", [
            'access_token' => $userAccessToken,
            'fields'       => 'id,name,access_token',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Facebook pages: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * @return array<int, array{id: string, name: string}>
     *
     * @throws ConnectionException
     */
    public function getPageLeadForms(string $pageId, string $pageAccessToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/leadgen_forms", [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,name,status',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch lead forms: ' . $response->body());
        }

        return $response->json('data', []);
    }

    /**
     * @throws ConnectionException
     */
    public function subscribePageToLeadgen(string $pageId, string $pageAccessToken): bool
    {
        $response = Http::post("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/subscribed_apps", [
            'access_token'      => $pageAccessToken,
            'subscribed_fields' => 'leadgen',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to subscribe to leadgen: ' . $response->body());
        }

        return $response->json('success', false);
    }

    /**
     * @return array{id: string, form_id: string, field_data: array<int, array{name: string, values: array<string>}>}
     *
     * @throws ConnectionException
     */
    public function getLeadData(string $leadId, string $pageAccessToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$leadId}", [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,form_id,field_data,created_time',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch lead data: ' . $response->body());
        }

        return $response->json();
    }

    /**
     * Loads the field definitions (questions) of a Facebook Lead Form.
     *
     * @return array<int, array{key: string, label: string, type: string}>
     *
     * @throws ConnectionException
     */
    public function getFormQuestions(string $formId, string $pageAccessToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$formId}", [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,name,questions',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch form questions: ' . $response->body());
        }

        return collect($response->json('questions', []))
            ->map(fn (array $q) => [
                'key'   => $q['key'] ?? $q['id'] ?? '',
                'label' => $q['label'] ?? '',
                'type'  => $q['type'] ?? 'CUSTOM',
            ])
            ->values()
            ->toArray();
    }

    /**
     * Loads historical leads from a form using cursor pagination.
     *
     * @return array{data: array<int, array>, paging: array}
     *
     * @throws ConnectionException
     */
    public function getFormLeads(string $formId, string $pageAccessToken, ?int $since = null, ?string $afterCursor = null): array
    {
        $params = [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,form_id,field_data,created_time',
            'limit'        => 25,
        ];

        if ($since) {
            $params['filtering'] = json_encode([
                ['field' => 'time_created', 'operator' => 'GREATER_THAN', 'value' => $since],
            ]);
        }

        if ($afterCursor) {
            $params['after'] = $afterCursor;
        }

        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$formId}/leads", $params);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch form leads: ' . $response->body());
        }

        return [
            'data'   => $response->json('data', []),
            'paging' => $response->json('paging', []),
        ];
    }

    /**
     * @return array{id: string, name: string}
     *
     * @throws ConnectionException
     */
    public function getMe(string $accessToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/me", [
            'access_token' => $accessToken,
            'fields'       => 'id,name',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Failed to fetch Facebook user: ' . $response->body());
        }

        return $response->json();
    }
}
