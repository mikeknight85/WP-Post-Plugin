<?php

declare(strict_types=1);

namespace WPPost\Labels;

use RuntimeException;
use setasign\Fpdi\Fpdi;

/**
 * Merges a list of PDF files (by filesystem path) into one PDF and returns
 * the resulting bytes.
 *
 * Requires setasign/fpdi (installed via Composer). If Composer deps are not
 * installed, an explanatory exception is thrown at call time so single-label
 * generation still works.
 */
final class PdfMerger
{
    /**
     * @param string[] $paths
     */
    public function merge(array $paths): string
    {
        if ($paths === []) {
            throw new RuntimeException('No PDFs to merge.');
        }
        if (!class_exists(Fpdi::class)) {
            throw new RuntimeException(
                'PDF merge requires setasign/fpdi. Run "composer install" inside the plugin directory.'
            );
        }

        $pdf = new Fpdi();
        foreach ($paths as $path) {
            if (!is_readable($path)) {
                throw new RuntimeException('Cannot read PDF: ' . $path);
            }
            $pageCount = $pdf->setSourceFile($path);
            for ($p = 1; $p <= $pageCount; $p++) {
                $tpl = $pdf->importPage($p);
                $size = $pdf->getTemplateSize($tpl);
                $orientation = ($size['width'] > $size['height']) ? 'L' : 'P';
                $pdf->AddPage($orientation, [$size['width'], $size['height']]);
                $pdf->useTemplate($tpl);
            }
        }
        return (string) $pdf->Output('S');
    }
}
