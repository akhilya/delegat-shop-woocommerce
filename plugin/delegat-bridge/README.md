# Delegat Bridge (WordPress / WooCommerce)

PHP bridge plugin for the Delegat WooCommerce adapter.

## Install

1. Copy this folder to `wp-content/plugins/delegat-bridge`
2. Activate **Delegat Bridge** (WooCommerce must be active)
3. **Settings → Delegat Bridge** — copy API key and REST base URL
4. In Delegat, create connection:
   - `apiBaseUrl` = `https://your-shop/wp-json/delegat/v1`
   - `apiKey` = key from settings

## Endpoints

All routes require `X-Delegat-Api-Key` or `Authorization: Bearer <key>`.

| Method | Path | Purpose |
|--------|------|---------|
| GET | `/health` | Connection test |
| GET | `/categories` | List product categories |
| POST | `/categories/upsert` | Create/update category |
| GET | `/categories/{id}/fields` | Default product fields |
| POST | `/products/upsert` | Create/update simple or variable product |
| POST | `/products/bulk` | Bulk upsert |
| POST | `/prices/bulk` | Update prices |
| POST | `/stocks/bulk` | Update stocks |
| GET | `/prices` | Current catalog prices (incl. `sale_price`) |
| GET | `/orders` | List orders |
| GET | `/orders/{id}` | Order detail |
| POST | `/orders/{id}/status` | Change Woo order status (`action`) |
| GET | `/promotions` | List coupons |
| GET | `/promotions/{id}` | Coupon detail |
| POST | `/promotions/{id}/products` | Add/remove coupon product ids (`removeAll` disables the coupon) |

Idempotency meta: `_delegat_product_id`, `_delegat_variant_id`. Images dedupe by `_delegat_source_url`.

