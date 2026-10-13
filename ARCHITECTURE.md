# Architecture

## Goals and principles

- **Long-term maintainability over cleverness.** No framework, and no
  runtime Composer dependencies (Composer provides only the autoloader and
  PHPUnit). Every dependency between components is wired explicitly in
  `src/Core/Services.php`.
- **Private by default.** Everything except the landing page, sign-in,
  language switch and health check requires authentication.
- **Security in the foundation.** The foundation itself provides CSRF
  protection, escaping, authorization, rate limiting and least-privilege
  database access, so later features don't have to opt in to them.
- **Bibliographic records are separate from physical copies.** The catalogue
  is shared; ownership and location belong to copies.

## Runtime topology

```
Browser ──HTTPS──▶ Bravina reverse proxy (TLS) ──HTTP──▶ nginx:1.27-alpine ──FastCGI──▶ php:8.4-fpm-alpine ──▶ mysql:8.4
                                                         serves /assets only          runs public/index.php    no published port
```

- **nginx** serves `public/assets` directly. Every other path is routed
  internally to `public/index.php`. Direct requests to any `.php` file,
  dotfiles and anything outside `public/` return 404.
- **php** runs PHP-FPM with `clear_env = no` (so configuration reaches PHP)
  and `disable_functions` for process execution. In development the source
  tree is bind-mounted; the `prod` image target bakes code in, read-only to
  `www-data`, with only `storage/` writable.
- **db** is MySQL 8.4 LTS. The init script creates two accounts (see
  [Database access](#database-access)).

## Source layout (`src/`, namespace `DigitalLibrary\`)

| Namespace | Responsibility |
|---|---|
| `Core` | `Application` (kernel and middleware pipeline), `Services` (container), `Config`/`DotEnv`, `Bootstrap` |
| `Http` | `Request`, `Response`, `Router`, `HttpException`, middleware, controllers (`Controllers\Catalogue\*`, `Controllers\Admin\*`) |
| `Security` | `Session`, `Csrf`, `PasswordHasher`, `PasswordPolicy`, `LoginThrottle`, `Role`, `Permission`, `Authorizer` |
| `Auth` | `User`, `UserRepository`, `AuthService`, `UserAdministration` (admin changes with lock-out safeguards) |
| `Database` | `ConnectionFactory`, `Migrator`, `SqlSplitter`, `Transaction` |
| `I18n` | `Translator`, `LocaleNegotiator` |
| `View` | `View` (plain-PHP templates with escaping helpers) |
| `Validation` | `Validator` (declarative rules → translation keys) |
| `Catalogue` | `Isbn`, `Vocabulary`, `Tree`, `Slug`, inputs (`Input\*`), repositories (`Repository\*`), `BookService` (transactional writes, duplicate detection), `CatalogueSearch` + `SearchCriteria` |
| `Upload` | `CoverImageProcessor` (validated, re-encoded image storage) |
| `Audit` | `AuditLogger`, read-only viewer support (`AuditLogRepository`, `AuditQuery`, `AuditDetails`) |
| `Loans` | `LoanInput`, `LoanRepository`, `LoanService` (transactional lend/return) |
| `Wishlist` | `WishlistInput`, `WishlistRepository`, `WishlistService` |
| `Collections` | `CollectionInput`, `CollectionRepository`, `CollectionService` |
| `Metadata` | `MetadataService`, providers (`Provider\OpenLibraryProvider`, `Provider\GoogleBooksProvider`), `BookMetadata`, `MetadataPrefill`, `CoverImporter`, hardened HTTP (`Http\CurlHttpClient`, `Http\OutboundPolicy`) |
| `Console` | `bin/console` commands |

## Request lifecycle

1. `public/index.php` builds `Services` from the environment and calls
   `Application::handle(Request::fromGlobals())`.
2. Global middleware, outermost first:
   1. `SecurityHeaders` sets the CSP, frame, sniffing, referrer, permissions
      and cache headers, and HSTS when enabled.
   2. `StartSession` starts the hardened session, applies the timeouts and
      shares the CSRF token with views.
   3. `SetLocale` chooses the language: session/user choice, then
      `Accept-Language`, then the default.
   4. An error boundary renders translated error pages; details go to the
      log only.
   5. `VerifyCsrf` checks the token and `Origin`/`Sec-Fetch-Site` on every
      non-safe method.
3. `Router` matches method and path; per-route middleware runs next:
   - `auth`: authenticated, active user whose `auth_version` matches the session.
   - `guest`: redirects signed-in users away from the login page.
   - `can:<permission>`: role permission check.
4. The controller performs **object-level** checks via `Authorizer` where a
   specific record is involved, and returns a `Response`.

`/health` bypasses the session stack and returns only `{"status":"ok"}`, or
503 when the database is unreachable.

## Authorization model

`Role` maps to a fixed set of `Permission`s (`src/Security/Role.php`):

| Permission | Admin | Member | Viewer |
|---|:-:|:-:|:-:|
| `catalogue.view` | ✔ | ✔ | ✔ |
| `catalogue.create` | ✔ | ✔ | |
| `catalogue.edit_own` (records they created) | ✔ | ✔ | |
| `catalogue.edit_any`, `catalogue.delete` | ✔ | | |
| `copy.manage_own` (copies they own) | ✔ | ✔ | |
| `copy.manage_any` | ✔ | | |
| `location.manage`, `category.manage` | ✔ | | |
| `user.manage`, `settings.manage`, `audit.view` | ✔ | | |
| `loan.manage_own` (lend copies they own) | ✔ | ✔ | |
| `loan.manage_any` | ✔ | | |
| `wishlist.manage_own` (own wishlist only; no override) | ✔ | ✔ | |
| `collection.manage_own` | ✔ | ✔ | |
| `collection.manage_any` (see and manage private collections) | ✔ | | |

Object-level decisions are `Authorizer::canEditRecord($user, $createdBy)` and
`Authorizer::canManageCopy($user, $ownerId)`; Phase 4A adds
`canManageLoans()`, `canSeeLoanDetails()`, `canManageWishlistItem()`,
`canViewCollection()` and `canManageCollection()`. Templates may use the same
methods to hide controls, but only the server-side checks are authoritative.
Inactive users have no permissions.

## Data model

All tables use InnoDB, `utf8mb4_0900_ai_ci`, UTC `DATETIME`s and named
foreign keys. The schema is created by `database/migrations/*.sql`.

```
roles ─< users >─ invitations
            │
            ├─< copies >── editions >── works ─< work_authors >── authors
            │      │          │  │        └─< work_categories >── categories (tree)
            │      └── locations (tree)   │
            │                 │  ├── publishers
            ├─< collections ─< collection_items >── editions
            └─< audit_log     │  ├─< edition_contributors >── authors
                              │  ├─< covers
                              │  └── metadata_records
```

- **works:** the abstract book (title, original title and language, first
  publication year, description).
- **editions:** a published manifestation: title as printed, publisher,
  year, language, format, pages, legal deposit, and **optional** `isbn_13` /
  `isbn_10`, each `UNIQUE` (NULLs don't collide) and `CHECK`-constrained.
  Every valid ISBN is normalised to its ISBN-13 form
  (`DigitalLibrary\Catalogue\Isbn`), so duplicates are detected whichever
  form the user types.
- **copies:** one row per physical book, with `owner_user_id`, an optional
  `location_id`, condition, status, acquisition data and notes. Several users
  can own copies of the same edition without duplicating catalogue records.
- **locations** and **categories** are adjacency-list trees with
  `UNIQUE(parent_id, name)`.
- **covers** store image metadata. Files live on disk outside the web root.
  A stored generated column enforces at most one primary cover per edition.
- **metadata_records** cache raw provider payloads (Open Library, Google
  Books) per ISBN, so entries can be reviewed and stay available offline.
- **audit_log** is append-only by convention: who did what, to which entity,
  from where, with redacted JSON details.
- **login_attempts** stores a SHA-256 of the normalised e-mail and the client
  IP, never the e-mail itself.
- **settings** is a key/JSON store; `registration.mode` defaults to `"disabled"`.

Delete behaviour: catalogue records `RESTRICT` deletion while copies or
editions reference them. Users are deactivated rather than deleted, so copies
`RESTRICT` user deletion. Join tables cascade.

## Catalogue (Phase 2)

### Layers

```
Controller (HTTP, permissions, audit)  ──▶  Input\* (parse + validate request data)
     │                                          │
     ├──▶ BookService (multi-table writes in one transaction, ISBN duplicate rules)
     │          └──▶ Repository\* (one class per aggregate; SQL lives only here)
     └──▶ CatalogueSearch (dynamic query built with Database\Params)
```

- **Inputs** (`WorkInput`, `EditionInput`, `CopyInput`, `People`) turn
  request data into typed values and validation errors (translation keys),
  and map validated values to column arrays. Repositories never see raw
  request data.
- **Repositories** use prepared statements only. Dynamic SQL
  (`CatalogueSearch`) adds every value through `Database\Params`, which
  gives each placeholder a unique name, because native MySQL prepares
  can't reuse one. It also escapes LIKE wildcards so `%` and `_` match
  literally. Column and table names are never taken from input.
- **BookService** wraps each multi-table write (work + authors + categories
  + edition + contributors + optional first copy) in
  `Transaction::run()`. ISBN uniqueness is checked first and also enforced
  by the unique indexes; a concurrent duplicate insert is converted into
  the same `DuplicateIsbnException`, never a 500.

### Adding a book

1. `GET /books/new` asks for an ISBN, or "book without ISBN".
2. `GET /books/new?isbn=…` validates and normalises it to ISBN-13. If an
   edition already has it (whichever form was typed), the duplicate page
   offers **View record** or **Add another copy**. Otherwise the full form
   opens with the ISBN pre-filled.
3. `POST /books` creates the work, edition, authors, publisher, categories
   and, if ticked, the user's first copy. Without an ISBN, editions with
   the same title (case- and accent-insensitive) are listed as possible
   duplicates until the user ticks "this is a different book".

Authors and publishers are matched by name, case-insensitively but
**accent-sensitively** (`utf8mb4_0900_as_ci`), so "José" and "Jose"
remain distinct people. Author `sort_name` defaults to "Surname, Given
names" and is editable, because compound Spanish and Galician surnames
defeat any heuristic.

### Search and filters (`GET /books`)

- Text search splits the query into up to 8 words. Every word must match
  the edition or work title or subtitle, the original title, an author or
  contributor name, or a partial ISBN. LIKE with the default
  `utf8mb4_0900_ai_ci` collation makes it case- and accent-insensitive
  ("garcia" finds "García"). A query that parses as a full ISBN is an exact
  ISBN-13 lookup.
- FULLTEXT indexes exist, but LIKE is used deliberately: at personal-library
  scale it's fast enough, and it avoids stop-word and minimum-token-length
  surprises.
- Category and location filters include descendants (trees are small and
  loaded whole; `Catalogue\Tree`).
- Owner and location filters must match **the same copy**.
- Results are paginated at 24 per page; out-of-range pages clamp to the
  last page. Malformed query parameters fall back to defaults.

### Covers

- `POST /editions/{id}/covers` (record-level edit permission) runs the
  upload through `CoverImageProcessor`, which writes a re-encoded JPEG (at
  most 1600 px) and a thumbnail (at most 480 px) under a random key. The
  metadata row is inserted in a transaction and the new cover becomes
  primary. If the database write fails, the files are deleted.
- `GET /covers/{id}[?size=thumb]` (signed-in users with `catalogue.view`)
  streams the file with `Content-Type: image/jpeg`, `nosniff`,
  `Content-Security-Policy: default-src 'none'; sandbox`,
  `Cache-Control: private`, and an ETag (304 on revalidation).
- Deleting a cover removes both files and promotes the newest remaining
  cover to primary.
- Storage is `storage/covers` (`COVER_STORAGE_PATH`). In development it is
  the `covers-data` named volume; the dev entrypoint hands it to `www-data`.

### Route permissions

| Area | Route guard | Record-level rule in the controller |
|---|---|---|
| Browse, search, view works/editions/authors/publishers/covers | `catalogue.view` | — |
| Add book, add edition to a work | `catalogue.create` | — |
| Edit edition/work, upload/delete covers | `catalogue.edit_own` | creator, or `catalogue.edit_any` |
| Delete edition/work | `catalogue.delete` | refused while copies/editions exist |
| Add copy | `copy.manage_own` | owner must be self unless `copy.manage_any` |
| Edit/delete copy | `copy.manage_own` | owner (or `copy.manage_any`); transferring ownership needs `copy.manage_any` |
| Edit, merge or delete authors/publishers | `catalogue.edit_any` | merges need a different existing target; deletion only when unused |
| User administration | `user.manage` | no self-demotion or self-deactivation; last active admin protected |
| Categories / locations admin | `category.manage` / `location.manage` | no cycles; refuse deletion of parents or items in use |
| Copy page `/copies/{id}`, loan list `/loans` | `catalogue.view` | borrower and history only for owner/admin (and the borrowing user) |
| Lend, return | `loan.manage_own` | copy owner, or `loan.manage_any`; re-checked under row locks |
| Wishlist | `wishlist.manage_own` | owner only; other users' entries are 404 |
| Collections: view | `catalogue.view` | private: owner or `collection.manage_any`, else 404 |
| Collections: create, edit, delete, items | `collection.manage_own` | owner, or `collection.manage_any` |
| Audit-log viewer | `audit.view` | GET routes only |

## Administration and data quality (Phase 3)

### User administration (`/admin/users/{id}/edit`, `user.manage`)

- Edit e-mail, name, role, language and active status; reset a password;
  sign a user out everywhere.
- Every session is re-validated on each request against the user row
  (`is_active` and `auth_version`):
  - role changes apply on the next request;
  - deactivation, password resets and "sign out everywhere" end all
    sessions (`auth_version` is incremented).
- `Auth\UserAdministration` enforces the safeguards in a transaction:
  - administrators can't change their own role or deactivate themselves;
  - demoting or deactivating an active administrator first locks all
    active admin rows (`SELECT … FOR UPDATE`) and refuses if none would
    remain, so concurrent requests can't remove the last admin;
  - admins reset their own password and sign themselves out through their
    own account pages.

### Edit conflicts (optimistic locking)

- Works, editions and copies have `row_version`. Edit forms carry the
  version they were loaded with.
- Updates are conditional (`WHERE id = ? AND row_version = ?`) and
  increment the version; zero affected rows means a conflict.
- On a conflict the transaction rolls back (no partial author or category
  changes) and the form is re-shown with HTTP 409 and the user's input.
- The stale version is kept in the re-shown form, so saving again can't
  overwrite the other change; the user must reload.
- Merges also increment the version of affected records.

### Merging authors and publishers (`catalogue.edit_any`)

- **Authors:** `UPDATE IGNORE` moves credits to the target (credits the
  target already holds are kept once), then the leftovers and the source
  are deleted. All in one transaction.
- **Publishers:** editions are re-pointed, then the source is deleted.
- Unused authors and publishers can be deleted; anything still in use
  can't.

## ISBN metadata lookup (Phase 3)

```
ISBN step (new ISBN) ──▶ MetadataService.lookup ──▶ cache hit? ── yes ─▶ normalise
                                   │                    └ no ─▶ provider.fetch (CurlHttpClient + OutboundPolicy)
                                   ▼                                  └▶ store raw payload in metadata_records
                         merged BookMetadata ──▶ MetadataPrefill ──▶ form (user reviews/edits)
POST /books ──▶ cachedRecord(id, ISBN) must match ──▶ editions.metadata_record_id
                                         └▶ import_cover ─▶ CoverImporter ─▶ CoverImageProcessor (validate + re-encode)
```

- **Providers,** in priority order from `METADATA_PROVIDERS` (default
  `open_library,google_books`):
  - Open Library Books API (`/api/books?bibkeys=ISBN:…&jscmd=data`);
  - Google Books (`/books/v1/volumes?q=isbn:…`). Its results are accepted
    only if the volume lists the requested ISBN.
  Fields are merged so the first provider wins and later ones fill gaps.
- **Cache:** raw payloads live in `metadata_records`, positive results for
  `METADATA_CACHE_DAYS` (30) and "not found" for one day. Transport failures
  are not cached. Later steps (linking, cover import) use the cache, never
  a new request from the browser.
- **Review before saving:** provider data only pre-fills the form; it passes
  the same validation as manual input. Values are cleaned first (control
  characters removed, HTML stripped, lengths capped, years and page counts
  range-checked, languages mapped to the catalogue vocabulary).
- **Outbound HTTP:** see SECURITY.md › Outbound requests. Tests use
  `tests/Support/FakeHttpClient`, which enforces the same `OutboundPolicy`.
- **Cover import:** the server takes the cover URL from its cached record,
  never from form input, and only for the same ISBN. It downloads the image
  through the hardened client and stores it through
  `CoverImageProcessor::storeDownloaded()`: the same validation and
  re-encoding as uploads, without the HTTP-upload origin check, which can't
  apply to a server-side file.
- **Verification status:** in the development sandbox, Open Library is
  blocked by egress policy and Google Books answers HTTP 429 without an API
  key. Live transport and error handling were exercised, but a successful
  live parse wasn't. Provider parsing is tested against fixtures in the
  documented response formats.

## Library management (Phase 4A)

### Loans (`loans` table)

- Each loan references a copy and either a user (`borrower_user_id`) or a
  free-text `borrower_name`, as a CHECK constraint requires. The dates are
  `loaned_on`, the optional `due_on` and `returned_on`.
- **One active loan per copy:** the stored generated column
  `active_copy_id = IF(returned_on IS NULL, copy_id, NULL)` carries a UNIQUE
  index. Returned loans hold NULL, so history is unlimited.
- `LoanService::lend()` and `returnLoan()` run in one transaction. Each locks
  the copy row (`FOR UPDATE`), re-checks `canManageLoans()` against the
  locked owner, and keeps `copies.status` in step (`available` and
  `on_loan`), bumping `row_version` so open copy-edit forms conflict.
  Duplicate-key and lock-timeout errors map to friendly refusals.
- The copy edit form can no longer set or clear `on_loan` by hand, and a copy
  with loan history can't be deleted (the FK is `RESTRICT`). Withdraw it
  instead.
- **Overdue** is `returned_on IS NULL AND due_on < UTC_DATE()`, computed in
  queries. There is no scheduler. All loan dates are UTC calendar dates.

### Wishlists (`wishlist_items`)

- Rows are private per user. Title and author are always stored. The links
  to an edition or work are `SET NULL` on catalogue deletion, so entries
  survive.
- Status is wanted, acquired or archived, with `status_changed_at`. Entries
  are never deleted by the application, and they go with their owner
  (`CASCADE`).
- Duplicates are checked under the owner's `users` row lock: the same ISBN-13
  (normalised through `Catalogue\Isbn`), the same edition, or the same title
  and author when neither is set (collation-insensitive). A unique index on
  `(user_id, wanted_isbn_13)` backs this up.
- Wishlist changes are personal and are not written to the audit log.

### Collections (`collections`, `collection_items`, Phase 1 tables)

- Items are editions. Positions are assigned `MAX + 1` under the collection
  row lock. Moves (up, down, top or bottom) renumber `1..n`.
- Collections are capped at 2,000 items. Pages show 50 items or 25
  collections.
- A new index `(collection_id, position)` supports ordered pages, and
  `(visibility, name)` supports the shared list.

### Audit-log viewer

- `AuditQuery` validates the filters. Dates are UTC days, defaulting to the
  last 30 and capped at 366. Malformed values are dropped, never passed to
  SQL.
- `AuditLogRepository::page()` filters on the indexed columns (`created_at`,
  `(action, created_at)`, `(user_id, created_at)` and `(entity_type,
  entity_id)`), with a LIKE search over details, IP and user agent inside the
  range.
- Pages use keyset pagination on the primary key (`id < :before`, fetching
  `LIMIT 51`), with no OFFSET or full COUNT. A `MAX_EXECUTION_TIME(5000)`
  optimizer hint bounds each statement; a timeout shows a message.
- `AuditDetails` redacts secret-looking keys again on display and
  pretty-prints the JSON. The template escapes it.

## Database access

| Account | Privileges | Used by |
|---|---|---|
| `DB_USERNAME` (`library_app`) | `SELECT, INSERT, UPDATE, DELETE` | Web application |
| `DB_MIGRATION_USERNAME` (`library_migrator`) | the above plus `CREATE, ALTER, DROP, INDEX, REFERENCES` | `bin/console migrate` only |
| `root` | everything | MySQL container initialisation only |

Connections use native prepared statements (`ATTR_EMULATE_PREPARES=false`),
exceptions, `utf8mb4`, UTC and a strict `sql_mode`. Connection errors are
reduced to a SQLSTATE before they can reach any output. Multi-step writes use
`Database\Transaction::run()`.

### Migrations

- Files are named `YYYY_MM_DD_HHMMSS_description.sql` and applied in order.
  Each applied version is recorded in `schema_migrations` with a SHA-256
  checksum. `migrate:status` reports `MODIFIED` if an applied file has since
  been edited. Never edit an applied migration; add a new one.
- Runs are serialised with `GET_LOCK`.
- Phase 3 added `2026_10_10_215610_add_row_versions.sql` (a `row_version`
  column on works, editions and copies; additive, existing rows start at 1).
  It applied cleanly to a database already at the Phase 2 schema.
- Phase 2 added `2026_10_10_000005_add_catalogue_browse_indexes.sql`
  (indexes only: author names, edition titles and creation dates, copies
  by edition and owner). It applies cleanly to a database at the Phase 1
  schema, which was verified in the Phase 2 session.
- MySQL commits DDL implicitly, so a migration can't be rolled back as a
  unit. Keep each file small and focused. If one fails, fix forward with a
  new migration, or repair manually and re-run.

## Internationalisation

- `lang/{en,es,gl}.php` hold nested arrays addressed by dot keys
  (`auth.login.title`). Controllers and templates use keys, never literal
  strings. A unit test checks that every locale defines the same keys and
  placeholders.
- Locale precedence: an explicit choice (stored in the session and the user
  profile), then `Accept-Language`, then `APP_LOCALE`. URLs aren't
  locale-prefixed because the catalogue is private and not indexed.
- To add a language, add `lang/xx.php`, append `'xx'` to
  `Translator::SUPPORTED`, and add it to the footer selector in
  `templates/layout.php`.

## Frontend

- Server-rendered HTML with plain-PHP templates. `$view->e()` / `$view->t()`
  escape everything; raw output is limited to pre-rendered partials.
- One stylesheet with design tokens in `:root`, so the palette can be aligned
  with the Bravina site, plus a dark-mode variant and reduced-motion support.
- JavaScript is progressive enhancement only, loaded as an external
  `defer`red file. No inline scripts or styles, so the CSP doesn't need
  `unsafe-inline`.
- Accessibility: skip link, landmark elements, labelled controls,
  `aria-invalid` / `aria-describedby` on errors, visible focus outlines,
  44 px touch targets.

## Deployment

Production deployment hasn't been performed or verified. Requirements:

1. **TLS** terminates at the existing Bravina reverse proxy, which forwards to
   the `nginx` service. Set `APP_URL=https://library.bravina.co.uk`,
   `SESSION_SECURE_COOKIE=true` and `SECURITY_HSTS=true`.
2. **Client IP:** configure trusted-proxy handling as described in
   [Client IP behind a reverse proxy](#client-ip-behind-a-reverse-proxy).
3. **Image (blocker until verified):** the Alpine image has not yet been
   built or tested anywhere. Build `docker build --target prod -f docker/php/Dockerfile .`
   and run it instead of the bind-mounted dev target. Nginx needs the same
   `public/` files (bake a small nginx image or share a read-only volume).
4. **Secrets:** use Docker secrets (`DB_PASSWORD_FILE`, …). Set
   `APP_ENV=production`.
5. **Persistence:** volumes for MySQL data and `storage/covers`. Include both
   in backups.
6. **Migrations:** run `php bin/console migrate` as a one-off task on each
   release, before the new image takes traffic.

### Client IP behind a reverse proxy

The application uses only `REMOTE_ADDR`, never a forwarded header itself.
Behind a reverse proxy, `REMOTE_ADDR` is the proxy's address unless Nginx
is told which peers to trust. Without that:

- every user shares one IP for the per-IP login limit (20 failures in
  15 minutes), so one attacker can lock out everyone;
- audit-log IP addresses are meaningless.

Configuring it wrongly is worse than not configuring it. If an untrusted
host is listed as trusted, any client can forge its IP address and bypass
the per-IP limit.

No proxy address is assumed in this repository. To configure:

1. Find the address the proxy connects **from**, as seen by the `nginx`
   container. For a proxy on a shared Docker network, use that container's
   address on the network (`docker network inspect <network>`); for a proxy
   on the host, use the gateway address of the Compose network. Docker may
   reassign container addresses on restart. Prefer a dedicated network with
   a fixed subnet, or a static address for the proxy.
2. In `docker/nginx/default.conf`, uncomment the block and replace
   `<PROXY_ADDRESS_OR_NARROW_CIDR>` with that address, or the narrowest
   CIDR containing only the proxy.
3. Make sure the proxy **overwrites or appends** `X-Forwarded-For` with the
   real client address. With `real_ip_recursive on`, Nginx walks the header
   from the right and stops at the first untrusted address.
4. Make sure the application port is reachable **only** through the proxy.
   Compose binds it to `127.0.0.1` for this reason.
5. Verify: sign in from a known IP, then confirm that
   `SELECT ip_address FROM audit_log ORDER BY id DESC LIMIT 1` shows that
   IP rather than the proxy's. Also confirm that a request sending a forged
   `X-Forwarded-For` directly to the container (bypassing the proxy) does
   not change the logged IP.

## Testing strategy

- Unit tests isolate pure logic. Database-backed classes whose SQL is
  portable (the login throttle) also run against SQLite in memory.
- Integration tests use real MySQL, run the real migrations, use the
  least-privilege accounts, and drive `Application::handle()` in-process with
  an in-memory session standing in for the browser cookie.
- CI (`.github/workflows/ci.yml`) runs lint, both suites against a MySQL 8.4
  service, and a Dockerfile lint.
