<?php

declare(strict_types=1);

namespace WPPost\Domain;

/**
 * The result of a generateAddressLabel call.
 */
final class Label
{
    public function __construct(
        public readonly string $identCode,   // Swiss Post tracking / ident code
        public readonly string $binary,      // raw (already base64-decoded) label bytes
        public readonly string $format,      // PDF | PNG | ZPL2 | ...
        public readonly bool $isSpecimen = false
    ) {}

    public function mimeType(): string
    {
        return match (strtoupper($this->format)) {
            'PDF', 'SPDF' => 'application/pdf',
            'PNG'         => 'image/png',
            'JPG', 'JPEG' => 'image/jpeg',
            'GIF'         => 'image/gif',
            'EPS'         => 'application/postscript',
            'ZPL2'        => 'application/zpl',
            default       => 'application/octet-stream',
        };
    }

    public function fileExtension(): string
    {
        return match (strtoupper($this->format)) {
            'PDF', 'SPDF' => 'pdf',
            'PNG'         => 'png',
            'JPG', 'JPEG' => 'jpg',
            'GIF'         => 'gif',
            'EPS'         => 'eps',
            'ZPL2'        => 'zpl',
            default       => 'bin',
        };
    }
}
