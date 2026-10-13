# Operations: backups, restore, maintenance and disaster recovery

This guide covers the tooling added in Phase 4B. Everything here is a CLI
command run inside the PHP container. None of it is reachable from the web
application. Commands assume the development stack (`docker compose exec php …`);
in production, run the same `php bin/console …` commands in the production PHP
container.

> **Status.** The commands are implemented and tested against MySQL 8.4 and
> PHP 8.4 in a development environment. Nothing has been scheduled or run
> against production. Schedules, off-site copies and the production backup
> location are future production configuration (see "Before production"
> below).

## What a backup contains

A backup is one file, `dla-backup-YYYYMMDDTHHMMSSZ.tar.gz`, with a
`.sha256` file beside it. Inside (gzip-compressed POSIX tar, regular files
only):

| Member | Contents |
|---|---|
| `database.sql` | Logical dump of every table: definitions and rows. It is written by PHP, so no `mysqldump` is needed. It is also valid input for the `mysql` client. |
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
| Restore a backup | `php bin/console backup:restore --file=PATH --confirm=DATABASE [--database=DATABASE] [--replace] [--covers-dir=DIR]` |
| Maintenance mode | `php bin/console maintenance:on [--reason=…]`, `maintenance:off`, `maintenance:status` |
| Housekeeping | `php bin/console cleanup [--apply] [--tasks=…] [--delete-orphan-covers]` |

Exit codes:
- `0`: success.
- `1`: refused, or failed without changing anything.
- `2`: failed after changes were made (a partial restore; see below).

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

A restore replaces data, so it is guarded:

1. **Maintenance mode must be on** (`maintenance:on`). Use
   `--skip-maintenance-check` only for disposable environments; it is
   refused when `APP_ENV=production`.
2. **Confirmation:** `--confirm` must repeat the exact target database name.
3. **Production:** with `APP_ENV=production`, `--allow-production` is also
   required.
4. The archive is **fully verified** first. It is then extracted into a
   private temporary directory (`TEMP_PATH`), where every member is hashed
   again against the manifest.
5. **Pre-flight, nothing changed yet.** Every dump statement must be one of
   the forms the backup tool writes; anything else, such as `DROP`, `GRANT`
   or file writes, rejects the archive. The target database must be empty
   unless `--replace` is given; with `--replace`, its tables are dropped
   first. No existing cover file may differ from the backup unless
   `--replace` is given. Unrelated files in the covers directory are never
   touched or deleted.
6. The dump is applied using the **migration account**, which needs DDL
   rights. Cover files are written to temporary names and renamed into place.
7. **Post-check:** row counts per table must match the manifest, and every
   referenced cover file must exist.

If the database step fails part-way, the command exits with code `2` and says
the database is **PARTIALLY restored**. Do not use that database: fix the cause
and restore again with `--replace`. The command never reports success after a
partial failure.

**Restoring into a new, empty database** is the safest route; it needs a
database administrator to create it. For example:
1. As MySQL root: `CREATE DATABASE library_restore …;` and grant the migration
   and application accounts the same privileges as on `library`
   (`docker/mysql/init/01-create-users.sh` shows them).
2. Run `backup:restore --file=… --database=library_restore --confirm=library_restore`.
3. Point `DB_DATABASE` at it.

## Disaster-recovery runbook

**Prerequisites:**
- a host with Docker Engine and the Compose plugin;
- this repository at the release that made the backup (or newer);
- the secrets listed below;
- the latest verified backup and its `.sha256` file, ideally an off-site copy.

1. **Prepare.** Check out the code. Recreate `.env`, or the Docker secrets,
   from your secure store. Start the stack: `docker compose up -d --wait`.
   On a fresh MySQL volume, the init script creates the databases and accounts.
2. **Verify the copy you will use:**
   `docker compose exec php php bin/console backup:verify --file=storage/backups/dla-backup-….tar.gz`
   (copy the archive into the backups volume first). Continue only if it
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

Sessions are deliberately not preserved: after a restore, users sign in again.

## Maintenance and retention

`cleanup` is a **dry run unless `--apply` is given**. Tasks (default: all):

| Task | Removes (with `--apply`) | Default retention |
|---|---|---|
| `invitations` | Unused invitations revoked or expired longer ago than the retention. Accepted invitations are kept. | `INVITATION_RETENTION_DAYS=90` |
| `login-attempts` | Sign-in and invitation attempt records older than the retention (never less than 1 day) | `LOGIN_ATTEMPT_RETENTION_DAYS=30` |
| `temp-files` | Stale `storage/tmp/restore-*` work directories and `.tmp-dla-*` files in the backup directory. Links are removed, never followed. | `TEMP_FILE_RETENTION_HOURS=24` |
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

Example host crontab. Adjust the path, and use the production compose file and
service name:

```cron
15 2 * * *  cd /srv/library && docker compose exec -T php php bin/console backup:create >> /var/log/dla-backup.log 2>&1
30 3 * * *  cd /srv/library && docker compose exec -T php php bin/console cleanup --apply >> /var/log/dla-cleanup.log 2>&1
```

Equivalent systemd units (`/etc/systemd/system/dla-backup.service` and `.timer`):

```ini
[Unit]
Description=Digital Library Archive backup

[Service]
Type=oneshot
WorkingDirectory=/srv/library
ExecStart=/usr/bin/docker compose exec -T php php bin/console backup:create
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

These steps are not done yet:
1. Mount a persistent host directory (or a dedicated volume) at
   `storage/backups` (`BACKUP_PATH`), outside the web root. Arrange off-site
   copies of it.
2. Install and enable the schedules above on the host.
3. Run one full restore drill into a scratch database on the production host.
4. Decide the retention values for the production environment.
