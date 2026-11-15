# Post-merge audit grants and logout hardening

Date: 2026-11-15. Branch: `security/audit-grants-logout`.
Base: `89c2f07` (owner's H1–H4 merge). Starting tree was clean.
This is a separate hardening change; no production action or household activation
is authorized. Everything is unstaged/uncommitted. Historical H1–H4 reports are
preserved; this report supersedes their R01/R02 implementation status only.

## Root causes and implemented boundaries

**Audit:** the runtime account inherited database-wide INSERT/UPDATE/DELETE.
The logger never needed audit UPDATE/DELETE, but those broad grants allowed
compromised SQL credentials to change old events. The released audit FK also
uses `ON DELETE SET NULL` for users: deleting an otherwise unreferenced user can
change audit attribution without directly updating audit. Service deactivation
alone did not constrain SQL credentials.

`Database\RuntimeGrants` is now the reviewed write manifest. It grants:

| Scope | Runtime capabilities and rationale |
|---|---|
| Application schema | SELECT only, including future tables: complete logical backup enumeration and current viewer/repository reads |
| audit_log | INSERT only; no UPDATE, DELETE, REPLACE-overwrite, UPSERT-overwrite or DDL |
| users | INSERT/UPDATE only; account administration deactivates, never deletes. No DELETE or REPLACE-overwrite, preventing the actor FK cascade |
| authors, publishers, works, work_authors, editions, edition_contributors, categories | INSERT/UPDATE/DELETE for catalogue edits, merge credit changes and deletion |
| work_categories | INSERT/DELETE for category assignment replacement |
| covers, copies, locations | INSERT/UPDATE/DELETE for authorized inventory/cover/tree operations |
| collections, collection_items, wishlist_items | INSERT/UPDATE/DELETE for personal/shared list edits and ordering |
| invitations | INSERT/UPDATE/DELETE for issue/consume/revoke/replace and expired-unused cleanup |
| invitation_households | INSERT only; invitation deletion cleans these via FK |
| households | INSERT/UPDATE/DELETE for household administration |
| household_members | INSERT/DELETE for grants/removal |
| loans | INSERT/UPDATE, no runtime history deletion |
| metadata_records | INSERT/UPDATE for lookup/cache refresh, no runtime purge |
| login_attempts | INSERT/DELETE for throttling and expiry cleanup |
| inventory_transfers | INSERT/UPDATE for saved plans/status/completion |
| inventory_transfer_items | INSERT only; saved items are immutable evidence |
| roles, settings, schema_migrations, schema_migration_attempts | Read-only to runtime; seed/bookkeeping writes belong to migrator |

No schema-wide runtime writes, DDL, TRIGGER, FILE, GRANT OPTION, root or
provisioning credentials are added. `bin/runtime-grants` is an offline SQL
generator: it loads no .env/Bootstrap, connects nowhere, changes nothing and reads
no passwords. The owner/DBA applies its reviewed output **after tables exist**.
It does not revoke historical grants or certify effective permissions.

First-init now grants SELECT only; MySQL refuses table write grants before table
creation. Normal writes fail until explicit post-migration provisioning. Neither
container startup nor migrate automatically elevates the runtime account. New
tables require a manifest write/read-only decision (enforced by a unit test) and
owner provisioning. SELECT remains schema-wide so backups do not silently omit
new tables due to table visibility; new-table writes fail closed.

Database-grant underscores are escaped for the supported default grant model.
For MySQL, owner verifies `partial_revokes=OFF` before using this generated
schema SELECT syntax; ON changes database-name interpretation and needs a
separately reviewed grant plan. MariaDB 11.4 uses the normal wildcard grant model.
The generator does not alter server settings. Table-qualified write grants name
literal tables. Live global/role/alternate-account grants still require inspection.

**Locking compatibility:** restricted MySQL runtime cannot perform the old
FOR UPDATE join on read-only roles. `Engine::exclusiveAliasLock` selects
MySQL FOR UPDATE OF u or MariaDB FOR UPDATE for the active-administrator join.
The same user rows remain locked for the same transaction lifetime; role codes
are static/read-only to runtime. No write permission on roles or lock-table
privilege is introduced. Existing revocation/last-admin/concurrency regressions
remain required on both engines.
Shared LOCK IN SHARE MODE reads work with SELECT-only roles on both engines
and remain unchanged, including catalogue export.

**Atomicity:** logger/domain services retain the same PDO and transaction. A
missing audit INSERT privilege raises a real DB error and rolls the domain
mutation back. This change does not introduce a second logger connection,
outbox, separate audit schema, trigger, hash chain or off-host service.

**Logout:** the old controller attempted audit before invalidation; an exception
could skip logout entirely. It now invalidates session data/identifier and
rotates CSRF **before** constructing/using the logger. On audit failure it writes
only the fixed server diagnostic:

```text
[digital-library] auth.logout audit recording failed; session invalidated
```

It redirects to login with a translated warning that sign-out succeeded but the
audit record could not be saved. No exception/SQL values reach that notice, even
in debug mode; no durable success event is fabricated. Successful logout still
inserts auth.logout. CSRF/POST/authentication middleware remain unchanged.
This does not promise logout during a complete DB outage before authentication
middleware can resolve the actor, nor redesign native sessions or login atomicity.

## Existing installations — owner-only procedure

**Not executed here.** Target is the owner's selected installation/database,
with explicit separate deployment authorization. SQL grant changes require DBA/
provisioning authority; runtime and migrator deliberately cannot perform them.
The commands below show operations for the owner to review, not an automated
upgrade script. Account/schema names and paths must come from verified local
configuration. Never paste real passwords into argv, shell history or reports.
Use protected client option files/socket authentication or the existing db root
secret inside the db container. Treat privilege snapshots as confidential.

### Preconditions and evidence

1. Choose the exact reviewed release/artifact and confirm the real engine,
   database, datadir/port and runtime `CURRENT_USER()` through authorized owner
   access. On MySQL inspect `SELECT @@partial_revokes`; stop on unexpected mode.
   Inspect SHOW GRANTS for the matched runtime account and every inherited/default
   role, relevant alternate user@host account and privileged definer/proxy path.
   Do not infer effective permissions from initialization scripts alone.
2. Take/verify a same-engine backup and protected off-host copy; record current
   account/grants/configuration. Maintenance plus **stopped web and all CLI/job/
   metadata writers**, not only a drain delay. Retain a tested recovery artifact.
   No operations lock or service writer may overlap the change.
3. With the separate migrator run the existing migrate/status/recovery procedure.
   Refuse checksum, incomplete-intent, unknown history or missing-table problems.
   Do not edit released SQL/pins. No new domain migration is part of this change.
4. Review generated grant SQL privately. These examples generate files only:

```bash
# Owner chooses literal simple names first; runtime USER <=32 characters.
umask 077
php bin/runtime-grants "$DATABASE" "$RUNTIME_USER" '%' > runtime-grants.review.sql
```

From the intended production artifact, the corresponding **generation-only**
command is:

```bash
dc run --rm -T ops php bin/runtime-grants "$DATABASE" "$RUNTIME_USER" '%' > runtime-grants.review.sql
```

Here `dc` is the owner-reviewed production Compose wrapper from DEPLOYMENT.md.
No runtime/migration/root password is included in generated SQL. Verify every
table is present and granted only the manifest operations. Do not give the
migrator GRANT OPTION to make this step automatic.

### Preferred: new dedicated runtime identity

5. In a private interactive DBA client create a **new, absent** runtime account
   with a privately supplied strong password. Disable client history for that
   private session (for example MYSQL_HISTFILE=/dev/null); keep SQL/password
   input out of shared logs. Use CREATE USER without IF NOT
   EXISTS so name collision refuses. Match the intended host (the supplied stack
   uses `%` with an internal/unpublished DB; do not widen an existing host policy).
   Confirm it has no global/schema write grants, roles or privileged routines.
   Apply the reviewed file using the DBA client. A protected option-file example
   for an authorized target is:

```bash
# OWNER ONLY. This changes the selected account's privileges, not table data.
# The option file supplies the verified socket/host/user/password, mode 0600.
mariadb --defaults-extra-file="$DBA_OPTION_FILE" < runtime-grants.review.sql
# MySQL targets use mysql with the same reviewed option-file pattern instead.
```

6. Test a **fresh connection** as the new identity: correct CURRENT_USER and
   database, complete SELECT/SHOW TABLES visibility, expected table grants, no
   role/global/schema write override, no schema-changing privileges. These
   zero-row probes must return privilege-denied, not success:

```sql
UPDATE audit_log SET action=action WHERE 1=0;
DELETE FROM audit_log WHERE 1=0;
DELETE FROM users WHERE 1=0;
```

   Do not run destructive DDL/REPLACE/FK probes against real data. Their positive
   and negative tests belong to isolated databases. Check normal authorized writes
   and audit insertion using an explicitly approved smoke operation, not arbitrary
   production fixtures. Backup must include audit, all tables and migration metadata.
7. Owner updates only the intended runtime username/secret, retaining web/ops/root
   separation, then recreates/reconnects workers through the existing authorized
   deployment procedure. Do not trust privilege-cache refresh on existing sessions.
   Keep maintenance until normal account/catalogue/audit/backup checks pass.
8. Retire the old runtime credential: verify it has no legitimate consumers,
   lock it, revoke its old privileges/roles, and terminate old connections through
   the owner's DBA procedure. Account locking alone does not end existing
   connections. Do not leave the old broad password usable beside the new one.
   Retain evidence and verify no alternate matching identity bypasses the policy.

### Alternative: reconcile the existing dedicated identity

If rotating identities is impractical, keep all writers stopped, save its exact
grants/role relationships and explicitly remove broad permissions **before**
applying the manifest. A narrow table REVOKE is not a deny over schema/global DML.
For a verified single-purpose account, the DBA can use this reviewed SQL shape:

```sql
-- OWNER ONLY; replace both literals with the verified account before execution.
REVOKE ALL PRIVILEGES, GRANT OPTION FROM 'verified_runtime'@'verified_host';
-- Separately revoke each actual inherited role; REVOKE ALL does not remove roles.
-- Then apply the generated manifest for exactly this account.
```

Stop if the account is shared, has other legitimate schema rights, default roles,
proxy/definer dependencies or unknown consumers. Do not guess which grants to
preserve or run this on migrator/root. Grants are not a transactional migration;
partial provisioning leaves the site closed until reconciled. Fresh connections,
effective-grant inspection, zero-row denial probes and approved smoke/backup
checks are required as above. Retire all stale connections before reopening.

**Failure/rollback:** remain in maintenance with writers stopped. Correct a
missing legitimate table grant using the reviewed manifest and DBA authority;
do not suppress audit exceptions or automatically restore broad audit/user
deletion rights. Account/grant changes do not alter catalogue rows. Restoring
older grants is a deliberate security downgrade needing owner approval. Use
the saved configuration/evidence if reverting a credential switch, never an
unreviewed automatic fallback. No grant rollback, flag toggle or old release
should bypass household privacy safeguards.

## First installation, future migrations, backups and retention

- New install: authorized empty DB init → migrate with migrator → owner applies
  generated runtime grants → verify fresh runtime → user:create → open writers.
  `.dla-init-complete` proves account initialization, not post-migration grants.
  Omitting the grant step denies writes; it must not be “fixed” with broad DML.
- Future migrations/new tables: review the manifest/read-only decision, stop
  writers as required, migrate, apply the new manifest and verify. Removed grants
  need explicit owner reconciliation; this additive generator does not revoke
  old table privileges or roles.
- Backups still use runtime SELECT on the entire schema, including audit and
  migration bookkeeping. No table move/backup format change. Migrate/restore use
  the separate migration PDO with DDL/DML; restore may intentionally replace old
  audit rows and append backup.restored. Test runs cover complete restore and
  retained table privileges; inspect effective grants after real restores.
- Retention remains an authorized, separately controlled ops/DBA task; no pruning
  job is introduced and runtime never deletes history. Approve retention/privacy,
  dry-run selection, protected backups and evidence of exceptional changes.
  Migrator/DBA/host compromise, forged/omitted runtime INSERTs and disclosure are
  still outside row-preservation assurance. Off-host evidence remains optional
  owner work. Local permissions do not provide forensic immutability.

## Disposable test provisioning and regressions

Service calls run with **genuine restricted accounts**, not root or a permission
mock. Destructive schema resets, restore and FK fixture deletion use the separate
test migrator. Tests that intentionally delete users/loan history were corrected
to use that identity; production credentials were not broadened to accommodate them.

Both CI DB jobs create dedicated accounts, migrate the disposable *_test schema,
apply `bin/runtime-grants`, then run the full suite. Production-stack CI starts
with SELECT-only bootstrap, migrates its runner-local DB, applies the manifest
using its runner-local root, checks denied audit UPDATE, and continues account/
backup/restore checks. No deployment trigger/remote destination is added.

The negative-permission fixture is test-only: `${DB_TEST_USERNAME}_no_audit`,
using the dedicated runtime test password, with the same manifest **except audit
INSERT revoked**. It must have no rights to normal databases. Initialization
creates this account only when dedicated test accounts are configured. For a
manually provisioned disposable environment, the DBA first creates it explicitly.
Then, after migrations, generate/apply its manifest and revoke that single
table grant on this test account (no schema INSERT grant exists):

```bash
# Generation only; names/credentials must be dedicated to the disposable target.
php bin/runtime-grants "$DB_TEST_DATABASE" "$DB_TEST_USERNAME" > test-grants.sql
php bin/runtime-grants "$DB_TEST_DATABASE" "${DB_TEST_USERNAME}_no_audit" > no-audit-grants.sql
```

```sql
-- DISPOSABLE DATABASE ONLY, replace these example identifiers with test values.
-- Apply both generated files as the isolated DBA first, then:
REVOKE INSERT ON `library_test`.`audit_log` FROM 'library_test_app_no_audit'@'%';
```

Use dedicated test usernames short enough that the `_no_audit` suffix fits the
32-character MySQL account limit. Legacy shared development/test credentials
must be replaced with dedicated test accounts for this hardening verification;
never add the negative fixture to production. See TestDatabaseGuard safety
requirements; missing fixture setup is a test failure, not a silent skip.

New regressions:

- AuditGrantTest: audit SELECT/INSERT; denial of UPDATE/DELETE/TRUNCATE/DROP/ALTER;
  duplicate-key REPLACE/UPSERT; runtime user DELETE/REPLACE; retained-row snapshots.
  A privileged deletion then proves the actor-only fixture really exercises
  ON DELETE SET NULL rather than hiding behind another restrictive FK.
- Same test: read-only roles support required shared/admin locks; no role write
  grant; new table is backup-readable but not automatically writable; a real
  missing audit INSERT privilege rolls household creation back at the audit
  boundary with no event/partial write.
- LogoutAuditFailureTest and logout_worker: private **native file sessions**,
  failure before audit prepare (unavailable table) and during execute (real FK
  failure); session/CSRF rotation, warning/no success event, fixed diagnostics,
  replayed old cookie refused on next request. Bad CSRF retains the session;
  successful logout still records exactly one event.
- RuntimeGrantsTest: all released/current metadata tables have explicit decisions,
  safe SQL identifiers and no broad writes/DDL. EngineTest covers locking syntax;
  existing full suites retain domain rollback/retry, last-admin/revocation,
  household read/write/disabled, loan, catalogue, H4 atomicity/idempotency and
  complete backup/restore coverage.

## Verification results and remaining deployment gates

No historical 998-test result is substituted for this change's runs. No Docker,
production build/runtime, live CI, browser matrix or production-grant claim is
made. Final results and exact changed paths follow below.

All execution used PHP **8.4.27**, offline provider fakes and private disposable
`hardening_test` schemas. MySQL **8.4.13** used loopback 33962; official MariaDB
**11.4.13** used loopback 33963. Runtime had the manifest, migrator had schema
DML/DDL without GRANT OPTION, and the negative account lacked audit INSERT.
The normal MySQL service/development databases were not used or modified.
MariaDB's official archive SHA-256 matched its official checksum list:
`3cebf63914df3154b7cb6d548337eb2be42c773aeb641110446b72e2746cfed7`.

| Final executed check | Result |
|---|---|
| Full MySQL 8.4.13 PHPUnit, fail-on-skipped / fail-on-warning | **1,008 tests / 13,450 assertions PASS**, no skips; 3m11.183s |
| Full MariaDB 11.4.13 PHPUnit, same flags | **1,008 tests / 13,450 assertions PASS**, no skips; 2m04.131s |
| Included security/integration coverage | Real grant denials, rollback, native-session logout, account/household/loan/catalogue/H4 operations, revocation concurrency, disabled households, audit rollback/retry, backup/restore, migration/checksum tests all pass on both engines |
| Focused deployment/grant/engine unit selection | **146 tests / 1,557 assertions PASS**; production Compose/identity/storage, init behavior, CI shell blocks, grant manifest and engine syntax |
| Native nginx/PHP-FPM harness | **54 passed / 0 failed**; disposable local echo application, no database |
| Composer lint; explicit extensionless bin/runtime-grants lint | PASS |
| Composer validation | `composer validate --strict --no-check-publish` PASS |
| Initializer shell syntax | `bash -n docker/mysql/init/01-create-users.sh` PASS |
| Released migration diff and pinned checksum regressions | Diff empty; tests PASS on both engines |
| Documentation, owner staging inventory and whitespace | Links/fences/paths checked; git diff --check and explicit checks of untracked files PASS; all 30 paths covered exactly by owner staging recipes |

Full-suite command per isolated engine was `php vendor/bin/phpunit
--fail-on-skipped --fail-on-warning`, under explicit private test configuration.
The focused selection used `--testsuite unit --filter
'RuntimeGrantsTest|EngineTest|ContainerScriptsTest|CiWorkflowTest|ProductionComposeTest|ProductionIdentityTest|PersistentStorageTest'`
with the same flags. nginx used `php tests/nginx/run.php`.

Intermediate failures were investigated: older tests deleted users/loans using
runtime credentials; those deliberate destructive fixtures now use migrator.
MySQL's administrator FOR UPDATE join required narrowing to its writable user
alias; a direct probe established shared locks needed no change. MariaDB quoted
its real audit privilege-denial message differently; the assertion now checks
the denied table without depending on quote style. The final runs above follow
all corrections. Expected missing-audit diagnostics and provider-fake error logs
are fault-injection evidence, not live network traffic.

Not run: shellcheck (not installed), Docker/Compose runtime/image builds,
PHP 8.3 runtime, browser matrix, actual production grants/configuration/backup
rehearsal, or new GitHub Actions. Existing CI is updated for the owner to run
after committing; no green remote result is claimed for this uncommitted change.

Cleanup: both exact datadir/port/version identities and absence of other clients
were checked before normal SQL SHUTDOWN. Both server processes exited with code
0. Only this session's stopped datadirs and downloaded MariaDB binaries/archive
were removed; small private scripts/logs remain under
`/tmp/dla-audit-hardening.0RD8Zn`. Native logout session fixtures and nginx
processes/configuration were cleaned by their harnesses. No normal service or
pre-existing audit/test resources were stopped or removed.

Remaining owner decisions: authorize a later rollout; select/review effective
accounts/roles, credential rotation versus in-place reconciliation, retention and
optional independent evidence; verify actual backup/restore/host artifacts.
Households remain production-prohibited; their activation is neither implemented
nor authorized. No migrations or pinned checksums were edited.

## Changed-file inventory and owner commit boundaries

30 files; `A` means a new file. No existing working-tree changes were present.
The account/lock/fixture changes are coupled. The shared-lock export path was
verified and left unchanged; no export refactor is part of this delivery.

| Boundary | Exact paths |
|---|---|
| Grants, provisioning and compatibility | `.env.example`, `.env.production.example`, `.github/workflows/ci.yml`, **A** `bin/runtime-grants`, `docker/mysql/init/01-create-users.sh`, `src/Auth/UserRepository.php`, `src/Database/Engine.php`, **A** `src/Database/RuntimeGrants.php`, **A** `tests/Integration/AuditGrantTest.php`, `tests/Integration/BackupTest.php`, `tests/Integration/HouseholdWriteConcurrencyTest.php`, `tests/Integration/LibrarySchemaTest.php`, `tests/Integration/SchemaTest.php`, `tests/Unit/Database/EngineTest.php`, **A** `tests/Unit/Database/RuntimeGrantsTest.php`, `tests/Unit/Deployment/CiWorkflowTest.php`, `tests/Unit/Deployment/ContainerScriptsTest.php` |
| Logout | `src/Http/Controllers/AuthController.php`, `lang/en.php`, `lang/es.php`, `lang/gl.php`, **A** `tests/Integration/LogoutAuditFailureTest.php`, **A** `tests/Support/logout_worker.php` |
| Guidance/evidence | `ARCHITECTURE.md`, `CLAUDE.md`, `README.md`, `SECURITY.md`, `docs/DEPLOYMENT.md`, `docs/OPERATIONS.md`, **A** `docs/AUDIT_GRANTS_LOGOUT_HARDENING.md` |

The owner may run these commands after reviewing the complete unstaged diff and
test evidence. They do not push, merge, deploy, override identity/signing or
disable hooks. Do not substitute historical base-SHA commands from H1–H4 reports.

```bash
cd /home/brais/Projects/book-library-catalog
test "$(git branch --show-current)" = security/audit-grants-logout
test "$(git rev-parse HEAD)" = 89c2f07b75fb4b4db892320142bb96ddbd2792d5
test -z "$(git diff --cached --name-only)"
git diff --check

git add -- .env.example .env.production.example .github/workflows/ci.yml \
  bin/runtime-grants docker/mysql/init/01-create-users.sh \
  src/Auth/UserRepository.php src/Database/Engine.php src/Database/RuntimeGrants.php \
  tests/Integration/AuditGrantTest.php tests/Integration/BackupTest.php \
  tests/Integration/HouseholdWriteConcurrencyTest.php tests/Integration/LibrarySchemaTest.php \
  tests/Integration/SchemaTest.php tests/Unit/Database/EngineTest.php \
  tests/Unit/Database/RuntimeGrantsTest.php tests/Unit/Deployment/CiWorkflowTest.php \
  tests/Unit/Deployment/ContainerScriptsTest.php
git diff --cached --check
git commit -m "fix: restrict runtime audit writes with reviewed table grants"
git log -1 --format=fuller
git cat-file -p HEAD

git add -- src/Http/Controllers/AuthController.php lang/en.php lang/es.php lang/gl.php \
  tests/Integration/LogoutAuditFailureTest.php tests/Support/logout_worker.php
git diff --cached --check
git commit -m "fix: invalidate logout sessions despite audit failures"
git log -1 --format=fuller
git cat-file -p HEAD

git add -- ARCHITECTURE.md CLAUDE.md README.md SECURITY.md docs/DEPLOYMENT.md \
  docs/OPERATIONS.md docs/AUDIT_GRANTS_LOGOUT_HARDENING.md
git diff --cached --check
git commit -m "docs: record audit grant rollout and hardening verification"
git log -1 --format=fuller
git cat-file -p HEAD
```

No command in this block was executed here. The owner performs all commits and
subsequent CI/push/release decisions. Household activation remains prohibited.
