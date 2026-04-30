<?php

declare(strict_types=1);

namespace WPPost\Api;

use WPPost\Domain\Label;
use WPPost\Domain\Shipment;
use WPPost\Settings\Settings;
use WPPost\Support\Logger;

/**
 * Client for the Swiss Post Digital Commerce Barcode API.
 *
 * POST https://dcapi.apis.post.ch/barcode/v1/generateAddressLabel
 *
 * One label per request — bulk must iterate.
 */
final class BarcodeClient
{
    public const BASE_URL = 'https://dcapi.apis.post.ch/barcode/v1';
    public const SCOPE    = 'DCAPI_BARCODE_READ';

    public function __construct(
        private HttpClient $http,
        private OAuthClient $oauth,
        private Settings $settings,
        private Logger $logger
    ) {}

    public function generateAddressLabel(Shipment $shipment): Label
    {
        $token = $this->oauth->getToken(self::SCOPE);

        $printPreview = $this->settings->environment() === 'test';

        // Request shape follows the DCAPI generateAddressLabel schema.
        // Structure: language + envelope containing labelDefinition, item, and addresses.
        $payload = [
            'language' => $this->settings->language(),
            'envelope' => [
                'labelDefinition' => [
                    'labelLayout'     => $shipment->labelSize,
                    'printAddresses'  => 'RECIPIENT_AND_CUSTOMER',
                    'imageFileType'   => $shipment->labelFormat,
                    'imageResolution' => $shipment->resolution,
                    'printPreview'    => $printPreview,
                ],
                'fileInfos' => [
                    [
                        'item' => [
                            'itemID'            => $shipment->id,
                            'customerReference' => $shipment->customerReference !== ''
                                ? $shipment->customerReference
                                : $shipment->id,
                            'physical' => [
                                'weight' => $shipment->weightGrams,
                            ],
                            'attributes' => [
                                'przl' => array_values($shipment->prznlList),
                            ],
                            'recipient' => $shipment->recipient->toApiArray(),
                            'customer'  => array_merge(
                                $shipment->sender->toApiArray(),
                                ['frankingLicense' => $shipment->frankingLicense]
                            ),
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->http->request(
            'POST',
            self::BASE_URL . '/generateAddressLabel',
            [
                'Authorization' => 'Bearer ' . $token,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
            ],
            $payload,
            45
        );

        // If the token was stale, drop it and retry once.
        if ($response['status'] === 401) {
            $this->oauth->forgetToken(self::SCOPE);
            $token = $this->oauth->getToken(self::SCOPE);
            $response = $this->http->request(
                'POST',
                self::BASE_URL . '/generateAddressLabel',
                [
                    'Authorization' => 'Bearer ' . $token,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                $payload,
                45
            );
        }

        if ($response['status'] < 200 || $response['status'] >= 300 || !is_array($response['json'])) {
            $this->logger->error('generateAddressLabel failed', [
                'url'              => self::BASE_URL . '/generateAddressLabel',
                'status'           => $response['status'],
                'response_headers' => $response['headers'],
                'response_body'    => substr($response['body'], 0, 2000),
                'request_payload'  => substr((string) wp_json_encode($payload), 0, 2000),
            ]);
            throw new ApiException(
                'Swiss Post label request failed (HTTP ' . $response['status'] . ').',
                $response['status'],
                $response['body']
            );
        }

        $fileInfo = $response['json']['envelope']['fileInfos'][0] ?? null;
        $item     = is_array($fileInfo) ? ($fileInfo['item'] ?? null) : null;

        if (!is_array($item) || empty($item['label']) || !is_string($item['label'])) {
            throw new ApiException('Swiss Post response missing label payload.');
        }

        $binary = base64_decode($item['label'], true);
        if ($binary === false) {
            throw new ApiException('Swiss Post label payload is not valid base64.');
        }

        $identCode = (string) ($item['identCode'] ?? $item['trackingNumber'] ?? '');

        return new Label(
            identCode: $identCode,
            binary: $binary,
            format: $shipment->labelFormat,
            isSpecimen: $printPreview
        );
    }
}
