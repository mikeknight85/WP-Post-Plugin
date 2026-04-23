<?php

declare(strict_types=1);

namespace WPPost\Settings;

use WPPost\Domain\Address;
use WPPost\Support\Encryption;

/**
 * Centralized access to all plugin options. Secrets are transparently
 * encrypted/decrypted using Encryption.
 *
 * Stored options:
 *  - wpp_environment          : "test" | "prod"
 *  - wpp_language             : "DE" | "FR" | "IT" | "EN"
 *  - wpp_test_client_id       : string
 *  - wpp_test_client_secret   : encrypted string
 *  - wpp_prod_client_id       : string
 *  - wpp_prod_client_secret   : encrypted string
 *  - wpp_franking_license     : string
 *  - wpp_default_przl         : string[] (e.g. ["PRI"])
 *  - wpp_default_label_format : PDF|PNG|ZPL2|...
 *  - wpp_default_label_size   : A5|A6|A7|FE
 *  - wpp_default_resolution   : 200|300|600
 *  - wpp_sender_address       : array (see Address::fromArray)
 */
final class Settings
{
    public const OPTION_GROUP = 'wp-post-plugin';

    public function __construct(private Encryption $encryption) {}

    public function environment(): string
    {
        $v = (string) get_option('wpp_environment', 'test');
        return $v === 'prod' ? 'prod' : 'test';
    }

    public function language(): string
    {
        $v = strtoupper((string) get_option('wpp_language', 'DE'));
        return in_array($v, ['DE', 'FR', 'IT', 'EN'], true) ? $v : 'DE';
    }

    /**
     * @return array{client_id:string,client_secret:string}
     */
    public function credentials(string $env): array
    {
        $env = $env === 'prod' ? 'prod' : 'test';
        $id = (string) get_option('wpp_' . $env . '_client_id', '');
        $secretEnc = (string) get_option('wpp_' . $env . '_client_secret', '');
        $secret = $secretEnc === '' ? '' : $this->encryption->decrypt($secretEnc);
        return ['client_id' => $id, 'client_secret' => $secret];
    }

    public function saveCredentials(string $env, string $clientId, string $clientSecret): void
    {
        $env = $env === 'prod' ? 'prod' : 'test';
        update_option('wpp_' . $env . '_client_id', $clientId, false);
        $stored = $clientSecret === '' ? '' : $this->encryption->encrypt($clientSecret);
        update_option('wpp_' . $env . '_client_secret', $stored, false);
    }

    public function frankingLicense(): string
    {
        return (string) get_option('wpp_franking_license', '');
    }

    /** @return string[] */
    public function defaultPrznl(): array
    {
        $raw = get_option('wpp_default_przl', ['PRI']);
        if (!is_array($raw)) {
            $raw = array_filter(array_map('trim', explode(',', (string) $raw)));
        }
        return array_values(array_filter(array_map('strval', $raw)));
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
