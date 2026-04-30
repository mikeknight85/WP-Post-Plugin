<?php

declare(strict_types=1);

namespace WPPost\Admin;

use WPPost\Api\ApiException;
use WPPost\Api\OAuthClient;
use WPPost\Api\BarcodeClient;
use WPPost\Settings\Settings;
use WPPost\Support\Encryption;

/**
 * Registers the "WP Post Plugin" settings page under Settings.
 *
 * Nothing fancy — the WordPress Settings API, plus a "Test connection" button
 * that hits the OAuth token endpoint.
 */
final class SettingsPage
{
    public const MENU_SLUG   = 'wp-post-plugin';
    public const CAPABILITY  = 'manage_options';

    public function __construct(
        private Settings $settings,
        private OAuthClient $oauth,
        private Encryption $encryption
    ) {}

    public function register(): void
    {
        add_action('admin_menu', [$this, 'menu']);
        add_action('admin_init', [$this, 'registerSettings']);
        add_action('admin_post_wpp_test_connection', [$this, 'handleTestConnection']);
    }

    public function menu(): void
    {
        add_menu_page(
            __('WP Post Plugin', 'wp-post-plugin'),
            __('WP Post Plugin', 'wp-post-plugin'),
            self::CAPABILITY,
            self::MENU_SLUG,
            [$this, 'render'],
            'dashicons-archive',
            56
        );
    }

    public function registerSettings(): void
    {
        $group = Settings::OPTION_GROUP;

        register_setting($group, 'wpp_environment', [
            'type' => 'string',
            'sanitize_callback' => static fn ($v): string => $v === 'prod' ? 'prod' : 'test',
            'default' => 'test',
        ]);
        register_setting($group, 'wpp_language', [
            'type' => 'string',
            'sanitize_callback' => static function ($v): string {
                $v = strtoupper((string) $v);
                return in_array($v, ['DE', 'FR', 'IT', 'EN'], true) ? $v : 'DE';
            },
            'default' => 'DE',
        ]);

        register_setting($group, 'wpp_test_client_id',   ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting($group, 'wpp_test_client_secret', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeTestSecret'],
        ]);
        register_setting($group, 'wpp_prod_client_id',   ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);
        register_setting($group, 'wpp_prod_client_secret', [
            'type' => 'string',
            'sanitize_callback' => [$this, 'sanitizeProdSecret'],
        ]);

        register_setting($group, 'wpp_franking_license', ['type' => 'string', 'sanitize_callback' => 'sanitize_text_field']);

        register_setting($group, 'wpp_default_przl', [
            'type' => 'array',
            'sanitize_callback' => static function ($v): array {
                if (is_string($v)) {
                    $v = array_map('trim', explode(',', $v));
                }
                if (!is_array($v)) {
                    return ['PRI'];
                }
                return array_values(array_filter(array_map('sanitize_text_field', $v)));
            },
            'default' => ['PRI'],
        ]);

        register_setting($group, 'wpp_default_label_format', [
            'type' => 'string',
            'sanitize_callback' => static function ($v): string {
                $v = strtoupper((string) $v);
                return in_array($v, ['PDF', 'PNG', 'ZPL2', 'JPG', 'GIF', 'EPS', 'SPDF'], true) ? $v : 'PDF';
            },
            'default' => 'PDF',
        ]);
        register_setting($group, 'wpp_default_label_size', [
            'type' => 'string',
            'sanitize_callback' => static function ($v): string {
                $v = strtoupper((string) $v);
                return in_array($v, ['A5', 'A6', 'A7', 'FE'], true) ? $v : 'A6';
            },
            'default' => 'A6',
        ]);
        register_setting($group, 'wpp_default_resolution', [
            'type' => 'integer',
            'sanitize_callback' => static function ($v): int {
                $v = (int) $v;
                return in_array($v, [200, 300, 600], true) ? $v : 300;
            },
            'default' => 300,
        ]);

        register_setting($group, 'wpp_sender_address', [
            'type' => 'array',
            'sanitize_callback' => static function ($v): array {
                if (!is_array($v)) {
                    return [];
                }
                $out = [];
                foreach (['first_name','last_name','company','street','house_no','zip','city','country','email','phone'] as $k) {
                    $out[$k] = sanitize_text_field((string) ($v[$k] ?? ''));
                }
                return $out;
            },
            'default' => [],
        ]);
    }

    public function sanitizeTestSecret(string $v): string
    {
        return $this->persistSecret('test', $v);
    }

    public function sanitizeProdSecret(string $v): string
    {
        return $this->persistSecret('prod', $v);
    }

    /**
     * Preserve the existing stored (encrypted) value when the admin submits
     * the form without changing the secret field. Otherwise encrypt and return
     * the new secret so WP stores the encrypted blob.
     *
     * Must NOT call update_option() — register_setting's sanitize_callback runs
     * inside the sanitize_option_<name> filter, which update_option() re-applies,
     * causing infinite recursion that re-encrypts the encrypted value until PHP
     * runs out of memory.
     */
    private function persistSecret(string $env, string $submitted): string
    {
        $submitted = trim($submitted);
        if ($submitted === '' || $submitted === '********') {
            return (string) get_option('wpp_' . $env . '_client_secret', '');
        }
        return $this->encryption->encrypt($submitted);
    }

    public function handleTestConnection(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            wp_die(__('You do not have permission to do that.', 'wp-post-plugin'));
        }
        check_admin_referer('wpp_test_connection');

        $redirect = admin_url('admin.php?page=' . self::MENU_SLUG);
        try {
            $this->oauth->forgetToken(BarcodeClient::SCOPE);
            $token = $this->oauth->getToken(BarcodeClient::SCOPE);
            $preview = substr($token, 0, 8) . '…';
            $redirect = add_query_arg([
                'wpp_test' => 'ok',
                'wpp_env'  => $this->settings->environment(),
                'wpp_preview' => rawurlencode($preview),
            ], $redirect);
        } catch (ApiException $e) {
            $redirect = add_query_arg([
                'wpp_test' => 'fail',
                'wpp_msg'  => rawurlencode($e->getMessage()),
            ], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }

    public function render(): void
    {
        if (!current_user_can(self::CAPABILITY)) {
            return;
        }
        $settings = $this->settings;
        $testResult = isset($_GET['wpp_test']) ? sanitize_text_field((string) $_GET['wpp_test']) : '';
        $testMsg    = isset($_GET['wpp_msg'])  ? sanitize_text_field(rawurldecode((string) $_GET['wpp_msg'])) : '';
        $testPreview = isset($_GET['wpp_preview']) ? sanitize_text_field(rawurldecode((string) $_GET['wpp_preview'])) : '';
        $testEnv    = isset($_GET['wpp_env'])  ? sanitize_text_field((string) $_GET['wpp_env']) : '';

        include WPPOST_DIR . 'templates/settings-page.php';
    }
}
