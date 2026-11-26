#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

PROJECT=${DELEGAT_SMOKE_PROJECT:-delegat-bridge-smoke}

compose() {
    docker compose --project-name "$PROJECT" \
        -f docker-compose.yml \
        -f docker-compose.smoke.yml \
        "$@"
}

cleanup() {
    compose down -v --remove-orphans >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

cleanup
compose build wordpress
compose up -d db wordpress
compose run --rm wp-init
compose run --rm smoke

echo "Dockerized bridge smoke tests passed"
