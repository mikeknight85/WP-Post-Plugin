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
        $token           = $this->oauth->getToken(self::SCOPE);
        $subscriptionKey = $this->settings->subscriptionKey();
        if ($subscriptionKey === '') {
            throw new ApiException(
                'Missing subscription key. Get the "Primary key" from your app on developer.post.ch and paste it into the WP Post Plugin settings.'
            );
        }
        // The plugin runs Production-only — Swiss Post never returns SPECIMEN.
        $printPreview = false;

        // DCAPI generateAddressLabel uses a FLAT schema (verified empirically
        // against dcapi.apis.post.ch/barcode/v1):
        //   - root: language, frankingLicense, labelDefinition, customer, item
        //   - item.recipient (recipient is nested in item)
        //   - item.attributes.{przl, weight} (weight is grams, not in physical{})
        //   - customer rejects houseNo/email/phone — Address::toApiArray
        //     handles that with $forCustomer=true.
        $payload = [
            'language'        => $this->settings->language(),
            'frankingLicense' => $shipment->frankingLicense,
            'labelDefinition' => [
                'labelLayout'     => $shipment->labelSize,
                'printAddresses'  => 'RECIPIENT_AND_CUSTOMER',
                'imageFileType'   => $shipment->labelFormat,
                'imageResolution' => $shipment->resolution,
                'printPreview'    => $printPreview,
            ],
            'customer' => $shipment->sender->toApiArray(forCustomer: true),
            'item' => [
                'itemID'    => (string) $shipment->id,
                'attributes' => [
                    'przl'   => array_values($shipment->prznlList),
                    'weight' => $shipment->weightGrams,
                ],
                'recipient' => $shipment->recipient->toApiArray(),
            ],
        ];

        $headers = [
            'Authorization'              => 'Bearer ' . $token,
            'Ocp-Apim-Subscription-Key'  => $subscriptionKey,
            'Content-Type'               => 'application/json',
            'Accept'                     => 'application/json',
        ];

        $response = $this->http->request(
            'POST',
            self::BASE_URL . '/generateAddressLabel',
            $headers,
            $payload,
            45
        );

        // If the token was stale, drop it and retry once.
        if ($response['status'] === 401) {
            $this->oauth->forgetToken(self::SCOPE);
            $token = $this->oauth->getToken(self::SCOPE);
            $headers['Authorization'] = 'Bearer ' . $token;
            $response = $this->http->request(
                'POST',
                self::BASE_URL . '/generateAddressLabel',
                $headers,
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

        $item = $response['json']['item'] ?? null;
        if (!is_array($item)) {
            $this->logger->error('generateAddressLabel returned 2xx without item', [
                'status'        => $response['status'],
                'response_body' => substr($response['body'], 0, 2000),
            ]);
            throw new ApiException('Swiss Post response missing item.');
        }

        // DCAPI returns label as either an array of base64 chunks (one per page)
        // or a single string. Concatenate before decoding.
        $labelData = $item['label'] ?? null;
        if (is_array($labelData)) {
            $labelData = implode('', array_filter($labelData, 'is_string'));
        }
        if (!is_string($labelData) || $labelData === '') {
            // DCAPI sometimes returns 2xx with an `errors` array on item or root
            // instead of a label — surface those messages so the cause is visible.
            $errors = $item['errors'] ?? $response['json']['errors'] ?? null;
            $detail = '';
            if (is_array($errors) && $errors !== []) {
                $messages = [];
                foreach ($errors as $e) {
                    if (is_array($e)) {
                        $messages[] = trim((string) ($e['message'] ?? $e['code'] ?? wp_json_encode($e)));
                    } elseif (is_string($e)) {
                        $messages[] = $e;
                    }
                }
                $detail = ' ' . implode('; ', array_filter($messages));
            }
            $this->logger->error('generateAddressLabel returned 2xx without label payload', [
                'status'        => $response['status'],
                'response_body' => substr($response['body'], 0, 2000),
            ]);
            throw new ApiException('Swiss Post response missing label payload.' . $detail);
        }

        $binary = base64_decode($labelData, true);
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
