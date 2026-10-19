#!/bin/sh
set -eu
umask 027
# Only local filesystem initialization; never migrations, key generation or DB probes.
mkdir -p storage/app/private/workshop storage/app/fleet-documents-private \
    storage/framework/cache/data storage/framework/cache/locks \
    storage/framework/sessions storage/framework/views storage/logs
if [ "${1:-}" = "scheduler" ]; then
    # Shared volume + flock: the lock survives container replacement and releases on exit.
    exec flock --exclusive --nonblock --no-fork --conflict-exit-code 0 \
        storage/framework/scheduler.lock php artisan schedule:work --no-interaction
fi
exec "$@"
