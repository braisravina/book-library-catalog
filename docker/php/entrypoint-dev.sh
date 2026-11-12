#!/bin/sh
# Development image only. The covers directory is a named Docker volume that
# starts out root-owned; hand it to the PHP-FPM user, then start normally.
# (The production image runs as www-data and ships storage/ pre-owned.)
set -eu
covers="${COVER_STORAGE_PATH:-/var/www/html/storage/covers}"
if [ -d "$covers" ]; then
    chown www-data:www-data "$covers"
    chmod 0750 "$covers"
fi
exec docker-php-entrypoint "$@"
