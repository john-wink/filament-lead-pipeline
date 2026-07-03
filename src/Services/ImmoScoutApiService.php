<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Services;

use Carbon\CarbonInterface;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JohnWink\FilamentLeadPipeline\Enums\ImmoScoutEnvironmentEnum;
use JohnWink\FilamentLeadPipeline\Exceptions\ImmoScoutAuthException;
use JohnWink\FilamentLeadPipeline\Exceptions\ImmoScoutTransientException;
use JohnWink\FilamentLeadPipeline\Models\ImmoScoutConnection;
use JohnWink\FilamentLeadPipeline\Support\ImmoScoutOAuthSigner;

/**
 * Client for the ImmoScout24 Construction Financing Lead API.
 *
 * The endpoint is pull-only (no webhooks, no pagination): leads are fetched
 * for a from/to window and deduplicated downstream via lead.id.
 */
class ImmoScoutApiService
{
    private const LEAD_PATH = '/api/financing/construction/v2/lead';

    public function __construct(private readonly ImmoScoutOAuthSigner $signer) {}

    /** @return list<array<string, mixed>> */
    public function fetchLeads(ImmoScoutConnection $connection, CarbonInterface $from, CarbonInterface $to): array
    {
        $params = [
            'from' => $from->format('Y-m-d\TH:i:s'),
            'to'   => $to->format('Y-m-d\TH:i:s'),
        ];

        if (filled($connection->scout_id)) {
            $params['scoutid'] = $connection->scout_id;
        }

        return $this->request($connection, $params);
    }

    /** @return list<array<string, mixed>> */
    public function fetchTestLeads(ImmoScoutConnection $connection): array
    {
        return $this->request($connection, ['test' => 'true']);
    }

    /**
     * Starts the three-legged flow with the app-level consumer credentials.
     *
     * @return array{token: string, secret: string}
     */
    public function fetchRequestToken(ImmoScoutEnvironmentEnum $environment, string $callbackUrl): array
    {
        return $this->requestTokenCall(
            $environment->baseUrl() . '/security/oauth/request_token',
            extraOauth: ['oauth_callback' => $callbackUrl],
        );
    }

    /** @return array{token: string, secret: string} */
    public function exchangeAccessToken(
        ImmoScoutEnvironmentEnum $environment,
        string $requestToken,
        string $requestTokenSecret,
        string $verifier,
    ): array {
        return $this->requestTokenCall(
            $environment->baseUrl() . '/security/oauth/access_token',
            token: $requestToken,
            tokenSecret: $requestTokenSecret,
            extraOauth: ['oauth_verifier' => $verifier],
        );
    }

    public function confirmAccessUrl(ImmoScoutEnvironmentEnum $environment, string $requestToken): string
    {
        return $environment->baseUrl() . '/security/oauth/confirm_access?oauth_token=' . rawurlencode($requestToken);
    }

    /**
     * @param  array<string, string>  $extraOauth
     * @return array{token: string, secret: string}
     */
    private function requestTokenCall(string $url, string $token = '', string $tokenSecret = '', array $extraOauth = []): array
    {
        $authorization = $this->signer->authorizationHeader(
            method: 'POST',
            url: $url,
            queryParams: [],
            consumerKey: (string) config('lead-pipeline.immoscout.consumer_key'),
            consumerSecret: (string) config('lead-pipeline.immoscout.consumer_secret'),
            token: $token,
            tokenSecret: $tokenSecret,
            extraOauth: $extraOauth,
        );

        $response = Http::withHeaders(['Authorization' => $authorization])->post($url);

        $this->throwOnError($response);

        parse_str($response->body(), $data);

        if (empty($data['oauth_token']) || empty($data['oauth_token_secret'])) {
            throw new ImmoScoutAuthException('ImmoScout24 returned an incomplete OAuth token response.');
        }

        return ['token' => (string) $data['oauth_token'], 'secret' => (string) $data['oauth_token_secret']];
    }

    /**
     * @param  array<string, string>  $params
     * @return list<array<string, mixed>>
     */
    private function request(ImmoScoutConnection $connection, array $params): array
    {
        $url = $connection->baseUrl() . self::LEAD_PATH;

        $authorization = $this->signer->authorizationHeader(
            method: 'GET',
            url: $url,
            queryParams: $params,
            consumerKey: $connection->consumer_key,
            consumerSecret: (string) $connection->consumer_secret,
            token: (string) ($connection->access_token ?? ''),
            tokenSecret: (string) ($connection->access_token_secret ?? ''),
        );

        $response = Http::withHeaders([
            'Authorization' => $authorization,
            'Accept'        => 'application/json',
        ])->get($url, $params);

        $this->throwOnError($response);

        $leads = $response->json('lead') ?? [];

        if ([] === $leads) {
            return [];
        }

        return array_is_list($leads) ? $leads : [$leads];
    }

    private function throwOnError(Response $response): void
    {
        if ($response->successful()) {
            return;
        }

        $message = $response->json('message.0.message')
            ?? sprintf('ImmoScout24 API request failed with status %d.', $response->status());

        if (in_array($response->status(), [401, 403], true)) {
            throw new ImmoScoutAuthException($message);
        }

        if (429 === $response->status() || $response->serverError()) {
            throw new ImmoScoutTransientException($message);
        }

        throw new ImmoScoutTransientException($message);
    }
}
