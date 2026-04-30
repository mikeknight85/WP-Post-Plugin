<?php

declare(strict_types=1);

namespace WPPost\Labels;

use RuntimeException;
use WPPost\Api\BarcodeClient;
use WPPost\Domain\Label;
use WPPost\Domain\Shipment;
use WPPost\Sources\SourceInterface;
use WPPost\Support\Logger;

/**
 * Orchestrates: resolve Shipment from source → call Barcode API → persist label file
 * → record the ident/path back on the source entity.
 */
final class LabelService
{
    public function __construct(
        private SourceInterface $source,
        private BarcodeClient $barcode,
        private LabelStorage $storage,
        private Logger $logger
    ) {}

    /**
     * @param ?string $productKey Optional Products::PRESETS key to override
     *                            the configured default product for this call.
     *
     * @return array{label:Label,path:string,shipment:Shipment}
     */
    public function generateForEntity(int $entityId, ?string $productKey = null): array
    {
        $shipment = $this->source->getShipment($entityId, $productKey);
        if ($shipment->frankingLicense === '') {
            throw new RuntimeException('Franking licence is not configured (Settings → WP Post Plugin).');
        }
        if ($shipment->prznlList === []) {
            throw new RuntimeException('No PRZL service codes configured.');
        }

        $label = $this->barcode->generateAddressLabel($shipment);
        $path  = $this->storage->save($shipment->id, $label);
        $this->source->recordLabel($entityId, $label->identCode, $path);

        $this->logger->info('Label generated', [
            'entity'    => $entityId,
            'ident'     => $label->identCode,
            'specimen'  => $label->isSpecimen,
            'path'      => $path,
        ]);

        return ['label' => $label, 'path' => $path, 'shipment' => $shipment];
    }
}
