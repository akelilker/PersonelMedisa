#!/usr/bin/env bash

set -Eeuo pipefail

: "${MEDISA_APP_PATH:?MEDISA_APP_PATH is required}"
PHP_CLI_PATH="${MEDISA_PHP_CLI_PATH:-php}"
EXPECTED_SHA="${1:-}"

cd -- "$MEDISA_APP_PATH"

test -f api/bin/migrate.php
test -d api/migrations

if [[ -n "$EXPECTED_SHA" && -f api/.deploy-sha ]]; then
  DEPLOYED_SHA="$(tr -d '\r\n' < api/.deploy-sha)"
  [[ "$DEPLOYED_SHA" == "$EXPECTED_SHA" ]] || {
    printf 'deployment_sha=failed\n' >&2
    exit 1
  }
fi

MIGRATION_ARGS=()
if [[ -n "${MEDISA_MIGRATION_BASELINE:-}" ]]; then
  MIGRATION_ARGS+=("--baseline=${MEDISA_MIGRATION_BASELINE}")
fi

"$PHP_CLI_PATH" api/bin/migrate.php "${MIGRATION_ARGS[@]}"
"$PHP_CLI_PATH" api/bin/migrate.php --verify
