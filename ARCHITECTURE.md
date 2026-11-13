# Architecture

## Goals and principles

- **Long-term maintainability over cleverness.** No framework, and no
  runtime Composer dependencies (Composer provides only the autoloader and
  PHPUnit). Every dependency between components is wired explicitly in
  `src/Core/Services.php`.
- **Private by default.** Everything except the landing page, sign-in,
  invitation acceptance, language switch and health check requires
  authentication.
- **Security in the foundation.** The foundation itself provides CSRF
  protection, escaping, authorization, rate limiting and least-privilege
  database access, so later features don't have to opt in to them.
- **Bibliographic records are separate from physical copies.** The catalogue
  is shared; ownership and location belong to copies.

## Runtime topology

```
Browser ──HTTPS──▶ Cloudflare edge ──tunnel──▶ existing cloudflared (systemd, Docker host)
                   ──HTTP──▶ 127.0.0.1:DLA_HTTP_PORT ───▶ nginx 1.30 (unprivileged, :8080) ──FastCGI──▶ php 8.4-fpm ──▶ mariadb 11.4.13
                                                            serves /assets only                   runs public/index.php    no published port
```

The site reuses the owner's **existing** Cloudflare Tunnel: cloudflared runs
as a systemd service on the Docker host and gets one route to nginx's single
published port, bound to `127.0.0.1` (see
[Production ingress](#production-ingress-existing-cloudflare-tunnel)).

- **nginx** serves `public/assets` directly. Every other path is routed
  internally to `public/index.php`. Direct requests to any `.php` file,
  dotfiles and anything outside `public/` return 404.
- **php** runs PHP-FPM with `clear_env = no` (so configuration reaches PHP)
  and `disable_functions` for process execution. In development the source
  tree is bind-mounted; the `prod` image target bakes code in, read-only to
  `www-data`, with only `storage/` writable.
- **db** is MariaDB 11.4 LTS in production and development (the production
  CPU cannot run MySQL 8.4 images; see "Production database"). MySQL 8.4
  stays supported and is tested in CI. The init script creates two
  accounts (see [Database access](#database-access)).

## Source layout (`src/`, namespace `DigitalLibrary\`)

| Namespace | Responsibility |
|---|---|
| `Core` | `Application` (kernel and middleware pipeline), `Services` (container), `Config`/`DotEnv`, `Bootstrap`, `MaintenanceMode` |
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
| `Invitations` | `InvitationToken`, `InvitationRepository`, `InvitationService` (issue, revoke, replace, single-use redemption), `InvitationDelivery` (`ManualLinkDelivery` now; e-mail later) |
| `Export` | `CatalogueExport`, `PersonalExport` (keyset-batched sources), `Dataset`, `CsvWriter`, `JsonWriter` |
| `Backup` | `BackupCreator`, `BackupVerifier`, `BackupRestorer`, `DatabaseDumper`, `DumpReader`, `Manifest`, strict `TarWriter`/`TarReader`/`TarPath` |
| `Maintenance` | `Cleanup` (retention and housekeeping) |
| `Metadata` | `MetadataService`, providers (`Provider\OpenLibraryProvider`, `Provider\GoogleBooksProvider`), `BookMetadata`, `MetadataPrefill`, `MetadataReview`, `CoverImporter`, provider backoff (`Backoff\ProviderBackoff`, `Backoff\FileBackoffStore`), hardened HTTP (`Http\CurlHttpClient`, `Http\OutboundPolicy`) |
| `Console` | `bin/console` commands (`Console`; backups, restore, maintenance and cleanup in `Operations`) |

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
503 when the database is unreachable. While maintenance mode is on (a flag
file toggled from the CLI), every other request gets a 503 page before any
session or database work.

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
| `invitation.manage` (issue, revoke, replace invitations) | ✔ | | |
| `catalogue.export` (whole-catalogue CSV/JSON export) | ✔ | | |

Personal exports need no extra permission: they are always scoped to the
signed-in user.

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
  gives each placeholder a unique name, because native MariaDB/MySQL prepares
  can't reuse one. It also escapes LIKE wildcards so `%` and `_` match
  literally. Column and table names are never taken from input.
- **BookService** wraps each multi-table write (work + authors + categories
  + edition + contributors + optional first copy) in
  `Transaction::run()`. ISBN uniqueness is checked first and also enforced
  by the unique indexes; a concurrent duplicate insert is converted into
  the same `DuplicateIsbnException`, never a 500.

### Adding a book

1. `GET /books/new` asks for an ISBN, or "book without ISBN".
   `Isbn::problem()` gives the specific reason an ISBN is invalid
   (characters, length, prefix, check digit); `app.js` applies the same
   rules while typing (`IsbnEntryAssetsTest` keeps them in step) and shows
   an `aria-busy` / `role="status"` loading state during the lookup.
2. `GET /books/new?isbn=…` ("Look up book") validates and normalises it to
   ISBN-13. If an edition already has it (whichever form was typed), the
   duplicate page offers **View record** or **Add another copy**. Otherwise
   the full form opens with the ISBN pre-filled and the lookup review.
   `&manual=1` ("Enter details manually") does the same checks but never
   contacts a provider.
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
| Invitations admin | `invitation.manage` | Member/Viewer roles only; links shown once |
| Invitation acceptance `/invite…` | `guest` | token hash in session; single-use transaction |
| Personal export `/export/personal…` | `catalogue.view` | always the signed-in user's id; no user parameter |
| Catalogue export `/admin/export/…` | `catalogue.export` | whitelisted datasets; no wishlists, loans or private collections |

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
- **Review panel** (`MetadataReview`, `catalogue/_lookup_review`): built
  from each provider's own normalised data (`LookupResult::$byProvider`):
  the provider that supplied each pre-filled value, the fields no provider
  supplied, and differing values from other providers (case, spacing,
  trailing punctuation and author order are not differences). It reports;
  it never invents or combines values. Cover URLs are never shown. After a
  validation error on `POST /books` the panel is rebuilt from the cache only
  (`lookup(..., network: false)`): no outbound request on POST.
- **Existing works** (`WorkRepository::similar()`): same title or original
  title (collation: case- and accent-insensitive), also without a subtitle
  part, or a title containing it by a shared author. Shown with **Add as a
  new edition** (`/works/{id}/editions/new?isbn=…`), whose form is pre-filled
  from the cache only; the user submits it through the normal edition flow.
  Nothing is merged automatically.
- **Backoff** (`Backoff\ProviderBackoff`): HTTP 429, 500, 502, 503 and 504
  pause that provider: 30 s, doubling per consecutive failure up to 15 min,
  or `Retry-After` (seconds or HTTP date) clamped to 5 s – 15 min. While
  paused the provider is not contacted and the next one is used; any other
  answer, including "not found", clears the pause. Transport errors and
  other statuses are plain failures. Nothing sleeps or retries inside a
  request. State: `storage/cache/metadata-backoff-<provider>.json`
  (`FileBackoffStore`, `flock`ed, `0600`); if it cannot be written, there is
  simply no backoff. No schema change.
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

## Invitations, export and recovery (Phase 4B)

### Invitations

- **Table:** `invitations`, created in Phase 1, hardened by migration
  `2026_10_14_090000`:
  - a stored generated column `open_email` with a UNIQUE index allows one open invitation per address;
  - `CHECK (role_id IN (2, 3))` means no administrator invitations;
  - `CHECK (expires_at > created_at)`;
  - `revoked_by` and `replaced_by_id`.
- **Issuing.** `InvitationService::issue()` generates 32 random bytes,
  base64url-encoded. It stores `SHA-256(token)` and returns the raw token
  once. An expired open invitation for the same address is revoked first; an
  unexpired one is refused (use *New link*). Expiry is the end of an
  administrator-chosen UTC day, at most `INVITATION_MAX_DAYS` ahead.
- **Delivery.** `InvitationDelivery::deliver()` returns false for
  `ManualLinkDelivery`, so the link is rendered once on the response to the
  issuing POST. The link is never kept in the session, a flash message, a
  redirect or the audit log. An SMTP implementation can be swapped in
  `Services::invitationDelivery()` later.
- **Redemption:**
  1. `GET /invite?token=…` never renders a page. On success it puts only the
     hash in the session (session id regenerated) and redirects to
     `/invite/accept`; on any failure it redirects to `/invite/invalid`.
     These redirects are sent with `Referrer-Policy: no-referrer` and
     `no-store`. Rendered pages keep the site-wide `same-origin` policy,
     because `no-referrer` makes browsers send `Origin: null` on form posts,
     which `VerifyCsrf` refuses.
  2. `POST /invite/accept` validates the form (the invitee re-types the
     invited address; password policy). It then calls `redeem()`, which in
     one transaction:
     - locks the invitation row (`FOR UPDATE`);
     - checks it is unused, unrevoked and unexpired, and that the address
       matches;
     - creates the active user (`created_by` = inviter);
     - marks the invitation accepted with a conditional UPDATE.

     A concurrent redemption waits on the lock and is then refused. Every
     failure shows the same generic page.
  3. The user signs in through the normal login.
- **Rate limiting.** A second `LoginThrottle` instance counts failures per
  client IP, hashed, in `login_attempts`.
- **Logging.** The bundled nginx excludes `/invite?…` from its access log.

### Export

- `CatalogueExport` and `PersonalExport` expose `Dataset`s: a fixed column
  list plus a lazy row generator.
- Rows come from keyset-paginated queries (`(key) > (:last)`, 500 per batch)
  with per-batch lookups for multi-valued fields. There is no
  `GROUP_CONCAT`, so nothing is silently truncated.
- The controller streams the result through `Response::stream()`, inside
  `START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY`. Memory stays flat
  whatever the size; a test checks that 2,537 works add less than 8 MiB of
  peak memory.
- **CSV** (`CsvWriter`): UTF-8 with BOM, RFC 4180 quoting (`fputcsv` with
  escape `""`), CRLF and stable headers. Lists are joined with `; `. Text
  cells starting with `= + - @`, a tab or CR are prefixed with `'`; this also
  catches cells after leading spaces and full-width variants.
- **JSON** (`JsonWriter`): `{"export": {format, type, schema_version: 1,
  generated_at, datasets, …}, "data": {dataset: [rows]}}`.
  - Every column is always present, with `null` for missing values.
  - Ids are database ids. Datetimes are ISO 8601 UTC (`…Z`); dates are
    `YYYY-MM-DD`; prices are decimal strings.
- **Personal datasets:** `account`, `wishlist`, `copies`, `collections`,
  `collection_items`, `loans`.
- **Catalogue datasets:** `works`, `editions`, `authors`, `work_authors`,
  `edition_contributors`, `publishers`, `categories`, `locations`, `copies`,
  `collections` and `collection_items` (shared collections only).

### Backup, verify, restore

See [docs/OPERATIONS.md](docs/OPERATIONS.md) for the operator's view.

- **`DatabaseDumper`.** Pure PHP; reads with the runtime account inside one
  consistent snapshot:
  - output is `SHOW CREATE TABLE` plus multi-row INSERTs in primary-key
    order, keyset-batched;
  - generated columns are skipped (they are recomputed on insert);
  - binary columns are written as hex;
  - each statement is length-framed (`-- dla:statement N`), so restoring
    never parses SQL.
- **`TarWriter` / `TarReader`.** A minimal ustar subset: regular files only,
  conservative names (`TarPath`), mode 0600, mtime 0. The reader rejects
  everything else (links, devices, directories, extended headers,
  duplicates, bad checksums, truncation, size limits), and its callback
  never touches the filesystem itself.
- **`BackupCreator`.** Writes the dump to a temporary file, then archives
  `database.sql`, the covers and `manifest.json` (last). It hashes while
  writing, fsyncs, **verifies the finished temporary archive**, writes the
  `.sha256` sidecar, and only then renames both into place (a final name
  always means a complete, verified backup). Referenced covers must exist.
  Free space is checked first (injectable for tests).
- **`BackupVerifier` / `BackupRestorer`.** Verify, then extract with
  re-hashing. Every statement is checked against an allow-list of dumper
  shapes (`DumpReader::isAllowed`) and executed as a single prepared
  statement. The pre-flight checks (target database empty or `--replace`,
  cover conflicts, free space for extraction and covers) run before any
  change; INSERT values must be literals only (`DumpReader::isLiteralInsert`).
  A `$beforeChanges` hook lets the console take a **safety backup** of a
  non-empty target and write the restore marker. The dump runs through the
  migration account; covers are placed by temporary file and rename. Row
  counts, **foreign-key integrity** (`foreignKeyViolations()`, as the dump
  loads with checks off) and covers are checked afterwards, and a mid-way
  failure is reported as partial. A restore is **not atomic** (MariaDB/MySQL DDL
  commits implicitly); the safety backup is the rollback path.
- **Maintenance and retention.** `MaintenanceMode` is a flag file;
  `Application` answers 503 while it exists, or while a restore into the
  live database is marked incomplete (`<flag>.restore-incomplete`), which
  `maintenance:off` clears only with `--acknowledge-incomplete-restore=<database>`,
  after recording it in the audit log. The 35-second pre-restore wait in
  production is best effort only (see docs/OPERATIONS.md).
  `Cleanup` runs retention as a dry run by default and never deletes the
  newest verified backup.
- **Role separation and locking (Console).** In production, `migrate`,
  `user:create`, `backup:create`, `backup:restore`, `maintenance:on|off` and
  `cleanup` require `APP_ROLE=ops`; `APP_ROLE=web` refuses them everywhere.
  Commands that must not overlap take `OperationLock` (`flock` on
  `TEMP_PATH/operations.lock`; released by the kernel when the process
  exits, so locks never go stale).

## Database access

| Account | Privileges | Used by |
|---|---|---|
| `DB_USERNAME` (`library_app`) | `SELECT, INSERT, UPDATE, DELETE` | Web application |
| `DB_MIGRATION_USERNAME` (`library_migrator`) | the above plus `CREATE, ALTER, DROP, INDEX, REFERENCES` | `bin/console migrate` only |
| `root` | everything | database container only (`root@localhost`; initialisation, `mariadb-upgrade`) |
| `healthcheck` (image) | `USAGE` only, `localhost`/`127.0.0.1`/`::1` | created by the MariaDB image; unused by this project |

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
- **Engine-specific variants.** A released migration is never edited.
  When one cannot run on MariaDB, a file with the **same name** in
  `database/migrations/mariadb/` replaces it, only on a server whose
  `VERSION()` positively identifies MariaDB (`Database\Engine`); every
  other server runs the released file. One variant runs, one
  `schema_migrations` row is written with the checksum of the file that
  ran, and `migrate:status` shows the engine and `(MariaDB variant)`. A
  history holding the other engine's checksum is refused by `migrate`
  before any change and reported as `OTHER-ENGINE` (never rewritten). An
  override may differ from its released file only by a `-- ` header and
  documented replacements, and is frozen once released
  (`MigrationFilesTest`). The only one:
  `2026_10_13_155739_create_wishlist_items_table` (`RTRIM(isbn_13)` in the
  generated `wanted_isbn_13`; MariaDB refuses a stored generated column
  that returns a `CHAR` column, error 1901. `isbn_13` is exactly 13 digits,
  so the value, index and uniqueness are identical; `DatabaseEngineTest`
  checks this on both engines, with and without `PAD_CHAR_TO_FULL_LENGTH`).
  New migrations must run unchanged on both engines (CI runs both).
- Phase 3 added `2026_10_10_215610_add_row_versions.sql` (a `row_version`
  column on works, editions and copies; additive, existing rows start at 1).
  It applied cleanly to a database already at the Phase 2 schema.
- Phase 2 added `2026_10_10_000005_add_catalogue_browse_indexes.sql`
  (indexes only: author names, edition titles and creation dates, copies
  by edition and owner). It applies cleanly to a database at the Phase 1
  schema, which was verified in the Phase 2 session.
- MariaDB and MySQL commit DDL implicitly, so a migration can't be rolled back as a
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

Production deployment hasn't been performed. The production stack below is
prepared in the repository and validated statically and with local nginx,
but it has **never been built or run**: Docker is not used on the
development machine. See [Verification that needs Docker](#verification-that-needs-docker).
The operator procedures (secrets, migration, health checks, updates,
rollback, emergency shutdown, resource planning) are in
[docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).

### Production stack (`docker-compose.prod.yml`)

A separate Compose file; the development `docker-compose.yml` is never used
in production. Run from the deployed working tree `/opt/digital-library` on
the Docker host with
`docker compose -f docker-compose.prod.yml --env-file .env.production …`
(the header of the file lists the steps).

| Service | Image | Runs as | Networks | Credentials |
|---|---|---|---|---|
| `nginx` | `docker/nginx/Dockerfile`: pinned nginx, `nginx.prod.conf`, `default.conf`, `public/assets` baked in | `nginx` (101), port 8080, published on `127.0.0.1:DLA_HTTP_PORT` only | `frontend`, `edge` | none |
| `php` | `docker/php/Dockerfile` target `prod` | `www-data` (82) | `frontend`, `backend`, `egress` | runtime DB account (`APP_ROLE=web`) |
| `ops` | same image, profile `ops`, `run --rm` only | `www-data` (82) | `backend` | runtime + migration accounts (`APP_ROLE=ops`) |
| `db` | pinned `mariadb:11.4.13-noble` | guard, image entrypoint, then `mysql` (999) | `backend` | root (`root@localhost` only) |

- **Only nginx is published**, on `127.0.0.1` only (see
  "Production ingress"). `frontend` (nginx ↔ php) and `backend`
  (php/ops ↔ MariaDB) are `internal` networks with no route outside Docker;
  `edge` carries only nginx's published port. Only `php` joins `egress`, for ISBN metadata lookups (restrict it on the
  host firewall; drop it with `METADATA_PROVIDERS=none`). All networks
  belong to the Compose project (`digital-library-prod`); none is external
  or attachable.
- **Least privilege.** `nginx`, `php` and `ops` have a read-only root
  filesystem, drop all capabilities, use `no-new-privileges` and a private
  `/tmp` tmpfs. `db` uses `no-new-privileges` (the MariaDB entrypoint needs
  its default capabilities to switch to the `mysql` user).
- **Credential separation** (`APP_ROLE`, enforced by `ConfigValidator`): the
  web container receives only `secrets/db_password` and has the migration
  and root settings explicitly cleared; with `APP_ROLE=web`, any migration
  or root credential is a configuration **error** (refuse to start / 503).
  `ops` requires the migration account. The database root password exists
  only in the `db` container, and `MARIADB_ROOT_HOST=localhost` keeps root
  off the network (the image's default would also create `root@'%'`).
- **Startup validation.** The PHP image's entrypoint runs
  `bin/console config:check` before PHP-FPM; errors stop the container.
  The nginx entrypoint refuses to start without an explicit client IP
  policy (below). Production still refuses to serve (503) on errors.
- **Pinned images.** Base images are pinned by version and multi-architecture
  index digest (`php:8.4.27-fpm-alpine3.24`, `composer:2.11.2`,
  `nginx:1.30.6-alpine3.24`, `mariadb:11.4.13-noble`); development uses the
  same nginx and MariaDB pins. Update tag and digest together
  (`docker buildx imagetools inspect <image>:<tag>` on the Docker host),
  then rebuild and rerun the suite.
- **Health checks** (all `CMD`, no shell): nginx `GET /health` on
  127.0.0.1:8080 (end to end through PHP to MariaDB; `/health` is exempt from
  the Host and trusted-proxy checks); php: TCP connect to FPM on 9000; db:
  `mariadb-admin ping` over TCP without credentials (succeeds whenever the
  server answers, and fails while the image initialises with networking
  off). `nginx` waits for a healthy `php`, `php` and `ops` for a healthy `db`.
- **Restart and logs:** restart policy `"no"` for every service (Phase 5C).
  The Docker daemon never starts them by itself; `digital-library.service`
  is the only boot path, after the storage preflight (see "Persistent
  storage"). `json-file` logs, 10 MB × 5 files per container.
- **Migrations:** `run --rm ops php bin/console migrate` on each release,
  before the new image takes traffic. Backups, restores, maintenance mode and
  cleanup run the same way (docs/OPERATIONS.md).

### Production database (MariaDB 11.4)

**Why MariaDB.** The production Docker host has an Intel Pentium E5300:
SSE2, SSE3 and SSSE3, but no SSE4.1, SSE4.2 or POPCNT. The official
`mysql:8.4` image is built on Oracle Linux 9, whose glibc requires
x86-64-v2 and aborts at start on that CPU ("Fatal glibc error: CPU does not
support x86-64-v2"; docker-library/mysql issue 1055). The official
`mariadb:11.4.13-noble` image (MariaDB Foundation packages on Ubuntu
24.04) is pinned instead, by index digest
`sha256:5e73baf8…ab1532` (amd64 manifest `sha256:f6eca938…25e6`). MariaDB
11.4 is a long-term release; 11.4.5 or later is required (below).

**CPU evidence** (static, on the exact amd64 image; the CPU itself was not
available):
- glibc 2.39 and `mariadbd` carry the ELF note "x86 ISA needed:
  x86-64-baseline". Ubuntu 24.04 targets the x86-64 baseline.
- `mariadbd`'s SSE4.2/PCLMUL/AVX instructions are only in the CRC-32
  functions, reached only through `crc32c_x86_available` and
  `crc32_pclmul_enabled`, which test the `cpuid` bits first; on this CPU
  the portable code runs. `tzcnt` (used by the compiler) runs as `bsf` on
  older CPUs; there is no POPCNT. PCRE2's one `lzcnt` is its own JIT
  feature probe.
- The PHP-FPM and nginx images (Alpine 3.24, `-O2`, no `-march`): `php-fpm`,
  `php`, the extensions, musl and `nginx` contain no instruction beyond
  SSSE3. Libraries with run-time CPU dispatch (OpenSSL, libsodium,
  liblzma, zstd, libjpeg-turbo, libwebp) contain faster paths behind
  `cpuid` checks. The nginx configuration loads no dynamic module, so the
  image's codec modules are never loaded. PHP's AVX detection (`xgetbv`)
  runs only when `cpuid` reports OSXSAVE and AVX.
- CI (`production-stack`, "CPU baseline of the production images") repeats
  the ELF-note and instruction checks on every build. The only proof on the
  real CPU is the owner's smoke test (docs/DEPLOYMENT.md 5.1).

**Compatibility rules** (`Database\Engine` holds the few differences; write
portable SQL everywhere else):
- Collations: the schema keeps MySQL's `utf8mb4_0900_ai_ci` and
  `utf8mb4_0900_as_ci` names. MariaDB 11.4.5+ maps them to its UCA 14.0.0
  collations with MySQL's behaviour: case- and accent-insensitive (`ai_ci`),
  accent-sensitive author/publisher matching (`as_ci`), and NO PAD
  (trailing spaces significant). `DatabaseEngineTest` checks this on both.
- No MySQL-only syntax: no `INSERT ... AS alias` row aliases (MariaDB
  syntax error; `MetadataService` binds the values twice), no optimizer
  hints (MariaDB ignores `/*+ MAX_EXECUTION_TIME */` as a comment: the
  audit viewer's 5-second bound uses `Engine::boundedSelect()`, `SET
  STATEMENT max_statement_time=... FOR` on MariaDB), no JSON operators.
- Stored generated columns must not return a `CHAR` column (see
  "Migrations" for the one override).
- Differences that are only reported differently: CHECK violations are
  error 4025 on MariaDB, 3819 on MySQL (`Engine::CHECK_VIOLATION_ERRORS`);
  statement time-outs 1969 / 3024 (`Engine::TIMEOUT_ERRORS`); `JSON` is
  `LONGTEXT` plus a `json_valid()` CHECK on MariaDB (invalid JSON is
  refused on both); a foreign key without `ON DELETE` reads `RESTRICT` on
  MariaDB and `NO ACTION` on MySQL (same InnoDB behaviour); recursive CTE
  limits are `max_recursive_iterations` / `cte_max_recursion_depth`.
- Authentication: `mysql_native_password` accounts (PHP's mysqlnd supports
  it); connections stay on the internal `backend` network.
- Backups are engine-specific (their `CREATE TABLE` statements):
  `backup:restore` refuses an archive from the other engine, or one without
  a recorded server version, before any change.
- The image's entrypoint differs from MySQL's: only `mariadb*` binaries
  (no `mysql`, `mysqladmin`), an initialised directory is detected by
  `mysql/`, `MARIADB_ROOT_HOST` defaults to `%` (set to `localhost`), and
  `MARIADB_AUTO_UPGRADE` stays off (explicit `mariadb-upgrade`,
  docs/OPERATIONS.md "Database engine").

### Production secrets

Files in `/opt/digital-library-data/secrets/` on the system SSD
(`${DLA_DATA_ROOT}/secrets`, Phase 5C), outside the deployed working tree,
so never committed, never in a build context, and never written by a
deployment (a `git checkout` run as the deploying user; the directory is
also root-only, `0700`). Each file holds one random value
(`openssl rand -hex 32`). Compose file-based secrets are bind mounts, so the
container sees the host file's numeric owner and mode:

| File | Used by | Owner:group | Mode |
|---|---|---|---|
| `secrets/db_root_password` | db (999) | `999:999` | `0400` |
| `secrets/db_password` | php/ops (82), db init (999) | `82:999` | `0440` |
| `secrets/db_migration_password` | ops (82), db init (999) | `82:999` | `0440` |

The directory itself is `root:root`, `0700`. The storage preflight checks
the owners and modes, never the values.
The database init script (`docker/mysql/init/01-create-users.sh`) reads
`DB_PASSWORD_FILE`/`DB_MIGRATION_PASSWORD_FILE` (refusing both a value and a
file, or an unreadable/empty file) and runs only when the data volume is
first initialised (an empty data directory, after the database guard
allowed it). It uses the `mariadb` client (falling back to `mysql`) and
writes `.dla-init-complete` last. Keep copies in the password manager (docs/OPERATIONS.md,
"Secrets to preserve").

### Persistent storage and permissions

Phase 5C, split layout (owner-confirmed). Host directories only: no named
volumes, never `/mnt/dockerdata`.

| Host path | Owner | Mode | Mounted at | Containers |
|---|---|---|---|---|
| SSD `/opt/digital-library-data/mysql/` | `999:999` (MariaDB image `mysql` user) | `0700` | `/var/lib/mysql` | db |
| SSD `/opt/digital-library-data/mysql-init-guard/` | `root:root` | `0755` | `/run/dla-db-init` (ro) | db |
| SSD `/opt/digital-library-data/secrets/<file>` | see "Production secrets" | | `/run/secrets/<file>` | per secret |
| HDD `…/digital-library/storage/` | `82:82` (`www-data`) | `0750` | `/var/www/html/storage` | php, ops |
| HDD `…/digital-library/covers/` | `82:82` | `0750` | `/var/www/html/storage/covers` | php, ops |
| HDD `…/digital-library/backups/` | `82:82` | `0700` | `/var/www/html/storage/backups` | ops only |

The locations:

- **Code (system SSD):** `/opt/digital-library`, the deployed working tree
  (owned by the deploying administrator, 0755), Compose project directory
  and build context, holding code and configuration only. The Git objects
  stay in the bare repository `/home/brais/git/digital-library.git`; the
  tree is updated with
  `git --git-dir=… --work-tree=/opt/digital-library checkout -f main` and
  has no `.git` and no nested `app/`.
- **Data (system SSD):** `DLA_DATA_ROOT` = `/opt/digital-library-data`
  (`root:root` 0755), a sibling of the working tree: `mysql/`, `secrets/`,
  `mysql-init-guard/`.
- **Storage HDD:** `DLA_STORAGE_ROOT` = `/mnt/bravina-shared/digital-library`
  (`root:root` 0700). The disk is ext4, UUID
  `ad62670f-861d-47a4-a68d-967272d27866`, mounted at `/mnt/bravina-shared`.
  The mountpoint keeps `root:root` 0755. `books/` is reserved for
  PDF/EPUB and is neither created nor mounted.

Inside the HDD's `storage/` there are two mountpoint stubs: `covers/`
(`root:root` 0755) and `backups/` (`root:root` 0000, so the web container
cannot use it). Owners come from the images: `USER www-data` (uid/gid 82 in
the Alpine PHP base) and the `mysql` user (999) of the MariaDB image. nginx
mounts nothing. php and nginx mount nothing from the SSD.

The repository root is the Compose project directory and build context
(relative mounts, `context: .`). Production data is physically outside it,
so neither a checkout nor a build can reach it; the deploying user also
cannot write to the data root. As defence in depth `.gitignore` and
`.dockerignore` (no `!` exceptions) exclude the data directory names and
database file patterns, and the Dockerfiles copy explicit paths only. The
preflight refuses `.git` or any data directory inside the working tree.

**Fail closed.**

- Every data mount is a long-syntax bind with `create_host_path: false`, so
  Compose never creates a missing source. Docker uses the Mounts API for
  them and refuses to start a container whose source is missing. Neither
  root has a default in Compose.
- **Database guard.** db's entrypoint is `docker/mysql/db-guard.sh`. It
  runs before the image's own entrypoint, however db is started. It starts
  MariaDB when the system schema `mysql/` **and** the completion marker
  `.dla-init-complete` exist (the init script writes it last, after every
  account and grant), and lets the image initialise a new database only
  when the data directory is empty **and** the owner has created
  `allow-empty-init` in the read-only guard directory (a first
  deployment). Otherwise it refuses: an empty directory without
  authorisation, files without a database, an initialisation that did not
  finish (the image leaves `mysql/` behind when an init script fails), or
  MySQL 8 files (`mysql.ibd`).

**Boot path.** `docker.service` is not modified: Docker and the owner's other
workloads, such as Jellyfin, never depend on these disks. Every service has
restart policy `"no"`, so the daemon starts none of these containers by
itself, whether after a reboot, a Docker restart or a power loss. The
dedicated `digital-library.service` (`docker/storage/systemd/`) is the only
boot path:

- It is `BindsTo=`/`After=` the HDD's fstab mount unit,
  `RequiresMountsFor=/opt/digital-library /opt/digital-library-data`, and
  asserts the HDD mountpoint.
- It runs the root-owned installed copy of `docker/storage/preflight.sh`,
  `/usr/local/sbin/digital-library-preflight --existing-installation`
  (read-only), never the copy in the user-writable working tree: a checkout
  cannot change what root runs at boot. This checks the HDD's exact UUID, mountpoint, type and
  options, both layouts' owners and modes, and the database state:
  `mysql/`, `.dla-init-complete` and the application schema present, no
  MySQL 8 files, no initialisation authorisation left.
- Only then does it run `up -d --no-recreate --no-build --pull never
  --wait`.
- Any failure stops the stack (`ExecStopPost`). Unmounting the disk through
  systemd stops the unit first.

The cost is that Docker no longer restarts a crashed container
automatically. Restarting goes through the unit, which reruns the
preflight. An immutable empty mountpoint is optional.

Runbook: docs/DEPLOYMENT.md, section 3. Writes never rename across these
mounts: covers, restored covers and backup archives are written to a
temporary file in their final directory and then renamed there.

### Production ingress (existing Cloudflare Tunnel)

The site is reached through the owner's **existing** Cloudflare Tunnel,
served by the `cloudflared` systemd service on the Docker host. The stack
adds no cloudflared container, service or tunnel. The owner adds one route,
`library.bravina.co.uk` → `http://127.0.0.1:<DLA_HTTP_PORT>`, to that
tunnel (docs/DEPLOYMENT.md, section 0).

- **One published port, loopback only.** `nginx` publishes container port
  8080 on `DLA_HTTP_BIND`:`DLA_HTTP_PORT`. `DLA_HTTP_BIND` is `127.0.0.1`
  (preset in the template), and the nginx entrypoint refuses any address
  that is not loopback. The port has no default (8089 is a candidate), so
  Compose refuses to run until it is set.
- **Network `edge`.** Docker publishes no ports for containers that are
  only on internal networks, so nginx also joins `edge`. This plain bridge
  network belongs to the project and nginx is its only member. Its subnet
  and gateway (`DLA_EDGE_SUBNET`, `DLA_EDGE_GATEWAY`) are fixed and have
  no default. PHP-FPM and MariaDB stay on the internal
  `frontend`/`backend` networks with no host port.
- **Trusted peer = the `edge` gateway.** cloudflared connects to loopback,
  and Docker's port proxy or loopback NAT forwards the connection to nginx
  from the `edge` gateway. That gateway is the only peer nginx ever sees
  through the port. The production policy trusts `CF-Connecting-IP` from
  exactly that address. With `DLA_TRUSTED_PROXY=required`, the entrypoint
  refuses a policy that trusts any other or additional address. The
  gateway is confirmed from `peer=` in the access log before the policy is
  installed. The CI job checks the same prediction on a GitHub runner.
- **Fail-closed policy.** `docker/nginx/production/` (mounted at
  `/etc/nginx/dla/`) ships without `client-ip.conf`, and
  `DLA_TRUSTED_PROXY` is unset, so nginx refuses to start. It also refuses
  a template placeholder, a trust-everything range, `required` without a
  trusted proxy, `none` with one, and anything `nginx -t` rejects.
- **HTTPS.** TLS ends at the Cloudflare edge, and the cloudflared-to-nginx
  leg is HTTP over loopback. The application does not depend on the origin
  scheme:
  - `Secure` `__Host-` session cookies and HSTS come from configuration
    (`SESSION_SECURE_COOKIE`, `SECURITY_HSTS`);
  - absolute URLs (invitation links) come from `APP_URL`, and redirects
    are relative;
  - forwarding headers (`X-Forwarded-Proto`, …) are removed and never
    read.

  cloudflared forwards the visitor's `Host: library.bravina.co.uk` unless
  the route overrides it.

### Verification that needs Docker

Not possible on the development machine (no container runtime), to do on
the Docker host before go-live: build both images (`apk` and Composer
access), `docker compose -f docker-compose.prod.yml config`, start-up with
read-only root filesystems and dropped capabilities, health checks turning
healthy, named-volume ownership on first use, secret file permissions as
seen by uid 82 and 999, `MARIADB_ROOT_HOST=localhost` and the init script with
`*_FILE` secrets on a fresh volume, the credential-less MariaDB health check,
egress filtering, the full test suite inside the built PHP image
(`--fail-on-skipped`), and the nginx entrypoint refusing to start until the
policy is installed. Once it runs, the CI job `production-stack` covers the
build, start-up, health checks, users, read-only filesystems, secret
ownership, role refusals and a restore drill on a GitHub runner; the
production host itself still needs its own check (DEPLOYMENT.md).

### Client IP behind a reverse proxy

**Trust model.** The application never reads forwarding headers. Its only
input is `REMOTE_ADDR`, which `Http\ClientIp` normalises (one canonical
form; IPv4-mapped IPv6 becomes IPv4; anything that is not exactly one
address becomes the shared `0.0.0.0` bucket). Login throttling, invitation
throttling and the audit log all use that same value. Rate-limit buckets
are the IPv4 address or the IPv6 `/64` (`ClientIp::throttleKey()`), so an
IPv6 client cannot rotate through its own subnet.

Nginx decides what `REMOTE_ADDR` is:

- `docker/nginx/client-ip.conf` (mounted at `/etc/nginx/dla/client-ip.conf`)
  is the **only** place where proxies are trusted. The shipped file trusts
  **no** proxy: `REMOTE_ADDR` is the TCP peer.
- `default.conf` removes every forwarding header (`X-Forwarded-*`,
  `Forwarded`, `X-Real-IP`, `CF-Connecting-IP`, `True-Client-IP`, …)
  before FastCGI, whatever the policy, and logs the TCP peer
  (`peer=$realip_remote_addr`) next to the client address.
- With a policy that requires a trusted proxy, requests that did not come
  from it with a valid client address get **403**, except `/health`
  (local container probes).

Without a trusted proxy, every visitor behind Cloudflare or a reverse proxy
shares the proxy's address: one per-IP login bucket for everyone (an
attacker can lock everyone out for 15 minutes) and meaningless audit IPs.
Trusting the wrong peer is worse: anyone who can reach nginx from that
address can choose their IP and bypass the per-IP limit.

**Why `CF-Connecting-IP` is not trusted by default.** Anyone who can open a
TCP connection to nginx can send the header; it is meaningful only on
connections from cloudflared (the Cloudflare edge sets it, replacing any
visitor value, and cloudflared forwards it). So it is believed only from
one peer address: the `edge` gateway, through which every connection to the
loopback port arrives (DEPLOYMENT.md 0.2). Every local process on the Docker
host can use that port, and so can set the header.

Two reviewed templates exist; `nginx -t` rejects both until
their placeholder is replaced with a real address:

| Template | Topology | Header | Trusted peer |
|---|---|---|---|
| `client-ip.cloudflared.conf.example` (production) | Cloudflare → cloudflared (host) → `127.0.0.1` port → nginx | `CF-Connecting-IP` (single value, not walked) | `DLA_EDGE_GATEWAY` |
| `client-ip.reverse-proxy.conf.example` | proxy → nginx | `X-Forwarded-For`, walked from the right | the proxy's fixed address |

To configure:

1. Check the free port and `edge` subnet on the Docker host (DEPLOYMENT.md 0.1).
2. Start with the trust-nothing policy and confirm that `peer=` in the
   access log is `DLA_EDGE_GATEWAY` for tunnel requests (DEPLOYMENT.md 0.4).

   Never trust a whole Docker subnet, a LAN range or `0.0.0.0/0`.
3. Copy `client-ip.cloudflared.conf.example` to
   `docker/nginx/production/client-ip.conf` (the production mount;
   `docker/nginx/client-ip.conf` stays the trust-nothing policy), replace the
   placeholder with `DLA_EDGE_GATEWAY`, and set `DLA_TRUSTED_PROXY=required`.
4. Never publish PHP-FPM or MariaDB; publish nginx only on `127.0.0.1`.
5. Run `php tests/nginx/run.php` (local nginx + PHP-FPM, unprivileged).
6. Verify on the deployment: sign in from a known IP, then check
   `SELECT ip_address FROM audit_log ORDER BY id DESC LIMIT 1` and the
   access log's `peer=` field. The port must not be reachable from another
   machine (loopback only).

### Host header

The application answers only the host and port of `APP_URL`
(`Http\HostPolicy`). Nginx passes the raw `Host` header
(`fastcgi_param HTTP_HOST $http_host`, explicit so it does not depend on the
distribution's `fastcgi_params`) and refuses absolute-form requests whose
target host differs from the `Host` header (400). Note: Debian's packaged
`fastcgi_params` maps `HTTP_HOST` to `$host`, which drops the port; this
affects only a native Debian nginx (e.g. the local `books.test` vhost),
where the application cannot see the port.

## Testing strategy

- Unit tests isolate pure logic. Database-backed classes whose SQL is
  portable (the login throttle) also run against SQLite in memory.
- Integration tests use a real database server (MariaDB 11.4 in the `test`
  job, MySQL 8.4 in `test-mysql`), run the real migrations, use the
  least-privilege accounts, and drive `Application::handle()` in-process with
  an in-memory session standing in for the browser cookie.
- Backup and restore tests write only to temporary directories, and restore
  only into the disposable `DB_TEST_DATABASE`. Afterwards they rebuild its
  schema from the migrations.
- CI (`.github/workflows/ci.yml`) has five jobs:
  - `test` (PHP 8.4): `composer validate --strict`, `composer audit`, lint,
    the full suite with `--fail-on-skipped --fail-on-warning` against the
    production MariaDB image (dedicated test accounts; the engine is
    confirmed first), and `tests/nginx/run.php`;
  - `test-mysql` (PHP 8.4): the full suite against MySQL 8.4, a regression
    check of the engine-specific code and the released migrations;
  - `unit-php83`: lint and the unit suite on PHP 8.3 (tooling compatibility);
  - `static`: shellcheck on every shell script, `docker build --check` on
    both Dockerfiles, `docker compose config` on both Compose files (the
    production file refused without `DLA_HTTP_BIND`/`DLA_HTTP_PORT`, then
    exactly one published port: nginx on the given address);
  - `production-stack`: builds the production images, starts
    `docker-compose.prod.yml` with health checks, checks users, read-only
    filesystems, secrets per role, the web role refusing ops commands, the
    Host check and `config:check --strict` in both roles, then migrates,
    backs up, verifies and runs a restore drill (maintenance mode, web PHP
    stopped). It publishes nginx on the runner's `127.0.0.1:8089` and checks
    health, the `Secure` `__Host-` cookie, HSTS and the Host refusal through
    that port, and that PHP-FPM and MariaDB have no host port. It checks the
    images' CPU baseline, the database engine, accounts and grants (root
    only at localhost, USAGE-only healthcheck accounts), and that a missing
    completion marker is refused. Finally it
    installs the production policy with the `edge` gateway
    (`DLA_TRUSTED_PROXY=required`) and checks, as a local client sending
    `CF-Connecting-IP`, that the logged client is the header's address with
    `peer=` the gateway, and that a request without the header gets 403. No
    tunnel or Cloudflare service is involved.

  Least privilege: `contents: read`, no secrets, no `pull_request_target`,
  checkout without persisted credentials, actions pinned to commit SHAs,
  every job time-limited; a weekly schedule repeats the audit and the drill.
  `tests/Unit/Deployment/CiWorkflowTest.php` enforces these properties and
  that every `run` block parses (`bash -n`).
- `php tests/nginx/run.php` tests `docker/nginx/default.conf` and the client
  IP policies against a local nginx and PHP-FPM (unprivileged, temporary
  directory, loopback high ports; `127.0.0.2` plays the trusted proxy),
  and serves the production main configuration (`nginx.prod.conf`) as a
  non-root process with `/tmp`-only paths.
  Exit status 2 means nginx or PHP-FPM is not installed.
- `tests/Unit/Deployment/` checks the production stack without Docker:
  the Compose structure (parsed by the strict `Tests\Support\BlockYaml`
  reader), image pins, networks, credential separation per `APP_ROLE`
  against `.env.production.example`, the nginx and PHP entrypoints and the
  database init script (run with stub binaries), and the discovery script
  (read-only commands only; planted tokens, e-mail addresses, password
  hashes and public client addresses never reach the report).
