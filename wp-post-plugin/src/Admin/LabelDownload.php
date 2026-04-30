<?php

declare(strict_types=1);

namespace WPPost\Admin;

use WPPost\Cpt\ShipmentCpt;

/**
 * Streams generated labels to authenticated admins via admin-post.php.
 *
 * Files live under wp-content/uploads/wp-post-labels/ which is locked from
 * public access by an .htaccess Require-all-denied rule, so we cannot link
 * the meta-box "Download label" button at the public uploads URL. Instead
 * the button is built by url() below: a nonce'd admin-post URL that this
 * handler verifies and then streams the file contents.
 */
final class LabelDownload
{
    public const ACTION = 'wpp_download_label';

    public function register(): void
    {
        add_action('admin_post_' . self::ACTION, [$this, 'handle']);
    }

    /** Build a nonce-protected download URL for the given entity id. */
    public static function url(string $entity, int $id): string
    {
        return add_query_arg([
            'action'   => self::ACTION,
            'entity'   => $entity,
            'id'       => $id,
            '_wpnonce' => wp_create_nonce(self::ACTION . '_' . $entity . '_' . $id),
        ], admin_url('admin-post.php'));
    }

    public function handle(): void
    {
        $entity = isset($_GET['entity']) ? sanitize_key((string) $_GET['entity']) : '';
        $id     = isset($_GET['id']) ? (int) $_GET['id'] : 0;
        if ($id <= 0 || !in_array($entity, ['order', 'shipment'], true)) {
            wp_die(esc_html__('Bad request.', 'wp-post-plugin'), '', ['response' => 400]);
        }

        check_admin_referer(self::ACTION . '_' . $entity . '_' . $id);

        [$path, $filename] = $entity === 'order'
            ? $this->resolveOrder($id)
            : $this->resolveShipment($id);

        // Confine to the uploads directory to defend against symlink escape.
        $uploads     = wp_get_upload_dir();
        $real        = realpath($path);
        $allowedRoot = realpath((string) ($uploads['basedir'] ?? ''));
        if ($real === false || $allowedRoot === false
            || !str_starts_with($real, $allowedRoot . DIRECTORY_SEPARATOR)
        ) {
            wp_die(esc_html__('Forbidden.', 'wp-post-plugin'), '', ['response' => 403]);
        }

        $ext  = strtolower((string) pathinfo($real, PATHINFO_EXTENSION));
        $mime = match ($ext) {
            'pdf', 'spdf' => 'application/pdf',
            'png'         => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif'         => 'image/gif',
            'eps'         => 'application/postscript',
            default       => 'application/octet-stream',
        };

        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Length: ' . (string) (filesize($real) ?: 0));
        header('Content-Disposition: inline; filename="' . sanitize_file_name($filename . '.' . $ext) . '"');
        readfile($real);
        exit;
    }

    /** @return array{0:string,1:string} [path, downloadFilenameWithoutExt] */
    private function resolveOrder(int $id): array
    {
        if (!current_user_can('edit_shop_order', $id) && !current_user_can('manage_woocommerce')) {
            wp_die(esc_html__('Forbidden.', 'wp-post-plugin'), '', ['response' => 403]);
        }
        $order = function_exists('wc_get_order') ? wc_get_order($id) : null;
        if (!$order) {
            wp_die(esc_html__('Order not found.', 'wp-post-plugin'), '', ['response' => 404]);
        }
        $path = (string) $order->get_meta('_wpp_label_path');
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            wp_die(esc_html__('Label file not available.', 'wp-post-plugin'), '', ['response' => 404]);
        }
        $ident = (string) $order->get_meta('_wpp_ident_code');
        return [$path, sprintf('order-%d-%s', $id, $ident !== '' ? $ident : 'label')];
    }

    /** @return array{0:string,1:string} */
    private function resolveShipment(int $id): array
    {
        $post = get_post($id);
        if (!$post || $post->post_type !== ShipmentCpt::POST_TYPE) {
            wp_die(esc_html__('Shipment not found.', 'wp-post-plugin'), '', ['response' => 404]);
        }
        if (!current_user_can('edit_post', $id)) {
            wp_die(esc_html__('Forbidden.', 'wp-post-plugin'), '', ['response' => 403]);
        }
        $path = (string) get_post_meta($id, '_wpp_label_path', true);
        if ($path === '' || !is_file($path) || !is_readable($path)) {
            wp_die(esc_html__('Label file not available.', 'wp-post-plugin'), '', ['response' => 404]);
        }
        $ident = (string) get_post_meta($id, '_wpp_ident_code', true);
        return [$path, sprintf('shipment-%d-%s', $id, $ident !== '' ? $ident : 'label')];
    }
}
