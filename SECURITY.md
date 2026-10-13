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
- Files are stored **outside the web root** under random 128-bit names, and
  will be served only through an authorised controller (Phase 2). Storage
  keys are validated against a strict pattern.

### Infrastructure

- **Least-privilege database accounts:** the web application has DML only;
  migrations use a separate DDL-capable account. MySQL has no published port.
  `local-infile` is off.
- Nginx executes only the front controller. Dotfiles, source, `.env` and
  `vendor/` aren't reachable. `server_tokens` is off.
- PHP: `expose_php=Off`, `display_errors=Off`, `allow_url_fopen=Off`. FPM
  disables process-execution functions.
- Production images run as `www-data` with code owned by root (read-only).
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

- The audit log is append-only by convention only (see
  [Audit log integrity review](#audit-log-integrity-review)).
- Without trusted-proxy configuration, the per-IP login limit applies to
  the reverse proxy's IP, which lets one attacker lock everyone out. A
  too-broad trusted range lets clients forge their IP. **Blocker:**
  configure and verify it before deployment, following
  [ARCHITECTURE.md › Client IP behind a reverse proxy](ARCHITECTURE.md#client-ip-behind-a-reverse-proxy).
- The production (Alpine) image has never been built or tested. **Blocker**
  until verified.
- Login throttling uses fixed thresholds with no progressive back-off or
  CAPTCHA. Account lockout can be triggered deliberately by someone who knows
  a user's e-mail (the trade-off accepted by NIST guidance).
- There's no MFA or password-reset flow yet (e-mail delivery isn't
  configured). Administrators reset passwords in Phase 2.
- `login_attempts` needs periodic pruning (`LoginThrottle::prune()`); a
  scheduled job is planned.
- Native PHP sessions are file-based inside the PHP container. Use a
  persistent volume or a DB-backed handler if you run multiple PHP replicas.

## Audit log integrity review

**Current state.** The runtime account holds `SELECT, INSERT, UPDATE,
DELETE` on the whole database, so an attacker who controls the application
(e.g. through a future SQL injection or code execution bug) could alter or
erase `audit_log` rows. It cannot `TRUNCATE` or `DROP` the table: those need
the `DROP` privilege, which it lacks. The application itself never updates
or deletes audit rows.

**Can MySQL enforce append-only?** Options evaluated against MySQL 8.4:

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

- [ ] `APP_ENV=production`, `APP_DEBUG=false`
- [ ] `APP_URL=https://library.bravina.co.uk` (exact origin)
- [ ] `SESSION_SECURE_COOKIE=true`, `SECURITY_HSTS=true`
- [ ] Unique random secrets for the app, migrator and root accounts, supplied
      as Docker secrets
- [ ] Trusted-proxy (`set_real_ip_from`) configured with the verified proxy
      address only, and verified via the audit log
- [ ] Alpine `dev` and `prod` images built; full test suite passes inside
      them with `--fail-on-skipped` (includes the 9 GD tests)
- [ ] CI workflow executed and green
- [ ] Audit-log integrity decision implemented (see review above)
- [ ] `prod` image target in use (no bind mounts, no Composer, runs as `www-data`)
- [ ] MySQL data and `storage/covers` volumes included in backups; restore tested
- [ ] `php bin/console migrate` run, and `migrate:status` shows no `pending`/`MODIFIED`
- [ ] First administrator created via CLI, with a strong passphrase
- [ ] The application isn't reachable except through the reverse proxy
