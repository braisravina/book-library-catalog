# Operations: backups, restore, maintenance and disaster recovery

This guide covers backups, restores, maintenance mode and retention.
Deployment, updates, monitoring and emergency shutdown are in
[DEPLOYMENT.md](DEPLOYMENT.md). Everything here is a CLI command run inside
the PHP container. None of it is reachable from the web
application. Commands assume the development stack (`docker compose exec php …`).
In production, run them in the one-off `ops` container, which alone holds the
migration account and the backups directory:

```bash
docker compose -f docker-compose.prod.yml --env-file .env.production run --rm ops php bin/console backup:create
```

The `php` (web) container has the runtime account only (`APP_ROLE=web`), so
`migrate` and `backup:restore` fail there by design. Run `backup:create` in
`ops` as well: only `ops` mounts the backups directory. The web container
sees only an unusable stub there.

> **Status.** The commands are implemented and tested against MariaDB 11.4
> (the production engine) and MySQL 8.4 (CI regression job) with PHP 8.4 in
> development and CI environments. Nothing has been scheduled or run
> against production. Schedules, off-site copies and the production backup
> location are future production configuration (see "Before production"
> below).

## What a backup contains

A backup is one file, `dla-backup-YYYYMMDDTHHMMSSZ.tar.gz`, with a
`.sha256` file beside it. Inside (gzip-compressed POSIX tar, regular files
only):

| Member | Contents |
|---|---|
| `database.sql` | Logical dump of every table: definitions and rows. It is written by PHP, so no dump tool is needed. It is also valid input for the `mariadb`/`mysql` client. Its table definitions are engine-specific: a backup is restored only into the same engine (MariaDB into MariaDB), see "Database engine". |
| `covers/ab/<key>.jpg`, `….thumb.jpg` | Every cover image and thumbnail in the covers directory |
| `manifest.json` | Format `dla-backup`, format version, creation time (UTC), application schema version (latest migration) and migration list, row counts per table, cover statistics, and the size and SHA-256 of every other member |

**Not included, by design:**
- `.env`, Docker secrets and any credentials;
- PHP session files;
- the rows of `login_attempts` (transient rate-limiting data; the table definition is included);
- application code and dependencies.

Keep the secrets separately (see "Secrets to preserve").

**Consistency strategy.** The whole database is read inside one
`REPEATABLE READ` transaction started `WITH CONSISTENT SNAPSHOT`. That gives a
point-in-time image without blocking users. The same snapshot lists the cover
files the data refers to. Cover files are immutable (random names, never
modified in place), so they are copied afterwards. Every referenced cover must
be present, or the backup fails. Covers uploaded during the backup may appear
as harmless extra files. For a fully quiescent backup, enable maintenance mode
first (below).

**Safety.**
- Archives are written with umask 077 (files `0600`, directory `0700`), and only outside `public/`.
- Work files are hidden temporaries in the backup directory. The archive is renamed into place only when complete, and temporaries are always removed.
- Free space is checked first.
- Every new archive is verified before the command reports success.

## Commands

| Task | Command |
|---|---|
| Create a backup | `php bin/console backup:create [--output-dir=DIR] [--allow-missing-covers]` |
| Verify a backup | `php bin/console backup:verify --file=PATH` |
| Restore a backup | `php bin/console backup:restore --file=PATH --confirm=DATABASE [--database=DATABASE] [--replace] [--covers-dir=DIR] [--no-safety-backup]` |
| Maintenance mode | `php bin/console maintenance:on [--reason=…]`, `maintenance:off [--acknowledge-incomplete-restore=DATABASE]`, `maintenance:status` |
| Housekeeping | `php bin/console cleanup [--apply] [--tasks=…] [--delete-orphan-covers]` |

Exit codes:
- `0`: success.
- `1`: refused, or failed without changing anything.
- `2`: failed after changes were made (a partial restore; see below).

**Who may run them.** `migrate`, `user:create`, `backup:create`,
`backup:restore`, `maintenance:on`, `maintenance:off` and `cleanup` are
operations commands. In production (including an unset or misspelled
`APP_ENV`) they run only with `APP_ROLE=ops`, i.e. in the `ops` container; a
container with `APP_ROLE=web` refuses them in every environment, and an
unknown role is refused. Development and testing without `APP_ROLE` work as
before. `backup:verify`, `maintenance:status`, `migrate:status` and
`config:check` are read-only and allowed everywhere.

**One at a time.** `migrate`, `backup:create`, `backup:restore`, `cleanup`
and `maintenance:off` take an exclusive lock (`TEMP_PATH/operations.lock`,
shared by every container that mounts the storage directory). A second one
refuses to start and names the running operation. The lock is held by the
running process and released by the kernel when it exits for any reason
(including `kill -9` or a container stop), so there is never a stale lock to
remove by hand. `maintenance:off` waits its turn, so it cannot reopen the
site in the middle of a restore.

### Verify

`backup:verify` reads the archive without extracting it. It rejects:
- an archive that isn't gzip, or is truncated;
- bad tar header checksums;
- any member that is not a regular file: symbolic or hard links, directories, devices, pax or GNU extended headers;
- unsafe names: absolute paths, `..`, backslashes, unusual characters;
- duplicate or unexpected members;
- a missing or unknown manifest, or a manifest format newer than this application supports;
- any size or SHA-256 mismatch;
- a dump in an unknown format;
- a `.sha256` file that doesn't match the archive.

Run it on every copy you keep, for example after copying an archive off-site.

### Restore

A restore replaces data, so it is guarded. In order:

1. **Role, lock, confirmation:** ops role in production (above); no other
   operation running; `--confirm` must repeat the exact target database name;
   `--allow-production` is required when `APP_ENV` is production (or unset).
2. **Maintenance mode must be on** (`maintenance:on`). Use
   `--skip-maintenance-check` only for disposable environments; it is refused
   in production. In production the restore also waits until maintenance
   mode has been on for 35 seconds. **This is a best-effort grace period,
   not a guarantee** that requests which started before maintenance mode
   have finished:
   - PHP's `max_execution_time` (30 s) counts script execution only; on
     Linux, time spent waiting for the database, the network, files or
     `sleep` is not counted, so a request can run much longer in real time;
   - PHP-FPM has no wall-clock limit configured (`request_terminate_timeout`
     is unset), and long responses such as exports or slow clients can
     outlast the wait;
   - nothing inspects PHP-FPM to confirm that no request is still running.

   For a guarantee, stop the web PHP service before a production restore
   (`docker compose -f docker-compose.prod.yml stop php`): the `ops` container
   does not need it. nginx then answers 502 instead of the maintenance page
   until `php` is started again after the checks.
3. **Verification:** the archive is fully verified (as `backup:verify`).
4. **Space pre-flight:** the temporary directory must hold the whole
   extracted backup and the covers directory the cover files to be written,
   each with 16 MiB to spare. The database's own free space cannot be measured from
   the application; check it on the host (`df` on the database data directory) before a
   large restore.
5. **Extraction** into a private temporary directory (`TEMP_PATH`, mode
   0700), re-hashing every member against the manifest, so the archive cannot
   be swapped after verification.
6. **Dump validation, nothing changed yet.** Every statement must be one of
   the forms the backup tool writes. `INSERT` statements may contain only
   literal values (`NULL`, integers, quoted strings, `X'…'`), one per column:
   no functions, subqueries, variables or comments. Anything else (`DROP`,
   `GRANT`, `SELECT … INTO OUTFILE`, other engines or data directories)
   rejects the archive.
7. **Target pre-flight:** the target database must be empty unless
   `--replace`; no existing cover file may differ from the backup unless
   `--replace`. Unrelated files in the covers directory are never touched.
8. **Safety backup (recovery checkpoint):** if the target database has
   tables, a normal backup of its current state (database and covers) is
   written to `BACKUP_PATH` first, and its path is printed. If that backup
   fails, the restore stops and nothing is changed. `--no-safety-backup`
   skips it; use that only for disposable targets or when you have just made
   a backup yourself.
9. **Restore marker:** when the target is the application's own database, a
   marker (`MAINTENANCE_FLAG_PATH.restore-incomplete`) is written. While it
   exists the site stays in maintenance mode, even if the flag is removed by
   hand, and `maintenance:off` refuses.
10. **Database:** with `--replace`, the existing tables are dropped; then the
    dump is applied with the **migration account** (DDL rights).
11. **Post-checks:** row counts per table must match the manifest; every
    foreign key must point at an existing row (checked after loading, as the
    dump is loaded with foreign-key checks off); then cover files are written
    to temporary names and renamed into place, and every referenced cover
    file must exist.
12. **Success:** the marker is removed and a `backup.restored` audit event is
    written. Maintenance mode stays on until you check the site and run
    `maintenance:off`.

**A restore is not atomic.** MariaDB (like MySQL) commits `DROP TABLE` and `CREATE TABLE`
immediately; they cannot be rolled back. If anything fails from step 10 on,
the command exits with code `2`, says the database is **PARTIALLY restored**
or the restore is **INCOMPLETE**, and prints the rollback command for the
safety backup:

```bash
php bin/console backup:restore --file=<safety backup> --confirm=<database> --replace --no-safety-backup
```

The site stays in maintenance mode (marker) until a restore succeeds. Only if
you have checked the database yourself and decided to serve it as it is, run
`maintenance:off --acknowledge-incomplete-restore=<database>` with the exact
database name. Nothing else clears the marker: not `maintenance:on`/`off`,
`cleanup`, a backup, a restore into another database, or any web request
(all pages but `/health` answer 503). The acknowledgement:
- is written to the audit log **first** (`maintenance.restore_marker_acknowledged`,
  with the file, database, start time and the outcome "restore not
  completed; database served as found, without verification"); if it cannot
  be recorded, for example because the partial database has no audit table,
  it is refused and the marker stays;
- says explicitly that the restore did **not** complete and that this is not
  a successful recovery; no `backup.restored` event is written.

**Restoring into a new, empty database** is the safest route; it needs a
database administrator to create it. For example:
1. As the database root (inside the db container: `mariadb -uroot -p`): `CREATE DATABASE library_restore …;` and grant the migration
   and application accounts the same privileges as on `library`
   (`docker/mysql/init/01-create-users.sh` shows them).
2. Run `backup:restore --file=… --database=library_restore --confirm=library_restore`.
   No safety backup is needed (empty target) and the live site is untouched.
3. Check it, then point `DB_DATABASE` at it (or restore the same archive into
   `library` once you are satisfied).

## Consistency and concurrency

**Online backups are safe.** `backup:create` does not need maintenance mode:
- The database is read in **one** `REPEATABLE READ` transaction started
  `WITH CONSISTENT SNAPSHOT` (read-only): rows written while the dump runs
  are not included, and no writer is blocked.
- The list of cover files the data refers to comes from that same snapshot.
  Cover files are immutable (random names, never rewritten), so they are
  copied afterwards. A cover **added** during the backup is either included
  as a harmless extra or not included at all. A **referenced** cover deleted
  during the backup makes the backup fail; nothing is left behind, so run it
  again.
- Only a complete archive that has passed full verification, with its
  `.sha256` written first, is renamed to its final `dla-backup-…` name. An
  interrupted, failed or disk-full run leaves only hidden `.tmp-dla-*` files,
  which the failed run removes, or `cleanup --tasks=temp-files` removes later.
  A file with the final name is therefore never a partial backup.
- Use maintenance mode for a backup only when you need a guaranteed quiet
  point, for example just before an upgrade.

**Interactions** (`OperationLock`; maintenance mode):

| While running… | backup | restore | migrate | cleanup | maintenance:off | uploads, sign-ins |
|---|---|---|---|---|---|---|
| backup | refused | refused | refused | refused | refused | continue (snapshot) |
| restore | refused | refused | refused | refused | refused | blocked (maintenance) |

Sessions are not part of a backup. In production they live in the PHP
container's `/tmp` (tmpfs): after a restore, restart the `php` container so
that everyone signs in again against the restored accounts.

**Resources.** Rows are read in batches of 1,000 and written as they are
read; archives are read and written in 1 MiB chunks; no step loads a whole
archive, dump or cover set into memory (the largest single item is one dump
statement, at most 1 MiB as written and 16 MiB as accepted). Disk:
- `backup:create` needs, in `BACKUP_PATH`, about the uncompressed dump plus
  the compressed archive; it checks for twice the database size plus the
  covers plus 64 MiB first and refuses otherwise.
- `backup:restore` needs, in `TEMP_PATH`, the uncompressed backup (dump and
  covers), plus the new cover files in the covers directory, plus room in
  the database for the restored tables (and, with a safety backup, a second backup in
  `BACKUP_PATH`).

## Backup strategy: frequency, retention, off-host copies

There is no committed recovery point objective (RPO) or recovery time
objective (RTO); choose them with the trade-offs below.

**Recovery point (how much data you can lose)** is the time since the last
good off-host copy:

| Option | Data at risk | Cost |
|---|---|---|
| Daily backup (default suggestion) | up to 24 h | one short job per day; little disk |
| Every 6 hours | up to 6 h | 4× the archives; same retention settings keep fewer days |
| Hourly | up to 1 h | frequent full archives; consider disk and CPU on a small server |
| Binary-log point-in-time recovery | minutes | Database configuration and tooling outside this application (not implemented) |

Every backup is a full backup (no incrementals): size grows with the library
and its covers.

**Recovery time (how long the site is down)** is roughly: copying the archive
back + verification (reads it twice) + restore (proportional to rows and cover
bytes) + checks. Measure it with a restore drill on the production host and
record the result; that number, not an estimate, is your RTO.

**Retention.** `cleanup --apply --tasks=backups` keeps the newest
`BACKUP_KEEP_COUNT` archives and every archive younger than
`BACKUP_KEEP_DAYS`, and never deletes the newest one that verifies. Safety
backups made by restores are ordinary archives and follow the same policy.

**Off-host copies are required.** A backup on the same disk or host as the
database does not survive a lost disk, host or volume. After each backup:
1. copy the archive **and** its `.sha256` file to another machine or storage
   (encrypted if it leaves your premises: the dump contains e-mail
   addresses, password hashes and the audit log);
2. run `backup:verify` on the copy (or `sha256sum -c` where PHP is not
   available);
3. keep off-host copies on their own retention schedule.

## Permissions

| Path | Owner (production) | Mode |
|---|---|---|
| `BACKUP_PATH` (`storage/backups`, ops-only directory) | `www-data` (82) | directory `0700`; archives and `.sha256` `0600` |
| `TEMP_PATH` (`storage/tmp`) | `www-data` | work directories `0700`; `operations.lock` `0600` |
| maintenance flag and restore marker | `www-data` | `0640` |
| restored cover files | `www-data` | `0640`; bucket directories `0750` |
| metadata provider backoff (`storage/cache/metadata-backoff-<provider>.json`) | `www-data` | `0600`; holds only a failure count and a timestamp. Safe to delete (resets the backoff); not part of backups |
| Open Library request pacing (`storage/cache/metadata-pace-open_library.json`) | `www-data` | `0600`; holds only the next request time. Safe to delete; not part of backups |

Backups never live under `public/` (refused by the code and by
`config:check`) and are never served: nginx serves `public/assets` only, and
the web container does not mount the backups directory. There is no download
route. Archives do not contain `.env`, Docker secrets, database credentials
or sessions.

## Operator responsibilities

The application cannot do these for you:
- schedule backups and cleanup, and watch for failures (non-zero exit);
- copy archives off-host, verify the copies, and protect them (encryption,
  access control) as personal data;
- keep the secrets (below) separately from the backups;
- check free space on the backup, temporary and database directories;
- run and record a restore drill (suggested: monthly, and after upgrades);
- decide and document your RPO and RTO.

## Manual recovery checklist

For a self-hosted production environment. Commands run in the `ops`
container:
`docker compose -f docker-compose.prod.yml --env-file .env.production run --rm ops php bin/console …`
(shortened to `ops …` below).

- [ ] **Decide:** what was lost (database, covers, the whole host), and
      which backup to restore (newest one that verifies and predates the
      problem).
- [ ] **Secrets and code:** the secret files and `.env.production` from your
      secure store; the repository at the backup's release or newer.
- [ ] **Copy** the archive and its `.sha256` into the backups directory.
- [ ] **Verify:** `ops backup:verify --file=…` prints `VALID`.
- [ ] **Space:** enough free space for the extraction (`TEMP_PATH`), the
      covers and the database (see "Resources").
- [ ] **No other operation running** (a running backup or cleanup makes the
      restore refuse; wait for it).
- [ ] **Maintenance on:** `ops maintenance:on --reason="restore"`.
- [ ] **Stop the web PHP service** for a guaranteed quiet database:
      `docker compose -f docker-compose.prod.yml stop php` (the 35-second wait
      inside the restore is only best effort).
- [ ] **Restore:** `ops backup:restore --file=… --confirm=library --allow-production`
      (plus `--replace` if the database has tables; note the safety backup
      path it prints).
- [ ] **If it fails with exit 2:** do not switch maintenance off; run the
      printed rollback command, or fix the cause and restore again.
- [ ] **Migrate** if the code is newer: `ops migrate`, then `ops migrate:status`
      shows nothing `pending` or `MODIFIED`.
- [ ] **Start** the `php` service again (`… up -d php`); this also clears
      the old sessions, so everyone signs in again.
- [ ] **Check:** `ops maintenance:status` shows no incomplete restore; sign in
      as an administrator; open a book with a cover, the loans list and the
      audit log (`backup.restored` event); compare a few counts with the
      manifest's `database.tables`.
- [ ] **Maintenance off:** `ops maintenance:off`.
- [ ] **New backup:** `ops backup:create`, then copy it off-host.
- [ ] **Record** what happened, how long it took (your measured RTO), and
      remove safety backups you no longer need.

## Disaster-recovery runbook

**Prerequisites:**
- a host with Docker Engine and the Compose plugin;
- this repository at the release that made the backup (or newer);
- the secrets listed below;
- the latest verified backup and its `.sha256` file, ideally an off-site copy.

This is the development-stack version; for production use the checklist
above (same steps, run in the `ops` container).

1. **Prepare.** Check out the code. Recreate `.env`, or the Docker secrets,
   from your secure store. Start the stack: `docker compose up -d --wait`.
   On an empty data directory, the init script creates the databases and accounts.
   In production, an empty data directory is initialised only with the
   first-deployment authorisation (DEPLOYMENT.md section 6); otherwise the
   database guard refuses to start db.
2. **Verify the copy you will use:**
   `docker compose exec php php bin/console backup:verify --file=storage/backups/dla-backup-….tar.gz`
   (copy the archive into the backups directory first). Continue only if it
   prints `VALID`.
3. **Enter maintenance mode:** `docker compose exec php php bin/console maintenance:on --reason="restore"`.
4. **Restore:**
   `docker compose exec php php bin/console backup:restore --file=… --confirm=library`
   (add `--replace` if the database already has tables, and
   `--allow-production` on a production host).
5. **Migrate,** if the code is newer than the backup:
   `docker compose exec php php bin/console migrate`, then
   `docker compose exec php php bin/console migrate:status`. Nothing should be
   `pending` or `MODIFIED`.
6. **Check:**
   - sign in as an administrator;
   - open a book with a cover, the loans list and the audit log;
   - spot-check counts against the manifest's `database.tables`.
7. **Leave maintenance mode:** `docker compose exec php php bin/console maintenance:off`.
8. **Make a fresh backup** and verify it: `backup:create` verifies automatically.

### Secrets to preserve (separately from backups)

Store these in a password manager or secrets vault, never with the archives:
- the `.env` values, or the files behind each `*_FILE` variable:
  - `DB_PASSWORD`, `DB_MIGRATION_PASSWORD`, `DB_ROOT_PASSWORD`;
  - `GOOGLE_BOOKS_API_KEY` (if used);
  - `APP_URL`, `SESSION_SECURE_COOKIE`, `SECURITY_HSTS` and the retention settings;
- the TLS certificates and keys of the reverse proxy (managed outside this project);
- the address or CIDR of the reverse proxy, if `set_real_ip_from` has been configured.

Sessions are deliberately not preserved in backups. Existing session files
are not cleared by a restore; restart the PHP container afterwards so that
users sign in again.

## Database engine

Production runs **MariaDB 11.4 LTS** (the production CPU cannot run the
MySQL 8.4 image: docs/DEPLOYMENT.md 5.1). MySQL 8.4 stays supported for
development and is tested in CI, so the two must never be mixed in one
database:

- **Migrations.** `migrate:status` prints the engine. A released migration
  that MariaDB cannot run has a MariaDB variant with the same name in
  `database/migrations/mariadb/`, used only on a server that identifies
  itself as MariaDB; the history records the checksum of the file that ran.
  `OTHER-ENGINE` in `migrate:status` (exit 1), or a `migrate` refusal that
  names "a migration history written by", means the database was migrated
  by the other engine. Nothing was changed. Never edit `schema_migrations`
  to silence it: use the database with the engine that created it, or
  start from an empty database on this engine and run `migrate`.
- **Backups.** Table definitions in a dump come from the engine that wrote
  it. `backup:restore` refuses, before any change, an archive taken from
  the other engine or one that does not record its server version.
- **Image updates.** `MARIADB_AUTO_UPGRADE` is off, so a new MariaDB image
  never changes the system tables by itself. After updating the pinned
  image (same 11.4 series), with a verified backup taken first:

  ```bash
  dc up -d --wait db
  dc exec -T db sh -c 'MYSQL_PWD="$(cat /run/secrets/db_root_password)" exec mariadb-upgrade -uroot'
  dc run --rm ops php bin/console migrate:status
  ```

  A change of major series (for example 11.4 to 11.8) is a planned
  migration: read MariaDB's upgrade notes, back up, test the restore on a
  copy first.

## Metadata cache

Online lookups are cached in `metadata_records` (one row per provider and
ISBN; backed up with the database).
- **Refreshing a wrong entry.** When a provider has corrected a wrong
  record, ask again on the next lookup without deleting anything:
  ```bash
  dc run --rm ops php bin/console metadata:refresh --isbn=9780132350884 --provider=open_library
  ```
  Omit `--provider` for every provider.
- **Corrupt entries.** Corrupt entries and those written by older code are
  ignored and replaced automatically. Manual entry never depends on the
  cache.
- **Do not truncate the table.** Editions may reference rows (their
  provenance and cover import).

## Maintenance and retention

`cleanup` is a **dry run unless `--apply` is given**. Tasks (default: all):

| Task | Removes (with `--apply`) | Default retention |
|---|---|---|
| `invitations` | Unused invitations revoked or expired longer ago than the retention. Accepted invitations are kept. | `INVITATION_RETENTION_DAYS=90` |
| `login-attempts` | Sign-in and invitation attempt records older than the retention (never less than 1 day) | `LOGIN_ATTEMPT_RETENTION_DAYS=30` |
| `temp-files` | Stale `storage/tmp/restore-*` work directories, `.tmp-dla-*` files in the backup directory and `.restore-*` files in cover buckets (left by an interrupted backup or restore). Links are removed, never followed. | `TEMP_FILE_RETENTION_HOURS=24` |
| `orphan-covers` | **Report only.** It deletes only with `--apply --delete-orphan-covers`: cover files no cover record refers to, and only those older than the minimum age. | `ORPHAN_COVER_MIN_AGE_HOURS=24` |
| `backups` | Archives (and their `.sha256`) beyond both the newest `BACKUP_KEEP_COUNT` and the last `BACKUP_KEEP_DAYS`. **The newest archive that passes verification is always kept; if none verifies, nothing is deleted.** | `BACKUP_KEEP_COUNT=14`, `BACKUP_KEEP_DAYS=30` |

Never cleaned:
- catalogue records, loans and loan history, wishlists, collections and users;
- the audit log;
- files the application did not create.

An applied cleanup is recorded as `maintenance.cleanup` in the audit log.

## Recommended schedule (not activated)

Suggested intervals:

| Job | When |
|---|---|
| Backup | daily, 02:15 |
| Copy the archive off-site, then `backup:verify` the copy | daily, after the backup |
| `cleanup --apply` | daily, 03:30 |
| Restore drill into a scratch database | monthly |

Example host crontab (production stack; adjust the path). Backup and cleanup
take the operations lock, so an overlapping run refuses instead of
interfering; keep them a few hours apart.

```cron
15 2 * * *  cd /opt/digital-library && docker compose -f docker-compose.prod.yml --env-file .env.production run --rm -T ops php bin/console backup:create >> /var/log/dla-backup.log 2>&1
30 3 * * *  cd /opt/digital-library && docker compose -f docker-compose.prod.yml --env-file .env.production run --rm -T ops php bin/console cleanup --apply >> /var/log/dla-cleanup.log 2>&1
```

Equivalent systemd units (`/etc/systemd/system/dla-backup.service` and `.timer`):

```ini
[Unit]
Description=Digital Library Archive backup

[Service]
Type=oneshot
WorkingDirectory=/opt/digital-library
ExecStart=/usr/bin/docker compose -f docker-compose.prod.yml --env-file .env.production run --rm -T ops php bin/console backup:create
```

```ini
[Unit]
Description=Daily Digital Library Archive backup

[Timer]
OnCalendar=*-*-* 02:15:00
Persistent=true

[Install]
WantedBy=timers.target
```

A failed backup exits non-zero, so cron mail or `systemctl status` shows it.

## Before production

These steps are not done yet (see also DEPLOYMENT.md):
1. Backups live in `/mnt/bravina-shared/digital-library/backups` on the
   storage HDD (Phase 5C; ops container only; `82:82`, 0700; DEPLOYMENT.md
   section 3). The database is on the system SSD, so a single disk failure
   no longer takes both. Still arrange off-host copies of the archives and
   `.sha256` files against losing the host.
2. Install and enable the schedules above on the host.
3. Run one full restore drill on the production host and record how long it
   took (your measured recovery time).
4. Decide the retention values and your recovery point objective.
