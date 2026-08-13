# Delegat WooCommerce shop

[English](README.md) · [Русский](README.ru.md)

Two things in this repo:

1. **A test shop** — WordPress + WooCommerce + Delegat Bridge, started with Docker.
2. **A plugin zip** — for a WooCommerce shop you already have.

You only need [Docker Desktop](https://www.docker.com/products/docker-desktop/) (must be running).

---

## A. Run a test shop on your computer

In this folder:

```bash
docker compose up -d --build
```

Or use the published image (no local build):

```bash
docker compose pull
docker compose up -d
```

The first start can take a few minutes (it downloads WooCommerce). Watch progress:

```bash
docker compose logs -f wp-init
```

When you see `READY`, open:

| | Address |
| --- | --- |
| Shop | http://localhost:8088 |
| Admin | http://localhost:8088/wp-admin |
| Login | `admin` / `admin` |

### Connect it in Delegat

| Field | Value |
| --- | --- |
| Bridge URL | `http://localhost:8088/wp-json/delegat/v1` |
| API key | `delegat-woo-sandbox-api-key-local` |

If Delegat itself runs in Docker, use `http://host.docker.internal:8088/wp-json/delegat/v1` instead of `localhost`.

**Stop** (keep the shop data): `docker compose down`  
**Delete everything** and start over: `docker compose down -v`

### Change the port, password, or key

```bash
cp .env.example .env
```

Edit `.env`, then `docker compose up -d` again. You do **not** need a `.env` file for the defaults above.

---

## B. Add Delegat to a shop you already have

1. Download **`delegat-bridge.zip`** from [Releases](https://github.com/akhilya/delegat-shop-woocommerce/releases).
2. In WordPress: **Plugins → Add New → Upload Plugin** — install and activate **Delegat Bridge**. WooCommerce must already be active.
3. Open **Settings → Delegat Bridge**, copy the API key and the REST URL.
4. In Delegat, paste:
   - URL: `https://your-shop/wp-json/delegat/v1`
   - the same API key

---

## C. Put the test shop on a server (Dokploy)

Same `docker-compose.yml`. In Dokploy you only need: the Git repo, environment variables, and a domain. Do not add extra Traefik / network settings in the compose file.

1. Connect this GitHub repo.
2. New **Compose** app, file `docker-compose.yml`.
3. Environment — copy `.env.example` and change at least:
   - `SITE_URL=https://woo.your.domain` — **no** slash at the end
   - a real `WP_ADMIN_PASSWORD` and `DELEGAT_API_KEY`
   - `PUBLISH_PORT` — any high port, **not** `80` (Dokploy already uses 80)
4. **Domains:** your hostname → service **`wordpress`**, container port **`80`**, enable HTTPS.
5. Deploy. The first start still needs internet (WooCommerce download).

Then in Delegat use `https://woo.your.domain/wp-json/delegat/v1`.

If the shop looks broken after you change `SITE_URL`, reset data (`docker compose down -v` or the Dokploy equivalent) and deploy again.

---

## If it does not start

- Wait: the first run is slow.
- Confirm Docker Desktop is running.
- Check logs: `docker compose logs -f wp-init`
- Reset: `docker compose down -v` then `docker compose up -d --build`

Quick check that the bridge answers:

```bash
curl -sS -H "Authorization: Bearer delegat-woo-sandbox-api-key-local" \
  http://localhost:8088/wp-json/delegat/v1/health
```

You should get JSON (not an HTML error page).

---

## For maintainers

Push to `main` publishes the shop image `ghcr.io/akhilya/delegat-shop-woocommerce:latest`.

A tag `v1.2.1` also publishes that image tag **and** a GitHub Release with `delegat-bridge.zip`.

```bash
git tag v1.2.1
git push origin v1.2.1
```

Anyone can pull `ghcr.io/akhilya/delegat-shop-woocommerce:latest` without logging in.
