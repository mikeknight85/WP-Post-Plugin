# WP Post Plugin

WordPress plugin that generates Swiss Post compliant barcodes and address labels via the official [Digital Commerce API](https://developer.post.ch/en/digital-commerce-api) (DCAPI).

- **Source-agnostic** — works with WooCommerce orders when WC is active, falls back to a standalone `Shipment` custom post type otherwise.
- **Production-only.** All requests run against the live DCAPI Barcode endpoint with `printPreview: false`. To experiment without billing, use the [tools/test-label.php](tools/test-label.php) CLI harness with your sandbox/test credentials.
- **Per-shipment product picker** — each label can be generated as PostPac Economy / Priority (with or without signature). Defaults configurable in settings, overridable per click.
- **Single and bulk** label generation — bulk merges PDF labels into one file, or zips non-PDF formats.
- **Secrets encrypted at rest** — `client_secret` and `subscription_key` stored with AES-256-GCM keyed on `AUTH_KEY`.

## Status

v0.3.x — Production-only DCAPI Barcode integration verified end-to-end. Address verification (`/runquery2`) and checkout autocomplete (`/autocomplete4`) are deferred. Webstamp (letter postage) is a separate Swiss Post API and not yet supported.

## Requirements

- WordPress 6.4+
- PHP 8.1+
- A Swiss Post billing relationship + API credentials from [developer.post.ch](https://developer.post.ch) with scope `DCAPI_BARCODE_READ`
- A Swiss Post franking licence (Frankierlizenz)
- (Optional) WooCommerce 8.x+
- (Optional) Composer — required only for bulk-PDF merging via `setasign/fpdi`

---

# User manual

## 1. Before you install — what you need from Swiss Post

The plugin is a client; Swiss Post is the issuer. You must already have:

| Item | Where you get it | Notes |
|---|---|---|
| **Customer number / billing relationship** | Swiss Post sales contact | Prerequisite for Production credentials — contact `digitalintegration@post.ch`. |
| **API client credentials** (Client ID + Client secret) | [developer.post.ch](https://developer.post.ch) → your app | Scope must include `DCAPI_BARCODE_READ`. |
| **Subscription key** (Ocp-Apim-Subscription-Key) | Same app page on developer.post.ch | Sometimes labelled "Primary key". Required by the API gateway — without it the call returns an empty HTTP 400. |
| **Franking licence** (Frankierlizenz) | Listed on your Swiss Post contract | A short numeric string. Goes on every label. |
| **Sender address** | Your business address | Appears on each label as the return address. |

The plugin runs against Production only. To experiment without billing real labels, use [tools/test-label.php](tools/test-label.php) — see [Local testing harness](#local-testing-harness) below.

## 2. Install

### Option A — install from GitHub Releases (recommended)

1. Open the [Releases page](https://github.com/mikeknight85/WP-Post-Plugin/releases) and download the latest `wp-post-plugin-vX.Y.Z.zip`.
2. In WP admin → **Plugins → Add New → Upload Plugin** → choose the zip → **Install Now** → **Activate**.
3. (Optional, for bulk PDF merging) SSH in and run `composer install` inside `wp-content/plugins/wp-post-plugin/`. The plugin boots without it; you just can't merge PDFs in bulk until it's installed.

To **update** later: download the newer zip and re-upload — WordPress will prompt to replace the existing install.

### Option B — direct copy from source

1. Clone this repository.
2. Copy the inner `wp-post-plugin/` folder into `wp-content/plugins/` on your WordPress site.
3. (Recommended) Run `composer install` inside that folder for the bulk-PDF-merge dependency.
4. In WP admin → **Plugins** → activate **WP Post Plugin**.

### Option C — as a git clone / symlink (dev workflow)

```bash
cd wp-content/plugins
git clone https://github.com/mikeknight85/WP-Post-Plugin.git
ln -s WP-Post-Plugin/wp-post-plugin ./wp-post-plugin
cd WP-Post-Plugin/wp-post-plugin && composer install
```

On activation the plugin creates `wp-content/uploads/wp-post-labels/` with an `.htaccess` rule that blocks direct download.

## 3. Configure

In the WP admin sidebar, click the top-level **WP Post Plugin** menu item. Fill the four sections top-to-bottom.

### 3.1 General

| Field | Notes |
|---|---|
| **Label language** | `DE` / `FR` / `IT` / `EN`. Controls the language of the printed text on the label. |

### 3.2 API credentials

A single set — Production. (The plugin no longer ships a Test mode toggle; use [tools/test-label.php](tools/test-label.php) for sandboxing.)

- **Client ID** — as shown on developer.post.ch.
- **Client secret** — stored encrypted (AES-256-GCM, key derived from your `AUTH_KEY` in `wp-config.php`). The field shows `********` once saved. Leave that placeholder untouched when updating other settings; replace it only when rotating the secret.
- **Subscription key** — the `Ocp-Apim-Subscription-Key` (sometimes "Primary key") from your app on developer.post.ch. Without this the API gateway returns an empty HTTP 400.

Click **Test connection** (bottom of the page) to verify. Expected result: a green notice like `Connection OK. Token preview: abc12345…`. If you see an error, check:

- Both Client ID/secret are pasted in (not blank).
- Scope `DCAPI_BARCODE_READ` is granted on the app.
- Subscription key is present.
- Outbound HTTPS to `api.post.ch` is not blocked.

### 3.3 Shipping defaults

| Field | Example | Notes |
|---|---|---|
| **Franking licence** | `42512345` | From your contract. |
| **Default product** | `PostPac Priority ≤ 2 kg` | Dropdown of curated presets (Economy / Priority, with or without signature). Each preset maps to a fixed list of PRZL service codes. The default applies to new labels — you can override per shipment in the meta box. |
| **Label format** | `PDF` | Use `PDF` for office printers, `ZPL2` for thermal label printers (Zebra etc.), `PNG` for inline display. Non-PDF bulk exports are zipped. |
| **Label size** | `A6` | `A5`, `A6`, `A7`, or `FE` (window envelope). A6 is the common sticker size. |
| **Resolution** | `300` | 200/300/600 dpi. 300 is a safe default. |

To add a new product preset (e.g. ECO + cash-on-delivery), edit [src/Domain/Products.php](wp-post-plugin/src/Domain/Products.php) — one entry, no other code change needed.

### 3.4 Sender address

This is your return address. Company, or first+last name, at least street+ZIP+city+country (ISO-2, e.g. `CH`). Email/phone are optional but recommended for recipient notifications that Swiss Post may send.

Hit **Save Changes**. You're ready.

## 4. Generate labels — WooCommerce path

When WooCommerce is active, the plugin hooks into the order edit screen (both HPOS and legacy) and the orders list.

### 4.1 Single label from an order

1. **WooCommerce → Orders** → open any order with a Swiss shipping address.
2. In the right sidebar you'll see a **Swiss Post label** meta box.
3. Pick the **Product** for this shipment (defaults to whatever you set in **Shipping defaults**). Changes to the dropdown only affect this single click — no persistence.
4. Click **Generate label**.
5. A green notice appears at the top; the meta box now shows:
   - **Ident:** the Swiss Post tracking/ident code
   - A **Download label** button — links to an authenticated admin-post endpoint that streams the PDF (the labels folder itself is locked from public access).
6. An order note is added: *"Swiss Post label generated. Ident: 99.00.000000.00000009"*.

If the shipping address is empty the plugin falls back to the billing address. If both are empty you'll see *"Order has no usable shipping/billing address."* — fix the order and retry.

Weight is calculated from product weights × quantities (converted to grams). If no products have weight, 500 g is used as a sensible default.

### 4.2 Bulk labels from the orders list

1. **WooCommerce → Orders**. Tick several orders.
2. In the **Bulk actions** dropdown pick **Generate Swiss Post labels** → **Apply**.
3. Your browser downloads a file:
   - If every label is PDF → `swisspost-labels-YYYYMMDD-HHMMSS.pdf` (merged, one order per page)
   - Otherwise → `swisspost-labels-YYYYMMDD-HHMMSS.zip` containing individual label files

Each order still gets its ident code and download link saved to its meta. If some labels succeed and some fail, you get a yellow notice like "3 labels generated, 2 failed." — failed orders are listed in `wp-content/debug.log` when `WP_DEBUG_LOG` is on.

**Tip:** keep bulk batches ≤ ~20 orders per run. The Swiss Post API accepts one label per request, so 20 orders means 20 HTTP round-trips; larger batches may hit PHP's `max_execution_time`. A future v2 will add async queuing.

## 5. Generate labels — Shipment CPT path (no WooCommerce)

When WooCommerce is not installed — or when you need a one-off label that isn't tied to an order — use the **Shipments** post type. It's always registered.

### 5.1 Create a shipment

1. **Shipments → Add new** in the WP admin sidebar.
2. Give the shipment a title (free text, e.g. *"Warranty return — Mustermann"*).
3. Fill the **Recipient address** meta box: company, first + last name, street, house no., ZIP, city, country (`CH` by default), and weight (grams).
4. **Publish** / **Update**.

### 5.2 Generate the label

In the sidebar meta box **Swiss Post label**, click **Generate label**. Behaviour mirrors the WooCommerce flow: ident code + download link appear after success; re-click to **Re-generate** if anything changes.

### 5.3 Bulk labels from the Shipments list

Same pattern as WooCommerce: select multiple shipment posts, use the **Generate Swiss Post labels** bulk action, get a merged PDF or ZIP.

## 6. Local testing harness

Iterating on Swiss Post payload shape against the live gateway through the WP UI is slow. [tools/test-label.php](tools/test-label.php) is a standalone CLI script (no WordPress dependency) that exercises the same OAuth + generateAddressLabel flow.

```sh
cp tools/.env.example tools/.env
# Edit tools/.env: WPP_CLIENT_ID, WPP_CLIENT_SECRET, WPP_SUBSCRIPTION_KEY,
# WPP_FRANKING_LICENSE, sender + recipient addresses.
php tools/test-label.php
```

It prints the OAuth response, the request payload it sent, and the full HTTP response (status + headers + body). On a 2xx it decodes and saves the label PDF to `tools/out/`. **Production credentials are billable** — to test without billing, get sandbox credentials from your Swiss Post salesperson. The script always sets `printPreview: true` only when `WPP_ENV=test`; otherwise the call is real.

## 7. Where the files live

```
wp-content/uploads/wp-post-labels/
├── .htaccess                    # Require all denied — no direct public download
├── index.html                   # empty, prevents directory listing
└── 2026/
    └── 04/
        ├── 1234-99.00.000000.00000001.pdf     # WC order 1234
        └── 15-99.00.000000.00000002.pdf       # shipment post 15
```

Files are served via signed links only — the **Download label** button in the meta box gives an authenticated admin the direct URL; unauthenticated visitors get a 403 from Apache/Nginx.

Labels are **not** deleted when the plugin is uninstalled. Delete them manually if you need to.

## 8. Troubleshooting

| Symptom | Likely cause | Fix |
|---|---|---|
| **"Missing credentials"** when testing connection | Client ID or secret empty | Re-enter both fields, Save, re-test |
| **"Missing subscription key"** | The Subscription key field is empty | Paste the Ocp-Apim-Subscription-Key (a.k.a. Primary key) from your developer.post.ch app |
| **"OAuth token request failed (HTTP 401)"** | Wrong secret, wrong scope, or stale cached token | Re-enter credentials. The plugin also auto-drops the cached token on 401 — second click usually resolves transient issues |
| **"Swiss Post label request failed (HTTP 400)"** with empty body | Subscription key missing or wrong; or extra/unknown field in the payload (e.g. `customer.houseNo` — sender doesn't accept that) | Check the error body in the WC log. With a valid subscription key the gateway returns proper JSON validation errors. |
| **"No usable shipping/billing address"** | Empty WC order addresses | Fill the order or add them manually |
| **"PDF merge requires setasign/fpdi"** | Composer deps not installed | `cd wp-post-plugin && composer install` |
| **Labels not scanning** | 200 dpi on a dense barcode | Switch to 300 or 600 dpi |
| **Token keeps re-requesting** | `AUTH_KEY`/`SECURE_AUTH_KEY` changed in `wp-config.php` | That invalidates encrypted secrets — re-enter the Client secret and Subscription key and test again |

**Where to read logs:**

- **With WooCommerce active** (the common case) — go to **WooCommerce → Status → Logs** and pick `wp-post-plugin-…` from the dropdown. On disk: `wp-content/uploads/wc-logs/wp-post-plugin-YYYY-MM-DD-<hash>.log`.
- **Without WooCommerce** — set `WP_DEBUG_LOG` and tail `wp-content/debug.log`:
  ```php
  // wp-config.php
  define('WP_DEBUG', true);
  define('WP_DEBUG_LOG', true);
  define('WP_DEBUG_DISPLAY', false);
  ```
  Plugin log lines are prefixed `[wp-post-plugin]`.
- **PHP fatals / uncaught errors** never reach the plugin logger — those go to `wp-content/debug.log` (if `WP_DEBUG_LOG` is on) or the host's PHP error log (Plesk: `logs/error_log`).

Secrets (`client_secret`, `access_token`, `authorization`) are redacted to `***` before write.

## 9. Security notes

- **Client secret** is encrypted at rest (AES-256-GCM) with a key derived from `AUTH_KEY`/`SECURE_AUTH_KEY`/`LOGGED_IN_KEY`. A raw database dump does not expose it; you need the wp-config.php file too.
- **Access tokens** are kept in WordPress site transients with a TTL of `expires_in - 60 s`. Short-lived; dropped automatically on 401; wiped on uninstall.
- **Label files** are protected by `.htaccess Require all denied`. Nginx setups should add an equivalent `location` block in the server config.
- **No PII** is logged. Addresses are sent to Swiss Post only at label-generation time.

## 10. What's next (v2 roadmap)

Scoped but not built yet:

- **Address verification** via Swiss Post's separate Address Web Services REST (`/runquery2`). Paid, contract-required, different auth (HTTP Basic).
- **Checkout autocomplete** (`/autocomplete4`) — free, no contract, but requires a front-end JS widget on WC checkout.
- **Async bulk processing** via Action Scheduler when more than ~20 orders are selected.
- **PHPUnit test suite** — the structure is already PSR-4; tests go under `tests/Unit/` and `tests/Integration/` with Brain Monkey for WP stubs.

---

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

## Cutting a release (maintainers)

Releases are produced by a **manually-triggered** GitHub Actions workflow — there is no need to edit the version in code or push tags by hand.

1. Go to **GitHub → Actions → Release → Run workflow**.
2. Enter the new version (e.g. `0.3.0`, no `v` prefix). It must be `MAJOR.MINOR.PATCH`.
3. Click **Run workflow**.

The workflow validates the version, edits `Version:` and `WPPOST_VERSION` in [wp-post-plugin/wp-post-plugin.php](wp-post-plugin/wp-post-plugin.php), commits the bump to `main`, builds `wp-post-plugin-vX.Y.Z.zip` (with the proper top-level folder structure expected by WordPress), and publishes the GitHub Release with auto-generated notes and the zip attached.

End users then install or update via **Plugins → Add New → Upload Plugin**.

## License

GPL-2.0-or-later
