<?php

declare(strict_types=1);

namespace WPPost\Sources;

use RuntimeException;
use WPPost\Cpt\ShipmentCpt;
use WPPost\Domain\Address;
use WPPost\Domain\Shipment;
use WPPost\Settings\Settings;

final class ShipmentCptSource implements SourceInterface
{
    public function __construct(private Settings $settings) {}

    public function getShipment(int $entityId): Shipment
    {
        $post = get_post($entityId);
        if (!$post || $post->post_type !== ShipmentCpt::POST_TYPE) {
            throw new RuntimeException('Not a shipment post.');
        }

        $raw = get_post_meta($entityId, '_wpp_address', true);
        if (!is_array($raw)) {
            $raw = [];
        }
        $recipient = Address::fromArray($raw);
        if ($recipient->isEmpty()) {
            throw new RuntimeException('Shipment has no recipient address.');
        }

        $weight = (int) (get_post_meta($entityId, '_wpp_weight_grams', true) ?: 500);

        return new Shipment(
            id: (string) $entityId,
            sender: $this->settings->senderAddress(),
            recipient: $recipient,
            frankingLicense: $this->settings->frankingLicense(),
            prznlList: $this->settings->defaultPrznl(),
            weightGrams: max(1, $weight),
            labelFormat: $this->settings->defaultLabelFormat(),
            labelSize: $this->settings->defaultLabelSize(),
            resolution: $this->settings->defaultResolution(),
            customerReference: (string) $entityId
        );
    }

    public function recordLabel(int $entityId, string $identCode, string $labelPath): void
    {
        update_post_meta($entityId, '_wpp_ident_code', $identCode);
        update_post_meta($entityId, '_wpp_label_path', $labelPath);
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['basedir']) && !empty($uploads['baseurl']) && str_starts_with($labelPath, $uploads['basedir'])) {
            $url = $uploads['baseurl'] . substr($labelPath, strlen($uploads['basedir']));
            update_post_meta($entityId, '_wpp_label_url', $url);
        }
    }

    public function entityLabel(): string
    {
        return 'shipment';
    }
}
