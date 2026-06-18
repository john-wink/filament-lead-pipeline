<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookGraphException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTokenInvalidException;
use JohnWink\FilamentLeadPipeline\Exceptions\FacebookTransientException;
use Throwable;

class FacebookGraphService
{
    /**
     * Required tasks for a page to be usable by the lead pipeline:
     * - `MANAGE` allows subscribing the leadgen webhook (`POST /{page-id}/subscribed_apps`).
     * - `ADVERTISE` or `MANAGE_LEADS` is required for `leads_retrieval` on a page.
     *
     * @var array{required_all: array<int, string>, required_any: array<int, string>}
     */
    public const LEAD_PIPELINE_REQUIRED_TASKS = [
        'required_all' => ['MANAGE'],
        'required_any' => ['ADVERTISE', 'MANAGE_LEADS'],
    ];

    private string $graphVersion;

    private string $graphUrl = 'https://graph.facebook.com';

    public function __construct()
    {
        $this->graphVersion = config('lead-pipeline.facebook.graph_version', 'v25.0');
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
     * @throws FacebookGraphException
     */
    public function exchangeCodeForToken(string $code): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/oauth/access_token", [
            'client_id'     => config('lead-pipeline.facebook.client_id'),
            'client_secret' => config('lead-pipeline.facebook.client_secret'),
            'redirect_uri'  => $this->getRedirectUri(),
            'code'          => $code,
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Facebook token exchange failed');
        }

        return $response->json();
    }

    /**
     * @return array{access_token: string, token_type: string, expires_in: int}
     *
     * @throws ConnectionException
     * @throws FacebookGraphException
     */
    public function exchangeForLongLivedToken(string $shortLivedToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/oauth/access_token", [
            'grant_type'        => 'fb_exchange_token',
            'client_id'         => config('lead-pipeline.facebook.client_id'),
            'client_secret'     => config('lead-pipeline.facebook.client_secret'),
            'fb_exchange_token' => $shortLivedToken,
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Facebook long-lived token exchange failed');
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
     * @return array<int, array{id: string, name: string, access_token: string, tasks: array<int, string>}>
     *
     * @throws ConnectionException
     * @throws FacebookGraphException
     */
    public function getUserPages(string $userAccessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/me/accounts", [
            'access_token' => $userAccessToken,
            'fields'       => 'id,name,access_token,tasks',
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to fetch Facebook pages');
        }

        return $response->json('data', []);
    }

    /**
     * @return array<int, array{id: string, name: string}>
     *
     * @throws ConnectionException
     * @throws FacebookGraphException
     */
    public function getPageLeadForms(string $pageId, string $pageAccessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/leadgen_forms", [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,name,status',
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to fetch lead forms');
        }

        return $response->json('data', []);
    }

    /**
     * @throws ConnectionException
     * @throws FacebookGraphException
     */
    public function subscribePageToLeadgen(string $pageId, string $pageAccessToken): bool
    {
        $response = $this->client()->post("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/subscribed_apps", [
            'access_token'      => $pageAccessToken,
            'subscribed_fields' => 'leadgen',
        ]);

        if ($response->failed()) {
            app(WebhookLogger::class)->recordRegistration(
                $pageId,
                ['subscribed_fields' => 'leadgen'],
                (array) $response->json(),
                false,
                $response->json('error.message') ?? $response->body(),
            );

            throw $this->classifyError($response, 'Failed to subscribe to leadgen');
        }

        app(WebhookLogger::class)->recordRegistration(
            $pageId,
            ['subscribed_fields' => 'leadgen'],
            (array) $response->json(),
            true,
        );

        return $response->json('success', false);
    }

    /**
     * Returns the apps subscribed to this page (live truth from Graph API).
     *
     * @return array<int, array{id?: string, name?: string, subscribed_fields?: array<int, string>}>
     *
     * @throws ConnectionException
     * @throws FacebookGraphException
     */
    public function getPageSubscribedApps(string $pageId, string $pageAccessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/{$pageId}/subscribed_apps", [
            'access_token' => $pageAccessToken,
        ]);

        if ($response->failed()) {
            app(WebhookLogger::class)->recordStatusCheck(
                $pageId,
                (array) $response->json(),
                false,
                $response->json('error.message') ?? $response->body(),
            );

            throw $this->classifyError($response, 'Failed to fetch subscribed apps');
        }

        app(WebhookLogger::class)->recordStatusCheck($pageId, (array) $response->json(), true);

        return $response->json('data', []);
    }

    /**
     * Convenience: is at least one app subscribed to the `leadgen` field on this page?
     *
     * @throws ConnectionException
     */
    public function isPageSubscribedToLeadgen(string $pageId, string $pageAccessToken): bool
    {
        foreach ($this->getPageSubscribedApps($pageId, $pageAccessToken) as $app) {
            if (in_array('leadgen', $app['subscribed_fields'] ?? [], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{id: string, form_id: string, field_data: array<int, array{name: string, values: array<string>}>}
     *
     * @throws ConnectionException
     * @throws FacebookGraphException
     */
    public function getLeadData(string $leadId, string $pageAccessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/{$leadId}", [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,form_id,field_data,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,platform,is_organic',
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to fetch lead data');
        }

        return $response->json();
    }

    /**
     * Loads the field definitions (questions) of a Facebook Lead Form.
     *
     * @return array<int, array{key: string, label: string, type: string}>
     *
     * @throws ConnectionException
     * @throws FacebookGraphException
     */
    public function getFormQuestions(string $formId, string $pageAccessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/{$formId}", [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,name,questions',
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to fetch form questions');
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
     * @throws FacebookGraphException
     */
    public function getFormLeads(string $formId, string $pageAccessToken, ?int $since = null, ?string $afterCursor = null): array
    {
        $params = [
            'access_token' => $pageAccessToken,
            'fields'       => 'id,form_id,field_data,created_time,ad_id,ad_name,adset_id,adset_name,campaign_id,campaign_name,platform,is_organic',
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

        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/{$formId}/leads", $params);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to fetch form leads');
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
     * @throws FacebookGraphException
     */
    public function getMe(string $accessToken): array
    {
        $response = $this->client()->get("{$this->graphUrl}/{$this->graphVersion}/me", [
            'access_token' => $accessToken,
            'fields'       => 'id,name',
        ]);

        if ($response->failed()) {
            throw $this->classifyError($response, 'Failed to fetch Facebook user');
        }

        return $response->json();
    }

    /**
     * Die Ads-Methoden nutzen bewusst Http::get(...)->throw() (RequestException-Vertrag
     * für SyncMetaInsightsJob) und weichen vom client()/classifyError()-Muster ab.
     *
     * @return list<array{id: string, account_id: string, name: string}>
     */
    public function getAdAccounts(string $accessToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/me/adaccounts", [
            'access_token' => $accessToken,
            'fields'       => 'id,account_id,name',
            'limit'        => 200,
        ])->throw();

        return $response->json('data', []);
    }

    /**
     * Tages-Insights auf Kampagnen-Ebene (time_increment=1).
     *
     * @param  array{since: string, until: string}  $timeRange
     * @param  list<string>|null  $campaignIds
     * @return array{data: list<array<string, mixed>>, paging: array<string, mixed>, usage_pct: int}
     */
    public function getAdAccountInsights(
        string $adAccountId,
        string $accessToken,
        array $timeRange,
        ?string $breakdown = null,
        ?array $campaignIds = null,
        ?string $after = null,
    ): array {
        $params = [
            'access_token'   => $accessToken,
            'level'          => 'campaign',
            'time_increment' => 1,
            'time_range'     => json_encode($timeRange),
            'fields'         => 'campaign_id,campaign_name,impressions,reach,spend,clicks,inline_link_clicks,actions',
            'limit'          => 500,
        ];

        if (null !== $breakdown) {
            $params['breakdowns'] = $breakdown;
        }

        if (null !== $campaignIds && [] !== $campaignIds) {
            $params['filtering'] = json_encode([[
                'field'    => 'campaign.id',
                'operator' => 'IN',
                'value'    => $campaignIds,
            ]]);
        }

        if (null !== $after) {
            $params['after'] = $after;
        }

        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$adAccountId}/insights", $params)->throw();

        return [
            'data'      => $response->json('data', []),
            'paging'    => $response->json('paging', []),
            'usage_pct' => $this->businessUseCaseUsagePercent($response),
        ];
    }

    /**
     * Deduplizierte Reichweite für einen Gesamtzeitraum (ohne time_increment).
     * Bei Kampagnen-Filter wird über Kampagnen summiert — dokumentierte Näherung,
     * kampagnenübergreifende Personen-Dedupe ist via API nicht filterbar.
     *
     * @param  array{since: string, until: string}  $timeRange
     * @param  list<string>|null  $campaignIds
     */
    public function getAdAccountReach(
        string $adAccountId,
        string $accessToken,
        array $timeRange,
        ?array $campaignIds = null,
    ): int {
        $params = [
            'access_token' => $accessToken,
            'level'        => 'account',
            'time_range'   => json_encode($timeRange),
            'fields'       => 'reach',
        ];

        if (null !== $campaignIds && [] !== $campaignIds) {
            $params['level']     = 'campaign';
            $params['filtering'] = json_encode([[
                'field'    => 'campaign.id',
                'operator' => 'IN',
                'value'    => $campaignIds,
            ]]);
        }

        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$adAccountId}/insights", $params)->throw();

        return (int) collect($response->json('data', []))->sum('reach');
    }

    /**
     * @param  list<string>|null  $campaignIds
     * @return list<array<string, mixed>>
     */
    public function getAdsWithCreatives(string $adAccountId, string $accessToken, ?array $campaignIds = null): array
    {
        $params = [
            'access_token' => $accessToken,
            'fields'       => 'id,name,status,campaign_id,creative{thumbnail_url,image_url,image_hash},insights.date_preset(maximum){impressions,spend,actions}',
            'limit'        => 100,
        ];

        if (null !== $campaignIds && [] !== $campaignIds) {
            $params['filtering'] = json_encode([[
                'field'    => 'campaign.id',
                'operator' => 'IN',
                'value'    => $campaignIds,
            ]]);
        }

        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$adAccountId}/ads", $params)->throw();

        return $response->json('data', []);
    }

    /**
     * Permanente Bild-URLs zu image_hashes (Spec §5: permanent_url bevorzugen).
     *
     * @param  list<string>  $hashes
     * @return array<string, string> hash => permanent_url
     */
    public function getAdImagePermanentUrls(string $adAccountId, string $accessToken, array $hashes): array
    {
        if ([] === $hashes) {
            return [];
        }

        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$adAccountId}/adimages", [
            'access_token' => $accessToken,
            'hashes'       => json_encode($hashes),
            'fields'       => 'hash,permanent_url',
        ])->throw();

        return collect($response->json('data', []))
            ->mapWithKeys(fn (array $image): array => [(string) ($image['hash'] ?? '') => (string) ($image['permanent_url'] ?? '')])
            ->filter()
            ->all();
    }

    /**
     * Kampagnenliste eines Kontos (für das Kampagnen-Multi-Select).
     *
     * @return array<string, string> campaign_id => name
     */
    public function getCampaigns(string $adAccountId, string $accessToken): array
    {
        $response = Http::get("{$this->graphUrl}/{$this->graphVersion}/{$adAccountId}/campaigns", [
            'access_token' => $accessToken,
            'fields'       => 'id,name',
            'limit'        => 200,
        ])->throw();

        return collect($response->json('data', []))->pluck('name', 'id')->all();
    }

    private function client(): PendingRequest
    {
        return Http::timeout(10)
            ->connectTimeout(5)
            ->retry(2, 200, when: function (Throwable $exception): bool {
                if ($exception instanceof ConnectionException) {
                    return true;
                }

                if ($exception instanceof RequestException) {
                    $status = $exception->response->status();

                    return 429 === $status || $status >= 500;
                }

                return false;
            }, throw: false);
    }

    /**
     * Classify a failed Graph response into a typed exception.
     * The terminal-vs-transient mapping is the integration's domain policy.
     */
    private function classifyError(Response $response, string $context): FacebookGraphException
    {
        /** @var array<string, mixed> $error */
        $error  = (array) $response->json('error', []);
        $status = $response->status();
        $code   = isset($error['code']) && is_numeric($error['code']) ? (int) $error['code'] : null;

        $message = $this->sanitize($context . ': ' . ($error['message'] ?? $response->body()));

        if (401 === $status || 190 === $code) {
            return new FacebookTokenInvalidException($message, $status, $error);
        }

        if (429 === $status || $status >= 500 || in_array($code, [4, 17, 32, 613], true)) {
            return new FacebookTransientException($message, $status, $error);
        }

        return new FacebookGraphException($message, $status, $error);
    }

    /**
     * Redact token-like material so secrets never reach logs or exceptions.
     */
    private function sanitize(string $body): string
    {
        return (string) preg_replace(
            '/(access_token|appsecret_proof|client_secret|fb_exchange_token)=[^&\s"\']+/i',
            '$1=[REDACTED]',
            $body,
        );
    }

    /**
     * Maximale Auslastung (%) aus dem x-business-use-case-usage-Header.
     * Header ist JSON: { "<business-id>": [ { call_count, total_cputime, total_time, ... } ] }.
     * Maximum über alle Einträge und alle drei Werte; 0 bei fehlendem/unlesbarem Header.
     */
    private function businessUseCaseUsagePercent(Response $response): int
    {
        $usage = json_decode((string) $response->header('x-business-use-case-usage'), true);

        if ( ! is_array($usage)) {
            return 0;
        }

        $max = 0;

        foreach ($usage as $entries) {
            foreach ((array) $entries as $entry) {
                foreach (['call_count', 'total_cputime', 'total_time'] as $key) {
                    $max = max($max, (int) ($entry[$key] ?? 0));
                }
            }
        }

        return $max;
    }
}
