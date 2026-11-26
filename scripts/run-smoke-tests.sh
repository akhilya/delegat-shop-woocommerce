#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
cd "$ROOT"

if [ -n "${DELEGAT_SMOKE_PROJECT:-}" ]; then
    PROJECT=$DELEGAT_SMOKE_PROJECT
else
    PROJECT="delegat-bridge-smoke-$$"
fi

existing_resources=$(
    docker ps --all --quiet --filter "label=com.docker.compose.project=$PROJECT"
    docker volume ls --quiet --filter "label=com.docker.compose.project=$PROJECT"
    docker network ls --quiet --filter "label=com.docker.compose.project=$PROJECT"
)
if [ -n "$existing_resources" ]; then
    echo "Compose project '$PROJECT' already exists; refusing to remove or reuse it." >&2
    echo "Choose another DELEGAT_SMOKE_PROJECT or remove that project explicitly after verifying it is disposable." >&2
    exit 1
fi

compose() {
    docker compose --project-name "$PROJECT" \
        -f docker-compose.yml \
        -f docker-compose.smoke.yml \
        "$@"
}

cleanup() {
    compose down -v --remove-orphans --rmi local >/dev/null 2>&1 || true
}

trap cleanup EXIT INT TERM

compose build wordpress
compose up -d db wordpress
compose run --rm wp-init
compose run --rm smoke

echo "Dockerized bridge smoke tests passed"
