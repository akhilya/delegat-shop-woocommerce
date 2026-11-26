#!/bin/sh
set -eu

ROOT=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
if command -v cygpath >/dev/null 2>&1; then
    ROOT=$(cygpath -m "$ROOT")
    export MSYS_NO_PATHCONV=1
fi

for version in 8.0 8.2; do
    echo "PHP $version lint"
    docker run --rm \
        -v "$ROOT:/workspace:ro" \
        -w /workspace \
        "php:$version-cli" \
        sh -c 'find plugin/delegat-bridge tests/smoke -name "*.php" -print0 | xargs -0 -n1 php -l'
done
