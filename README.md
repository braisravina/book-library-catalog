# Digital Library Archive

A private, self-hosted, multi-user web application for cataloguing and managing
a personal collection of physical books. Intended domain: `library.bravina.co.uk`.

- **Stack:** PHP 8.4 (PHP-FPM) · MySQL 8.4 · Nginx (Alpine) · Docker Compose ·
  HTML5/CSS3/vanilla JS. No framework. PDO with prepared statements, Argon2id.
- **Languages:** English, Spanish (Español), Galician (Galego).
- **Status:** Phase 1 (foundation) is complete. See [Roadmap](#roadmap).

| Document | Contents |
|---|---|
| [ARCHITECTURE.md](ARCHITECTURE.md) | Structure, request lifecycle, data model, deployment model |
| [SECURITY.md](SECURITY.md) | Threat model, implemented controls, deployment checklist |
| [CLAUDE.md](CLAUDE.md) | Persistent instructions for automated contributors, including Git rules |

## Features (Phase 1)

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
- ISBN validation and conversion, and a secure cover-image upload pipeline
  (wired into the UI in Phase 2).
- Responsive, accessible layout with light/dark mode. No inline scripts.

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

## Testing

- **Unit tests** (`tests/Unit`) need no database. They cover routing, ISBN
  handling, validation, translations, escaping, sessions, CSRF, password
  hashing and policy, authorization, login throttling (SQLite in memory), the
  SQL splitter, migration-file conventions and cover-image processing (needs `gd`).
- **Integration tests** (`tests/Integration`) run against the MySQL database
  named in `DB_TEST_DATABASE`, using the same least-privilege accounts as the
  application. They rebuild the schema from the migrations and drive the full
  HTTP stack in-process. They're skipped automatically when no test database
  is configured.

**Cover-image (GD) tests.** `CoverImageProcessorTest` (9 tests) needs the
`gd` extension and is skipped without it. The project's PHP image installs
`gd`, and CI runs with `--fail-on-skipped`, so these tests must pass on
PHP 8.4. During Phase 1 they passed only on PHP 8.3 (host); the PHP 8.4
container used for Phase 1 lacked `gd` (see Known deployment blockers).
To rerun them on PHP 8.4:

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

## Deployment

Production deployment to the Bravina Docker infrastructure is **not** done yet.

### Known deployment blockers

These must be resolved and verified before any deployment:

1. **The Alpine PHP image has never been built.** During Phase 1, the build
   environment could not reach the Alpine package mirrors. Only the
   Dockerfile lint (`docker build --check`) and the `vendor` stage were
   verified. Integration tests ran on a Debian-based PHP 8.4 stand-in
   without `gd`. Build both the `dev` and `prod` targets, then run the full
   suite with `--fail-on-skipped` inside the built image.
2. **Client IP forwarding is not configured.** See
   [ARCHITECTURE.md › Client IP behind a reverse proxy](ARCHITECTURE.md#client-ip-behind-a-reverse-proxy).
3. **The CI workflow has never been executed.**

The production image target (`docker build --target prod -f docker/php/Dockerfile .`)
and the requirements are described in
[ARCHITECTURE.md › Deployment](ARCHITECTURE.md#deployment). Work through the
checklist in [SECURITY.md](SECURITY.md#production-deployment-checklist) before
going live.

## Project layout

```
bin/console              CLI: migrations, user creation
config/routes.php        Route table and per-route middleware
database/migrations/     Versioned SQL migrations (applied in filename order)
docker/                  PHP-FPM image, Nginx vhost, MySQL init and config
lang/{en,es,gl}.php      Translation catalogues
public/                  Web root: front controller and static assets only
src/                     Application code (PSR-4, namespace DigitalLibrary\)
storage/                 Runtime files outside the web root (covers, logs)
templates/               Plain-PHP templates; all output escaped
tests/                   PHPUnit unit and integration suites
```

## Roadmap

- **Phase 1 — Foundation (done):** infrastructure, schema, authentication,
  authorization, security controls, i18n, tests and docs.
- **Phase 2 — Catalogue:** add/edit works and editions, ISBN duplicate check
  ("view existing" or "add another copy"), authors and publishers, physical
  copies with owner and location, cover upload and serving, cover grid and
  list views, search (title/author/ISBN) and filters (category, language,
  owner, location), admin management of locations and categories, user
  deactivation and role changes.
- **Phase 3 — Metadata providers:** Open Library and Google Books lookups via
  a provider interface, cached in `metadata_records`, with a review step
  before saving. Remote covers are downloaded and re-encoded locally.
- **Phase 4 — Circulation and lists:** loans, wishlists, collections UI,
  invitation-based registration, audit-log viewer, CSV export and backups.
