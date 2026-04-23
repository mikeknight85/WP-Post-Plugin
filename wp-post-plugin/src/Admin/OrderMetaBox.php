<?php

declare(strict_types=1);

namespace WPPost\Admin;

use WPPost\Api\ApiException;
use WPPost\Labels\LabelService;
use WPPost\Support\Logger;

/**
 * Adds a "Swiss Post label" meta box to WooCommerce order edit screen (HPOS + legacy).
 * Registers an admin-post handler for generating a single label from that screen.
 */
final class OrderMetaBox
{
    public function __construct(
        private LabelService $labelService,
        private Logger $logger
    ) {}

    public function register(): void
    {
        add_action('add_meta_boxes', [$this, 'addMetaBox'], 30);
        add_action('admin_post_wpp_generate_order_label', [$this, 'handleGenerate']);
    }

    public function addMetaBox(): void
    {
        // HPOS
        add_meta_box(
            'wpp_order_label',
            __('Swiss Post label', 'wp-post-plugin'),
            [$this, 'render'],
            ['woocommerce_page_wc-orders', 'shop_order'],
            'side',
            'high'
        );
    }

    public function render($postOrOrder): void
    {
        $orderId = 0;
        if (is_object($postOrOrder) && method_exists($postOrOrder, 'get_id')) {
            $orderId = (int) $postOrOrder->get_id();
        } elseif (is_object($postOrOrder) && isset($postOrOrder->ID)) {
            $orderId = (int) $postOrOrder->ID;
        }
        if ($orderId <= 0) {
            return;
        }

        $order = function_exists('wc_get_order') ? wc_get_order($orderId) : null;
        $ident = $order ? (string) $order->get_meta('_wpp_ident_code') : '';
        $url   = $order ? (string) $order->get_meta('_wpp_label_url') : '';

        if ($ident !== '') {
            echo '<p><strong>' . esc_html__('Ident:', 'wp-post-plugin') . '</strong> <code>' . esc_html($ident) . '</code></p>';
        }
        if ($url !== '') {
            echo '<p><a class="button" href="' . esc_url($url) . '" target="_blank" rel="noopener">' . esc_html__('Download label', 'wp-post-plugin') . '</a></p>';
        }

        $nonce = wp_create_nonce('wpp_generate_order_label_' . $orderId);
        echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
        echo '<input type="hidden" name="action" value="wpp_generate_order_label">';
        echo '<input type="hidden" name="order_id" value="' . esc_attr((string) $orderId) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($nonce) . '">';
        submit_button(
            $ident === '' ? __('Generate label', 'wp-post-plugin') : __('Re-generate label', 'wp-post-plugin'),
            'primary',
            'submit',
            false
        );
        echo '</form>';
        echo '<p class="description">' . esc_html__('Uses current Test/Prod setting.', 'wp-post-plugin') . '</p>';
    }

    public function handleGenerate(): void
    {
        $orderId = (int) ($_POST['order_id'] ?? 0);
        if ($orderId <= 0) {
            wp_die(__('Missing order.', 'wp-post-plugin'));
        }
        if (!current_user_can('edit_shop_order', $orderId) && !current_user_can('manage_woocommerce')) {
            wp_die(__('You do not have permission to do that.', 'wp-post-plugin'));
        }
        check_admin_referer('wpp_generate_order_label_' . $orderId);

        $redirect = wp_get_referer() ?: admin_url();
        try {
            $this->labelService->generateForEntity($orderId);
            $redirect = add_query_arg('wpp_label', 'ok', $redirect);
        } catch (ApiException $e) {
            $this->logger->error('Order label failed', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            $redirect = add_query_arg(['wpp_label' => 'fail', 'wpp_msg' => rawurlencode($e->getMessage())], $redirect);
        } catch (\Throwable $e) {
            $this->logger->error('Order label error', ['order_id' => $orderId, 'error' => $e->getMessage()]);
            $redirect = add_query_arg(['wpp_label' => 'fail', 'wpp_msg' => rawurlencode($e->getMessage())], $redirect);
        }
        wp_safe_redirect($redirect);
        exit;
    }
}
