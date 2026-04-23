<?php

declare(strict_types=1);

namespace WPPost\Domain;

/**
 * A shipment to be labelled. Holds sender, recipient, and service metadata.
 *
 * Fields map to the Swiss Post DCAPI generateAddressLabel payload.
 *
 *   - frankingLicense : contract / Frankierlizenz number (e.g. "42512345")
 *   - prznlList       : Product/Service codes ("PRZL"), e.g. ["PRI","ZAW3213"]
 *                       PRI = A-Post, ECO = B-Post; combined with additional services.
 *   - weightGrams     : gross weight in grams
 *   - labelFormat     : PDF | sPDF | PNG | JPG | GIF | EPS | ZPL2
 *   - labelSize       : A5 | A6 | A7 | FE
 *   - resolution      : 200 | 300 | 600  (dpi)
 */
final class Shipment
{
    public function __construct(
        public readonly string $id,
        public readonly Address $sender,
        public readonly Address $recipient,
        public readonly string $frankingLicense,
        /** @var string[] */
        public readonly array $prznlList,
        public readonly int $weightGrams = 500,
        public readonly string $labelFormat = 'PDF',
        public readonly string $labelSize = 'A6',
        public readonly int $resolution = 300,
        public readonly string $customerReference = ''
    ) {}
}
