# Delegat WooCommerce shop

Standalone WordPress + WooCommerce stack with a vendored [Delegat Bridge](plugin/delegat-bridge/) (v **1.2.1**). Use it to smoke-test the Delegat WooCommerce adapter locally or on Dokploy.

This repo does **not** depend on the Control Plane. Plugin files live in `plugin/delegat-bridge/` and are bind-mounted into `wp-content/plugins/delegat-bridge`.

## One local command

```bash
docker compose up -d
```

`.env` is optional. Compose defaults match the table below. To change URL, ports, or passwords:

```bash
cp .env.example .env
# edit .env
docker compose up -d
```

First start downloads WooCommerce from wordpress.org (outbound network). Watch:

```bash
docker compose logs -f wp-init
```

When you see `READY`, open http://localhost:8088

Stop (keep data): `docker compose down`  
Wipe volumes: `docker compose down -v`

## Environment

| Variable | Default | Notes |
| --- | --- | --- |
| `SITE_URL` | `http://localhost:8088` | Public origin, **no trailing slash**. WordPress `WP_HOME` / `WP_SITEURL`. |
| `PUBLISH_PORT` | `8088` | Host → container **80**. On Dokploy leave a high unused port; Traefik still targets container 80. Do **not** publish host `:80`. |
| `MYSQL_*` | `wordpress` | MariaDB database, user, passwords. |
| `WP_ADMIN_USER` / `WP_ADMIN_PASSWORD` / `WP_ADMIN_EMAIL` | `admin` / `admin` / `admin@example.com` | WP-CLI install (first boot only). |
| `WP_TITLE` | `Delegat WooCommerce shop` | Site title. |
| `DELEGAT_API_KEY` | `delegat-woo-sandbox-api-key-local` | Stored as `delegat_bridge_api_key`. Paste into Delegat. |

## Credentials (paste into Delegat)

| | Local default |
| --- | --- |
| Storefront | http://localhost:8088 |
| Admin | http://localhost:8088/wp-admin |
| Login | `admin` / `admin` |
| Bridge URL | `http://localhost:8088/wp-json/delegat/v1` |
| API key | `delegat-woo-sandbox-api-key-local` |

If Delegat.Module runs **inside Docker**, use `http://host.docker.internal:8088/wp-json/delegat/v1` instead of `localhost`.

## Health

```bash
curl -sS -H "Authorization: Bearer delegat-woo-sandbox-api-key-local" \
  http://localhost:8088/wp-json/delegat/v1/health
```

Expect JSON and header `X-Delegat-Bridge-Version: 1.2.1`. The adapter also sends `X-Delegat-Api-Key`; Bearer is what must work.

## Dokploy

Same compose file. Do **not** bake Traefik labels or `dokploy-network` into compose — Dokploy attaches those when you add a domain.

1. Connect this **private** GitHub repo (GitHub App or deploy key).
2. Create a Compose application; compose file `docker-compose.yml`.
3. Environment = copy of `.env.example` with real values, especially:
   - `SITE_URL=https://woo.your.domain` (no trailing slash)
   - Strong `MYSQL_*` / `WP_ADMIN_PASSWORD` / `DELEGAT_API_KEY`
   - `PUBLISH_PORT` = a high unused port (not `80`)
4. **Domains:** hostname → service `wordpress`, container port **80**, HTTPS / Let’s Encrypt.
5. Deploy. First start still needs outbound access to wordpress.org for WooCommerce.

Changing `SITE_URL` after data exists: WordPress follows `WP_HOME` / `WP_SITEURL` from compose. If permalinks or assets break, `docker compose down -v` and redeploy.

Bridge URL on Dokploy: `https://woo.your.domain/wp-json/delegat/v1`
