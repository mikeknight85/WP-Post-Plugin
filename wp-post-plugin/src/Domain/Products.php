<?php

declare(strict_types=1);

namespace WPPost\Domain;

/**
 * Curated catalog of Swiss Post parcel products. Each preset maps a
 * human-readable label (shown in the admin UI) to a list of PRZL service
 * codes sent to the DCAPI generateAddressLabel endpoint.
 *
 * The list is intentionally small — these are the products most users
 * actually pick. Add more entries here as needed; no other code change.
 */
final class Products
{
    public const DEFAULT_KEY = 'pri';

    /** @var array<string, array{label:string, przl:string[]}> */
    public const PRESETS = [
        'eco' => [
            'label' => 'PostPac Economy ≤ 2 kg',
            'przl'  => ['ECO'],
        ],
        'eco_sig' => [
            'label' => 'PostPac Economy + Unterschrift ≤ 2 kg',
            'przl'  => ['ECO', 'ZAW3213'],
        ],
        'pri' => [
            'label' => 'PostPac Priority ≤ 2 kg',
            'przl'  => ['PRI'],
        ],
        'pri_sig' => [
            'label' => 'PostPac Priority + Unterschrift ≤ 2 kg',
            'przl'  => ['PRI', 'ZAW3213'],
        ],
    ];

    /** @return string[] PRZL codes for the given preset key, or the default's. */
    public static function przl(string $key): array
    {
        return self::PRESETS[$key]['przl'] ?? self::PRESETS[self::DEFAULT_KEY]['przl'];
    }

    public static function label(string $key): string
    {
        return self::PRESETS[$key]['label'] ?? $key;
    }

    public static function isValid(string $key): bool
    {
        return isset(self::PRESETS[$key]);
    }

    /** @return array<string, string> map of preset key → label, suitable for a <select>. */
    public static function options(): array
    {
        $out = [];
        foreach (self::PRESETS as $key => $cfg) {
            $out[$key] = $cfg['label'];
        }
        return $out;
    }
}
