<?php

declare(strict_types=1);

namespace WPPost\Labels;

use RuntimeException;
use WPPost\Domain\Label;

/**
 * Saves generated labels under wp-content/uploads/wp-post-labels/YYYY/MM/
 * and drops an .htaccess + index.html into the root directory so labels
 * can't be browsed directly.
 */
final class LabelStorage
{
    public const SUBDIR = 'wp-post-labels';

    public function save(string $entityId, Label $label): string
    {
        $dir = $this->ensureMonthDir();
        $safeEntity = preg_replace('/[^A-Za-z0-9_\-]/', '_', $entityId) ?? 'x';
        $safeIdent  = preg_replace('/[^A-Za-z0-9_\-]/', '_', $label->identCode !== '' ? $label->identCode : uniqid('lbl', true)) ?? 'lbl';
        $filename = $safeEntity . '-' . $safeIdent . '.' . $label->fileExtension();
        $path = trailingslashit($dir) . $filename;

        $written = file_put_contents($path, $label->binary);
        if ($written === false) {
            throw new RuntimeException('Could not write label file to ' . $path);
        }

        @chmod($path, 0644);
        return $path;
    }

    public function ensureRootDir(): string
    {
        $uploads = wp_get_upload_dir();
        if (!empty($uploads['error'])) {
            throw new RuntimeException('Uploads directory unavailable: ' . $uploads['error']);
        }
        $root = trailingslashit($uploads['basedir']) . self::SUBDIR;
        if (!is_dir($root) && !wp_mkdir_p($root)) {
            throw new RuntimeException('Could not create ' . $root);
        }

        // Protective files — harmless if they already exist.
        $hta = $root . '/.htaccess';
        if (!file_exists($hta)) {
            file_put_contents($hta, "Require all denied\n<IfModule !mod_authz_core.c>\nOrder allow,deny\nDeny from all\n</IfModule>\n");
        }
        $idx = $root . '/index.html';
        if (!file_exists($idx)) {
            file_put_contents($idx, '');
        }
        return $root;
    }

    private function ensureMonthDir(): string
    {
        $root = $this->ensureRootDir();
        $sub = $root . '/' . gmdate('Y') . '/' . gmdate('m');
        if (!is_dir($sub) && !wp_mkdir_p($sub)) {
            throw new RuntimeException('Could not create ' . $sub);
        }
        return $sub;
    }
}
