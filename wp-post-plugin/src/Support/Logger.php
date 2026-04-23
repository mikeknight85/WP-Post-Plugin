<?php

declare(strict_types=1);

namespace WPPost\Support;

/**
 * Thin logging facade. Uses WooCommerce logger when available, falls back to error_log().
 * Keeps log lines to a single line so they grep cleanly.
 */
final class Logger
{
    private const SOURCE = 'wp-post-plugin';

    public function info(string $message, array $context = []): void
    {
        $this->write('info', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('warning', $message, $context);
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('error', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $line = $message;
        if ($context !== []) {
            // Redact obvious secrets before persisting.
            $context = $this->redact($context);
            $line .= ' ' . wp_json_encode($context);
        }

        if (function_exists('wc_get_logger')) {
            wc_get_logger()->log($level, $line, ['source' => self::SOURCE]);
            return;
        }

        if (defined('WP_DEBUG_LOG') && WP_DEBUG_LOG) {
            error_log(sprintf('[%s][%s] %s', self::SOURCE, $level, $line));
        }
    }

    private function redact(array $context): array
    {
        $sensitive = ['client_secret', 'secret', 'access_token', 'authorization', 'password'];
        foreach ($context as $key => $value) {
            if (is_string($key) && in_array(strtolower($key), $sensitive, true)) {
                $context[$key] = '***';
            } elseif (is_array($value)) {
                $context[$key] = $this->redact($value);
            }
        }
        return $context;
    }
}
