<?php

declare(strict_types=1);

namespace WPPost\Api;

use WPPost\Support\Logger;

/**
 * Thin wrapper around wp_remote_request() that:
 *  - retries idempotently on network errors and 5xx (max 2 retries, exp backoff)
 *  - returns a normalized array {status, headers, body, json?}
 *  - logs failures via Logger
 */
final class HttpClient
{
    public function __construct(private Logger $logger) {}

    /**
     * @param array<string,string> $headers
     * @param array<string,mixed>|string|null $body  If array, JSON-encoded unless already a form.
     * @return array{status:int,headers:array<string,string>,body:string,json:mixed}
     */
    public function request(
        string $method,
        string $url,
        array $headers = [],
        array|string|null $body = null,
        int $timeout = 30
    ): array {
        $args = [
            'method'  => strtoupper($method),
            'headers' => $headers,
            'timeout' => $timeout,
            'redirection' => 2,
            'sslverify' => true,
        ];

        if ($body !== null) {
            if (is_array($body)) {
                $contentType = $headers['Content-Type'] ?? $headers['content-type'] ?? 'application/json';
                if (stripos($contentType, 'application/x-www-form-urlencoded') !== false) {
                    $args['body'] = http_build_query($body);
                } else {
                    $args['body'] = wp_json_encode($body);
                }
            } else {
                $args['body'] = $body;
            }
        }

        $attempt = 0;
        $maxAttempts = 3;
        $lastError = null;

        while ($attempt < $maxAttempts) {
            $attempt++;
            $response = wp_remote_request($url, $args);

            if (is_wp_error($response)) {
                $lastError = $response->get_error_message();
                $this->logger->warning('HTTP error', [
                    'url' => $url, 'attempt' => $attempt, 'error' => $lastError,
                ]);
                if ($attempt >= $maxAttempts) {
                    throw new ApiException("Network error: {$lastError}");
                }
                $this->backoff($attempt);
                continue;
            }

            $status = (int) wp_remote_retrieve_response_code($response);
            $rawHeaders = wp_remote_retrieve_headers($response);
            $headersOut = [];
            if (is_object($rawHeaders) && method_exists($rawHeaders, 'getAll')) {
                foreach ($rawHeaders->getAll() as $k => $v) {
                    $headersOut[strtolower((string) $k)] = is_array($v) ? implode(',', $v) : (string) $v;
                }
            } elseif (is_array($rawHeaders)) {
                foreach ($rawHeaders as $k => $v) {
                    $headersOut[strtolower((string) $k)] = is_array($v) ? implode(',', $v) : (string) $v;
                }
            }
            $bodyOut = (string) wp_remote_retrieve_body($response);

            if ($status >= 500 && $attempt < $maxAttempts) {
                $this->logger->warning('HTTP 5xx — retrying', [
                    'url' => $url, 'status' => $status, 'attempt' => $attempt,
                ]);
                $this->backoff($attempt);
                continue;
            }

            $json = null;
            if ($bodyOut !== '' && isset($headersOut['content-type']) && stripos($headersOut['content-type'], 'json') !== false) {
                $decoded = json_decode($bodyOut, true);
                if (json_last_error() === JSON_ERROR_NONE) {
                    $json = $decoded;
                }
            }

            return [
                'status'  => $status,
                'headers' => $headersOut,
                'body'    => $bodyOut,
                'json'    => $json,
            ];
        }

        // Should not reach here
        throw new ApiException('Request failed: ' . (string) $lastError);
    }

    private function backoff(int $attempt): void
    {
        // 0.5s, 1s, 2s, ...
        $micros = (int) (500_000 * (2 ** ($attempt - 1)));
        usleep(min($micros, 4_000_000));
    }
}
