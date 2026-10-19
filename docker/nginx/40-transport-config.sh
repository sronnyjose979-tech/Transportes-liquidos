#!/bin/sh
set -eu
# Domain is used in paths and Nginx configuration: reject interpolation/injection.
case "${APP_DOMAIN:-}" in
    ''|*[!a-zA-Z0-9.-]*|.*|-*|*..*) echo 'APP_DOMAIN must be a DNS hostname' >&2; exit 1 ;;
esac
rm -f /etc/nginx/conf.d/default.conf
envsubst '${APP_DOMAIN}' < /etc/transport/http.conf.template > /etc/nginx/conf.d/default.conf
if [ -f "/etc/letsencrypt/live/$APP_DOMAIN/fullchain.pem" ] && \
   [ -f "/etc/letsencrypt/live/$APP_DOMAIN/privkey.pem" ]; then
    envsubst '${APP_DOMAIN}' < /etc/transport/tls.conf.template >> /etc/nginx/conf.d/default.conf
else
    echo 'No certificate yet: only ACME HTTP challenge and HTTPS redirect are enabled.' >&2
fi
