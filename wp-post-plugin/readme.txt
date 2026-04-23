=== WP Post Plugin ===
Contributors: kesslemi
Tags: swiss post, barcode, label, woocommerce, shipping
Requires at least: 6.4
Tested up to: 6.5
Requires PHP: 8.1
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Generate Swiss Post compliant barcodes and address labels from WordPress (WooCommerce optional) via the official Digital Commerce API.

== Description ==

* OAuth 2.0 Client Credentials flow against Swiss Post DCAPI
* Single-click label generation on WooCommerce orders
* Standalone "Shipment" custom post type when WooCommerce is not installed
* Bulk labels: merged PDF or ZIP of individual files
* Test mode (SPECIMEN) and Production mode
* Client secrets encrypted at rest (AES-256-GCM, AUTH_KEY-derived)

== Configuration ==

1. Obtain API credentials at https://developer.post.ch with the scope `DCAPI_BARCODE_READ`.
2. Go to Settings → WP Post Plugin, enter credentials for Test and/or Production.
3. Enter your franking licence (Frankierlizenz) and default PRZL codes (e.g. "PRI").
4. Click "Test connection" to verify.
5. With Test mode on, generate a label — the result will be a SPECIMEN.

== Changelog ==

= 0.1.0 =
* Initial release: barcode + address label generation, WC + CPT sources, bulk action.
