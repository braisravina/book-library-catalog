#!/bin/sh
# Digital Library Archive - database start guard (Phase 5C; MariaDB since
# the CPU-compatibility change).
#
# The db service's entrypoint (docker-compose.prod.yml). It runs before the
# MariaDB image's own docker-entrypoint.sh, whichever way the container is
# started (digital-library.service, "docker compose up", "docker start"),
# and refuses to let the image initialise a NEW, EMPTY database unless the
# owner has authorised exactly that for a first deployment:
#
#   - /var/lib/mysql holds a complete database of this
#     project: the system schema (mysql/) and the
#     marker .dla-init-complete                          -> start normally;
#   - /var/lib/mysql is empty and the owner created
#     <data>/mysql-init-guard/allow-empty-init            -> initialise;
#   - anything else                                      -> refuse, exit 1:
#       * empty without authorisation;
#       * a MySQL 8 data directory (mysql.ibd): MariaDB cannot use it;
#       * mysql/ without the marker: an initialisation that did not finish
#         (docker/mysql/init/01-create-users.sh writes the marker last, after
#         every account and grant exists), which would otherwise start a
#         database without its least-privilege accounts;
#       * any other files.
#
# The authorisation directory is mounted read-only at /run/dla-db-init; the
# owner removes allow-empty-init as soon as the first start has finished
# (docs/DEPLOYMENT.md, section 6), and the storage preflight refuses an
# existing installation while it is present.
set -eu

datadir=${DLA_DB_DATADIR:-/var/lib/mysql}
authorisation=${DLA_DB_INIT_DIR:-/run/dla-db-init}/allow-empty-init
marker=.dla-init-complete

refuse() {
    echo "dla-db-guard: refusing to start the database: $1" >&2
    exit 1
}

[ -d "$datadir" ] || refuse "$datadir is not a directory (is the data directory mounted?)"

if [ -e "$datadir/mysql.ibd" ]; then
    refuse "$datadir holds a MySQL 8 data directory (mysql.ibd), which MariaDB cannot open; it was never this installation's database (docs/DEPLOYMENT.md, section 3.5)"
fi

if [ -d "$datadir/mysql" ]; then
    [ -f "$datadir/$marker" ] || refuse "$datadir has a database whose initialisation did not finish ($marker missing): its accounts may be incomplete; inspect it and start again from an empty directory (docs/DEPLOYMENT.md, section 3.5)"
    exec docker-entrypoint.sh "$@"
fi

if [ -n "$(find "$datadir" -mindepth 1 -maxdepth 1 -print 2>/dev/null | head -n 1)" ]; then
    refuse "$datadir has files but no database (mysql/); not initialising over them (docs/DEPLOYMENT.md, section 3.5)"
fi

if [ -f "$authorisation" ]; then
    echo "dla-db-guard: $datadir is empty and a first deployment is authorised: initialising a NEW database." >&2
    echo "dla-db-guard: remove allow-empty-init on the host once the first start has finished." >&2
    exec docker-entrypoint.sh "$@"
fi

refuse "no database in $datadir and no first-deployment authorisation; refusing to initialise a new, empty database (docs/DEPLOYMENT.md, section 6)"
