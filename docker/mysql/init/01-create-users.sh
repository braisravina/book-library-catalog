#!/bin/bash
# Runs once, when the MySQL data volume is first initialised.
# Creates two least-privilege accounts instead of using MYSQL_USER (which
# would receive ALL PRIVILEGES on the database):
#   - application account: data manipulation only (no DDL)
#   - migration account:   schema changes, used only by `bin/console migrate`
# An optional test database gets the same split for the integration suite.
# With DB_TEST_USERNAME/DB_TEST_MIGRATION_USERNAME set, the test database is
# served by those dedicated accounts only, and the application accounts get
# no privileges on it (and vice versa).
#
# Passwords may be given directly (development: DB_PASSWORD, ...) or as
# files (production Docker secrets: DB_PASSWORD_FILE, ...), never both.
# MYSQL_ROOT_PASSWORD(_FILE) is resolved by the MySQL image's own entrypoint.
set -euo pipefail

# dla_file_env VAR: set VAR from VAR_FILE when given (trailing CR/LF removed, as
# the application's Config does). Refuses both, and unreadable files.
dla_file_env() {
    local var="$1" file_var="${1}_FILE"
    local value="${!var:-}" file="${!file_var:-}"
    if [[ -n "$value" && -n "$file" ]]; then
        echo "Both $var and $file_var are set; use one." >&2
        exit 1
    fi
    if [[ -n "$file" ]]; then
        [[ -f "$file" && -r "$file" ]] || { echo "$file_var: file is not readable." >&2; exit 1; }
        value="$(<"$file")"
        while [[ "$value" == *$'\r' || "$value" == *$'\n' ]]; do
            value="${value%[$'\r\n']}"
        done
    fi
    printf -v "$var" '%s' "$value"
}

for secret in DB_PASSWORD DB_MIGRATION_PASSWORD DB_TEST_PASSWORD DB_TEST_MIGRATION_PASSWORD; do
    dla_file_env "$secret"
done

: "${MYSQL_DATABASE:?MYSQL_DATABASE must be set}"
: "${DB_USERNAME:?DB_USERNAME must be set}"
: "${DB_PASSWORD:?DB_PASSWORD must be set}"
: "${DB_MIGRATION_USERNAME:?DB_MIGRATION_USERNAME must be set}"
: "${DB_MIGRATION_PASSWORD:?DB_MIGRATION_PASSWORD must be set}"

sql_escape() { printf '%s' "$1" | sed "s/\\\\/\\\\\\\\/g; s/'/''/g"; }
ident_ok() { [[ "$1" =~ ^[A-Za-z0-9_]+$ ]] || { echo "Invalid identifier: $1" >&2; exit 1; }; }

ident_ok "$MYSQL_DATABASE"; ident_ok "$DB_USERNAME"; ident_ok "$DB_MIGRATION_USERNAME"
APP_PW="$(sql_escape "$DB_PASSWORD")"
MIG_PW="$(sql_escape "$DB_MIGRATION_PASSWORD")"

DEDICATED=0
if [[ -n "${DB_TEST_DATABASE:-}" ]]; then
    ident_ok "$DB_TEST_DATABASE"
    [[ "$DB_TEST_DATABASE" != "$MYSQL_DATABASE" ]] || { echo "DB_TEST_DATABASE must differ from MYSQL_DATABASE" >&2; exit 1; }
    if [[ -n "${DB_TEST_USERNAME:-}" || -n "${DB_TEST_MIGRATION_USERNAME:-}" ]]; then
        : "${DB_TEST_USERNAME:?DB_TEST_USERNAME must be set with DB_TEST_MIGRATION_USERNAME}"
        : "${DB_TEST_PASSWORD:?DB_TEST_PASSWORD must be set}"
        : "${DB_TEST_MIGRATION_USERNAME:?DB_TEST_MIGRATION_USERNAME must be set with DB_TEST_USERNAME}"
        : "${DB_TEST_MIGRATION_PASSWORD:?DB_TEST_MIGRATION_PASSWORD must be set}"
        ident_ok "$DB_TEST_USERNAME"; ident_ok "$DB_TEST_MIGRATION_USERNAME"
        DEDICATED=1
        TEST_APP_PW="$(sql_escape "$DB_TEST_PASSWORD")"
        TEST_MIG_PW="$(sql_escape "$DB_TEST_MIGRATION_PASSWORD")"
    fi
fi

# grant_pair <database> <app user> <migration user>
grant_pair() {
    echo "CREATE DATABASE IF NOT EXISTS \`$1\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
    echo "GRANT SELECT, INSERT, UPDATE, DELETE ON \`$1\`.* TO '$2'@'%';"
    echo "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON \`$1\`.* TO '$3'@'%';"
}

{
    echo "CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'%' IDENTIFIED BY '${APP_PW}';"
    echo "CREATE USER IF NOT EXISTS '${DB_MIGRATION_USERNAME}'@'%' IDENTIFIED BY '${MIG_PW}';"
    grant_pair "$MYSQL_DATABASE" "$DB_USERNAME" "$DB_MIGRATION_USERNAME"
    if [[ $DEDICATED == 1 ]]; then
        echo "CREATE USER IF NOT EXISTS '${DB_TEST_USERNAME}'@'%' IDENTIFIED BY '${TEST_APP_PW}';"
        echo "CREATE USER IF NOT EXISTS '${DB_TEST_MIGRATION_USERNAME}'@'%' IDENTIFIED BY '${TEST_MIG_PW}';"
        grant_pair "$DB_TEST_DATABASE" "$DB_TEST_USERNAME" "$DB_TEST_MIGRATION_USERNAME"
    elif [[ -n "${DB_TEST_DATABASE:-}" ]]; then
        grant_pair "$DB_TEST_DATABASE" "$DB_USERNAME" "$DB_MIGRATION_USERNAME"
    fi
    echo "FLUSH PRIVILEGES;"
} | MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql --protocol=socket -uroot --database=mysql

echo "Created least-privilege accounts for: ${MYSQL_DATABASE}${DB_TEST_DATABASE:+ ${DB_TEST_DATABASE}}$([[ $DEDICATED == 1 ]] && echo ' (dedicated test accounts)')"
