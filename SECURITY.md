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
    account can still `UPDATE`/`DELETE` audit rows (see the review below);
    the separation recommended there is still pending and wasn't
    implemented in Phase 4A.
  - Rows written before a redaction rule existed are masked on display only,
    not in the database.
  - The details search scans rows inside the chosen date range, which is
    acceptable at this project's scale but not indexed full-text.
  - Wishlist changes aren't audited by design (private, not catalogue
    data).

### Households (phase H1, development-only)

Phase H1 prepares household-based access control. **It is not a security
boundary yet**: every signed-in user with `catalogue.view` still sees the
whole catalogue. Enforcement on read paths (H2) and write paths (H3) ships
together, and no household functionality is enabled in production before
that.

- **Fail closed.** `HOUSEHOLDS_ENABLED` defaults to `false`. In production
  the application ignores it and `config:check` reports it as an error, so
  the incomplete feature cannot be exposed by a configuration mistake.
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
  added to a household. New accounts, however created, belong to no
  household.
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
- **Known gaps until H2/H3** (each listed per route in
  `config/household_access.php`): search, direct URLs, dashboard
  statistics, cover images, loans and borrower lists, author and publisher
  pages, duplicate-ISBN and similar-title prompts, collections, wishlist
  links and exports are not yet filtered by household; shared metadata can
  still be edited by its creator regardless of who else holds the title;
  locations are one global tree.

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

- **Least-privilege database accounts:** the web application has DML only;
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

## Known limitations and follow-ups

- Households (phase H1): development-only and not an access boundary; see
  "Households" above. Do not deploy before phases H2 and H3 are complete.
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
- The audit log is append-only by convention only (see
  [Audit log integrity review](#audit-log-integrity-review)).
- Without trusted-proxy configuration, the per-IP login limit applies to
  the reverse proxy's IP, which lets one attacker lock everyone out. A
  too-broad trusted range lets clients forge their IP. **Blocker:** the
  production nginx refuses to start until an approved policy exists
  (docs/DEPLOYMENT.md, section 0;
  [ARCHITECTURE.md › Client IP behind a reverse proxy](ARCHITECTURE.md#client-ip-behind-a-reverse-proxy)).
- The production images have never been built or run (CI has not executed).
  **Blocker** until the `production-stack` CI job and a production-host
  deployment have verified them.
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
  (`row_version`). Categories, locations, authors and publishers still use
  last-write-wins; their edits are admin-only and audited.
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

**Current state.** The runtime account holds `SELECT, INSERT, UPDATE,
DELETE` on the whole database, so an attacker who controls the application
(e.g. through a future SQL injection or code execution bug) could alter or
erase `audit_log` rows. It cannot `TRUNCATE` or `DROP` the table: those need
the `DROP` privilege, which it lacks. The application itself never updates
or deletes audit rows.

**Can the database enforce append-only?** Options evaluated against MySQL 8.4
(MariaDB 11.4 has the same grant model, so the conclusions hold):

| Option | Assessment |
|---|---|
| Revoke `UPDATE`/`DELETE` on `audit_log` only | Not possible: a database-level grant can't be partially revoked for one table (`partial_revokes` applies to global privileges only). |
| `BEFORE UPDATE/DELETE` triggers that raise an error | Tested: the migration account gets error 1419. With binary logging on (the default), creating triggers needs `SUPER` or a server-wide `log_bin_trust_function_creators=1`. Both weaken the least-privilege model. **Not recommended.** |
| Per-table grants (app gets only `SELECT, INSERT` on `audit_log`) | Enforced by MySQL. But every new table then needs an explicit grant from an account with `GRANT OPTION`, which the migration account should not hold. That's an operational burden and easy to get wrong. |
| **Separate audit database** (e.g. `library_audit`), app granted only `SELECT, INSERT` there | Enforced by MySQL with simple database-level grants and no per-table maintenance. InnoDB supports the cross-database foreign key to `users`. Costs: one more database to create, back up and mirror for tests. |
| Ship audit events off-host (syslog/log collector) as well | Protects against database-level tampering by any account, including the migrator. Complements, not replaces, the above. |

**Recommendation.** Before production, move `audit_log` into a separate
audit database where the runtime account has only `SELECT, INSERT`.
Optionally, also forward audit events off-host. Not required for local
integration of Phase 1; awaiting the owner's decision.

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
