<?php

declare(strict_types=1);

namespace WPPost\Api;

use WPPost\Settings\Settings;
use WPPost\Support\Logger;

/**
 * OAuth 2.0 Client Credentials flow against the Swiss Post API.
 * Tokens are cached in site transients keyed by scope.
 */
final class OAuthClient
{
    public const TOKEN_URL = 'https://api.post.ch/OAuth/token';

    public function __construct(
        private HttpClient $http,
        private Settings $settings,
        private Logger $logger
    ) {}

    /**
     * Get a valid access token, requesting a new one if the cache is empty/expired.
     *
     * @param string $scope space-separated scopes, e.g. "DCAPI_BARCODE_READ"
     */
    public function getToken(string $scope): string
    {
        $transientKey = 'wpp_dcapi_token_' . md5($scope);

        $cached = get_site_transient($transientKey);
        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $creds = $this->settings->credentials();
        if ($creds['client_id'] === '' || $creds['client_secret'] === '') {
            throw new ApiException(
                'Missing credentials. Configure them in WP Post Plugin settings.'
            );
        }

        $response = $this->http->request(
            'POST',
            self::TOKEN_URL,
            [
                'Content-Type' => 'application/x-www-form-urlencoded',
                'Accept'       => 'application/json',
            ],
            [
                'grant_type'    => 'client_credentials',
                'client_id'     => $creds['client_id'],
                'client_secret' => $creds['client_secret'],
                'scope'         => $scope,
            ]
        );

        if ($response['status'] !== 200 || !is_array($response['json'])) {
            $this->logger->error('OAuth token request failed', [
                'status' => $response['status'],
                'body'   => $response['body'],
            ]);
            throw new ApiException(
                'OAuth token request failed (HTTP ' . $response['status'] . ').',
                $response['status'],
                $response['body']
            );
        }

        $token = (string) ($response['json']['access_token'] ?? '');
        $expiresIn = (int) ($response['json']['expires_in'] ?? 0);
        if ($token === '' || $expiresIn <= 0) {
            throw new ApiException('OAuth response missing access_token/expires_in.');
        }

        // Cache for (expires_in - 60s) to be safe; floor at 60s.
        $ttl = max(60, $expiresIn - 60);
        set_site_transient($transientKey, $token, $ttl);

        return $token;
    }

    public function forgetToken(string $scope): void
    {
        delete_site_transient('wpp_dcapi_token_' . md5($scope));
    }
}
