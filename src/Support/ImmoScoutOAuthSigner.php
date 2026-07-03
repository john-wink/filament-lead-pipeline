<?php

declare(strict_types=1);

namespace JohnWink\FilamentLeadPipeline\Support;

/**
 * Builds OAuth 1.0a Authorization headers (HMAC-SHA1) for the classic
 * ImmoScout24 REST API. Signature logic verified live against the sandbox.
 */
class ImmoScoutOAuthSigner
{
    /**
     * @param  array<string, string>  $queryParams
     * @param  array<string, string>  $extraOauth  Additional oauth_* protocol params
     *                                             (oauth_callback, oauth_verifier) that
     *                                             must be part of the signature base string.
     */
    public function authorizationHeader(
        string $method,
        string $url,
        array $queryParams,
        string $consumerKey,
        string $consumerSecret,
        string $token = '',
        string $tokenSecret = '',
        ?string $nonce = null,
        ?int $timestamp = null,
        array $extraOauth = [],
    ): string {
        $oauth = array_merge([
            'oauth_consumer_key'     => $consumerKey,
            'oauth_nonce'            => $nonce ?? bin2hex(random_bytes(16)),
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp'        => (string) ($timestamp ?? time()),
            'oauth_version'          => '1.0',
        ], $extraOauth);

        if ('' !== $token) {
            $oauth['oauth_token'] = $token;
        }

        $baseString = $this->signatureBaseString($method, $url, array_merge($queryParams, $oauth));
        $signingKey = $this->encode($consumerSecret) . '&' . $this->encode($tokenSecret);

        $oauth['oauth_signature'] = base64_encode(hash_hmac('sha1', $baseString, $signingKey, true));

        ksort($oauth);

        $parts = [];
        foreach ($oauth as $key => $value) {
            $parts[] = $key . '="' . $this->encode($value) . '"';
        }

        return 'OAuth ' . implode(', ', $parts);
    }

    /** @param array<string, string> $params */
    private function signatureBaseString(string $method, string $url, array $params): string
    {
        ksort($params);

        $pairs = [];
        foreach ($params as $key => $value) {
            $pairs[] = $this->encode($key) . '=' . $this->encode($value);
        }

        return mb_strtoupper($method) . '&' . $this->encode($url) . '&' . $this->encode(implode('&', $pairs));
    }

    private function encode(string $value): string
    {
        return str_replace(['+', '%7E'], ['%20', '~'], rawurlencode($value));
    }
}
