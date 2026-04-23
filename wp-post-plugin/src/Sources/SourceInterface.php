<?php

declare(strict_types=1);

namespace WPPost\Sources;

use WPPost\Domain\Shipment;

interface SourceInterface
{
    /**
     * Build a Shipment from whatever backing entity this source represents
     * (WooCommerce order, CPT post, etc.).
     *
     * @throws \RuntimeException if the entity is missing or has no usable address.
     */
    public function getShipment(int $entityId): Shipment;

    /**
     * Persist the generated tracking/ident code + label path against the entity
     * so it survives admin reloads.
     */
    public function recordLabel(int $entityId, string $identCode, string $labelPath): void;

    /**
     * Short name used in admin UI, e.g. "order" or "shipment".
     */
    public function entityLabel(): string;
}
