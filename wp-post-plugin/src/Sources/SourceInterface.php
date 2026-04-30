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
     * @param ?string $productKey Optional Products::PRESETS key to override
     *                            the configured default for this single call.
     *                            Pass null to use the configured default.
     *
     * @throws \RuntimeException if the entity is missing or has no usable address.
     */
    public function getShipment(int $entityId, ?string $productKey = null): Shipment;

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
