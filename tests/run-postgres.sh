#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
postgres_bin="${POSTGRES_TEST_BIN:-/usr/lib/postgresql/18/bin}"
if [[ ! -x "$postgres_bin/initdb" || ! -x "$postgres_bin/pg_ctl" ]]; then
    echo "PostgreSQL 18 local no disponible; no se ejecutan pruebas con DB." >&2
    exit 1
fi
postgres_workspace="$(mktemp -d /tmp/transporte-tests-XXXXXX)"
postgres_data="$postgres_workspace/data"
if [[ -n "${POSTGRES_TEST_DATA_ROOT:-}" ]]; then
    # Keep the verified private socket in /tmp; a separate temporary data root
    # avoids exhausting tmpfs during the full integration/concurrency suite.
    mkdir -p "$POSTGRES_TEST_DATA_ROOT"
    postgres_data="$(mktemp -d "$POSTGRES_TEST_DATA_ROOT/transporte-tests-XXXXXX")"
    postgres_php_tmp="$(mktemp -d "$POSTGRES_TEST_DATA_ROOT/php-tmp-XXXXXX")"
    export TMPDIR="$postgres_php_tmp"
fi
postgres_started=0
finish() {
    if [[ "$postgres_started" == 1 ]]; then
        "$postgres_bin/pg_ctl" -D "$postgres_data" -m fast stop >/dev/null
    fi
    echo "Artefactos de pruebas: $postgres_workspace"
    if [[ "$postgres_data" != "$postgres_workspace/data" ]]; then
        echo "Datos sintéticos de pruebas: $postgres_data"
    fi
}
trap finish EXIT
"$postgres_bin/initdb" -D "$postgres_data" -U transport_test --auth=trust --no-locale --encoding=UTF8 >"$postgres_workspace/init.log"
"$postgres_bin/pg_ctl" -D "$postgres_data" -l "$postgres_workspace/server.log" -o "-k $postgres_workspace -p 55439 -h '' -c timezone=UTC" start >/dev/null
postgres_started=1
"$postgres_bin/createdb" -h "$postgres_workspace" -p 55439 -U transport_test transporte_test
export TEST_DB_HOST="$postgres_workspace" TEST_DB_PORT=55439 TEST_DB_USERNAME=transport_test TEST_DB_PASSWORD='' TEST_DB_ISOLATED=1
if [[ "${1:-}" == '--e2e' || "${1:-}" == '--e2e-transport' || "${1:-}" == '--e2e-workshop' || "${1:-}" == '--e2e-workshop-execution' ]]; then
    postgres_suite="$1"
    shift
    export APP_ENV=e2e TRANSPORTE_E2E_ROOT="$postgres_workspace/e2e"
    TRANSPORTE_E2E_KEY="$(php -r 'echo "base64:".base64_encode(random_bytes(32));')"
    export TRANSPORTE_E2E_KEY
    mkdir -p "$TRANSPORTE_E2E_ROOT/storage/framework/"{cache/data,views,sessions} "$TRANSPORTE_E2E_ROOT/storage/app"
    php tests/E2E/console.php prepare </dev/null
    if [[ "$postgres_suite" == '--e2e-workshop-execution' ]]; then
        "${PLAYWRIGHT_PYTHON:-python3}" tests/E2E/test_workshop_execution.py "$@"
    elif [[ "$postgres_suite" == '--e2e-workshop' ]]; then
        "${PLAYWRIGHT_PYTHON:-python3}" tests/E2E/test_workshop_module.py "$@"
    elif [[ "$postgres_suite" == '--e2e-transport' ]]; then
        "${PLAYWRIGHT_PYTHON:-python3}" tests/E2E/test_transport_module.py "$@"
    else
        "${PLAYWRIGHT_PYTHON:-python3}" tests/E2E/test_document_module.py "$@"
    fi
else
    vendor/bin/phpunit "$@"
fi
