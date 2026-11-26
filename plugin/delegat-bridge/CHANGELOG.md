# Changelog

All notable changes to **Delegat Bridge** for WooCommerce are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

Version numbers match the plugin header. Distribution tags are created in this repository when a packaged `delegat-bridge.zip` is released.

## [Unreleased]

### Fixed

- Variable card target resolution no longer predicts a SKU-conflict redirect when the post-save SKU state names multiple distinct live parent products, or when the parent save itself claims the SKU before a child collision and leaves no different live retry parent. The fallback SKU lookup is deterministic, and unresolved retry ownership is reported as explicit `ambiguous` plus `possible_ids` evidence while a sole live owner remains a deterministic redirect.

## [1.3.4] - 2026-11-25

### Added

- Batch product lookup with `GET /products?ids=1,2,3`. Found rows use the same payload shape as the single-product read; ids whose WooCommerce product object no longer exists are reported in `missing_ids` instead of failing the whole lookup, and trashed products keep the single-read visibility with `status: trash`.
- `products.batch_get` in the `/health` capability list.

### Fixed

- Batch product lookup ids are validated as positive decimal integers before casting. Malformed, zero, and out-of-range tokens are rejected instead of being silently coerced.
- Exact price readback uses the stored edit-context regular and sale prices written by the mutation path; a variable parent's synchronized active child price is no longer false confirmation evidence.
- `GET /prices` applies the same strict id-token validation as the batch product lookup.
- Empty price/stock bulk requests are successful no-ops rather than partial evidence because they contain no rows to reconcile.
- Simple card target resolution no longer treats a trash-only SKU owner as a write redirect: WooCommerce SKU uniqueness excludes trash. Variable card resolution mirrors child-SKU retry ownership when possible and otherwise returns explicit `ambiguous` plus `possible_ids` evidence instead of a false canonical parent.

## [1.3.3] - 2026-11-25

### Added

- Read-only `POST /resolve` endpoint and the `resolve` capability for canonical batch target resolution. Card rows use the same precedence as the product write path, including simple/variable write shape and SKU-owner redirect evidence; price/stock mutation rows use the same parent-owned variation precedence as the writers.
- Exact write-faithful price/stock readback with `GET /prices?ids=...&exact=1&include_variations=1`. Exact mode resolves requested products without the legacy catalog status filter, echoes the applied mode in the `exact` response field, and can include variation rows that are still writable but omitted by the normal published/private child list.
- Confirmation-grade variation stock evidence in price payloads: `quantity_edit`, `stock_managed_by_id`, and the raw `manage_stock` value distinguish locally managed variation stock from inherited parent stock.

### Fixed

- Bulk price and stock row exceptions are reported as `uncertain` rather than as definitive failures, because WooCommerce after-save hooks can throw after the write has already been persisted. A batch is fully successful only when every row explicitly succeeds; uncertain or missing row verdicts degrade the response to partial evidence.
- Canonical target resolution now mirrors write behavior for SKU-owner redirects, simple-vs-variable card conversion, parent-owned numeric variation ids, and Delegat variant meta ids.
- Exact variation readback covers draft, pending, private, and trashed variations so a writable variation target is not invisible to end-state confirmation.

## [1.3.2] - 2026-11-25

### Added

- `GET /prices` supports `include_variations=1`, returning variation-level price and stock payloads with Delegat variant ids and parent product ids for variable products.

### Fixed

- Bulk price and stock writes isolate row-level PHP errors and exceptions (`Throwable`): one bad row can no longer abort the request after earlier rows committed or erase the sibling row verdicts needed for reconciliation.

## [1.3.1] - 2026-11-21

### Added

- Live catalog price and stock readback through `GET /prices`.
- Order list, detail, and status-writeback endpoints.
- Coupon promotion list/detail endpoints and coupon product restriction updates.

### Fixed

- Order status mapping rejects unsupported actions instead of writing an arbitrary status, accepts `wc-` prefixed statuses consistently, and verifies failed status transitions.
- Coupon `removeAll` reliably disables the coupon before clearing product restrictions.
- Explicit variation ids must belong to the requested parent product; unresolved variation ids no longer fall back to updating the parent.
- Archive image import can resolve products by SKU, accepts JSON `archive_base64` as well as multipart upload, and deduplicates stored archive images by product plus content hash.

## [1.2.1] - 2026-08-13

### Added

- First public WordPress plugin: REST API at `/wp-json/delegat/v1` (health, categories, products, variations, prices, stocks, async images).
- Settings screen for the API key (`Settings → Delegat Bridge`).
