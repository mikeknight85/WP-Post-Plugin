<?php

declare(strict_types=1);

namespace WPPost\Sources;

use RuntimeException;
use WPPost\Domain\Address;
use WPPost\Domain\Products;
use WPPost\Domain\Shipment;
use WPPost\Settings\Settings;

/**
 * Reads shipping address + weight from a WooCommerce order.
 * Returns a Shipment using the plugin's configured defaults.
 */
final class WooCommerceSource implements SourceInterface
{
    public function __construct(private Settings $settings) {}

    public function getShipment(int $entityId, ?string $productKey = null): Shipment
    {
        if (!function_exists('wc_get_order')) {
            throw new RuntimeException('WooCommerce is not active.');
        }
        $order = wc_get_order($entityId);
        if (!$order) {
            throw new RuntimeException('Order not found.');
        }

        $recipient = new Address(
            firstName: (string) $order->get_shipping_first_name(),
            lastName:  (string) $order->get_shipping_last_name(),
            company:   (string) $order->get_shipping_company(),
            street:    trim((string) $order->get_shipping_address_1()),
            houseNo:   '', // WC does not split street/house — left empty, API is tolerant
            zip:       (string) $order->get_shipping_postcode(),
            city:      (string) $order->get_shipping_city(),
            country:   (string) ($order->get_shipping_country() ?: 'CH'),
            email:     (string) $order->get_billing_email(),
            phone:     (string) $order->get_billing_phone()
        );

        if ($recipient->isEmpty()) {
            // Fall back to billing address if shipping is empty.
            $recipient = new Address(
                firstName: (string) $order->get_billing_first_name(),
                lastName:  (string) $order->get_billing_last_name(),
                company:   (string) $order->get_billing_company(),
                street:    trim((string) $order->get_billing_address_1()),
                houseNo:   '',
                zip:       (string) $order->get_billing_postcode(),
                city:      (string) $order->get_billing_city(),
                country:   (string) ($order->get_billing_country() ?: 'CH'),
                email:     (string) $order->get_billing_email(),
                phone:     (string) $order->get_billing_phone()
            );
        }

        if ($recipient->isEmpty()) {
            throw new RuntimeException('Order has no usable shipping/billing address.');
        }

        // Weight — sum of line items, grams.
        $weight = 0;
        foreach ($order->get_items() as $item) {
            if (!is_object($item) || !method_exists($item, 'get_product')) {
                continue;
            }
            $product = $item->get_product();
            if ($product && $product->get_weight() !== '') {
                $qty = method_exists($item, 'get_quantity') ? (int) $item->get_quantity() : 1;
                $weight += (int) round((float) wc_get_weight((float) $product->get_weight(), 'g') * max(1, $qty));
            }
        }
        if ($weight <= 0) {
            $weight = 500; // sensible default
        }

        $przl = $productKey !== null && Products::isValid($productKey)
            ? Products::przl($productKey)
            : $this->settings->defaultPrznl();

        return new Shipment(
            id: (string) $entityId,
            sender: $this->settings->senderAddress(),
            recipient: $recipient,
            frankingLicense: $this->settings->frankingLicense(),
            prznlList: $przl,
            weightGrams: $weight,
            labelFormat: $this->settings->defaultLabelFormat(),
            labelSize: $this->settings->defaultLabelSize(),
            resolution: $this->settings->defaultResolution(),
            customerReference: 'WC-' . $entityId
        );
    }

    public function recordLabel(int $entityId, string $identCode, string $labelPath): void
    {
        $order = function_exists('wc_get_order') ? wc_get_order($entityId) : null;
        if (!$order) {
            return;
        }
        $order->update_meta_data('_wpp_ident_code', $identCode);
        $order->update_meta_data('_wpp_label_path', $labelPath);
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['basedir']) && !empty($uploads['baseurl']) && str_starts_with($labelPath, $uploads['basedir'])) {
            $url = $uploads['baseurl'] . substr($labelPath, strlen($uploads['basedir']));
            $order->update_meta_data('_wpp_label_url', $url);
        }
        $order->add_order_note(sprintf(
            /* translators: 1: ident code */
            __('Swiss Post label generated. Ident: %s', 'wp-post-plugin'),
            $identCode
        ));
        $order->save();
    }

    public function entityLabel(): string
    {
        return 'order';
    }
}
