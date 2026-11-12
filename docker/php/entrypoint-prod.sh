#!/bin/sh
# Digital Library Archive - production PHP entrypoint.
#
# Before PHP-FPM starts, validate the configuration with the same rules the
# application enforces per request (bin/console config:check; it never
# prints secret values). Any error stops the container instead of serving
# HTTP 503s. One-off commands (docker compose run ... ops php bin/console
# migrate) run unchanged.
set -eu

if [ "${1:-}" = php-fpm ]; then
    if ! php /var/www/html/bin/console config:check; then
        echo "entrypoint: refusing to start PHP-FPM: invalid configuration (see the report above)." >&2
        exit 1
    fi
fi

exec docker-php-entrypoint "$@"
