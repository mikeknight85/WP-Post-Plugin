<?php
/**
 * Standalone CLI harness for the Swiss Post DCAPI Barcode API.
 *
 * Bypasses WordPress so we can iterate on the request shape quickly:
 *   php tools/test-label.php
 *
 * Reads credentials from tools/.env (gitignored). See tools/.env.example.
 *
 * Prints OAuth response, the exact request payload sent to
 * generateAddressLabel, and the full response (status, headers, body).
 * On a successful 2xx, writes the decoded label PDF/PNG to tools/out/.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run from CLI only.\n");
    exit(1);
}

// --- .env loader (no Composer) -------------------------------------------
$envFile = __DIR__ . '/.env';
if (!is_file($envFile)) {
    fwrite(STDERR, "Missing tools/.env — copy tools/.env.example and fill it in.\n");
    exit(1);
}
foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
    $line = trim($line);
    if ($line === '' || str_starts_with($line, '#')) {
        continue;
    }
    if (!str_contains($line, '=')) {
        continue;
    }
    [$k, $v] = explode('=', $line, 2);
    $k = trim($k);
    $v = trim($v);
    if (str_starts_with($v, '"') && str_ends_with($v, '"')) {
        $v = substr($v, 1, -1);
    } elseif (str_starts_with($v, "'") && str_ends_with($v, "'")) {
        $v = substr($v, 1, -1);
    }
    putenv("$k=$v");
    $_ENV[$k] = $v;
}

function env(string $k, string $default = ''): string {
    $v = getenv($k);
    return ($v === false || $v === '') ? $default : (string) $v;
}

function require_env(string $k): string {
    $v = env($k);
    if ($v === '') {
        fwrite(STDERR, "Missing required env var: $k\n");
        exit(1);
    }
    return $v;
}

// --- HTTP helper ---------------------------------------------------------
/**
 * @param array<int,string> $headers
 * @return array{status:int,headers:string,body:string}
 */
function http(string $method, string $url, array $headers, ?string $body = null): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => strtoupper($method),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT        => 60,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    }
    $resp = curl_exec($ch);
    if ($resp === false) {
        $err = curl_error($ch);
        curl_close($ch);
        return ['status' => 0, 'headers' => '', 'body' => "curl error: $err"];
    }
    $status     = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    curl_close($ch);
    return [
        'status'  => $status,
        'headers' => (string) substr((string) $resp, 0, $headerSize),
        'body'    => (string) substr((string) $resp, $headerSize),
    ];
}

function section(string $title): void {
    echo "\n=== $title ===\n";
}

// --- Inputs --------------------------------------------------------------
$clientId        = require_env('WPP_CLIENT_ID');
$clientSecret    = require_env('WPP_CLIENT_SECRET');
$frankingLicense = require_env('WPP_FRANKING_LICENSE');
$subscriptionKey = env('WPP_SUBSCRIPTION_KEY');
$environment     = env('WPP_ENV', 'test');

// --- 1. OAuth ------------------------------------------------------------
section('OAuth — POST https://api.post.ch/OAuth/token');

$authHeaders = ['Content-Type: application/x-www-form-urlencoded'];
if ($subscriptionKey !== '') {
    $authHeaders[] = "Ocp-Apim-Subscription-Key: $subscriptionKey";
}

$tokenResp = http(
    'POST',
    'https://api.post.ch/OAuth/token',
    $authHeaders,
    http_build_query([
        'grant_type'    => 'client_credentials',
        'client_id'     => $clientId,
        'client_secret' => $clientSecret,
        'scope'         => 'DCAPI_BARCODE_READ',
    ])
);

echo "Status: {$tokenResp['status']}\n";
echo "Headers:\n{$tokenResp['headers']}\n";
echo "Body: {$tokenResp['body']}\n";

if ($tokenResp['status'] !== 200) {
    fwrite(STDERR, "OAuth failed — fix this before testing the label call.\n");
    exit(1);
}
$tokenJson = json_decode($tokenResp['body'], true);
$token     = is_array($tokenJson) ? (string) ($tokenJson['access_token'] ?? '') : '';
if ($token === '') {
    fwrite(STDERR, "No access_token in OAuth response.\n");
    exit(1);
}
echo "Token: " . substr($token, 0, 16) . "…\n";

// --- 2. generateAddressLabel ---------------------------------------------
section('generateAddressLabel — POST https://dcapi.apis.post.ch/barcode/v1/generateAddressLabel');

$payload = [
    'language' => 'DE',
    'envelope' => [
        'labelDefinition' => [
            'labelLayout'     => 'A6',
            'printAddresses'  => 'RECIPIENT_AND_CUSTOMER',
            'imageFileType'   => 'PDF',
            'imageResolution' => 300,
            'printPreview'    => $environment === 'test',
        ],
        'fileInfos' => [[
            'frankingLicense' => $frankingLicense,
            'ppFranking'      => false,
            'recipient' => array_filter([
                'name1'   => env('WPP_RECIPIENT_NAME', 'Max Muster'),
                'street'  => env('WPP_RECIPIENT_STREET', 'Bahnhofstrasse'),
                'houseNo' => env('WPP_RECIPIENT_HOUSE_NO', '1'),
                'zip'     => env('WPP_RECIPIENT_ZIP', '8001'),
                'city'    => env('WPP_RECIPIENT_CITY', 'Zurich'),
                'country' => env('WPP_RECIPIENT_COUNTRY', 'CH'),
            ], static fn ($v) => $v !== ''),
            'customer' => array_filter([
                'name1'   => env('WPP_SENDER_NAME', 'Test Sender'),
                'street'  => env('WPP_SENDER_STREET', 'Bahnhofstrasse'),
                'houseNo' => env('WPP_SENDER_HOUSE_NO', '2'),
                'zip'     => env('WPP_SENDER_ZIP', '8001'),
                'city'    => env('WPP_SENDER_CITY', 'Zurich'),
                'country' => env('WPP_SENDER_COUNTRY', 'CH'),
            ], static fn ($v) => $v !== ''),
            'item' => [
                'itemID'     => 'TEST-' . time(),
                'physical'   => ['weight' => 500],
                'attributes' => ['przl' => ['PRI']],
            ],
        ]],
    ],
];

$labelHeaders = [
    "Authorization: Bearer $token",
    'Content-Type: application/json',
    'Accept: application/json',
];
if ($subscriptionKey !== '') {
    $labelHeaders[] = "Ocp-Apim-Subscription-Key: $subscriptionKey";
}

echo "Request headers:\n";
foreach ($labelHeaders as $h) {
    if (stripos($h, 'Authorization:') === 0) {
        echo "  Authorization: Bearer " . substr($token, 0, 16) . "…\n";
    } elseif (stripos($h, 'Ocp-Apim-Subscription-Key:') === 0) {
        echo "  Ocp-Apim-Subscription-Key: " . substr($subscriptionKey, 0, 8) . "…\n";
    } else {
        echo "  $h\n";
    }
}
echo "Request payload:\n" . json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

$labelResp = http(
    'POST',
    'https://dcapi.apis.post.ch/barcode/v1/generateAddressLabel',
    $labelHeaders,
    (string) json_encode($payload)
);

echo "Status: {$labelResp['status']}\n";
echo "Headers:\n{$labelResp['headers']}\n";
echo "Body length: " . strlen($labelResp['body']) . "\n";
if (strlen($labelResp['body']) === 0) {
    echo "Body: (empty)\n";
} elseif (strlen($labelResp['body']) < 8192) {
    echo "Body:\n{$labelResp['body']}\n";
} else {
    echo "Body (first 8 KB):\n" . substr($labelResp['body'], 0, 8192) . "\n";
}

// --- Save the label on success ------------------------------------------
if ($labelResp['status'] >= 200 && $labelResp['status'] < 300) {
    $json = json_decode($labelResp['body'], true);
    $b64  = $json['envelope']['fileInfos'][0]['item']['label'] ?? null;
    if (is_string($b64)) {
        $bin = base64_decode($b64, true);
        if ($bin !== false) {
            $outDir = __DIR__ . '/out';
            if (!is_dir($outDir)) {
                mkdir($outDir, 0700, true);
            }
            $outFile = $outDir . '/label-' . date('Ymd-His') . '.pdf';
            file_put_contents($outFile, $bin);
            echo "Saved label to: $outFile\n";
        }
    }
}
