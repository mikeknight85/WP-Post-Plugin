# WP Post Plugin

WordPress plugin that generates Swiss Post compliant barcodes and address labels via the official [Digital Commerce API](https://developer.post.ch/en/digital-commerce-api) (DCAPI).

- **Source-agnostic** — works with WooCommerce orders when WC is active, falls back to a standalone `Shipment` custom post type otherwise.
- **Test & Production** modes — Test mode sets `printPreview: true` so the API returns non-billable SPECIMEN labels.
- **Single and bulk** label generation — bulk merges PDF labels into one file, or zips non-PDF formats.
- **Secrets encrypted at rest** — `client_secret` stored with AES-256-GCM keyed on `AUTH_KEY`.

## Status

v0.1.0 — scaffolded, not yet validated against a live Swiss Post account. Address verification (`/runquery2`) and checkout autocomplete (`/autocomplete4`) are deferred to v2.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- A Swiss Post billing relationship + API credentials from [developer.post.ch](https://developer.post.ch) with scope `DCAPI_BARCODE_READ`
- A Swiss Post franking licence (Frankierlizenz)
- (Optional) WooCommerce 8.x+
- (Optional) Composer — required only for bulk-PDF merging via `setasign/fpdi`

## Install

```bash
cd wp-post-plugin
composer install   # optional but recommended
```

Symlink or copy the `wp-post-plugin/` directory into `wp-content/plugins/` and activate.

## Configure

1. **Settings → WP Post Plugin**
2. Enter Test credentials → click **Test connection** → expect a token preview
3. Enter Franking licence, default PRZL codes (e.g. `PRI`), sender address
4. Generate a label from an order or Shipment post

## Project layout

```
wp-post-plugin/
├── wp-post-plugin.php           bootstrap
├── composer.json                setasign/fpdi, phpunit (dev)
├── templates/settings-page.php
└── src/
    ├── Plugin.php               container + hook registration
    ├── Api/                     HttpClient, OAuthClient, BarcodeClient
    ├── Domain/                  Address, Shipment, Label
    ├── Sources/                 WooCommerceSource, ShipmentCptSource
    ├── Cpt/ShipmentCpt.php
    ├── Admin/                   SettingsPage, OrderMetaBox, BulkActions
    ├── Labels/                  LabelService, LabelStorage, PdfMerger
    ├── Settings/Settings.php
    └── Support/                 Logger, Encryption
```

## License

GPL-2.0-or-later
