<?php

declare(strict_types=1);

namespace WPPost;

use WPPost\Admin\BulkActions;
use WPPost\Admin\LabelDownload;
use WPPost\Admin\OrderMetaBox;
use WPPost\Admin\SettingsPage;
use WPPost\Api\BarcodeClient;
use WPPost\Api\HttpClient;
use WPPost\Api\OAuthClient;
use WPPost\Cpt\ShipmentCpt;
use WPPost\Labels\LabelService;
use WPPost\Labels\LabelStorage;
use WPPost\Labels\PdfMerger;
use WPPost\Settings\Settings;
use WPPost\Sources\ShipmentCptSource;
use WPPost\Sources\WooCommerceSource;
use WPPost\Support\Encryption;
use WPPost\Support\Logger;

/**
 * Plugin container + hook registration.
 *
 * Boots both the CPT (always available — used for the fallback source) and
 * the WooCommerce meta box when WooCommerce is active.
 */
final class Plugin
{
    private static ?self $instance = null;
    private bool $booted = false;

    public static function instance(): self
    {
        return self::$instance ??= new self();
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }
        $this->booted = true;

        $logger     = new Logger();
        $encryption = new Encryption();
        $settings   = new Settings($encryption);
        $http       = new HttpClient($logger);
        $oauth      = new OAuthClient($http, $settings, $logger);
        $barcode    = new BarcodeClient($http, $oauth, $settings, $logger);
        $storage    = new LabelStorage();
        $merger     = new PdfMerger();

        // Settings page always present.
        (new SettingsPage($settings, $oauth, $encryption))->register();

        // Authenticated label download endpoint — the labels directory is
        // locked from public access by .htaccess; this serves the file to
        // admins via admin-post.php with a per-id nonce.
        (new LabelDownload())->register();

        // Decide which source to prefer.
        $wooActive = $this->isWooCommerceActive();
        $wcSource  = $wooActive ? new WooCommerceSource($settings) : null;
        $cptSource = new ShipmentCptSource($settings);

        // Always register the CPT so it's available as a manual path.
        $cpt = new ShipmentCpt(
            new LabelService($cptSource, $barcode, $storage, $logger),
            $settings,
            $logger
        );
        $cpt->register();

        if ($wcSource !== null) {
            $orderService = new LabelService($wcSource, $barcode, $storage, $logger);
            (new OrderMetaBox($orderService, $logger))->register();

            // Bulk actions for WC orders list (use WC source).
            (new BulkActions($orderService, $merger, $logger))->register();
        }

        // Always register bulk actions for the CPT list (uses CPT source).
        $cptService = new LabelService($cptSource, $barcode, $storage, $logger);
        (new BulkActions($cptService, $merger, $logger))->register();

        add_action('admin_notices', [$this, 'maybeShowSingleLabelNotice']);
    }

    private function isWooCommerceActive(): bool
    {
        if (class_exists('WooCommerce')) {
            return true;
        }
        if (function_exists('is_plugin_active')) {
            return is_plugin_active('woocommerce/woocommerce.php');
        }
        return in_array('woocommerce/woocommerce.php', (array) get_option('active_plugins', []), true);
    }

    public function maybeShowSingleLabelNotice(): void
    {
        if (!isset($_GET['wpp_label'])) {
            return;
        }
        $state = sanitize_text_field((string) $_GET['wpp_label']);
        if ($state === 'ok') {
            echo '<div class="notice notice-success is-dismissible"><p>' .
                esc_html__('Swiss Post label generated.', 'wp-post-plugin') .
                '</p></div>';
        } elseif ($state === 'fail') {
            $msg = isset($_GET['wpp_msg']) ? sanitize_text_field(rawurldecode((string) $_GET['wpp_msg'])) : '';
            echo '<div class="notice notice-error is-dismissible"><p><strong>' .
                esc_html__('Label generation failed:', 'wp-post-plugin') . '</strong> ' .
                esc_html($msg) . '</p></div>';
        }
    }

    public static function activate(): void
    {
        // Ensure the storage directory + .htaccess exist on activation.
        try {
            (new LabelStorage())->ensureRootDir();
        } catch (\Throwable $e) {
            // Non-fatal — logged on first write.
        }

        // Flush rewrite rules because we register a CPT.
        flush_rewrite_rules();
    }

    public static function deactivate(): void
    {
        flush_rewrite_rules();
    }
}
