#!/bin/sh
set -eu
cd "$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)"
# No shell sourcing of secrets. Compose reads the dedicated file directly.
compose() { docker compose --env-file "${AZURE_ENV_FILE:-.env.azure-testing}" "$@"; }
if [ -z "$(compose ps --status running -q web)" ]; then
    echo 'Nginx is stopped; certificate renewal deferred until startup.'
    exit 0
fi
compose run --rm --no-deps certbot renew --webroot -w /var/www/acme --non-interactive
# Restart regenerates configuration, including when the first certificate was issued.
compose restart web
