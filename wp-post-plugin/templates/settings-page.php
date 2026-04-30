<?php
/**
 * @var \WPPost\Settings\Settings $settings
 * @var string $testResult  'ok' | 'fail' | ''
 * @var string $testMsg
 * @var string $testPreview
 */

if (!defined('ABSPATH')) { exit; }

use WPPost\Domain\Products;

$lang          = $settings->language();
$prodId        = get_option('wpp_prod_client_id', '');
$hasProdSecret = (string) get_option('wpp_prod_client_secret', '') !== '';
$hasProdSubKey = (string) get_option('wpp_prod_subscription_key', '') !== '';
$franking      = $settings->frankingLicense();
$defaultProduct = $settings->defaultProduct();
$defaultFormat = $settings->defaultLabelFormat();
$defaultSize   = $settings->defaultLabelSize();
$defaultRes    = $settings->defaultResolution();
$sender        = $settings->senderAddress();
?>
<div class="wrap">
    <h1><?php esc_html_e('WP Post Plugin — Swiss Post Labels', 'wp-post-plugin'); ?></h1>

    <?php if ($testResult === 'ok'): ?>
        <div class="notice notice-success is-dismissible">
            <p><?php printf(
                esc_html__('Connection OK. Token preview: %s', 'wp-post-plugin'),
                '<code>' . esc_html($testPreview) . '</code>'
            ); ?></p>
        </div>
    <?php elseif ($testResult === 'fail'): ?>
        <div class="notice notice-error is-dismissible">
            <p><strong><?php esc_html_e('Connection failed:', 'wp-post-plugin'); ?></strong> <?php echo esc_html($testMsg); ?></p>
        </div>
    <?php endif; ?>

    <form action="options.php" method="post">
        <?php settings_fields(\WPPost\Settings\Settings::OPTION_GROUP); ?>

        <h2><?php esc_html_e('General', 'wp-post-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpp_language"><?php esc_html_e('Label language', 'wp-post-plugin'); ?></label></th>
                <td>
                    <select id="wpp_language" name="wpp_language">
                        <?php foreach (['DE','FR','IT','EN'] as $code): ?>
                            <option value="<?php echo esc_attr($code); ?>" <?php selected($lang, $code); ?>><?php echo esc_html($code); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('API credentials', 'wp-post-plugin'); ?></h2>
        <p class="description">
            <?php esc_html_e('Get these from your app on developer.post.ch. Required scope: DCAPI_BARCODE_READ. Production credentials require an active Swiss Post billing relationship.', 'wp-post-plugin'); ?>
        </p>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><?php esc_html_e('Client ID', 'wp-post-plugin'); ?></th>
                <td><input type="text" class="regular-text" name="wpp_prod_client_id" value="<?php echo esc_attr((string) $prodId); ?>" autocomplete="off"></td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Client secret', 'wp-post-plugin'); ?></th>
                <td>
                    <input type="password" class="regular-text" name="wpp_prod_client_secret" value="<?php echo $hasProdSecret ? '********' : ''; ?>" autocomplete="new-password">
                    <p class="description"><?php esc_html_e('Leave unchanged to keep existing value. Stored encrypted.', 'wp-post-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Subscription key', 'wp-post-plugin'); ?></th>
                <td>
                    <input type="password" class="regular-text" name="wpp_prod_subscription_key" value="<?php echo $hasProdSubKey ? '********' : ''; ?>" autocomplete="new-password">
                    <p class="description"><?php esc_html_e('Ocp-Apim-Subscription-Key (sometimes labelled "Primary key"). Required by the Swiss Post API gateway. Stored encrypted.', 'wp-post-plugin'); ?></p>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Shipping defaults', 'wp-post-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <tr>
                <th scope="row"><label for="wpp_franking_license"><?php esc_html_e('Franking licence', 'wp-post-plugin'); ?></label></th>
                <td>
                    <input type="text" id="wpp_franking_license" class="regular-text" name="wpp_franking_license" value="<?php echo esc_attr($franking); ?>">
                    <p class="description"><?php esc_html_e('Your Swiss Post Frankierlizenz number (required on every label).', 'wp-post-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpp_default_product"><?php esc_html_e('Default product', 'wp-post-plugin'); ?></label></th>
                <td>
                    <select id="wpp_default_product" name="wpp_default_product">
                        <?php foreach (Products::options() as $key => $label): ?>
                            <option value="<?php echo esc_attr($key); ?>" <?php selected($defaultProduct, $key); ?>><?php echo esc_html($label); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php esc_html_e('Used as the default for new labels. You can override per shipment in the meta box.', 'wp-post-plugin'); ?></p>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpp_default_label_format"><?php esc_html_e('Label format', 'wp-post-plugin'); ?></label></th>
                <td>
                    <select id="wpp_default_label_format" name="wpp_default_label_format">
                        <?php foreach (['PDF','PNG','ZPL2','JPG','GIF','EPS','SPDF'] as $f): ?>
                            <option value="<?php echo esc_attr($f); ?>" <?php selected($defaultFormat, $f); ?>><?php echo esc_html($f); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpp_default_label_size"><?php esc_html_e('Label size', 'wp-post-plugin'); ?></label></th>
                <td>
                    <select id="wpp_default_label_size" name="wpp_default_label_size">
                        <?php foreach (['A5','A6','A7','FE'] as $s): ?>
                            <option value="<?php echo esc_attr($s); ?>" <?php selected($defaultSize, $s); ?>><?php echo esc_html($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
            <tr>
                <th scope="row"><label for="wpp_default_resolution"><?php esc_html_e('Resolution (dpi)', 'wp-post-plugin'); ?></label></th>
                <td>
                    <select id="wpp_default_resolution" name="wpp_default_resolution">
                        <?php foreach ([200,300,600] as $r): ?>
                            <option value="<?php echo esc_attr((string) $r); ?>" <?php selected($defaultRes, $r); ?>><?php echo esc_html((string) $r); ?></option>
                        <?php endforeach; ?>
                    </select>
                </td>
            </tr>
        </table>

        <h2><?php esc_html_e('Sender address', 'wp-post-plugin'); ?></h2>
        <table class="form-table" role="presentation">
            <?php
            $fields = [
                'company'    => __('Company', 'wp-post-plugin'),
                'first_name' => __('First name', 'wp-post-plugin'),
                'last_name'  => __('Last name', 'wp-post-plugin'),
                'street'     => __('Street', 'wp-post-plugin'),
                'house_no'   => __('House no.', 'wp-post-plugin'),
                'zip'        => __('ZIP', 'wp-post-plugin'),
                'city'       => __('City', 'wp-post-plugin'),
                'country'    => __('Country (ISO 2)', 'wp-post-plugin'),
                'email'      => __('Email', 'wp-post-plugin'),
                'phone'      => __('Phone', 'wp-post-plugin'),
            ];
            $senderArr = [
                'company'    => $sender->company,
                'first_name' => $sender->firstName,
                'last_name'  => $sender->lastName,
                'street'     => $sender->street,
                'house_no'   => $sender->houseNo,
                'zip'        => $sender->zip,
                'city'       => $sender->city,
                'country'    => $sender->country,
                'email'      => $sender->email,
                'phone'      => $sender->phone,
            ];
            foreach ($fields as $key => $label):
            ?>
            <tr>
                <th scope="row"><label for="wpp_sender_<?php echo esc_attr($key); ?>"><?php echo esc_html($label); ?></label></th>
                <td>
                    <input type="text" id="wpp_sender_<?php echo esc_attr($key); ?>"
                           class="regular-text"
                           name="wpp_sender_address[<?php echo esc_attr($key); ?>]"
                           value="<?php echo esc_attr((string) $senderArr[$key]); ?>">
                </td>
            </tr>
            <?php endforeach; ?>
        </table>

        <?php submit_button(); ?>
    </form>

    <hr>

    <h2><?php esc_html_e('Test API connection', 'wp-post-plugin'); ?></h2>
    <form action="<?php echo esc_url(admin_url('admin-post.php')); ?>" method="post">
        <input type="hidden" name="action" value="wpp_test_connection">
        <?php wp_nonce_field('wpp_test_connection'); ?>
        <p><?php esc_html_e('Requests an OAuth token using the configured credentials and reports the result.', 'wp-post-plugin'); ?></p>
        <?php submit_button(__('Test connection', 'wp-post-plugin'), 'secondary', 'submit', false); ?>
    </form>
</div>
