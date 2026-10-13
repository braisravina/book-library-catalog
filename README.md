# Digital Library Archive

A private, self-hosted, multi-user web application for cataloguing and managing
a personal collection of physical books. Intended domain: `library.bravina.co.uk`.

- **Stack:** PHP 8.4 (PHP-FPM) · MySQL 8.4 · Nginx (Alpine) · Docker Compose ·
  HTML5/CSS3/vanilla JS. No framework. PDO with prepared statements, Argon2id.
- **Languages:** English, Spanish (Español), Galician (Galego).
- **Status:** Phases 1 (foundation), 2 (functional catalogue) and 3 (user
  administration, data quality and ISBN metadata lookup) are implemented.
  None has been deployed. See [Roadmap](#roadmap).

| Document | Contents |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Structure, request lifecycle, data model, deployment model |
| [SECURITY.md](SECURITY.md) | Threat model, implemented controls, deployment checklist |
| [CLAUDE.md](CLAUDE.md) | Persistent instructions for automated contributors, including Git rules |

## Features

### Foundation (Phase 1)

- Secure sign-in and sign-out with hardened PHP sessions, idle and absolute
  timeouts, and session invalidation on password change.
- Three roles (Administrator, Member, Viewer), enforced server-side, with
  object-level policies for catalogue records and physical copies.
- Administrator-created accounts. Public registration is disabled; the
  invitations table is ready for invitation-based sign-up.
- CSRF protection, login rate limiting, audit logging and strict security headers.
- Normalised schema for works, editions (optional unique ISBN-10/13), authors,
  publishers, hierarchical categories and locations, physical copies with owners,
  collections, covers, external metadata cache, and the audit log.
- Versioned SQL migrations and a CLI (`bin/console`).
- Centralised translations (EN/ES/GL) with `Accept-Language` negotiation.
- ISBN validation and conversion, and a secure cover-image upload pipeline.
- Responsive, accessible layout with light/dark mode. No inline scripts.

### Catalogue (Phase 2)

- **Add a book** in two steps. First the ISBN is validated (ISBN-10 or
  ISBN-13) and checked against the catalogue. An existing ISBN, in either
  form, leads to "view record" or "add another copy" instead of a duplicate.
  Books without an ISBN are entered manually; same-title records are shown
  as possible duplicates until the user confirms.
- **Works and editions:** create, view and edit both. Add further editions
  (translations, reprints) to a work. Multiple authors with roles (author,
  editor, compiler) per work, and contributors (translator, illustrator, …)
  per edition. Authors and publishers are found or created by name.
- **Physical copies:** owner, hierarchical location, condition, status,
  signed flag, inventory code, acquisition date and source, price and notes.
  Several users can own copies of the same edition.
- **Covers:** authenticated upload (validated and re-encoded, with a
  thumbnail). Images are served only to signed-in users through
  `/covers/{id}`. The newest upload becomes the main cover.
- **Browse and search:** cover-grid and list views, search by title, author,
  contributor or ISBN (case- and accent-insensitive), filters by category,
  language, owner and location (sub-categories and sub-locations included),
  sorting and pagination.
- **Authors and publishers:** listing and detail pages; editing is
  admin-only because they are shared records.
- **Administration:** hierarchical categories and storage locations, with
  cycle prevention and protection against deleting items in use.
- **Permissions on every operation:**
  - Viewers are read-only.
  - Members add books and edit the works and editions they created.
  - Members manage only the copies they own, and can't assign copies to
    other people.
  - Administrators can do everything; deletion is admin-only and refused
    while dependent records exist.
- Every catalogue change is recorded in the audit log.

### Administration, data quality and metadata (Phase 3)

- **User administration:**
  - Edit a user's name, e-mail, role and language; deactivate and reactivate
    accounts.
  - Reset passwords and sign a user out of every session. Both take effect
    on the user's next request.
  - Safeguards: administrators can't change their own role or deactivate
    themselves, and the last active administrator can never be demoted or
    deactivated (enforced with row locks).
- **Edit-conflict detection** on works, editions and copies. A save based on
  an outdated form is rejected (HTTP 409) and the user's input is kept, so
  nobody silently overwrites someone else's change.
- **Merge duplicate authors and publishers,** and delete unused ones
  (admin-only). Overlapping credits collapse into one.
- **ISBN metadata lookup.** When a new ISBN is entered, Open Library and then
  Google Books are queried. Results pre-fill the form for review, never save
  automatically, and are cached locally. The cover can be imported on save:
  the server downloads it, then validates and re-encodes it like an upload.
  Failures fall back to manual entry. Set `METADATA_PROVIDERS=none` to turn
  lookups off.

### Library management (Phase 4A)

- **Lending and returns** of physical copies. The borrower is a library user
  or a free-text name; no contact details are stored. Loans have an optional
  due date, are listed under *Loans* (lent by me, borrowed by me and, for
  administrators, all) and keep a full history per copy. Overdue status is
  derived from the due date (UTC), with no background job. Availability is
  shown on book and copy pages; only the owner, administrators and the
  borrowing user see who has a copy. A copy can have at most one active loan
  (enforced by a unique index and row locks), only available copies can be
  lent, and members lend only their own copies.
- **Private wishlists.** Entries come from a catalogue edition or are typed
  in (title, author, optional ISBN), with priority and notes. Their status
  moves between wanted, acquired and archived and is never deleted.
  Duplicates (same ISBN, edition or title and author) are refused. *Add to
  catalogue* on an acquired entry opens the add-book or add-copy form; no
  copy is created automatically. Wishlists are visible to their owner only,
  administrators included.
- **Collections** of editions, private (owner and administrators) or shared
  (every signed-in user). Create, edit, delete with confirmation, add from a
  book's page, remove and reorder. Viewing never implies editing, and there
  is no anonymous access.
- **Audit-log viewer** (`/admin/audit`, administrators only, read-only).
  Filter by date range (UTC, at most 366 days), event, user, record and a
  details search. Paging uses keyset pagination, and a details page shows the
  redacted, escaped JSON.

### Invitations, data portability and recovery (Phase 4B)

- **Invitation-only registration.**
  - Administrators invite an e-mail address as Member or Viewer, never Administrator, with an expiry date.
  - The single-use link is shown once, to copy and send yourself; no SMTP is needed.
  - Invitations can be revoked or replaced with a new link, and the list shows each one's status.
  - The invitee confirms the invited address, chooses a name and password (the same Argon2id hashing and password policy), then signs in normally.
  - Tokens are 256-bit random values, stored only as SHA-256 hashes. Redemption is atomic and single-use, even under concurrent requests, and rate-limited per client address.
  - The token never reaches a rendered page, a Referer header, the audit log or the nginx access log.
- **Data export** (*Export* page, CSV or JSON):
  - Every user can download their own data: account, wishlist, owned copies, own collections and loans they can see.
  - Administrators can also download the catalogue: works, editions, credits, publishers, categories, locations, copies with their owners, and shared collections.
  - JSON is a versioned, complete document with stable identifiers. CSV is UTF-8 and protected against spreadsheet formula injection.
  - Exports are streamed in bounded batches from one consistent snapshot, never cached, and audited.
  - Passwords, sessions, invitations, sign-in records, the audit log, other users' wishlists and private collections are never exported.
- **Backups and restore** (CLI only):
  - `backup:create` writes a checksummed archive (database dump, cover images, manifest) from a consistent snapshot.
  - `backup:verify` rejects corrupted or malicious archives.
  - `backup:restore` is guarded: it needs maintenance mode and a typed confirmation, verifies first, refuses to overwrite without `--replace`, and reports partial failure explicitly.
  - `maintenance:on|off` switches the site to a 503 page.
  - See [docs/OPERATIONS.md](docs/OPERATIONS.md), which includes the disaster-recovery runbook.
- **Housekeeping:** `cleanup` prunes expired invitations, old sign-in records, stale temporary files and old backups.
  - It is a dry run unless `--apply` is given.
  - Orphaned covers are only reported unless explicitly requested.
  - The newest verified backup is never deleted.

## Requirements

- Docker Engine 24+ with the Compose plugin.
- For running tests outside Docker: PHP ≥ 8.3 with `pdo_mysql`, `mbstring`,
  `fileinfo`, `sodium` and optionally `gd`; Composer 2.

## Quick start (development)

```bash
cp .env.example .env
# Edit .env: replace every CHANGE_ME value with a long random string, e.g.
#   php -r 'echo bin2hex(random_bytes(24)), PHP_EOL;'

docker compose build
docker compose run --rm php composer install
docker compose up -d --wait

docker compose exec php php bin/console migrate

# Create the first administrator. The password is read from STDIN (prompted
# without echo in a terminal), never passed on the command line.
docker compose exec php php bin/console user:create \
    --email=you@example.org --name="Your Name" --role=admin --locale=en
```

Open <http://localhost:8080> and sign in. `APP_URL` must match the URL you
browse to exactly (scheme, host and port). Form submissions whose `Origin`
header doesn't match are rejected with 403, so `127.0.0.1:8080` won't work
when `APP_URL` says `localhost:8080`.

The database has no published port. Only the `php` service can reach it.

## Everyday commands

| Task | Command |
|---|---|
| Start / stop | `docker compose up -d` / `docker compose down` |
| Logs | `docker compose logs -f php nginx` |
| Apply migrations | `docker compose exec php php bin/console migrate` |
| Migration status | `docker compose exec php php bin/console migrate:status` |
| Create a user | `docker compose exec php php bin/console user:create --email=… --name="…" --role=member` |
| All tests | `docker compose exec php vendor/bin/phpunit` |
| Unit tests only | `docker compose exec php vendor/bin/phpunit --testsuite unit` |
| Integration tests | `docker compose exec php vendor/bin/phpunit --testsuite integration` |
| PHP lint | `docker compose exec php composer lint` |
| Reset the database | `docker compose down -v` (destroys all data) |
| Create / verify a backup | `docker compose exec php php bin/console backup:create` / `backup:verify --file=…` |
| Restore a backup | see [docs/OPERATIONS.md](docs/OPERATIONS.md#restore) (guarded; needs maintenance mode) |
| Maintenance mode | `docker compose exec php php bin/console maintenance:on` / `maintenance:off` |
| Housekeeping (dry run) | `docker compose exec php php bin/console cleanup` (add `--apply` to delete) |

## Testing

- **Unit tests** (`tests/Unit`) need no database. They cover routing, ISBN
  handling, validation, translations, escaping, sessions, CSRF, password
  hashing and policy, authorization, login throttling (SQLite in memory), the
  SQL splitter, migration-file conventions, cover-image processing and
  thumbnails (needs `gd`), catalogue inputs, trees, slugs and search criteria.
- **Integration tests** cover the catalogue flows: ISBN duplicate detection,
  manual entry, editing, editions and copies. They also cover search and
  filters, including LIKE-wildcard and injection inputs; covers (upload,
  serving headers, caching, malicious files, cleanup); category and location
  administration; and a full permission matrix: guest, viewer, member, other
  member and admin across every catalogue route, IDOR attempts on copies, and
  CSRF on every POST route.
- **Phase 3 tests** cover:
  - user administration: the self-lockout and last-admin safeguards,
    immediate effect of role changes and deactivation, password reset and
    session revocation;
  - edit-conflict detection on works, editions and copies;
  - author and publisher merges;
  - the outbound URL allowlist, including tricks such as credentials in the
    URL, lookalike or suffix domains, IP literals, other schemes and ports;
  - metadata parsing, cleaning and merging;
  - the full lookup flow against an offline HTTP fake: pre-fill, caching,
    negative caching, provider failure, hostile payloads, cover import and
    rejected cover bytes.
  Tests never touch the network.
- **Phase 4A tests** cover:
  - loans: lending, return, history, duplicate active loans (service and
    unique index), concurrent lending (two connections and lock timeouts),
    cross-user and viewer attempts, borrower privacy, derived overdue state,
    invalid dates, missing copies and copy status and deletion rules;
  - wishlists: CRUD, ISBN normalisation, duplicates, status history, the
    hand-off to the add-book flow, invalid input and strict isolation (other
    users and administrators get 404);
  - collections: lifecycle, items and ordering, private and shared
    visibility, read-only sharing and IDOR attempts;
  - the audit viewer: admin-only access, no write routes, filters, bounded
    ranges, keyset pagination, redaction and escaping of stored values;
  - migration verification: released migrations are frozen by checksum, all
    migrations are non-destructive, and the new tables, indexes, generated
    columns, CHECK constraints and foreign-key actions exist on MySQL.
- **Phase 4B tests** cover:
  - invitations: creation, hashing (no token stored anywhere), expiry, revocation, replacement, single use, concurrent redemption (two connections), role restrictions (application and database), rate limiting, administrator-only access, and the token-free redirect and headers;
  - exports: JSON structure and versioning, CSV quoting, BOM, no truncation and formula-injection protection, per-user isolation and IDOR attempts, administrator-only catalogue export and its exclusions, headers, and a large catalogue streamed in batches with bounded memory;
  - backups: archive contents, permissions and no secrets; refusal of unsafe locations and missing covers; verification against corrupted, truncated and malicious archives (path traversal, links, devices, pax headers, duplicates, forged manifests); guarded restore into the disposable test database (refusals, `--replace`, an empty target, partial failure); console guards; maintenance mode;
  - cleanup: dry runs, retention, symbolic-link safety, report-only orphaned covers, and backup retention that keeps the newest verified archive;
  - the Phase 4B migration applied to a database holding Phase 4A data.
- **Integration tests** (`tests/Integration`) run against the MySQL database
  named in `DB_TEST_DATABASE`, using the same least-privilege accounts as the
  application. They rebuild the schema from the migrations and drive the full
  HTTP stack in-process. They're skipped automatically when no test database
  is configured.

**Cover-image (GD) tests.** `CoverImageProcessorTest` and the cover
integration tests need the `gd` extension; they are skipped without it.
The project's PHP image installs `gd`, and CI runs with
`--fail-on-skipped`. During Phase 2 the whole suite (182 tests) passed on
PHP 8.4 with `gd`, with no skips. That run used a sandbox stand-in image,
not the project's Alpine image (see Known deployment blockers). To rerun
the GD tests on PHP 8.4:

```bash
docker compose exec php php -m | grep -i gd     # must print "gd"
docker compose exec php vendor/bin/phpunit --filter CoverImageProcessorTest --fail-on-skipped
```

The MySQL init script creates `DB_TEST_DATABASE` (default `library_test`) the
first time the data volume is initialised. If you added it later, run
`docker compose down -v` to re-initialise.

## Configuration

All configuration comes from environment variables. See `.env.example` for
the full, commented list. Any variable can instead be supplied as a file via
`NAME_FILE=/run/secrets/name` (Docker secrets). Key points:

- `APP_ENV=production` disables all debug output, even if `APP_DEBUG=true`.
- `SESSION_SECURE_COOKIE=true` is required behind HTTPS. It also switches the
  cookie to the `__Host-` prefix.
- `DB_USERNAME` is the runtime account (DML only). `DB_MIGRATION_USERNAME` is
  used only by `bin/console migrate`.
- `APP_URL` is also the base of invitation links.
- `BACKUP_PATH`, `TEMP_PATH` and `MAINTENANCE_FLAG_PATH` default to
  `storage/backups`, `storage/tmp` and `storage/maintenance.flag`, all outside
  `public/`. Retention settings (`INVITATION_RETENTION_DAYS`,
  `LOGIN_ATTEMPT_RETENTION_DAYS`, `TEMP_FILE_RETENTION_HOURS`,
  `ORPHAN_COVER_MIN_AGE_HOURS`, `BACKUP_KEEP_COUNT`, `BACKUP_KEEP_DAYS`) have
  safe defaults; see `.env.example`.
- `METADATA_PROVIDERS` controls the online ISBN lookup (default
  `open_library,google_books`; `none` disables it). Lookups send the ISBN to
  those services. `GOOGLE_BOOKS_API_KEY` (or `_FILE`) is optional, and
  raises Google's rate limit.

## Deployment

Production deployment to the Bravina Docker infrastructure is **not** done yet.

### Known deployment blockers

These must be resolved and verified before any deployment:

1. **The Alpine PHP image has never been built successfully.** In Phases 1
   and 2, real builds of both the `dev` and `prod` targets failed at
   `apk add`: the cloud build environment's egress policy blocks the Alpine
   package mirrors (HTTP 403). Only the Dockerfile lint (`docker build
   --check`) and the `vendor` stage were verified. The test suites ran on
   sandbox stand-in images: Debian PHP 8.4 in Phase 1, and the official
   `wordpress:php8.4-fpm` image (PHP 8.4 with GD) plus `pdo_mysql` in
   Phase 2. Both used the project's own `php.ini`, FPM pool and entrypoint.
   Build both targets on a machine with normal network access, then run
   the full suite with `--fail-on-skipped` inside the built image.
2. **Client IP forwarding is not configured.** See
   [ARCHITECTURE.md › Client IP behind a reverse proxy](ARCHITECTURE.md#client-ip-behind-a-reverse-proxy).
3. **The CI workflow has never been executed.**
4. **Backups are not scheduled and have no production location.** Mount
   persistent storage at `storage/backups`, add off-site copies, enable the
   schedules in [docs/OPERATIONS.md](docs/OPERATIONS.md#recommended-schedule-not-activated),
   and run one restore drill on the production host.
5. **The reverse proxy must not log query strings for `/invite`.** The
   bundled nginx already omits these requests; check the Bravina proxy too.

The production image target (`docker build --target prod -f docker/php/Dockerfile .`)
and the requirements are described in
[ARCHITECTURE.md › Deployment](ARCHITECTURE.md#deployment). Work through the
checklist in [SECURITY.md](SECURITY.md#production-deployment-checklist) before
going live.

## Project layout

```
bin/console              CLI: migrations, users, backups, restore, maintenance, cleanup
config/routes.php        Route table and per-route middleware
database/migrations/     Versioned SQL migrations (applied in filename order)
docker/                  PHP-FPM image, Nginx vhost, MySQL init and config
docs/OPERATIONS.md       Backup, restore, disaster recovery, retention and scheduling
lang/{en,es,gl}.php      Translation catalogues
public/                  Web root: front controller and static assets only
src/                     Application code (PSR-4, namespace DigitalLibrary\)
storage/                 Runtime files outside the web root (covers, logs, backups,
                         tmp); in development, storage/covers and storage/backups
                         are the covers-data and backups-data volumes
templates/               Plain-PHP templates; all output escaped
tests/                   PHPUnit unit and integration suites
```

## Roadmap

- **Phase 1 — Foundation (done):** infrastructure, schema, authentication,
  authorization, security controls, i18n, tests and docs.
- **Phase 2 — Catalogue (done):** works and editions, ISBN duplicate
  check, authors and publishers, physical copies with owner and location,
  cover upload and serving, cover-grid and list views, search and filters,
  and admin management of categories and locations.
- **Phase 3 — Administration, data quality and metadata (done):** user
  administration with lock-out safeguards, edit-conflict detection, merging
  of authors and publishers, and Open Library / Google Books lookup with
  review before saving and local cover import.
- **Phase 4A — Library management (done):** lending and returns, private
  wishlists, personal and shared collections, and the read-only audit-log
  viewer.
- **Pending decision:** audit-database separation (see SECURITY.md).
- **Phase 4B — Invitations, data portability and recovery (done):**
  invitation-only registration, CSV/JSON export, backup/verify/restore
  tooling with maintenance mode, and retention housekeeping.
- **Phase 5 — proposed:** production readiness: build and validate the
  Alpine images and CI, configure the reverse proxy and client IP, implement
  the audit-database separation, schedule and drill backups. Then optional
  e-mail delivery (invitations, password reset) and multi-factor
  authentication.
