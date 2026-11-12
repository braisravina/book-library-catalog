#!/usr/bin/env bash
# Digital Library Archive - storage preflight (Phase 5C, split layout).
#
# READ-ONLY. Run as root (the data directories are not readable by other
# users). The privileged copy is INSTALLED, root-owned, outside the working
# tree (docs/DEPLOYMENT.md, 3.2):
#
#   sudo install -o root -g root -m 0755 docker/storage/preflight.sh /usr/local/sbin/digital-library-preflight
#
# digital-library.service runs that copy before it starts the stack (every
# boot), and the owner runs it before every manual deployment, "up", update
# or restore. A change to this file in the checkout takes effect only when
# the owner reviews it and installs it again. Exactly one mode is required:
#
#   sudo /usr/local/sbin/digital-library-preflight --env-file /opt/digital-library/.env.production --first-deployment
#   sudo /usr/local/sbin/digital-library-preflight --env-file /opt/digital-library/.env.production --existing-installation
#
#   --first-deployment       a NEW, EMPTY database is expected: the MySQL
#                            directory must be empty and the owner must have
#                            authorised initialisation (allow-empty-init).
#   --existing-installation  an existing database is expected: mysql.ibd and
#                            the application schema must be present, and no
#                            initialisation authorisation may be left behind.
#
# Checks:
#   Storage HDD: mounted at exactly /mnt/bravina-shared with the exact
#     filesystem UUID, ext4, read-write; the mountpoint keeps root:root 0755;
#     DLA_STORAGE_ROOT is exactly the confirmed directory; covers, backups
#     and storage exist on that filesystem with their owners and modes.
#   Installed copy: /usr/local/sbin/digital-library-preflight exists, is
#     root-owned and not group- or world-writable (notes when the
#     checkout's copy differs from it).
#   Working tree /opt/digital-library (code and configuration only): the
#     env file is the one in it; owned by the deploying administrator
#     (never a container user), 0755; no .git (Git objects stay in the bare
#     repository); no mysql/, secrets/ or mysql-init-guard/ in it; the
#     files root or the containers use are not group- or world-writable
#     and not symlinks.
#   Data root /opt/digital-library-data (system SSD, outside the working
#     tree and every build context): DLA_DATA_ROOT is exactly that; the
#     MySQL, secrets and guard directories and the secret files have their
#     owners and modes; the MySQL directory is not on the HDD and not under
#     /mnt/dockerdata.
#   The database state of the chosen mode; free space on the HDD.
#
# It only reads (findmnt, stat, find, df, grep, cmp); it never creates,
# changes or deletes anything, never reads secret values, and never talks
# to Docker or systemd. Exit status: 0 all checks passed; 1 a check failed
# (do not start the stack); 2 usage error.
set -euo pipefail

readonly MOUNTPOINT=/mnt/bravina-shared
readonly FS_UUID=ad62670f-861d-47a4-a68d-967272d27866
readonly FS_TYPE=ext4
readonly ROOT=$MOUNTPOINT/digital-library
readonly APP_DIR=/opt/digital-library
readonly DATA_ROOT=/opt/digital-library-data
readonly MYSQL_DIR=$DATA_ROOT/mysql
readonly INIT_AUTHORISATION=$DATA_ROOT/mysql-init-guard/allow-empty-init
readonly INSTALLED=/usr/local/sbin/digital-library-preflight

# path|type|uid|gid|mode (stat: %F|%u|%g|%a).
# Storage HDD. The two stubs inside storage/ are the mountpoints of the
# covers and backups mounts; the backups stub is mode 0000 so the web
# container (which does not mount backups) cannot use it. books/ is
# reserved and deliberately not checked or created.
readonly HDD_ENTRIES=(
    "$ROOT|directory|0|0|700"
    "$ROOT/storage|directory|82|82|750"
    "$ROOT/storage/covers|directory|0|0|755"
    "$ROOT/storage/backups|directory|0|0|0"
    "$ROOT/covers|directory|82|82|750"
    "$ROOT/backups|directory|82|82|700"
)
# System SSD, the data root: outside the working tree, never in Git or a
# build context. root:root 0755, so nothing in it is writable by the
# deploying user.
readonly DATA_ENTRIES=(
    "$DATA_ROOT|directory|0|0|755"
    "$MYSQL_DIR|directory|999|999|700"
    "$DATA_ROOT/secrets|directory|0|0|700"
    "$DATA_ROOT/secrets/db_root_password|regular file|999|999|400"
    "$DATA_ROOT/secrets/db_password|regular file|82|999|440"
    "$DATA_ROOT/secrets/db_migration_password|regular file|82|999|440"
    "$DATA_ROOT/mysql-init-guard|directory|0|0|755"
)
# Paths in the working tree that root (docker compose, run by
# digital-library.service) or the containers use as configuration or run:
# owned by the tree's owner (or root), not symlinks, never group- or
# world-writable.
readonly TREE_USED=(
    '.env.production'
    'docker-compose.prod.yml'
    'docker'
    'docker/mysql'
    'docker/mysql/db-guard.sh'
    'docker/mysql/init'
    'docker/mysql/my.cnf'
)
# Production data never lives in the working tree.
readonly TREE_FORBIDDEN=('.git' 'mysql' 'secrets' 'mysql-init-guard' 'mysql-data' 'data')

usage() {
    echo "usage: $0 --env-file <path to .env.production> (--first-deployment | --existing-installation) [--min-free-gb N]" >&2
    exit 2
}

env_file=''
mode=''
min_free_gb=20
while [ "$#" -gt 0 ]; do
    case "$1" in
        --env-file) [ "$#" -ge 2 ] || usage; env_file=$2; shift 2 ;;
        --first-deployment) [ -z "$mode" ] || usage; mode=first; shift ;;
        --existing-installation) [ -z "$mode" ] || usage; mode=existing; shift ;;
        --min-free-gb) [ "$#" -ge 2 ] || usage; min_free_gb=$2; shift 2 ;;
        *) usage ;;
    esac
done
[ -n "$env_file" ] && [ -n "$mode" ] || usage
[[ "$min_free_gb" =~ ^[0-9]+$ ]] || usage

failures=0
ok() { printf 'ok    %s\n' "$1"; }
fail() { printf 'FAIL  %s\n' "$1"; failures=$((failures + 1)); }
note() { printf 'note  %s\n' "$1"; }

if [ "${EUID:-$(id -u)}" -ne 0 ]; then
    note "not running as root: the data directories cannot be inspected (use sudo)"
fi
if [ "$mode" = first ]; then
    note "mode: FIRST DEPLOYMENT (a new, empty database is expected)"
else
    note "mode: existing installation (an existing database is expected)"
fi

# The value of one unquoted KEY=value line of the env file (exactly one).
env_value() {
    local lines count
    lines=$(grep -E "^[[:space:]]*(export[[:space:]]+)?$1[[:space:]]*=" -- "$env_file" || true)
    count=$(printf '%s' "$lines" | grep -c '' || true)
    if [ "$count" -ne 1 ]; then
        echo "$env_file must set $1 exactly once (found $count)"
        return 1
    fi
    if [[ "$lines" != "$1="* ]]; then
        echo "$env_file has '$lines'; expected an unquoted $1=value line"
        return 1
    fi
    printf '%s\n' "${lines#"$1="}"
}

# type|uid|gid|mode|device:inode of a path (empty if it does not exist).
info() { stat -c '%F|%u|%g|%a|%d:%i' -- "$1" 2>/dev/null || true; }

# Checks path|type|uid|gid|mode; with an expected filesystem target, also
# that the path lies on that filesystem.
check_entry() {
    local path type uid gid perm actual on
    IFS='|' read -r path type uid gid perm <<<"$1"
    actual=$(stat -c '%F|%u|%g|%a' -- "$path" 2>/dev/null || true)
    if [ -z "$actual" ]; then
        fail "$path is missing (initialise it: docs/DEPLOYMENT.md, section 3.2)"
        return
    fi
    if [ "$actual" != "$type|$uid|$gid|$perm" ]; then
        fail "$path is '$actual' (type|uid|gid|mode), expected $type|$uid|$gid|$perm"
        return
    fi
    if [ -n "${2:-}" ]; then
        on=$(findmnt --noheadings --raw --output TARGET --target "$path" 2>/dev/null || true)
        if [ "$on" != "$2" ]; then
            fail "$path is on the filesystem mounted at '$on', expected $2"
            return
        fi
    fi
    ok "$path $uid:$gid $perm"
}

# 1. Configuration: both roots exactly as confirmed.
db=''
if [ ! -r "$env_file" ]; then
    fail "cannot read $env_file"
else
    for pair in "DLA_STORAGE_ROOT|$ROOT" "DLA_DATA_ROOT|$DATA_ROOT"; do
        key=${pair%%|*}
        expected=${pair#*|}
        if ! value=$(env_value "$key"); then
            fail "$value"
        elif [ "$value" = "$expected" ]; then
            ok "$key=$expected"
        else
            fail "$key is '$value', expected exactly $expected"
        fi
    done
    if ! db=$(env_value DB_DATABASE); then
        fail "$db"
        db=''
    elif [[ ! "$db" =~ ^[A-Za-z0-9_]+$ ]]; then
        fail "DB_DATABASE '$db' is not a plain schema name"
        db=''
    fi
fi

# 2. Storage HDD: exact mountpoint, UUID, type, read-write.
mounted=$(findmnt --mountpoint "$MOUNTPOINT" --noheadings --raw --output UUID,FSTYPE,OPTIONS 2>/dev/null || true)
if [ -z "$mounted" ]; then
    fail "nothing is mounted at $MOUNTPOINT (do not start the stack)"
elif [ "$(printf '%s\n' "$mounted" | grep -c '')" -ne 1 ]; then
    fail "more than one filesystem is mounted at $MOUNTPOINT"
else
    read -r uuid fstype options <<<"$mounted"
    if [ "$uuid" = "$FS_UUID" ]; then ok "UUID $FS_UUID mounted at $MOUNTPOINT"; else fail "UUID at $MOUNTPOINT is '$uuid', expected $FS_UUID"; fi
    if [ "$fstype" = "$FS_TYPE" ]; then ok "filesystem type $FS_TYPE"; else fail "filesystem type is '$fstype', expected $FS_TYPE"; fi
    case ",$options," in
        *,rw,*) ok "mounted read-write" ;;
        *) fail "not mounted read-write (options: $options)" ;;
    esac
fi

# 3. The HDD mountpoint keeps root:root 0755; the HDD directories.
mp_stat=$(stat -c '%F|%u|%g|%a' -- "$MOUNTPOINT" 2>/dev/null || true)
if [ "$mp_stat" = "directory|0|0|755" ]; then
    ok "$MOUNTPOINT is root:root 0755"
else
    fail "$MOUNTPOINT is '$mp_stat' (type|uid|gid|mode), expected directory|0|0|755"
fi
for entry in "${HDD_ENTRIES[@]}"; do
    check_entry "$entry" "$MOUNTPOINT"
done

# 4. The data root on the system SSD: directories, secret files (owner and
#    mode only).
for entry in "${DATA_ENTRIES[@]}"; do
    check_entry "$entry"
done

# 5. The installed, root-owned copy (the one digital-library.service runs)
#    and the working tree (code and configuration only).
IFS='|' read -r i_type i_uid _ i_mode _ <<<"$(info "$INSTALLED")"
if [ "$i_type" != 'regular file' ]; then
    fail "$INSTALLED is '${i_type:-missing}': install the reviewed preflight (docs/DEPLOYMENT.md, section 3.2)"
elif [ "$i_uid" != 0 ] || ! [[ "$i_mode" =~ ^[0-7]+$ ]] || (( 8#$i_mode & 8#022 )); then
    fail "$INSTALLED is owned by uid $i_uid with mode $i_mode: it must be root-owned and not group- or world-writable"
else
    ok "$INSTALLED is root-owned, mode $i_mode"
fi
if cmp -s -- "${BASH_SOURCE[0]}" "$APP_DIR/docker/storage/preflight.sh" 2>/dev/null; then
    ok "the checkout's preflight is identical to the one running"
else
    note "the checkout's preflight differs from the one running: review the change, then install it again (section 3.2)"
fi
tree_owner=''
IFS='|' read -r t_type t_uid _ t_mode _ <<<"$(info "$APP_DIR")"
if [ "$t_type" != directory ]; then
    fail "$APP_DIR (the working tree) is missing"
elif [ "$t_mode" != 755 ]; then
    fail "$APP_DIR (the working tree) has mode $t_mode, expected 755"
elif [ "$t_uid" = 82 ] || [ "$t_uid" = 999 ]; then
    fail "$APP_DIR (the working tree) is owned by container user $t_uid; it belongs to the deploying administrator"
else
    ok "$APP_DIR is the working tree (owner uid $t_uid, 755)"
    tree_owner=$t_uid
fi
IFS='|' read -r _ _ _ _ e_id <<<"$(info "$env_file")"
IFS='|' read -r _ _ _ _ p_id <<<"$(info "$APP_DIR/.env.production")"
if [ -n "$p_id" ] && [ "$e_id" = "$p_id" ]; then
    ok "the env file is $APP_DIR/.env.production"
else
    fail "$env_file is not $APP_DIR/.env.production (the stack runs from $APP_DIR)"
fi
for rel in "${TREE_FORBIDDEN[@]}"; do
    if [ -z "$(info "$APP_DIR/$rel")" ]; then
        ok "no $rel in $APP_DIR"
    else
        fail "$APP_DIR/$rel exists: Git objects stay in the bare repository and production data in $DATA_ROOT, never in the working tree"
    fi
done
for rel in "${TREE_USED[@]}"; do
    IFS='|' read -r f_type f_uid _ f_mode _ <<<"$(info "$APP_DIR/$rel")"
    if [ "$f_type" != directory ] && [ "$f_type" != 'regular file' ]; then
        fail "$APP_DIR/$rel is '${f_type:-missing}', expected a regular file or directory (no symlinks)"
    elif [ "$f_uid" != 0 ] && [ "$f_uid" != "$tree_owner" ]; then
        fail "$APP_DIR/$rel is owned by uid $f_uid, expected the working tree's owner or root"
    elif ! [[ "$f_mode" =~ ^[0-7]+$ ]] || (( 8#$f_mode & 8#022 )); then
        fail "$APP_DIR/$rel has mode $f_mode: group- or world-writable (docker compose uses it as root)"
    else
        ok "$APP_DIR/$rel uid $f_uid, mode $f_mode"
    fi
done

# 6. The MySQL directory is on the system SSD: not on the HDD, not under
#    /mnt/dockerdata (it is outside the working tree by construction).
on=$(findmnt --noheadings --raw --output TARGET --target "$MYSQL_DIR" 2>/dev/null || true)
case "$on" in
    '') fail "cannot determine the filesystem of $MYSQL_DIR" ;;
    "$MOUNTPOINT" | "$MOUNTPOINT"/* | /mnt/dockerdata | /mnt/dockerdata/*) fail "$MYSQL_DIR is on the filesystem mounted at $on; it must be on the system SSD" ;;
    *) ok "$MYSQL_DIR is on the system filesystem mounted at $on" ;;
esac

# 7. The database state of the chosen mode.
authorised=$(stat -c '%F|%u' -- "$INIT_AUTHORISATION" 2>/dev/null || true)
if [ "$mode" = first ]; then
    first_entry=$(find "$MYSQL_DIR" -mindepth 1 -maxdepth 1 -print -quit 2>/dev/null || echo '(unreadable)')
    if [ -z "$first_entry" ]; then
        ok "$MYSQL_DIR is empty: a new database will be initialised"
    else
        fail "$MYSQL_DIR is not empty ($first_entry): this is not a first deployment; use --existing-installation"
    fi
    case "$authorised" in
        'regular file|0' | 'regular empty file|0') ok "initialisation authorised ($INIT_AUTHORISATION, owned by root)" ;;
        *) fail "initialisation not authorised: create $INIT_AUTHORISATION as root (docs/DEPLOYMENT.md, section 6)" ;;
    esac
else
    if [ "$(stat -c '%F' -- "$MYSQL_DIR/mysql.ibd" 2>/dev/null || true)" = 'regular file' ]; then
        ok "existing MySQL database (mysql.ibd present)"
    else
        fail "no MySQL database in $MYSQL_DIR (mysql.ibd): wrong or empty directory? Never start db here (section 3.5)"
    fi
    if [ -n "$db" ]; then
        if [ "$(stat -c '%F' -- "$MYSQL_DIR/$db" 2>/dev/null || true)" = directory ]; then
            ok "application schema '$db' present"
        else
            fail "application schema '$db' missing in $MYSQL_DIR: not this installation's database"
        fi
    fi
    if [ -n "$authorised" ]; then
        fail "$INIT_AUTHORISATION is still present: remove it (an existing installation never re-initialises)"
    else
        ok "no initialisation authorisation left behind"
    fi
fi

# 8. Free space on the HDD.
avail=''
while read -r value; do avail=$value; done < <(df --output=avail -B1G -- "$MOUNTPOINT" 2>/dev/null || true)
avail=${avail%G}
if [[ "$avail" =~ ^[0-9]+$ ]] && [ "$avail" -ge "$min_free_gb" ]; then
    ok "${avail} GB free on $MOUNTPOINT (minimum $min_free_gb)"
else
    fail "free space on $MOUNTPOINT is '${avail}' GB, minimum $min_free_gb"
fi

if [ "$failures" -ne 0 ]; then
    echo "preflight: $failures check(s) FAILED - do not start or update the stack." >&2
    exit 1
fi
echo "preflight: all storage checks passed."
