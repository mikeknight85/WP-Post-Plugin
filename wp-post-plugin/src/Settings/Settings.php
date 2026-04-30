<?php

declare(strict_types=1);

namespace WPPost\Settings;

use WPPost\Domain\Address;
use WPPost\Domain\Products;
use WPPost\Support\Encryption;

/**
 * Centralized access to all plugin options. Secrets are transparently
 * encrypted/decrypted using Encryption.
 *
 * Stored options:
 *  - wpp_language               : "DE" | "FR" | "IT" | "EN"
 *  - wpp_prod_client_id         : string
 *  - wpp_prod_client_secret     : encrypted string
 *  - wpp_prod_subscription_key  : encrypted string (Ocp-Apim-Subscription-Key)
 *  - wpp_franking_license       : string
 *  - wpp_default_product        : string (Products::PRESETS key, e.g. "pri")
 *  - wpp_default_label_format   : PDF|PNG|ZPL2|...
 *  - wpp_default_label_size     : A5|A6|A7|FE
 *  - wpp_default_resolution     : 200|300|600
 *  - wpp_sender_address         : array (see Address::fromArray)
 *
 * The plugin runs Production-only — Swiss Post's API uses the same endpoint
 * for both, with `printPreview: true` distinguishing test/SPECIMEN. We always
 * send `printPreview: false`. (The `wpp_*_test_*` and `wpp_environment`
 * options were removed in 0.3.x; uninstall.php still cleans them up.)
 */
final class Settings
{
    public const OPTION_GROUP = 'wp-post-plugin';

    public function __construct(private Encryption $encryption) {}

    public function language(): string
    {
        $v = strtoupper((string) get_option('wpp_language', 'DE'));
        return in_array($v, ['DE', 'FR', 'IT', 'EN'], true) ? $v : 'DE';
    }

    /**
     * @return array{client_id:string,client_secret:string}
     */
    public function credentials(): array
    {
        $id        = (string) get_option('wpp_prod_client_id', '');
        $secretEnc = (string) get_option('wpp_prod_client_secret', '');
        $secret    = $secretEnc === '' ? '' : $this->encryption->decrypt($secretEnc);
        return ['client_id' => $id, 'client_secret' => $secret];
    }

    public function subscriptionKey(): string
    {
        $enc = (string) get_option('wpp_prod_subscription_key', '');
        return $enc === '' ? '' : $this->encryption->decrypt($enc);
    }

    public function frankingLicense(): string
    {
        return (string) get_option('wpp_franking_license', '');
    }

    public function defaultProduct(): string
    {
        $v = (string) get_option('wpp_default_product', Products::DEFAULT_KEY);
        return Products::isValid($v) ? $v : Products::DEFAULT_KEY;
    }

    /** @return string[] Resolved PRZL codes for the configured default product. */
    public function defaultPrznl(): array
    {
        return Products::przl($this->defaultProduct());
    }

    public function defaultLabelFormat(): string
    {
        $v = strtoupper((string) get_option('wpp_default_label_format', 'PDF'));
        return in_array($v, ['PDF', 'PNG', 'ZPL2', 'JPG', 'GIF', 'EPS', 'SPDF'], true) ? $v : 'PDF';
    }

    public function defaultLabelSize(): string
    {
        $v = strtoupper((string) get_option('wpp_default_label_size', 'A6'));
        return in_array($v, ['A5', 'A6', 'A7', 'FE'], true) ? $v : 'A6';
    }

    public function defaultResolution(): int
    {
        $v = (int) get_option('wpp_default_resolution', 300);
        return in_array($v, [200, 300, 600], true) ? $v : 300;
    }

    public function senderAddress(): Address
    {
        $raw = get_option('wpp_sender_address', []);
        if (!is_array($raw)) {
            $raw = [];
        }
        return Address::fromArray($raw);
    }
}
