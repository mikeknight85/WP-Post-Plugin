<?php

declare(strict_types=1);

namespace WPPost\Cpt;

use WPPost\Admin\LabelDownload;
use WPPost\Labels\LabelService;
use WPPost\Settings\Settings;
use WPPost\Support\Logger;
use WPPost\Api\ApiException;

/**
 * Custom post type "wpp_shipment" — used when WooCommerce is not installed.
 * Each post holds the recipient address and shipment defaults. A meta box
 * lets the admin generate a label.
 */
final class ShipmentCpt
{
    public const POST_TYPE = 'wpp_shipment';

    private const FORM_ID = 'wpp-shipment-label-form';

    private int $postId = 0;
    private string $nonce = '';

    public function __construct(
        private LabelService $labelService,
        private Settings $settings,
        private Logger $logger
    ) {}

    public function register(): void
    {
        add_action('init', [$this, 'registerPostType']);
        add_action('add_meta_boxes', [$this, 'registerMetaBoxes']);
        add_action('save_post_' . self::POST_TYPE, [$this, 'saveMeta'], 10, 2);
        add_action('admin_post_wpp_generate_shipment_label', [$this, 'handleGenerate']);
        // Render the action form OUTSIDE the post-edit <form>; nested forms are
        // dropped by the browser and would submit the post save instead.
        add_action('admin_footer', [$this, 'printForm']);
    }

    public function registerPostType(): void
    {
        register_post_type(self::POST_TYPE, [
            'label'         => __('Shipments', 'wp-post-plugin'),
            'labels'        => [
                'name'          => __('Shipments', 'wp-post-plugin'),
                'singular_name' => __('Shipment', 'wp-post-plugin'),
                'add_new_item'  => __('Add shipment', 'wp-post-plugin'),
                'edit_item'     => __('Edit shipment', 'wp-post-plugin'),
            ],
            'public'        => false,
            'show_ui'       => true,
            'show_in_menu'  => true,
            'menu_icon'     => 'dashicons-archive',
            'supports'      => ['title'],
            'capability_type' => 'post',
            'map_meta_cap'  => true,
        ]);
    }

    public function registerMetaBoxes(): void
    {
        add_meta_box(
            'wpp_shipment_address',
            __('Recipient address', 'wp-post-plugin'),
            [$this, 'renderAddressBox'],
            self::POST_TYPE,
            'normal',
            'default'
        );
        add_meta_box(
            'wpp_shipment_label',
            __('Swiss Post label', 'wp-post-plugin'),
            [$this, 'renderLabelBox'],
            self::POST_TYPE,
            'side',
            'default'
        );
    }

    public function renderAddressBox(\WP_Post $post): void
    {
        wp_nonce_field('wpp_shipment_save', 'wpp_shipment_nonce');
        $addr = (array) get_post_meta($post->ID, '_wpp_address', true);
        $weight = (int) (get_post_meta($post->ID, '_wpp_weight_grams', true) ?: 500);
        $fields = [
            'company' => __('Company', 'wp-post-plugin'),
            'first_name' => __('First name', 'wp-post-plugin'),
            'last_name' => __('Last name', 'wp-post-plugin'),
            'street' => __('Street', 'wp-post-plugin'),
            'house_no' => __('House no.', 'wp-post-plugin'),
            'zip' => __('ZIP', 'wp-post-plugin'),
            'city' => __('City', 'wp-post-plugin'),
            'country' => __('Country', 'wp-post-plugin'),
            'email' => __('Email', 'wp-post-plugin'),
            'phone' => __('Phone', 'wp-post-plugin'),
        ];
        echo '<table class="form-table" role="presentation"><tbody>';
        foreach ($fields as $key => $label) {
            $val = esc_attr((string) ($addr[$key] ?? ''));
            echo '<tr><th scope="row"><label for="wpp_addr_' . esc_attr($key) . '">' . esc_html($label) . '</label></th>';
            echo '<td><input type="text" id="wpp_addr_' . esc_attr($key) . '" class="regular-text" name="wpp_address[' . esc_attr($key) . ']" value="' . $val . '"></td></tr>';
        }
        echo '<tr><th scope="row"><label for="wpp_weight">' . esc_html__('Weight (g)', 'wp-post-plugin') . '</label></th>';
        echo '<td><input type="number" id="wpp_weight" name="wpp_weight_grams" value="' . esc_attr((string) $weight) . '" min="1"></td></tr>';
        echo '</tbody></table>';
    }

    public function renderLabelBox(\WP_Post $post): void
    {
        $ident    = (string) get_post_meta($post->ID, '_wpp_ident_code', true);
        $hasLabel = (string) get_post_meta($post->ID, '_wpp_label_path', true) !== '';
        if ($ident !== '') {
            echo '<p><strong>' . esc_html__('Ident:', 'wp-post-plugin') . '</strong> <code>' . esc_html($ident) . '</code></p>';
        }
        if ($hasLabel) {
            $downloadUrl = LabelDownload::url('shipment', (int) $post->ID);
            echo '<p><a class="button" href="' . esc_url($downloadUrl) . '" target="_blank" rel="noopener">' . esc_html__('Download label', 'wp-post-plugin') . '</a></p>';
        }

        $this->postId = (int) $post->ID;
        $this->nonce  = wp_create_nonce('wpp_generate_shipment_label_' . $post->ID);

        $label = $ident === '' ? __('Generate label', 'wp-post-plugin') : __('Re-generate label', 'wp-post-plugin');
        echo '<p><button type="submit" class="button button-primary" form="' . esc_attr(self::FORM_ID) . '">' . esc_html($label) . '</button></p>';
        echo '<p class="description">' . esc_html__('Uses current Test/Prod setting.', 'wp-post-plugin') . '</p>';
    }

    public function printForm(): void
    {
        if ($this->postId <= 0) {
            return;
        }
        echo '<form id="' . esc_attr(self::FORM_ID) . '" method="post" action="' . esc_url(admin_url('admin-post.php')) . '" style="display:none">';
        echo '<input type="hidden" name="action" value="wpp_generate_shipment_label">';
        echo '<input type="hidden" name="post_id" value="' . esc_attr((string) $this->postId) . '">';
        echo '<input type="hidden" name="_wpnonce" value="' . esc_attr($this->nonce) . '">';
        echo '</form>';
    }

    public function saveMeta(int $postId, \WP_Post $post): void
    {
        if (!isset($_POST['wpp_shipment_nonce']) || !wp_verify_nonce((string) $_POST['wpp_shipment_nonce'], 'wpp_shipment_save')) {
            return;
        }
        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }
        if (!current_user_can('edit_post', $postId)) {
            return;
        }

        $raw = $_POST['wpp_address'] ?? [];
        $clean = [];
        if (is_array($raw)) {
            foreach (['company','first_name','last_name','street','house_no','zip','city','country','email','phone'] as $k) {
                $clean[$k] = sanitize_text_field((string) ($raw[$k] ?? ''));
            }
        }
        update_post_meta($postId, '_wpp_address', $clean);

        $weight = (int) ($_POST['wpp_weight_grams'] ?? 500);
        update_post_meta($postId, '_wpp_weight_grams', max(1, $weight));
    }

    public function handleGenerate(): void
    {
        $postId = (int) ($_POST['post_id'] ?? 0);
        if ($postId <= 0) {
            wp_die(__('Missing post.', 'wp-post-plugin'));
        }
        if (!current_user_can('edit_post', $postId)) {
            wp_die(__('You do not have permission to do that.', 'wp-post-plugin'));
        }
        check_admin_referer('wpp_generate_shipment_label_' . $postId);

        $redirect = get_edit_post_link($postId, 'url') ?: admin_url();
        try {
            $this->labelService->generateForEntity($postId);
            $redirect = add_query_arg('wpp_label', 'ok', $redirect);
        } catch (ApiException $e) {
            $this->logger->error('Label generation failed', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $redirect = add_query_arg(['wpp_label' => 'fail', 'wpp_msg' => rawurlencode($e->getMessage())], $redirect);
        } catch (\Throwable $e) {
            $this->logger->error('Label generation error', ['post_id' => $postId, 'error' => $e->getMessage()]);
            $redirect = add_query_arg(['wpp_label' => 'fail', 'wpp_msg' => rawurlencode($e->getMessage())], $redirect);
        }

        wp_safe_redirect($redirect);
        exit;
    }
}
