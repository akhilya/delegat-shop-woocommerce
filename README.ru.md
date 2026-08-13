# Магазин Delegat + WooCommerce

[English](README.md) · [Русский](README.ru.md)

В этом репозитории две вещи:

1. **Тестовый магазин** — WordPress + WooCommerce + мост Delegat, запускается через Docker.
2. **Zip плагина** — если у вас уже есть свой WooCommerce.

Нужен только [Docker Desktop](https://www.docker.com/products/docker-desktop/), и он должен быть запущен.

---

## А. Тестовый магазин на компьютере

В папке репозитория:

```bash
docker compose up -d --build
```

Или готовый образ без локальной сборки:

```bash
docker compose pull
docker compose up -d
```

Первый запуск может занять несколько минут (скачивается WooCommerce). Смотреть прогресс:

```bash
docker compose logs -f wp-init
```

Когда появится `READY`, откройте:

| | Адрес |
| --- | --- |
| Магазин | http://localhost:8088 |
| Админка | http://localhost:8088/wp-admin |
| Логин | `admin` / `admin` |

### Что вставить в Delegat

| Поле | Значение |
| --- | --- |
| URL моста | `http://localhost:8088/wp-json/delegat/v1` |
| API-ключ | `delegat-woo-sandbox-api-key-local` |

Если сам Delegat тоже в Docker, вместо `localhost` укажите `http://host.docker.internal:8088/wp-json/delegat/v1`.

**Остановить** (данные магазина сохранятся): `docker compose down`  
**Удалить всё** и начать заново: `docker compose down -v`

### Сменить порт, пароль или ключ

```bash
cp .env.example .env
```

Поправьте `.env` и снова `docker compose up -d`. Для значений по умолчанию файл `.env` не нужен.

---

## Б. Мост на уже существующий магазин

1. Скачайте **`delegat-bridge.zip`** со страницы [Releases](https://github.com/akhilya/delegat-shop-woocommerce/releases).
2. В WordPress: **Плагины → Добавить новый → Загрузить плагин** — установите и включите **Delegat Bridge**. WooCommerce уже должен быть включён.
3. **Настройки → Delegat Bridge** — скопируйте API-ключ и REST URL.
4. В Delegat вставьте:
   - URL: `https://ваш-магазин/wp-json/delegat/v1`
   - тот же API-ключ

---

## В. Выложить тестовый магазин на сервер (Dokploy)

Тот же `docker-compose.yml`. В Dokploy нужны только: Git-репозиторий, переменные окружения и домен. Не добавляйте в compose свои настройки Traefik или сети.

1. Подключите этот GitHub-репозиторий.
2. Новое приложение **Compose**, файл `docker-compose.yml`.
3. Environment — скопируйте `.env.example` и поменяйте хотя бы:
   - `SITE_URL=https://woo.ваш.домен` — **без** слэша в конце
   - нормальные `WP_ADMIN_PASSWORD` и `DELEGAT_API_KEY`
   - `PUBLISH_PORT` — любой большой порт, **не** `80` (80 уже занят Dokploy)
4. **Domains:** ваш хост → сервис **`wordpress`**, порт контейнера **`80`**, включите HTTPS.
5. Deploy. При первом старте нужен интернет (скачивается WooCommerce).

В Delegat тогда будет `https://woo.ваш.домен/wp-json/delegat/v1`.

Если после смены `SITE_URL` магазин «поехал», сбросьте данные и задеплойте снова.

---

## Если не запускается

- Подождите: первый раз долго.
- Проверьте, что Docker Desktop запущен.
- Логи: `docker compose logs -f wp-init`
- Сброс: `docker compose down -v`, затем `docker compose up -d --build`

Проверка, что мост отвечает:

```bash
curl -sS -H "Authorization: Bearer delegat-woo-sandbox-api-key-local" \
  http://localhost:8088/wp-json/delegat/v1/health
```

Должен вернуться JSON, а не страница ошибки.

---

## Для тех, кто сопровождает репозиторий

Пуш в `main` публикует образ магазина `ghcr.io/akhilya/delegat-shop-woocommerce:latest`.

Тег `v1.2.1` публикует этот тег образа **и** GitHub Release с `delegat-bridge.zip`.

```bash
git tag v1.2.1
git push origin v1.2.1
```

Образ публичный: `docker pull ghcr.io/akhilya/delegat-shop-woocommerce:latest` без логина.
