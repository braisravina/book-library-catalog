# Security

## Reporting

This is a private, self-hosted application. Report suspected vulnerabilities
privately to the repository owner. Do not open
public issues for security problems.

## Threat model (summary)

| Asset | Threats considered |
|---|---|
| Catalogue and ownership data | Unauthenticated access, privilege escalation between roles, editing other users' records |
| User accounts | Credential stuffing and brute force, session hijacking and fixation, CSRF, account enumeration |
| Server and database | SQL injection, file-upload abuse, information leakage through errors, path traversal |
| Audit trail | Loss of accountability, leaking secrets into logs |

Out of scope for Phase 1: multi-factor authentication, malware scanning of
uploads, a WAF, and hardening of the host and reverse proxy.

## Implemented controls

### Authentication and sessions

- **Argon2id** password hashing (default m=64 MiB, t=3), with transparent
  rehash on login when parameters change (`Security\PasswordHasher`).
- **Password policy** following NIST SP 800-63B: minimum 12 characters,
  maximum 512, no composition rules, and a rejection list for trivial and
  e-mail-equal passwords. Unicode is allowed.
- **Generic login failure message.** A dummy hash verification for unknown
  accounts equalises timing, so account existence isn't revealed.
- **Rate limiting:** 5 failures per account and 20 per IP within 15 minutes
  (configurable). Throttled requests get HTTP 429 with `Retry-After`. Only an
  e-mail hash is stored.
- **Hardened sessions:**
  - `use_strict_mode`, cookie-only, HttpOnly, `SameSite=Lax`.
  - `Secure` with a `__Host-` prefix when `SESSION_SECURE_COOKIE=true`.
  - 30-minute idle and 12-hour absolute timeouts.
  - The session ID is regenerated, and the CSRF token rotated, at login.
  - Logout (POST only) invalidates the whole session.
- **Session revocation:** every request re-loads the user. Inactive accounts,
  and sessions whose `auth_version` is stale (bumped on password change), are
  signed out.
- **No public registration.** Accounts are created by administrators (web UI
  or CLI). The CLI reads passwords from STDIN, never argv.

### Authorization

- Role → permission mapping in one place (`Security\Role`). Route guards
  (`auth`, `can:<permission>`) run before controllers.
- Object-level policies (`Security\Authorizer`): members may edit only records
  they created and manage only copies they own; viewers are read-only;
  inactive users have no rights.
- Templates hide controls the user can't use, but **never** decide access.

### Catalogue authorization (Phase 2)

Every catalogue route has a role guard. Every action on a specific record
also runs an object-level check in the controller:

- members may edit only works and editions they created;
- members may manage only copies they own, may not create a copy owned by
  someone else, and may not transfer their copy to someone else;
- only admins edit shared authors and publishers, delete records, and
  manage categories and locations.

The full matrix is in ARCHITECTURE.md › Route permissions. Integration
tests check it for guests, viewers, two different members and admins. That
includes IDOR attempts on copy ids, attempts to forge `owner_user_id`, and
inactive or unknown owners.

### User administration (Phase 3)

- Only `user.manage` (administrators) can reach `/admin/users/*`; tests check
  that members can't promote themselves.
- Safeguards in `Auth\UserAdministration`:
  - no self-demotion or self-deactivation;
  - the last active administrator can't be demoted or deactivated. The check
    runs under row locks, so racing requests can't bypass it.
- Deactivation, admin password resets and "sign out everywhere" increment
  `auth_version`, ending every session on its next request. Role changes
  apply on the next request too, because users are reloaded from the
  database each time.
- Passwords set by administrators must pass the normal password policy. They
  never appear in the audit log or in responses.
- Audit actions: `user.updated` (with a from/to diff of changed fields),
  `user.password_reset`, `user.sessions_revoked`.

### Outbound requests (ISBN metadata, Phase 3)

The application makes outbound HTTP requests only for metadata lookup and
cover import, and only through `Metadata\Http\CurlHttpClient`:

- **Allowlist:** HTTPS only, port 443, no credentials in the URL, no literal
  IP addresses, and only hosts under `openlibrary.org`, `archive.org`,
  `googleapis.com`, `books.google.com` and `googleusercontent.com`.
- **Redirects:** followed manually (at most 3), and every hop is
  re-checked; curl's own redirect following is off.
- **TLS:** peer and host verification are always on.
- **Limits:** connect timeout of 4 s or less, total timeout from
  `METADATA_TIMEOUT_SECONDS` (default 8), and responses capped at 1 MB for
  JSON and `UPLOAD_MAX_BYTES` for images (the transfer is aborted when
  exceeded).
- **No user-controlled URLs.** Request URLs are built from fixed templates
  plus the validated 13-digit ISBN. The cover URL comes from the server's
  own cached provider record and is only used for the same ISBN.
  `metadata_record_id` from the form is just a pointer, verified against
  that ISBN.
- **Untrusted provider data:** it only pre-fills a form that the user
  reviews, it's cleaned and length-capped, and output is escaped as usual.
  A hostile payload (HTML or script, an internal cover URL such as
  `169.254.169.254`) is tested to be inert.
- **Privacy:** the browser never contacts the providers (the CSP stays
  `img-src 'self'`). The server does send each looked-up ISBN to the
  configured providers; set `METADATA_PROVIDERS=none` to disable this.
- **Secrets:** `GOOGLE_BOOKS_API_KEY` is optional; supply it as
  `GOOGLE_BOOKS_API_KEY_FILE` in production. It's sent only to
  googleapis.com.
- **Rate limits and server errors:** HTTP 429 and 500/502/503/504 pause
  that provider (bounded backoff, `Retry-After` honoured within 5 s –
  15 min), so a struggling provider is not hammered; no request is retried
  or delayed inside a web request. The state files in `storage/cache` hold
  only a failure count and a timestamp.
- **Review panel and suggestions:** provider values, including conflicting
  alternatives in `data-fill-value` attributes, are escaped like any other
  output, and "Use this value" only copies text into a form field with
  `value`/`textContent` (no HTML sinks). Existing-work suggestions are
  links; nothing is merged. The `isbn` parameter of the new-edition form is
  validated with `Isbn::tryParse()` and only reads the cache.
- **Untrusted metadata:** an HTTP 200 or an ISBN match proves nothing.
  - Records with strong spam signals (promotional wording, the title also
    being the publisher with no author, a future year) are shown for
    review but never pre-fill the form, never supply a cover and are never
    linked.
  - Open Library editions are used only if they list the ISBN.
  - Records claiming the same ISBN are listed, not chosen.
  - Corroboration needs two independent providers.
  - Nothing is saved without the user.
- **Outcomes, pacing and cache:**
  - TLS failures are reported, never bypassed.
  - Open Library requests are paced to its published limit, with no
    retries inside a request.
  - The User-Agent carries a contact only if the owner sets
    `METADATA_CONTACT`.
  - Cache entries are validated on every read; corrupt or legacy entries
    are refetched, never used as a fallback.
- **Open Library contributions** (`/editions/{id}/open-library`):
  - Read-only GET pages, with the edition page's permission.
  - Nothing is sent to Open Library from the server. No Open Library
    credentials, cookies or tokens are requested, stored or used, and no
    browser session is driven.
  - The prepared text holds only the bibliographic fields the user
    selected from an allowlist. Never copies, owners, locations, prices,
    acquisition data, notes, collections, wishlists, internal IDs or cover
    files.
  - Links open Open Library (or the user's e-mail draft) in the user's
    browser.
- **Residual risk:** DNS for the allowlisted domains is trusted. Network
  egress filtering at the host or container level is a sensible extra layer.

### Invitations (Phase 4B)

- **Tokens.**
  - Each token is 256 bits from `random_bytes()`, base64url-encoded, and
    only its SHA-256 hash is stored. A high-entropy token needs no slow hash.
  - The raw link appears exactly once: in the response to the
    administrator's POST, with `no-store` set. The page URL holds no token.
  - The link is never in a session, a flash message, a redirect, the audit
    log (`token`, `token_hash` and `link` keys are also redacted) or the
    database.
- **Link handling.**
  - `GET /invite?token=…` never renders HTML. It stores only the hash in a
    freshly regenerated session and redirects to a token-free URL, with
    `Referrer-Policy: no-referrer` and `Cache-Control: no-store`.
  - So the token is in a single request: it is not in page URLs, Referer
    headers or cached pages.
  - The bundled nginx keeps every request carrying a `token` parameter out
    of its access log, whatever the path spelling (`/invite/?token=`,
    `//invite?token=`, percent-encoded paths). The upstream reverse proxy and
    CDN must be configured the same way (see the checklist).
  - The link stays in the invitee's own browser history, but it is
    single-use and expires.
- **Fragment links (Phase 5A, `INVITATION_LINK_FORMAT=fragment`).**
  - Links become `APP_URL/invite/redeem#TOKEN`. Browsers never send the
    fragment to a server or in a `Referer`, so the token is in no request
    line: not at Cloudflare, cloudflared, nginx (access or error log) or any
    other intermediary.
  - `GET /invite/redeem` renders a CSRF-protected form (same-origin referrer
    policy, `no-store`, no token). `assets/js/app.js` copies the fragment
    into it, removes it from the address bar and the current history entry
    with `history.replaceState`, and submits. Without JavaScript the invitee
    pastes the code (the part after `#`).
  - `POST /invite/redeem` goes through the normal CSRF and `Origin` checks,
    then behaves exactly like `GET /invite?token=`: hash-only session state,
    token-free `no-referrer` redirect, generic failures, the same per-IP
    limit and audit entries (reason `redeem`).
  - Both entry points accept tokens from either format, so the setting can
    be changed at any time without invalidating links already sent. The
    query format is kept (default) in Phase 5A; retiring it needs an owner
    decision.
- **Logging (Phase 5A review).**
  - The application never logs tokens or their hashes; an integration test
    captures the PHP error log, audit log, sessions and every response
    across both flows and asserts the raw tokens appear in none of them.
  - In the Docker image PHP logs to the FPM process's stderr, not through
    FastCGI, so application errors are not paired with nginx's request line.
  - Residual risk (query format only): nginx's own upstream errors (for
    example PHP-FPM unreachable) include the request line, and therefore the
    token. They are kept, because they are needed for diagnosis; restrict
    access to and retention of the nginx error log, or use fragment links.
  - A native PHP-FPM pool without `error_log` (e.g. Debian's default
    `www.conf`, used for local development) sends PHP errors through
    FastCGI, and nginx then logs them with the request line. Development
    only; never used for production.
- **Single use under concurrency.**
  - Redemption locks the invitation row, re-checks its state, creates the
    account and marks the invitation used with a conditional UPDATE, all in
    one transaction. The account exists only if consumption succeeded.
  - A second, concurrent attempt waits on the lock and is refused. A unique
    `users.email` index is the final backstop.
- **Privilege.**
  - Invitations can grant only Member or Viewer, enforced in the service,
    the form and a database CHECK. There are no administrator invitations.
  - Only `invitation.manage` (administrators) can issue, revoke or replace
    invitations.
- **Abuse.**
  - Failed link and e-mail-mismatch attempts count against a per-IP limit
    (`INVITATION_MAX_FAILURES` per `LOGIN_LOCKOUT_SECONDS`); throttled
    clients get 429 even for valid links.
  - Invalid, expired, revoked and used links all get the same generic page.
  - The invited address is never shown to the invitee; they must type it.
  - Every acceptance and rejection is audited, without tokens.

### Data export (Phase 4B)

- **Authorization.** All export routes require authentication.
  - Personal exports use the session's user id only. There is no user
    parameter, so there is nothing to tamper with; tests try `?user=…`.
  - The catalogue export requires `catalogue.export`. It excludes:
    - e-mail addresses and accounts, password hashes and sessions;
    - invitations, `login_attempts` and settings;
    - the audit log and the metadata cache;
    - wishlists, loans and private collections.
  - Administrators therefore cannot read private wishlists through exports.
  - Dataset names are whitelisted; unknown names give 404.
- **Spreadsheet formula injection** is neutralised (leading `'`).
- **Responses.** `Content-Disposition: attachment` with server-built
  filenames, `Cache-Control: no-store, private`, and `nosniff`. Nothing is
  stored server-side.
- **Bounded resources.** Streaming from keyset batches inside a read-only
  snapshot transaction. Each export is audited (`export.personal`,
  `export.catalogue`) with dataset and format only.

### Backups, restore and maintenance (Phase 4B)

- **CLI only.** There are no web routes for backups, restore, maintenance or
  cleanup.
- **No secrets in backups.** The database dump is read with the runtime
  account and holds data only. `.env`, Docker secrets, sessions and
  `login_attempts` rows are excluded. A test checks that the database
  passwords do not appear in an archive.
- **Storage.**
  - Archives are created with umask 077 (`0600` files, `0700` directory),
    and never inside `public/`.
  - Temporary files are removed on every exit path, and the archive is
    renamed into place atomically.
  - Archives still hold all library data, including e-mail addresses and
    borrower names. Store and transfer them as confidential.
- **Hostile archives.** `TarReader` accepts regular files with conservative
  relative names only. It rejects:
  - `..`, absolute paths and backslashes;
  - symbolic and hard links, devices, FIFOs and directories;
  - pax and GNU extended headers;
  - duplicates and bad checksums.

  Further protections:
  - Sizes are bounded, and the manifest may list only `database.sql` and
    cover-pattern paths.
  - Extraction happens only into a fresh `0700` work directory and re-hashes
    every byte against the verified manifest.
  - Cover files are placed by temporary file and rename, so symbolic links
    at the target are never followed (they are refused), and unrelated files
    are never touched.
  - The dump is executed by its length frames; statements are never
    reconstructed by parsing.
  - Before anything changes, every statement must match one of the shapes
    the dumper writes: the fixed `SET` statements, `CREATE TABLE … ENGINE=InnoDB`
    without `DATA/INDEX DIRECTORY` or `CONNECTION`, or `INSERT INTO`.
  - Each statement runs as a single prepared statement, so nothing can be
    appended after a `;`. A forged but correctly checksummed archive
    therefore cannot drop, grant, write files or touch other databases.
    Still, restore only archives you created.
  - Phase 5A: `INSERT` statements may carry only literal values (`NULL`,
    integers, quoted strings, `X'…'`), one per column; functions,
    subqueries, variables and comments are refused, so a forged archive
    cannot read other tables or call functions through the migration
    account.
- **Restore guards.** Maintenance mode is required, `--confirm=<database>`
  must be typed, and production also needs `--allow-production` (the
  maintenance check cannot be skipped there) and waits 35 s after
  maintenance:on as a best-effort grace period for in-flight requests (not
  a guarantee: stop the web PHP service for one; docs/OPERATIONS.md).
  - Verification runs first. Non-empty targets and differing covers are
    refused unless `--replace`; free space is checked before extraction.
  - A non-empty target is backed up first (safety backup); if that fails,
    nothing is changed.
  - Partial failure is reported as such, with exit code 2 and the rollback
    command; a restore into the live database leaves a marker that keeps the
    site in maintenance mode until a restore succeeds or an operator
    acknowledges it with `--acknowledge-incomplete-restore=<database>`;
    the acknowledgement is audited first and refused if it cannot be. Foreign-key integrity is checked after loading.
- **Role separation.** Operations commands (migrate, user creation, backup,
  restore, maintenance on/off, cleanup) are refused by the web container
  (`APP_ROLE=web`) and, in production, by anything but `APP_ROLE=ops`;
  this is enforced in code (`Console`), not only by which secrets are
  mounted.
- **Concurrency.** Backup, restore, migrate, cleanup and maintenance:off
  never overlap (`OperationLock`, kernel-released `flock`).
- **Retention.** `cleanup` is a dry run by default. Orphaned covers are
  report-only unless explicitly requested. The newest verified backup is
  never deleted. Loans, catalogue records and the audit log are never
  pruned.
- **Audit.** Events `backup.created`, `backup.restored` (written into the
  restored database), `maintenance.cleanup` and
  `maintenance.restore_marker_acknowledged`.

### Library management (Phase 4A)

- **Object-level checks everywhere.** Lending and returns check
  `canManageLoans()` in the controller and again in `LoanService` under row
  locks. Wishlist entries are owner-only (`canManageWishlistItem()`), with no
  administrator override. Collections use `canViewCollection()` and
  `canManageCollection()`. Records a user may not see are answered with 404,
  so IDs can't be probed. Records they may see but not change get 403.
- **Borrower privacy.** Only a name or a user link is stored, never contact
  details. The borrower and loan history are visible to the copy owner,
  administrators and the borrowing user. Everyone else sees only
  "On loan" and the due date. Audit entries for loans record copy ID,
  borrower user ID and due date, never the free-text name.
- **Integrity under concurrency.** A unique index on the generated
  `active_copy_id` column guarantees one active loan per copy, even if two
  requests race. Wishlist duplicate checks run under a per-user row lock,
  backed by a unique `(user_id, wanted_isbn_13)` index.
- **Collections** are private or shared. There is no public or anonymous
  visibility: the input is validated, the column is an ENUM, and the
  authorizer denies unknown values. New collections are private.
- **Audit-log viewer** (`audit.view`, administrators only) has GET routes
  only and no write methods. It re-redacts secret-looking keys (password,
  token, secret, CSRF, API keys, session, cookie, authorization, hash) and
  escapes every value, including the pretty-printed JSON. Each query is
  bounded: at most 366 UTC days, indexed filters, keyset pagination and a
  5-second statement time limit (`Database\Engine::boundedSelect()`:
  `SET STATEMENT max_statement_time` on MariaDB, which ignores MySQL's
  `MAX_EXECUTION_TIME` hint; the hint on MySQL). The viewer shows IP addresses and
  browser strings to administrators; treat exports of these pages as
  personal data.
- **Viewer limitations.**
  - The viewer doesn't make the log tamper-proof. The runtime database
    account must be provisioned with the restricted manifest below; old
    schema-wide grants remain unsafe until the owner applies that procedure.
  - Rows written before a redaction rule existed are masked on display only,
    not in the database.
  - The details search scans rows inside the chosen date range, which is
    acceptable at this project's scale but not indexed full-text.
  - Wishlist changes aren't audited by design (private, not catalogue
    data).

### Households (phases H1 to H4, development-only)

Household-based access control is being built in phases. Reads are
isolated since phase H2 and writes since phase H3. **It has not been
released**: it stays development-only until the owner decides otherwise
(a separate activation release is required), and production refuses to start with it enabled.
While the feature is disabled, every signed-in user with `catalogue.view`
sees the whole catalogue and every rule is the one from before households.

- **Fail closed.** `HOUSEHOLDS_ENABLED` defaults to `false`. In production
  the application ignores it and `config:check` reports it as an error, and the production image carries a root-owned production marker. The production
  Compose services pin APP_ENV and clear APP_ENV_FILE. These protect against
  env-file mistakes; changing the deployment artifact remains an owner action.
  While disabled, every `/admin/households` route answers 404 (also for
  administrators), the navigation entry is absent, and a `household_id`
  submitted with a copy is ignored.
- **Administration is administrator-only**: `household.manage` on the
  routes, re-checked in `HouseholdService`; CSRF on every POST; optimistic
  locking on rename; all changes audited. Members are recorded in the
  audit log by user id only (no names or e-mail addresses).
- **Membership** is read from the database on every request
  (`Security\HouseholdScope`), never cached in the session: adding or
  removing a member applies to that member's next request. Administrators
  reach every household. Deactivated accounts reach none and cannot be
  added to a household. New accounts belong to no household unless an
  administrator assigns one explicitly (on creation or on the invitation).
- **Copy assignment** goes through `Households\CopyHousehold` only. A
  non-administrator can place a copy only in a household they belong to;
  a household they may not use and one that does not exist produce the
  same response, so the form does not reveal household ids. Membership
  does not allow editing another member's copy (owner-only, unchanged).
- **Migration.** Existing copies and locations move to a temporary default
  household; only accounts that are active and have signed in become
  members. No row is deleted and no other column changes. The new columns
  are nullable, so the previous application version runs on the migrated
  schema; rows it writes have no household and are reachable by
  administrators only once enforcement lands.
- **Read isolation (H2).** With the feature enabled, a user reads only
  the inventory of their own households (copies, locations, loans), the
  bibliographic records those households hold, and the ones they
  catalogued themselves. `Security\Visibility` is the single definition
  for data-facing inventory reads. Internal lock/authorization helpers,
  explicit administrator diagnostics and bibliography-only ISBN lookup are
  deliberate exceptions; their call-site authorization remains necessary. Administrators are unrestricted.
  - A record outside the user's view answers **404, identical to a record
    that does not exist**, for GET and POST, before any permission check,
    validation or write. 403 is only given for records the user can see.
    This covers detail pages, edit and lending forms, covers and every
    state-changing route that loads a record.
  - Lists, search results, totals, page counts, dashboard figures, copy
    counts, filters and option lists are computed over visible records
    only. Users of other households are never listed or named (owner
    filter, borrower choice, collection owners).
  - Another household's holdings are never revealed: not its copies, copy
    counts, owners, locations, notes, prices, inventory codes, loans or
    borrowers. A user who catalogued a record keeps seeing its
    description after another household adds a copy, without that copy.
  - **Residual, accepted:** ISBN-13 is unique across the shared catalogue,
    so entering an ISBN that only another household holds reveals that
    the book is catalogued, with its bibliographic description and
    nothing else. (Shared collections were visible to every user in H2;
    since H3 they are visible to the household they are shared with.)
  - **Revocation** applies to the next request in the existing session:
    memberships are read from the database per request and never stored
    in the session; covers are served `private, no-cache` and the
    visibility check precedes the ETag comparison; exports are not cached.
  - Tests: `HouseholdReadIsolationTest` (a leak canary over every GET route
    that returns record data, identical-404 comparison, counts, revocation,
    feature-off behaviour) on MariaDB and MySQL; `VisibilityTest`;
    `HouseholdAccessMatrixTest`.
- **Write authorization (H3).** Every write that depends on household
  access runs in a guarded transaction (`Households\WriteGuard`): shared
  locks on the household rows, then the acting account and its memberships
  read again from the database, then the service's authorization repeated
  against the rows it locks. The controller's check is never the only one.
  - **Revocation and races.** `HouseholdService` removes a membership
    under the exclusive lock of the household row, so a removal waits for
    the writes in flight and every later write sees it: after an
    administrator is told a member was removed, no write authorized by
    that membership can still be committed. The same holds for a
    deactivated account. A first copy added by another household and an
    edit of the record lock the edition (and work) row against each other.
  - **404 before 403, for writes too.** A copy, loan, edition, collection
    or wishlist entry outside the actor's households is refused exactly
    like one that does not exist, also when it left their view between
    the form and the submit. Identifiers of another household's records
    (copy, loan, edition, cover, location, collection, household) open
    nothing and change nothing (`HouseholdWriteAuthorizationTest::
    testUnauthorizedRequestsModifyNothing` compares every table).
  - **Shared bibliographic records.** Administrators change any work or
    edition. A member changes one they created only while no household
    they do not belong to holds it; covers follow their edition; authors
    and publishers stay with administrators; seeing never implies
    editing.
  - **Inventory.** A copy is placed only in a household the actor may use,
    owned by someone who reaches it, in a location of that household.
    Inventory codes are unique per household (a database index over
    `COALESCE(household_id, 0)`, so copies without a household cannot
    repeat a code either); the "already in use" error only ever concerns
    the copy's own household. A copy on loan cannot change household.
  - **Add a copy by ISBN.** The edition is resolved from the ISBN on the
    server inside the transaction; an edition id from the request never
    reaches an edition the user cannot see. Only the shared description
    is shown.
  - **Loans.** A borrower account must be an active member of the copy's
    household at that moment, for administrators as well; a lender who no
    longer reaches the household can neither lend nor return. Loan
    history is never rewritten.
  - **Collections.** Sharing is with one explicitly chosen household; a
    collection never becomes shared or changes household on its own;
    lists, totals, the edition page and direct URLs follow the household;
    items out of the actor's view cannot be added, moved, removed or
    detected.
  - **Wishlists.** A link to a catalogue edition is stored only for an
    edition the user can see when saving, and dropped on the next save
    once it is out of view.
  - **Locations** are administrator-only, one tree per household; a
    location never moves to another household's tree.
  - **Accounts and invitations.** No membership by default. Assignments on
    an invitation are an intention only: memberships are created in the
    redeeming transaction, for households that still exist, so a revoked,
    expired, replaced or used link grants nothing. Membership changes are
    audited by account id, never by name or address.
  - **Lock failures.** A deadlock victim is run again; a lock wait timeout
    rolls back and is answered with a "try again" page. No partial write.
  - **Residual, accepted:**
    - a member who catalogued a record learns that some other household
      now holds it, because they can no longer edit it (the policy itself:
      "once another household holds the record, an administrator edits
      it"). The refusal is the generic 403 and the page shows a neutral
      note; nothing identifies the household, owner, count or location;
    - ISBN-13 stays unique across the shared catalogue, so an ISBN that
      only another household holds is still reported as catalogued, with
      its bibliographic description only (as in H2);
    - authors, publishers and categories are one shared vocabulary.
  - Tests, on MariaDB 11.4 and MySQL 8.4: `HouseholdWriteAuthorizationTest`,
    `HouseholdWriteConcurrencyTest` (second connection and second process:
    removal in flight, real deadlock, parallel inventory codes, loan
    against removal), `HouseholdPhaseThreeMigrationTest`,
    `HouseholdFeatureDisabledTest`, `HouseholdAccessMatrixTest`.
- **H4 implemented in development:** reviewed bounded inventory transfers.
  Nullable household columns and the default household remain intentionally;
  production activation still requires a separate release.

### Request integrity

- **CSRF:** a per-session synchroniser token (`hash_equals`) is required on
  every non-GET/HEAD/OPTIONS request. Requests are also rejected when
  `Origin` doesn't match `APP_URL` or `Sec-Fetch-Site: cross-site` is sent.
- **Open redirects:** `Response::redirect()` accepts only local absolute
  paths (no `//`, backslashes or control characters).
- **Header injection:** header values containing CR or LF are refused.

### Injection and output handling

- **SQL:** PDO with native prepared statements everywhere. Identifiers are
  never taken from user input.
- **XSS:** all template output passes through `htmlspecialchars(ENT_QUOTES |
  ENT_SUBSTITUTE | ENT_HTML5)`, including translation parameters. The strict
  CSP (`default-src 'self'`, no `unsafe-inline`, `object-src 'none'`,
  `frame-ancestors 'none'`, `base-uri 'none'`, `form-action 'self'`)
  provides defence in depth.
- **Catalogue search** builds SQL dynamically but binds every value
  (`Database\Params`). LIKE wildcards in user input are escaped, so `%`
  doesn't match everything. Tests cover quote and comment injection
  attempts. Numeric ids from routes and filters must match `^[1-9]\d{0,9}$`
  or the request gets a 404, or the value falls back to a default.
- **Template and locale names** are restricted to a safe character set, so
  path traversal isn't possible.
- **Input validation:** server-side `Validation\Validator`. Errors are
  translation keys, and submitted passwords are never echoed back.

### File uploads (`Upload\CoverImageProcessor`)

- Accepts only genuine HTTP uploads (`is_uploaded_file`), with a size limit
  and a MIME type sniffed from the content. The client-supplied name and type
  are ignored.
- Allows JPEG, PNG and WebP only, and limits dimensions and pixel count to
  guard against decompression bombs.
- **Every image is fully re-encoded** with GD into a new JPEG. This strips
  metadata and any appended or polyglot payload.
- Files are stored **outside the web root** under random 128-bit names. A
  smaller re-encoded thumbnail is stored alongside. Storage keys are
  validated against a strict pattern before any filesystem access.
- **Serving (Phase 2):** `GET /covers/{id}` requires a signed-in user with
  `catalogue.view` and looks covers up by numeric id only. It responds with
  `image/jpeg`, `nosniff`, `Content-Security-Policy: default-src 'none';
  sandbox` and `Cache-Control: private`. Guests are redirected to sign-in
  and never receive image bytes.
- Only users who can edit the edition may upload or delete its covers.
  Failed database writes remove the stored files; deleting a cover removes
  both files.

### Infrastructure

- **Least-privilege database accounts:** runtime has SELECT plus reviewed table writes;
  migrations use a separate DDL-capable account. MariaDB has no published port.
  `local-infile` is off. The MariaDB image's `healthcheck` accounts
  (`localhost`, `127.0.0.1`, `::1`) hold `USAGE` only; root exists only as
  `root@localhost` (CI checks the account list and grants).
- **Database start guard:** the db entrypoint refuses to initialise an
  empty data directory without the owner's first-deployment authorisation,
  and refuses a data directory whose initialisation did not finish (no
  `.dla-init-complete`, written after every account and grant) or that
  holds MySQL 8 files.
- **Production CPU:** the database, PHP and nginx images run on the
  x86-64 baseline (no SSE4.1/SSE4.2/POPCNT needed); see ARCHITECTURE.md,
  "Production database". CI checks the built images on every run.
- Nginx executes only the front controller. Dotfiles, source, `.env` and
  `vendor/` aren't reachable. `server_tokens` is off.
- PHP: `expose_php=Off`, `display_errors=Off`, `allow_url_fopen=Off`. FPM
  disables process-execution functions.
- Production images run as `www-data` (PHP) and `nginx` (port 8080) with
  code and configuration owned by root, read-only root filesystems, all
  capabilities dropped and `no-new-privileges` (`docker-compose.prod.yml`).
  No production service publishes a port; application and database networks
  are internal.
- **Credential separation:** the web container (`APP_ROLE=web`) holds only
  the runtime database account; migration or root credentials there are a
  configuration error. Migrations, backups and restores run in the one-off
  `ops` container. The MariaDB root password exists only in the `db` container
  (`root@localhost`; `MARIADB_ROOT_HOST=localhost`).
- **Persistent storage (Phase 5C, split layout):**
  - MariaDB data (`999:999`, 0700, db only) and the secrets are on the
    system SSD in `/opt/digital-library-data`, outside the deployed working
    tree `/opt/digital-library` (code only, from the bare repository; no
    `.git` inside), so outside Git and every build context; not writable
    by the deploying user; checked by the preflight, which also refuses
    data or `.git` in the working tree.
  - The preflight runs as root at boot, so systemd runs a root-owned
    installed copy (`/usr/local/sbin/digital-library-preflight`), never
    the user-writable working tree's; a checkout cannot change it.
    `docker compose` still reads the working tree as root, so it must stay
    writable by the deploying administrator only (DEPLOYMENT.md 3.4).
  - Covers, backups (ops only) and application storage are on the storage
    HDD under `/mnt/bravina-shared/digital-library`.
  - All are bind mounts with `create_host_path: false`. There are no named
    volumes, and `/mnt/dockerdata` is never used.
  - A database guard in the db container refuses to initialise a new,
    empty database without an explicit first-deployment authorisation.
  - A read-only preflight verifies the HDD UUID and mountpoint, both
    layouts, owners and modes, and the database state. Restart policies are `"no"`, so the Docker daemon never
  starts the stack by itself. A dedicated `digital-library.service` (not a
  change to `docker.service`) is the only boot path and runs the preflight
  first (docs/DEPLOYMENT.md, sections 3.4 and 3.5).
- **Fail-closed start-up:** the PHP container runs `config:check` before
  PHP-FPM; the nginx container refuses to start without an explicit,
  placeholder-free client IP policy acknowledged by `DLA_TRUSTED_PROXY`.
- Base images are pinned by version and digest.
- **Secrets** come from the environment or `*_FILE` Docker secrets. `.env` is
  git-ignored, and `.env.example` contains placeholders only.

### Error handling and logging

- Production error pages show a generic, translated message. The exception
  class, message and location go to the server log only. Debug output can
  never be enabled when `APP_ENV=production`, and a missing `APP_ENV` counts
  as production.
- Database connection errors are reduced to a SQLSTATE code; DSNs and
  credentials never reach logs or pages.
- **Audit log:** sign-ins, failed sign-ins (with reason), lockouts, sign-outs,
  password changes and user creation are recorded with user, IP and user
  agent. Keys such as `password`, `token` and `secret` are redacted
  automatically.
- Every dynamic response is sent with `Cache-Control: no-store` and
  `X-Robots-Tag: noindex`.


Invitation replacement and redemption take shared household locks before
invitation/account locks, avoiding an inverse order with household deletion.
With the feature disabled, pending assignments are ignored during redemption
and are not carried into replacement invitations.

Location tree mutations take exclusive household locks in id order before
account and location locks, including with the feature disabled. This
serialises root-name checks and disjoint subtree moves: the nullable
parent unique index alone cannot prevent duplicate roots or concurrent cycles.
Ordinary guarded writes retain shared household locks when enabled.

Application rollback retains the H3 schema; it does not restore global
inventory-code uniqueness. If H3 has allowed the same code in different
households, the previous application's global pre-check can refuse edits
of those copies, including an unchanged code. Review and resolve such
collisions explicitly before reverting to global uniqueness; never drop
copies or silently rewrite codes. Disabling the feature also restores the
global application pre-check, with the same collision limitation. There is
no automatic down migration; an older backup loses subsequent writes.



## Known limitations and follow-ups

- Households (phases H1 to H4): development-only. Reads and writes are
  isolated per household; see "Households" above for the accepted
  residuals. Not released: do not enable in production (the application
  refuses) until the owner decides, after phase H4.
- Phase 4B:
  - Without trusted-proxy configuration, the per-IP invitation limit applies
    to the proxy's address, as the login limit does.
  - Backups are not encrypted by the application. Protect them with
    filesystem permissions and encrypt off-site copies (for example with
    age or GPG) under your own key management.
  - The catalogue export can be large. It is administrator-only, streamed
    and bounded in memory, but not rate-limited.
  - Invitation e-mail delivery is not implemented. Administrators pass links
    on themselves, over a channel they trust.
- Audit row preservation is enforced for correctly provisioned runtime accounts (see
  [Audit log integrity review](#audit-log-integrity-review)).
- Without trusted-proxy configuration, the per-IP login limit applies to
  the reverse proxy's IP, which lets one attacker lock everyone out. A
  too-broad trusted range lets clients forge their IP. **Blocker:** the
  production nginx refuses to start until an approved policy exists
  (docs/DEPLOYMENT.md, section 0;
  [ARCHITECTURE.md › Client IP behind a reverse proxy](ARCHITECTURE.md#client-ip-behind-a-reverse-proxy)).
- The owner reports H1–H4 GitHub Actions CI passed. This remediation does not
  independently inspect remote CI or production. Its local verification is
  recorded in docs/H1_H4_REMEDIATION.md; a new owner-run CI pass is required.
- PHP-FPM listens on port 9000 on all of the php container's networks,
  including `egress` (needed for ISBN lookups). Only containers of this
  Compose project share those networks, but FastCGI has no authentication:
  keep the Docker host's forwarding rules strict (current Docker Engine
  versions block routed access to unpublished container ports from other
  hosts) and never attach other containers to the project's networks.
  Unverified on the production host.
- Ingress uses the existing Cloudflare Tunnel (cloudflared systemd service
  on the Docker host) and nginx's one published port on `127.0.0.1`. The
  cloudflared-to-nginx leg is plain HTTP over loopback. nginx believes
  `CF-Connecting-IP` from the `edge` network gateway, the peer of every
  connection to that port. So every local process on the Docker host can
  choose its logged client address and its rate-limit bucket. Keep the
  host's local users and services trusted. Unverified on the production
  host until the `peer=` check (DEPLOYMENT.md 0.4).
- Login throttling uses fixed thresholds with no progressive back-off or
  CAPTCHA. Account lockout can be triggered deliberately by someone who knows
  a user's e-mail (the trade-off accepted by NIST guidance).
- There's no MFA or password-reset flow yet (e-mail delivery isn't
  configured). Administrators reset passwords in Phase 2.
- `login_attempts` is pruned by `cleanup --apply` (task `login-attempts`),
  which still has to be scheduled on the production host.
- Native PHP sessions are file-based inside the PHP container. Use a
  persistent volume or a DB-backed handler if you run multiple PHP replicas.

### Phase 2 notes

- Every signed-in user, viewers included, can see who owns each copy and
  where it's stored (display names only, never e-mail addresses). That
  suits a private shared household catalogue; reconsider if the user base
  widens.
- Uploads larger than PHP's `post_max_size` (8 MB) arrive without a body, so
  they fail the CSRF check and show the "form expired" page rather than a
  size error. Files between `UPLOAD_MAX_BYTES` (5 MB) and that limit get a
  proper size error.
- Concurrent edits of works, editions and copies are detected since Phase 3
  (`row_version`). Categories, authors and publishers still use
  last-write-wins; their edits are admin-only and audited. Locations carry
  optimistic row versions since H4.
- Search uses LIKE with leading wildcards, which scans the editions table.
  That's fine at personal-library scale; revisit beyond tens of thousands
  of editions.
- Audit actions added in Phase 4B: `invitation.created`, `invitation.revoked`,
  `invitation.replaced`, `invitation.accepted`, `invitation.rejected` (reason only),
  `user.created` (via invitation), `export.personal`, `export.catalogue`,
  `backup.created`, `backup.restored`, `maintenance.cleanup`.
- Audit actions added in Phase 4A: `loan.created`, `loan.returned`,
  `collection.created`, `collection.updated`, `collection.deleted`,
  `collection.item_added`, `collection.item_removed`.
- Audit actions added in Phase 3: `user.updated`, `user.password_reset`,
  `user.sessions_revoked`, `author.merged`, `author.deleted`,
  `publisher.merged`, `publisher.deleted`, `cover.imported`.
- Audit actions added in Phase 2: `work.*`, `edition.*`, `copy.*`,
  `cover.uploaded`/`cover.deleted`, `author.updated`, `publisher.updated`,
  `category.*`, `location.*`.

## Audit log integrity review

**Intended provisioned state (post-merge hardening).** Runtime has schema
SELECT (including audit for the viewer and complete backups) and reviewed
per-table writes from `Database\RuntimeGrants`. Audit allows INSERT only;
users allows INSERT/UPDATE but no DELETE or REPLACE. This prevents direct
runtime alteration/deletion and the audit actor FK's indirect ON DELETE SET
NULL. Runtime has no DDL, TRIGGER, GRANT OPTION or provisioning authority.
Domain events still share their mutation's PDO/transaction; permission errors
roll back the domain action, never silently disable logging.

First database initialization grants runtime **SELECT only**. After migration,
the owner applies the SQL generated by `php bin/runtime-grants DATABASE USER`.
Tables must exist before their write grants. New tables need an explicit
manifest decision. Initializer updates do not retrofit existing databases;
adding narrow grants cannot negate old global/schema/role grants. Existing
installations remain on their old protection until the owner reconciles grants
and reconnects workers. Follow
[the owner-only procedure](docs/AUDIT_GRANTS_LOGOUT_HARDENING.md).

This protects retained rows against runtime SQL tampering, not full forensic
integrity. Compromised runtime/PHP can still forge INSERTs, omit events, read
logs or damage authorized domain data. Migration/DBA/host compromise and
privileged restore/retention can change history. Independent off-host evidence
and retention/privacy policy remain owner decisions; no separate database,
trigger, hash chain or collector is introduced. Raw audit/backup data can contain
personal information even when the viewer redacts it.

**Explicit logout:** session data and identifier are invalidated and CSRF rotates
before attempting audit. A failed audit insert produces a fixed server warning
and a translated notice that recording failed; no success event is fabricated.
Login/session/cookie atomicity and operational/filesystem effects remain outside
the domain database transaction guarantee.

## Production deployment checklist

Procedures: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md). Nothing below has been
done on the production host yet.

**Configuration and secrets**
- [ ] `.env.production` from `.env.production.example`, reviewed: `APP_ENV=production`,
      `APP_DEBUG=false`, `APP_URL=https://library.bravina.co.uk`,
      `SESSION_SECURE_COOKIE=true`, `SECURITY_HSTS=true`,
      `INVITATION_LINK_FORMAT=fragment`
- [ ] Unique random secrets in `/opt/digital-library-data/secrets/` with the owners and modes of
      DEPLOYMENT.md section 2, copies kept in your password manager
      (OPERATIONS.md, "Secrets to preserve")
- [ ] `config:check --strict` passes in the `php` (`APP_ROLE=web`) and `ops`
      (`APP_ROLE=ops`) containers
- [ ] `METADATA_PROVIDERS` decided (privacy); if enabled, host egress limited
      to the provider domains and any API key supplied as a secret file

**Ingress and client IP (existing tunnel, cloudflared on the Docker host)**
- [ ] A free `DLA_HTTP_PORT` and a free `edge` subnet checked and set
      (DEPLOYMENT.md 0.1); `DLA_HTTP_BIND=127.0.0.1`
- [ ] Only nginx publishes a port, on `127.0.0.1` only (`ss -Hltn`); not
      reachable from another machine
- [ ] Route `library.bravina.co.uk` added to the existing tunnel only; no
      other route, tunnel or DNS record changed; HTTP redirected to HTTPS
      for this hostname
- [ ] `peer=` in the access log confirmed to be `DLA_EDGE_GATEWAY` for
      tunnel requests; then `docker/nginx/production/client-ip.conf` (the
      cloudflared template with that address) and `DLA_TRUSTED_PROXY=required`
- [ ] The tunnel forwards `Host: library.bravina.co.uk` (route "HTTP Host
      Header" / `originRequest.httpHostHeader` unset or equal); other hosts
      get 400; the session cookie is `__Host-…; secure`
- [ ] Signed in through the tunnel: the audit log shows your public address;
      a request to the port without `CF-Connecting-IP` gets 403
- [ ] The application is not reachable except through the approved ingress
- [ ] If query-format invitation links are used: Cloudflare and cloudflared
      (log level info or lower) verified not to retain `/invite?token=` URLs;
      nginx error log access and retention restricted

**Persistent storage**
- [ ] SSD and HDD directories initialised as in DEPLOYMENT.md 3.2; HDD
      mountpoint still `root:root` 0755; no `chmod 777` or recursive change;
      `books/` not created
- [ ] Code deployed only by `git --git-dir=/home/brais/git/digital-library.git
      --work-tree=/opt/digital-library checkout -f main` as the deploying
      user, never a clone or `git clean -x` there (DEPLOYMENT.md 3.2, 4)
- [ ] Secrets in `/opt/digital-library-data/secrets` (DEPLOYMENT.md section 2)
- [ ] Preflight installed root-owned at `/usr/local/sbin/digital-library-preflight`
      from the reviewed release, reinstalled after every reviewed change
- [ ] First deployment done with the authorisation file, which was removed
      afterwards; `/usr/local/sbin/digital-library-preflight --env-file
      /opt/digital-library/.env.production --existing-installation` passes
- [ ] `digital-library.service` installed (working tree `/opt/digital-library`) and
      enabled; every container shows restart policy `no`; immutable
      mountpoint decided (DEPLOYMENT.md 3.4)
- [ ] Fail-closed drill done once: with the disk unmounted,
      `systemctl start digital-library` fails and nothing is created under
      `/mnt/bravina-shared`
- [ ] External `/health` monitoring in place (crashed containers are not
      restarted automatically; DEPLOYMENT.md 3.4)

**Images, stack and CI**
- [ ] CI executed and green on GitHub (all four jobs, including
      `production-stack`); shellcheck findings resolved
- [ ] Images built on the Docker host from the release commit
      (`APP_IMAGE_TAG`); base-image digests refreshed deliberately
- [ ] `docker compose -f docker-compose.prod.yml config` reviewed: no
      published port except nginx on `DLA_HTTP_BIND`, no extra mounts
- [ ] Every health check healthy; `/health` ok through the ingress
- [ ] `migrate` run from the `ops` container; `migrate:status` shows nothing
      `pending` or `MODIFIED`
- [ ] First administrator created via `user:create`, with a strong passphrase

**Backups and recovery**
- [ ] `backup:create` and `cleanup --apply` scheduled from the `ops`
      container; failures alert you
- [ ] Off-host, encrypted copies of archives and `.sha256` files, verified
- [ ] One restore drill done on the production host, time recorded (RTO)
- [ ] Recovery point objective decided (backup frequency)

**Open decisions**
- [ ] Audit-log integrity decision implemented (see review above)
- [ ] PHP-FPM worker count and MariaDB memory settings measured and tuned
      (DEPLOYMENT.md, "Resource planning")


## Household inventory management (H4, development-only)

Cross-household movement is administrator-only, including the existing copy-edit
endpoint, which now refuses household and owner changes while enabled. Members
retain same-household own-copy editing. H4 GET/POST routes require household.manage
and feature enablement, with fresh service-level administrator authorization.
Plans, ids and digests confer no privileges. All POSTs retain CSRF. Selection
ids/versions are exact and bounded; stale or invalid inputs reject the whole
transaction. Destination owners/accounts are locked and revalidated.

Transfers retain copy IDs and every loan relationship, reject active loans and
never grant membership or change ownership implicitly. Separate ownership
reassignment rejects any loan history to prevent newly exposing borrowers under
the existing owner-based authorization model. Household transfers keep the same
owner and preserve existing borrower/owner/admin history permissions.

Application, immutable completion and audit writes commit together; retries are
idempotent. Location subtrees are never split automatically. Default and NULL
assignments stay distinct. New tables retain evidence rather than cascading it
away when a household/location/copy changes or disappears.

Production checks in ConfigValidator and Services remain intact. inventory:check
is a read-only local rehearsal command with explicit environment, maintenance,
operator acknowledgements and exclusive operations-lock checks; it has no apply
or production mode. See docs/HOUSEHOLD_INVENTORY.md. Turning off enforcement is
not a privacy-safe rollback: disabled mode reveals the catalogue installation-wide.


## H1–H4 audit remediation (2026-11-15)

Administrative domain writes now reload an active administrator inside a
READ COMMITTED transaction and keep account locks until commit. Household locks
precede sorted account locks. Account administration takes all existing account
rows exclusively in id order before checking the active-administrator set;
other administrative operations take shared actor/required-account locks.
This broad account lock is deliberate for a small installation. The route check
and a previously captured User never authorize the write on their own.
An authorized write can commit before a blocked revocation; after revocation
commits, a new guarded mutation is denied. Last-admin and self-change safeguards,
CSRF and session auth_version behavior remain. Borrower and chosen-owner accounts
are also locked before catalogue rows; invitation redemption locks its issuer
account before the invitation to keep FK/audit references in the same order.

Domain audit events now share the mutation's PDO and transaction: households,
accounts (including password changes and CLI creation), invitations/redemption,
works/editions/covers/copies, loans, collections, locations and administrative
vocabulary changes. Audit insert failure rolls back the domain change. Deadlock
retries roll back failed attempts and their audit events; they run DB-only work.
H4 transfer audit/idempotency remains unchanged. This is atomic accountability,
not tamper-proof logging or general request idempotency. Runtime audit UPDATE and
DELETE grants still need the owner's separate integrity decision above.

Session/cookie and filesystem effects cannot participate in an InnoDB transaction.
Login/logout, backup/restore/cleanup and cover-file cleanup therefore retain the
explicit limits assessed in docs/H1_H4_REMEDIATION.md. Export audit records a
request, not proof of delivery: its attempt is durable before streaming, and
catalogue streams revalidate and hold the account lock through the final batch.

Production images now contain config/production-image (created only in the prod
Docker build, root-owned and read-only). Its presence forces production behavior
regardless of APP_ENV or APP_ENV_FILE, including both existing household guards.
Production Compose also pins APP_ENV=production and clears APP_ENV_FILE. Local
household tests require an explicit testing/development environment outside the
production image. These repository changes require an owner-built new artifact;
no running production configuration or functionality was changed.

Migration checksums are checked by migrate itself. Durable migration-attempt
metadata blocks replay after incomplete DDL or a failed history insert. DDL files
remain non-atomic on both engines; see docs/OPERATIONS.md for manual recovery.
The original findings remain in docs/FINAL_H1_H4_AUDIT.md, with their dispositions.
