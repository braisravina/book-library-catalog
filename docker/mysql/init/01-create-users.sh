#!/bin/bash
# Runs once, when the MySQL data volume is first initialised.
# Creates two least-privilege accounts instead of using MYSQL_USER (which
# would receive ALL PRIVILEGES on the database):
#   - application account: data manipulation only (no DDL)
#   - migration account:   schema changes, used only by `bin/console migrate`
# An optional test database gets the same split for the integration suite.
set -euo pipefail

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

DATABASES=("$MYSQL_DATABASE")
if [[ -n "${DB_TEST_DATABASE:-}" ]]; then
    ident_ok "$DB_TEST_DATABASE"
    DATABASES+=("$DB_TEST_DATABASE")
fi

{
    echo "CREATE USER IF NOT EXISTS '${DB_USERNAME}'@'%' IDENTIFIED BY '${APP_PW}';"
    echo "CREATE USER IF NOT EXISTS '${DB_MIGRATION_USERNAME}'@'%' IDENTIFIED BY '${MIG_PW}';"
    for db in "${DATABASES[@]}"; do
        echo "CREATE DATABASE IF NOT EXISTS \`${db}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;"
        echo "GRANT SELECT, INSERT, UPDATE, DELETE ON \`${db}\`.* TO '${DB_USERNAME}'@'%';"
        echo "GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES ON \`${db}\`.* TO '${DB_MIGRATION_USERNAME}'@'%';"
    done
    echo "FLUSH PRIVILEGES;"
} | MYSQL_PWD="${MYSQL_ROOT_PASSWORD}" mysql --protocol=socket -uroot --database=mysql

echo "Created least-privilege accounts for: ${DATABASES[*]}"
