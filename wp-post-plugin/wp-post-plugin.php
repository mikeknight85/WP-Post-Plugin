<?php
/**
 * Plugin Name:       WP Post Plugin
 * Plugin URI:        https://github.com/kesslemi/WP-Post-Plugin
 * Description:       Generate Swiss Post compliant barcodes and address labels for WooCommerce orders or standalone shipments via the official Digital Commerce API.
 * Version:           0.2.6
 * Requires at least: 6.4
 * Requires PHP:      8.1
 * Author:            kesslemi
 * License:           GPL-2.0-or-later
 * Text Domain:       wp-post-plugin
 *
 * @package WPPost
 */

declare(strict_types=1);

if (!defined('ABSPATH')) {
    exit;
}

define('WPPOST_VERSION', '0.2.6');
define('WPPOST_FILE', __FILE__);
define('WPPOST_DIR', plugin_dir_path(__FILE__));
define('WPPOST_URL', plugin_dir_url(__FILE__));

$autoload = WPPOST_DIR . 'vendor/autoload.php';
if (file_exists($autoload)) {
    require_once $autoload;
} else {
    // Fallback: tiny PSR-4 autoloader so the plugin still boots during local dev
    // before `composer install` has been run. Works for src/ only.
    spl_autoload_register(static function (string $class): void {
        $prefix = 'WPPost\\';
        if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
            return;
        }
        $relative = substr($class, strlen($prefix));
        $path = WPPOST_DIR . 'src/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require_once $path;
        }
    });
}

register_activation_hook(__FILE__, [\WPPost\Plugin::class, 'activate']);
register_deactivation_hook(__FILE__, [\WPPost\Plugin::class, 'deactivate']);

add_action('plugins_loaded', static function (): void {
    \WPPost\Plugin::instance()->boot();
});
