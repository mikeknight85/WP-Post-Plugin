<?php

declare(strict_types=1);

namespace WPPost\Admin;

use WPPost\Api\ApiException;
use WPPost\Cpt\ShipmentCpt;
use WPPost\Labels\LabelService;
use WPPost\Labels\PdfMerger;
use WPPost\Support\Logger;

/**
 * Adds "Generate Swiss Post labels" to the bulk-actions dropdown on both
 * the WooCommerce orders list (legacy + HPOS) and the Shipments CPT list.
 *
 * For PDF labels, streams a merged multi-page PDF. For non-PDF formats
 * (PNG / ZPL2 / …), streams a ZIP archive of the individual files.
 */
final class BulkActions
{
    public const ACTION = 'wpp_generate_labels';

    public function __construct(
        private LabelService $labelService,
        private PdfMerger $merger,
        private Logger $logger
    ) {}

    public function register(): void
    {
        // Shipments CPT list
        add_filter('bulk_actions-edit-' . ShipmentCpt::POST_TYPE, [$this, 'addAction']);
        add_filter('handle_bulk_actions-edit-' . ShipmentCpt::POST_TYPE, [$this, 'handle'], 10, 3);

        // WC legacy shop order list
        add_filter('bulk_actions-edit-shop_order', [$this, 'addAction']);
        add_filter('handle_bulk_actions-edit-shop_order', [$this, 'handle'], 10, 3);

        // WC HPOS orders list
        add_filter('bulk_actions-woocommerce_page_wc-orders', [$this, 'addAction']);
        add_filter('handle_bulk_actions-woocommerce_page_wc-orders', [$this, 'handle'], 10, 3);

        add_action('admin_notices', [$this, 'maybeShowNotice']);
    }

    public function addAction(array $actions): array
    {
        $actions[self::ACTION] = __('Generate Swiss Post labels', 'wp-post-plugin');
        return $actions;
    }

    /**
     * @param string $redirectTo
     * @param string $action
     * @param int[]  $ids
     */
    public function handle(string $redirectTo, string $action, array $ids): string
    {
        if ($action !== self::ACTION) {
            return $redirectTo;
        }
        if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce') && !current_user_can('edit_others_posts')) {
            wp_die(__('You do not have permission to generate labels.', 'wp-post-plugin'));
        }

        $results = [];
        $errors  = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            try {
                $res = $this->labelService->generateForEntity($id);
                $results[$id] = $res;
            } catch (ApiException $e) {
                $errors[$id] = $e->getMessage();
                $this->logger->error('Bulk label failed', ['id' => $id, 'error' => $e->getMessage()]);
            } catch (\Throwable $e) {
                $errors[$id] = $e->getMessage();
                $this->logger->error('Bulk label error', ['id' => $id, 'error' => $e->getMessage()]);
            }
        }

        if ($results === []) {
            return add_query_arg([
                'wpp_bulk' => 'fail',
                'wpp_count' => count($errors),
            ], $redirectTo);
        }

        // Determine output: all-PDF → merged, otherwise ZIP.
        $allPdf = true;
        $paths = [];
        foreach ($results as $res) {
            $paths[] = $res['path'];
            if (strtoupper($res['label']->format) !== 'PDF' && strtoupper($res['label']->format) !== 'SPDF') {
                $allPdf = false;
            }
        }

        try {
            if ($allPdf) {
                $bytes = $this->merger->merge($paths);
                $filename = 'swisspost-labels-' . gmdate('Ymd-His') . '.pdf';
                $this->streamDownload($bytes, $filename, 'application/pdf');
            } else {
                [$bytes, $filename] = $this->zipFiles($paths);
                $this->streamDownload($bytes, $filename, 'application/zip');
            }
        } catch (\Throwable $e) {
            $this->logger->error('Bulk output failed', ['error' => $e->getMessage()]);
            return add_query_arg([
                'wpp_bulk' => 'partial',
                'wpp_count' => count($results),
                'wpp_err' => count($errors),
                'wpp_msg' => rawurlencode($e->getMessage()),
            ], $redirectTo);
        }
        exit; // streamDownload exits, but just in case.
    }

    /**
     * @param string[] $paths
     * @return array{0:string,1:string}
     */
    private function zipFiles(array $paths): array
    {
        if (!class_exists(\ZipArchive::class)) {
            throw new \RuntimeException('ZipArchive not available on this server.');
        }
        $tmp = wp_tempnam('wpp-labels.zip');
        $zip = new \ZipArchive();
        if ($zip->open($tmp, \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Cannot create ZIP archive.');
        }
        foreach ($paths as $p) {
            $zip->addFile($p, basename($p));
        }
        $zip->close();
        $bytes = (string) file_get_contents($tmp);
        @unlink($tmp);
        return [$bytes, 'swisspost-labels-' . gmdate('Ymd-His') . '.zip'];
    }

    private function streamDownload(string $bytes, string $filename, string $mime): void
    {
        nocache_headers();
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($bytes));
        echo $bytes;
        exit;
    }

    public function maybeShowNotice(): void
    {
        if (!isset($_GET['wpp_bulk'])) {
            return;
        }
        $state = sanitize_text_field((string) $_GET['wpp_bulk']);
        if ($state === 'fail') {
            echo '<div class="notice notice-error"><p>' .
                esc_html__('No Swiss Post labels could be generated. Check the log.', 'wp-post-plugin') .
                '</p></div>';
        } elseif ($state === 'partial') {
            $count = (int) ($_GET['wpp_count'] ?? 0);
            $err   = (int) ($_GET['wpp_err'] ?? 0);
            echo '<div class="notice notice-warning"><p>' .
                sprintf(
                    esc_html__('%1$d labels generated, %2$d failed.', 'wp-post-plugin'),
                    $count,
                    $err
                ) . '</p></div>';
        }
    }
}
