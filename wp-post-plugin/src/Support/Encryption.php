<?php

declare(strict_types=1);

namespace WPPost\Support;

use RuntimeException;

/**
 * AES-256-GCM for secrets at rest (client_secret, etc.).
 * The key is derived from WordPress' AUTH_KEY/SECURE_AUTH_KEY so a DB dump
 * alone is not enough to recover plaintext.
 */
final class Encryption
{
    private const CIPHER = 'aes-256-gcm';
    private const PREFIX = 'wppenc:v1:';

    public function encrypt(string $plaintext): string
    {
        if ($plaintext === '') {
            return '';
        }
        $key = $this->key();
        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            self::CIPHER,
            $key,
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($ciphertext === false) {
            throw new RuntimeException('Encryption failed.');
        }
        return self::PREFIX . base64_encode($iv . $tag . $ciphertext);
    }

    public function decrypt(string $payload): string
    {
        if ($payload === '') {
            return '';
        }
        if (strncmp($payload, self::PREFIX, strlen(self::PREFIX)) !== 0) {
            // Legacy/plain value — return as-is so the plugin doesn't break on upgrade.
            return $payload;
        }
        $raw = base64_decode(substr($payload, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('Encrypted payload is malformed.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $ciphertext = substr($raw, 28);
        $plaintext = openssl_decrypt(
            $ciphertext,
            self::CIPHER,
            $this->key(),
            OPENSSL_RAW_DATA,
            $iv,
            $tag
        );
        if ($plaintext === false) {
            throw new RuntimeException('Decryption failed — key may have changed.');
        }
        return $plaintext;
    }

    private function key(): string
    {
        $material = '';
        foreach (['AUTH_KEY', 'SECURE_AUTH_KEY', 'LOGGED_IN_KEY'] as $const) {
            if (defined($const)) {
                $material .= (string) constant($const);
            }
        }
        if ($material === '') {
            // Last-resort fallback — still better than nothing, but admin should set the keys.
            $material = (string) wp_salt('auth');
        }
        return hash('sha256', 'wp-post-plugin|' . $material, true);
    }
}
