# Final H1–H4 security and release-readiness audit

Audit date: 2026-11-15 (Europe/Madrid). Review target: `household-access-h4`,
commit `d1518243e34f9159fb12c8e7665322aba12d3385`, tree
`07649fef8c6337ecc8d525acd9d68674cf89d6d9`.

## 1. Executive summary

**Do not activate household enforcement in production. Recommend holding the
merge until finding F1 is resolved and the medium findings have explicit owner
dispositions.** Merging the development feature with its production guards intact
is a separate decision from deployment, migrations, or activation. No activation
release is approved by this audit.

The inventory access model is coherent: shared bibliography, household inventory,
global roles, separate ownership, and personal lists. The actual SQL and mutation
paths support that model. No ordinary member/viewer cross-household physical
inventory IDOR, SQL injection, CSRF bypass, or executable template injection was
confirmed in this review. This is evidence from inspection and exercised tests,
not a proof that no vulnerability exists.

One high finding concerns administrative writes using stale actor permissions.
Medium findings concern nontransactional audit events, production environment
misconfiguration, and migration interruption/checksum handling. There are also
explicitly untested concurrency hypotheses and browser/real-data release gates.
The H4 transfer transaction itself refreshes authorization and makes its audit
events atomic; it does not share the earlier administration/audit weaknesses.

Production data, production topology, live CI results and real backup integrity
were not independently inspected. The owner's reported CI/984-test/Chrome/Edge
results are historical evidence, distinguished below from this session's runs.

## 2. Scope, method and verified controls

The initial working tree was clean and the actual branch matched the requested
branch. Work stayed in the existing project and temporary audit resources under
`/tmp`. Read CLAUDE.md, the infrastructure-access-boundary skill, SECURITY.md,
ARCHITECTURE.md, H3/H4 reports, HOUSEHOLD_INVENTORY.md and relevant deployment,
operations, backup and migration code/documentation. No application changes,
commits, pushes, merges, worktrees, deployments or production connections occurred.

All **115 GET/POST registrations** in `config/routes.php` were reviewed by route
family and followed into controllers and relevant repositories/services. The
matrix was a cross-check, not the source of authorization conclusions. Important
exceptions to the documentation's blanket “every read requires Visibility” claim
are internal lock/helper reads, bibliography-by-ISBN, admin diagnostics, and
collection/wishlist records whose object authorization is performed by callers.
Their current call sites matter; the method signature alone is not a guarantee.

| Surface | Controls traced and assessment |
|---|---|
| Public/auth/account/locale/health | Authentication reloads user and auth_version once per request; session fixation defenses and password/throttle controls remain. Global CSRF covers unsafe methods, including login and invitation POSTs. Health is deliberately outside the session stack and returns bounded status. Local redirect and Host policies remain. |
| Works/editions/catalogue/search/duplicates | Visibility predicates constrain records, physical copy counts, totals, options and filters. BookService refreshes actor and checks editable shared metadata under locks. ISBN lookup deliberately exposes shared bibliography, not inventory. Creator visibility survives loss of inventory access. |
| Copies, including add-by-ISBN | CopyService rechecks fresh actor, household, owner policy, location and scoped code. Ordinary enabled edits cannot transfer household or ownership. Inaccessible IDs produce 404 before ownership denial. ISBN addition resolves the edition on the server rather than accepting a hidden edition ID. |
| Covers and metadata contributions | Covers require visible edition before cache validation; enabled responses require revalidation. Upload/import changes use BookService authorization; staged files are removed after DB failure. Manual contribution output is bibliographic and allowlisted. No H4 outbound HTTP was introduced. |
| Authors/publishers/categories | Shared vocabulary; read lists and usage projections follow visible works/editions where relevant. Management routes are administrator-only. Direct admin repository mutations have the stale-route-actor limitation described in F1. Categories are shared, not private inventory. |
| Locations | Administrator routes; LocationService refreshes actor and serializes tree writes with exclusive household locks, including disabled mode. Child inheritance, cycle/name checks, same-household moves and HTTP row versions remain. |
| Loans/borrowers | Fresh lender actor and copy visibility/ownership checks; copy then loan locks; active-copy unique index; borrower membership checks; ordinary viewers receive availability rather than borrower history. Borrowers retain their own redacted historical title/date information after losing copy access. See H1 for borrower account-state timing. |
| Collections/wishlists | Collection ownership and explicit shared household are separate from edition visibility; lists/items/counts filter editions. Removed members cannot continue editing the shared space without rehoming. Wishlists remain owner-only, including against administrators; hidden edition links are omitted in output/exports. |
| Household administration | Administrator route guard, feature gate, default-household protection, dependency/FK checks and exclusive household lock for membership changes. Actor freshness and audit atomicity need F1/F2 remediation. |
| Invitations/user creation | Explicit household choices, no implicit default membership; only Member/Viewer invitations; token hashes; redemption locks invitation and creates user/memberships atomically. Replacement/redemption acquire household locks before invitation/account rows. Issuing and account administration need F1 remediation. |
| Exports/audit | Personal ID comes from the authenticated user; inventory and edition links are visibility-filtered. Admin catalogue export is global by deliberate permission, excludes loans/wishlists/private collections. CSV escaping and no-store streaming remain. Audit viewer is admin-only, bounded and escaped. |
| H4 inventory routes | Overview, dry run/save, show/apply/cancel all require feature/admin access. Service repeats current permissions. IDs/digests are not capabilities. Diagnostics and global code collisions are admin-only. |
| Console/background/operations | No applying transfer CLI/background job exists. inventory:check refuses production, normal DB port, nonloopback hosts and nonrehearsal DB names, requires maintenance/acknowledgements and operations lock. Backup/restore/migration/cleanup are CLI operations with role separation; metadata refresh has no inventory assignment path. |

PDO native prepared statements and Params bind dynamic values; sort/table choices
are internal/allowlisted. Reviewed H4 templates escape text, cast numeric IDs,
include CSRF fields and use native forms. Generic production errors and private
cache headers remain. No H4 HTML injection sink was found in app.js.

**Deliberate policy disclosures, requiring continued owner acceptance:** an ISBN
probe reveals shared bibliography; a creator can infer loss of metadata edit
exclusivity; bibliography, authors/publishers/categories are shared; borrowers
retain their own title/date history; administrators see all households. These
are not findings of unauthorized physical inventory disclosure. Household
membership alone grants no management rights over another member's copy.

## 3. Confirmed vulnerabilities and defects

Severity meanings: Critical prevents release; High must be resolved before
production; Medium requires a recorded decision/remediation; Low is nonblocking.
No Critical finding was confirmed. “Confirmed” below distinguishes demonstrated
service behavior from unexecuted HTTP interleavings.

### F1 — High: administrative writes can outlive actor demotion/deactivation

Locations: `src/Households/HouseholdService.php:142`, `:154`, `:115`;
`src/Auth/UserAdministration.php:48`, `:55`, `:80`;
`src/Invitations/InvitationService.php:64`, `:78`.
Related route-only administrative mutations:
`src/Http/Controllers/Catalogue/AuthorController.php:87`,
`PublisherController.php:58`, `MergesRecords.php:34`, and
`src/Http/Controllers/Admin/TreeAdminController.php:111`.

Mechanism: request middleware resolves an active administrator. Another admin
demotes/deactivates that account and commits. The first request then enters one
of these mutation paths, possibly after waiting for a household lock or hashing
a password. HouseholdService/InvitationService check the old User object;
UserAdministration.create checks that old object before its transaction;
update/reset/session operations rely on route authorization and do not validate
a fresh administrative actor. Unlike WriteGuard, these paths never lock and
reload the actor before authorizing the mutation.

Service-level reproduction on the isolated MySQL database is recorded in the
verification section. It captures an admin User, changes the stored role to
Member, then successfully adds a household membership with the captured object.
A second case deactivates the captured actor and successfully creates an active
Admin account. These deterministically represent the state between middleware
and a later write; a real concurrent HTTP scheduling test was not run.

Impact: a formerly privileged in-flight request can still grant household access
or create a durable privileged account after revocation returns. This is not an
unauthenticated or arbitrary-member escalation: it requires an already-authorized
request/object and a concurrent actor-state change.

Fix: authorize a freshly read active actor within the write transaction, holding
its account lock until commit, with consistent household/account ordering across
all administrative operations. Apply object checks in the service as well as the
route. Do not simply wrap an existing transaction in WriteGuard or introduce an
account-before-household inversion. Revisit target-account/admin-set locking too.

Regression: pause each administrative request before its service lock, commit
actor demotion/deactivation from another connection, resume, and assert no domain
or membership write. Exercise create/update/reset, household add/remove, and
invitation issue/replace/revoke. Reverse the ordering and verify revocation waits
for an already-authorized locked write. A stale User supplied directly must fail.

Mitigation: per-request reload blocks subsequent requests; household routes are
unreleased/production-disabled. Account administration remains available with
households disabled, so the feature flags do not fully mitigate F1.

### F2 — Medium: earlier security mutations and audit events are not atomic

Locations: `src/Http/Controllers/Admin/HouseholdController.php:158`, `:180`,
after the service commits at `src/Households/HouseholdService.php:115`/`:144`;
similar controller-after-service patterns exist for account/invitation, copy,
loan, collection and ordinary location writes. H4 transfer audit is different.

Mechanism: membership addition/removal commits, then the controller inserts an
audit event. Process termination, a lost connection, full audit storage, revoked
INSERT permission or any audit exception leaves a changed authorization boundary
without its event. Retrying may report “already member/not member” rather than
recover the missing event. A fault-injection reproduction confirms the membership
remains after the following audit INSERT fails; no membership audit exists.

Impact: missing accountability and a failure response after a committed action.
No cross-household read is required. Fix security-relevant domain changes and
audit inserts into the same PDO transaction (or a transactionally persisted
outbox with a defined delivery policy). Wishlist privacy policy remains separate.

Regression: force audit failure during HTTP member add/remove and account grants;
assert complete rollback and no success response. Kill/retry around commit and
verify one durable outcome. Existing H4 audit-failure tests are a useful pattern.

Mitigation: normal audit success is tested and H4 transfers already roll back
with audit failure. Runtime grants permit audit modification/deletion by a fully
compromised application: that separately documented audit-integrity decision
also remains open (SECURITY.md:738); atomicity does not make logs tamper-proof.

### F3 — Medium: both production guards depend on mutable APP_ENV classification

Locations: `src/Core/Config.php:121`, `src/Core/Services.php:392`,
`src/Core/ConfigValidator.php:315`, `docker-compose.prod.yml:121` and `:187`.

Confirmed locally without a DB: construct Config with APP_ENV=development,
APP_ROLE=web, HOUSEHOLDS_ENABLED=true. Services enables households and validation
contains no HOUSEHOLDS_ENABLED error. The production Compose service imports
APP_ENV from `.env.production`; it fixes APP_ROLE but not APP_ENV. A copied or
mistaken valid development/testing environment plus the enabled flag bypasses
both guards' shared premise. This is a configuration failure, not remote code
execution or an externally writable environment variable.

Impact: the strongest reading of “configuration mistakes cannot activate the
feature in the production stack” is not satisfied. Both safeguards remain
unchanged and work when the environment classifies as production. Missing,
empty or misspelled APP_ENV fails closed; the gap is valid nonproduction values.

Fix/decision: pin production environment identity in the production deployment
path, including defense against APP_ENV_FILE overriding the plain key, and test
that hostile env-file values cannot select development mode. Keep the household
flag off and both existing restrictions until the separate activation release.
Regression: production Compose/config fixtures with development/testing, unset,
unknown and *_FILE values; assert production classification and refusal to
activate. No real Compose stack was started here.

Mitigation: the supplied production example says production/false; only the
owner controls configuration. Record this residual if choosing not to harden it.

### F4 — Medium: migration-file atomicity and ordinary checksum enforcement gaps

Locations: `src/Database/Migrator.php:66`, `:72`, `:75`, `:201`;
`database/migrations/2026_11_13_180001_add_household_to_inventory.sql:19`;
`database/migrations/2026_11_15_100000_create_inventory_transfers.sql:6`/`:25`.

Confirmed by control-flow/SQL inspection, not a simulated server crash: files
containing multiple DDL statements execute them separately and record the version
only after all succeed. If the second statement or migration-history INSERT
fails after the first DDL commits, retry begins with an existing table/column and
fails again. Per-statement atomic DDL does not make the file atomic. Separately,
migrate() skips already-recorded versions without rejecting an ordinary modified
checksum; it rejects known other-engine checksums, while status() reports generic
modifications. Frozen-file unit tests protect repository files, not arbitrary
production migration history.

Impact: an interrupted upgrade needs manual recovery or a clean restore; a
modified applied file can be silently skipped by migrate itself. No changed
released migration was found in this checkout. Fix/decision: require checksum
status as an explicit preflight and rehearse restore-based recovery; consider
fail-closed checksum validation and separately reviewed resumable migration
handling. Never edit released SQL or blindly rewrite schema_migrations to recover.

Regression: fail the second DDL and history INSERT on a disposable upgrade, then
rerun and assert the documented recovery path; alter a recorded same-engine
checksum and require migration refusal before new schema changes. Mitigation:
ordered files, migration lock, retained backups and owner maintenance procedure.

### F5 — Low: unrelated household counters can invalidate a saved transfer plan

Locations: `src/Households/InventoryTransferService.php:235`/`:314` and
`src/Households/HouseholdRepository.php:23`/`:41`.

Confirmed static mechanism: plans include household.find() records containing
member/copy/location counters and timestamps, then require strict equality of the
rebuilt entire plan. Adding an unrelated copy to source/destination changes a
counter and rejects an otherwise unchanged plan. This is conservative refusal,
not unauthorized writes or a loss of inventory. Fix optionally compare only
review-relevant identity/version/eligibility fields; regression saves a plan,
adds unrelated inventory, then verifies the chosen documented behavior. Current
mitigation/recovery is a fresh preview; no security relaxation is needed.

### F6 — Low: release documentation contains superseded statements

Locations: `SECURITY.md:506` says H4 has not started; `SECURITY.md:680` says CI has
not executed; `SECURITY.md:719` groups locations with last-write-wins;
`docs/PHASE_H4_REPORT.md:232` records 983 tests, while the owner reports a later
984-test verification. Historical phase reports also describe their then-dirty
delivery state. These do not establish the current Git/test state.

Impact: release reviewers can mistake historical evidence and obsolete blockers
for current facts. Preserve dated reports and add explicit supersession notes;
update current security guidance after owner approval. Regression: release review
checks versioned evidence against the exact target SHA. Mitigation: H4's later
sections document the implemented behavior; this audit pins its own target.

## 4. Migration and data-integrity assessment

All nine H1/H3/H4 migrations were inspected. H2 adds no migration.

| Ordered migration suffix | Assessment |
|---|---|
| 11_13_180000_create_households | New household/member tables, global roles unchanged, membership PK and restrictive household FK. |
| 11_13_180001_add_household_to_inventory | Nullable copies/locations household FK and indexes; two separate ALTER statements, interruption caveat F4. |
| 11_13_180002_backfill_default_household | Assigns NULL inventory to default; adds only active previously signed-in users. Does not redistribute by ownership or location. Idempotent statement logic; broad access requires review. |
| 11_14_100000_scope_inventory_codes_to_households | One ALTER creates numeric stored generated COALESCE(household_id,0), scoped uniqueness and removes global index. NULL household is scope 0; NULL inventory codes remain repeatable. Never write the generated column. |
| 11_14_100001_add_household_to_collections | Nullable field/index/FK, preserves private collections. |
| 11_14_100002_backfill_shared_collection_households | Only NULL shared collections get default household. Existing contents/visibility preserved; requires default household to exist. |
| 11_14_100003_create_invitation_households | New intention table, composite PK, cascading cleanup; old invitations have no grants. |
| 11_15_100000_create_inventory_transfers | Two new tables; snapshots intentionally have no inventory/household FKs so evidence survives deletions. Actor and operation FKs are restrictive. No existing inventory rewrite. |
| 11_15_100001_add_location_row_versions | Adds version with default 1; prior IDs/parents/fields preserved. |

Git comparisons returned no changes to pre-household released SQL against
`aee3f3d`, H1 SQL against `9d613f3`, or H3 SQL against `a54a02b`. Pinned migration
tests passed in the unit suite. This verifies checkout history, not checksums
stored on production, which the owner must compare.

The SQL uses InnoDB, compatible integer generated columns, named-column writes,
RESTRICT rather than cascading changes on the generated column's household base,
and no new engine-specific JSON syntax. MySQL 8.4.13 was exercised this session;
MariaDB 11.4.13 results remain owner/prior-report evidence. Cross-engine restores
are refused; do not convert a MariaDB backup to MySQL as a rehearsal substitute.
MariaDB collation aliases and trailing-space equality must be checked on the
actual rehearsal engine; code-conflict SQL uses database equality and the unique
index is authoritative.

Budget for the H3 copies rebuild, copied indexes, undo/redo/binlog and temporary
space, not just H4's tables/version column. No explicit online-DDL guarantee is
provided. Runtime DML credentials cannot migrate; use the migrator account.
There is no down migration. An application rollback retaining this schema is
only SQL-compatible: duplicated codes can fail old/global prechecks, old location
writers lack H4 version semantics, and disabled reads are global.

Restored production data remains an **unverified release gate**: preexisting
cycles, mismatched assignments, unexpected NULLs, inactive owners, pending
invitations, collation collisions, and inventory larger than H4 bounds must be
measured, not inferred from synthetic tests. FK validity alone does not establish
same-household copy/location or parent alignment, or absence of tree cycles.

## 5. Concurrency and transfer assessment

WriteGuard refuses nested transactions, sets READ COMMITTED for its transaction,
locks households before accounts, rebuilds actor/membership state, retries only
deadlock victims up to three attempts, and rolls back timeout/exhaustion. Services
must keep retry callbacks database-only; reviewed transfer callbacks do so.

H4 mutation ordering is exclusive household rows, sorted accounts, operation,
sorted editions, sorted works, copies, then locations. Membership changes and
ordinary enabled writers serialize through the household rows; transfer owners
are locked and rechecked. Shared bibliography locks protect metadata exclusivity
against inventory creation. Broad exclusive locks avoid subtree phantoms/cycles
from cooperating writers, at a deliberate contention cost. No promise covers
arbitrary direct SQL or simultaneous old/new/feature-off application writers.

Preview/dry run acquires locks but writes no domain/plan/audit rows. Saving persists
an immutable bounded snapshot. Confirmation rereads current permissions and plan,
checks its digest, locks records, rebuilds and compares state, checks full subtree
membership, versions, loans, owner eligibility, locations/names and codes, then
performs narrow updates and audit/status changes in one transaction. A completed
confirmation returns the recorded outcome without replaying mutations/audits;
cancelled plans cannot apply. Digest is an integrity/confirmation binding, not an
authorization token or protection against an administrator with direct DB control.

Copies retain IDs, editions, business fields and loan rows. Household transfers
require eligible existing owners; ownership reassignment is one copy and blocked
by any loan history. Active loans or on_loan status block movement. Subtrees are
indivisible, maximum 100 nodes and configured maximum 100 copies; no automatic
chunking. Each separate confirmed batch is its own recovery boundary. A lost
response is resolved by reopening the operation; reversing it is a new operation
subject to current constraints. H4 audit failure rolls back changes/status.

**H1 — Medium hypothesis requiring a targeted schedule:**
`src/Loans/LoanService.php:168` reads a borrower without an account lock, unlike
the actor and H4 transfer owners. Proposed schedule: lender passes mayBorrow(),
another admin deactivates the borrower and commits, lender inserts the loan. The
household/copy locks protect membership/copy state but do not alone protect that
borrower's is_active field. This can violate the documented active-borrower rule;
the same account-state timing deserves review for an administrator creating a
copy for another owner (`src/Catalogue/CopyService.php:243`). No actual service
interleaving was executed, so these are not counted as reproduced vulnerabilities.
Fix if confirmed: include required accounts in sorted pre-record locks and reread
eligibility. Regression: instrument/pause after eligibility, deactivate on another
connection, and test both orderings without introducing a lock inversion. Existing
membership locks and ordinary next-request session checks mitigate access but do
not prove account-state serialization.

Remaining contention/deadlock risk includes legacy admin author merges taking
works then editions (`AuthorRepository.php:154`), against edition-before-work
inventory paths. Transactions roll back victims, but legacy merge paths do not
share all WriteGuard retry/error handling. No data-loss schedule was demonstrated;
measure and add a targeted merge/inventory schedule if supporting heavy concurrent
administration. Household insertion/absent-default and direct-SQL scenarios also
need restored-data review; current locking assumes a small cooperating installation.

## 6. Feature flag, activation and rollback

Verified unchanged safeguards: ConfigValidator refuses an enabled flag in
production and Services independently returns false there. Config defaults the
flag to false; unset/unknown APP_ENV is production. Application validates before
serving and CLI config checking is retained. Services initializes Visibility,
CopyHousehold, WriteGuard and invitation/account services from the same gated
switch. The documented raw constructors/testing injection are not web routes.
F3 qualifies the independence of these guards against valid APP_ENV mistakes.

**Turning the flag off after separation is a privacy breach scenario:**
Services::visibility returns unrestricted predicates; catalogue reads become
installation-wide, shared collections become globally shared, and the application
again uses global code prechecks despite the scoped DB index. Restored old code
can omit household assignments and does not honor H4 location versions. Never use
flag-off as incident rollback after users have been promised isolation. Keep
maintenance active and recover to a reviewed household-aware release, or restore
a chosen backup with explicit loss of subsequent writes. Restores are non-atomic.

A separately approved activation release must resolve F1, disposition medium
findings/hypotheses, verify the production environment boundary, deliberately
revise **both** ConfigValidator and Services restrictions, update tests/runbooks,
rehearse real-data schema and assignment, approve memberships/default inventory,
NULLs/collections/invitations/ownership, establish supported browsers and capacity,
and agree recovery point/time. No bypass, activation change, NOT NULL migration,
default retirement or media development was implemented by this audit.

## 7. Browser security and compatibility

Historical H4 report: Chrome 157.0.8103.78 and Edge 157.0.4341.46 each passed 95
checks: JS on/off, en/es/gl, desktop/mobile layouts, roles, canaries, transfer
confirmation/repeat/stale behavior, location options and member edits. This was
reviewed, not rerun. Both are Chromium; they are not independent Gecko/WebKit
coverage. The CDP harness blocks other origins and uses fake provider HTTP.

Local tooling assessment: Firefox ESR 153.7 is installed; WebKitGTK 2.54 runtime
is installed; Python websockets is available. Python Playwright and Selenium are
not installed, geckodriver/WebKitWebDriver were not found, and the existing CDP
harness is Chromium-specific. Firefox can be manually tested locally without
Docker, or automated after adding an approved BiDi/WebDriver harness/driver.
WebKitGTK automation needs its driver/runner; a runtime library alone is not a
working test matrix. A local Playwright installation with its matching engines
is another possible route, requiring dependency/download approval and a harness
port. WebKitGTK/Playwright do not establish Safari-on-real-device compatibility.
No package installation, browser launch, production URL or browser test occurred
in this session. Missing tooling is not evidence that Docker is required.

Native H4 forms support the essential workflow without JS; confirmation is a
required checkbox plus server validation. Labels/fieldset/legend/table headings
and alert roles are present. app.js changes form values/text and location option
visibility, not an authorization boundary; server validation remains decisive.
Translation-key parity is exercised by unit tests, and HTTP H4 tests cover all
three languages. This does not certify translation quality, screen-reader order,
keyboard focus after errors, zoom/reflow or real mobile accessibility. Complete
those checks in Firefox/WebKit as the owner defines the supported-browser policy.

## 8. Owner-executable production-data migration rehearsal

**Design only; not executed. Only the owner obtains/accesses the real backup or
production source.** Use an owner-controlled confidential same-engine MariaDB
11.4 rehearsal server, isolated from production and normal development, loopback
only on a dedicated non-3306 port, fresh credentials restricted to a named
`*_rehearsal` database, private storage/covers/sessions/backups, no ingress and no
outbound provider traffic. Do not run PHPUnit or the synthetic browser fixture
against restored data: both reset/truncate their test schema.

### A. Establish evidence and an exact configuration

1. Owner records the source application SHA, MariaDB version, SQL modes,
   collations, schema_migrations rows/checksums and backup UTC recovery point.
   Quiesce writers for the source evidence/backup window; include CLI jobs and
   metadata/cover writers, not just maintenance mode. Owner alone performs source
   backup and transfer using the existing approved operations procedure.
2. Owner verifies the archive with `backup:verify`, verifies its SHA-256 sidecar
   and independently retained hash, and protects the off-host copy. Do not use
   `--allow-missing-covers` to declare a release backup healthy. Archive hashes
   prove byte consistency; a full restore plus reconciliation proves usability.
3. In a private owner-controlled shell, define explicit REHEARSAL_PORT,
   REHEARSAL_DB (ending `_rehearsal`), REHEARSAL_ROOT, ARCHIVE and ADMIN_ID.
   Create the isolated instance/database/accounts through the local DBA procedure;
   no production credentials, DNS aliases, tunnels or copied .env files. Record
   free space on DB data/temp/binlog and archive/extraction/cover filesystems.
4. For all commands below, create a private JSON configuration containing string
   values for APP_ENV=testing, APP_ROLE=ops,
   APP_URL=http://127.0.0.1:<chosen-local-web-port>, DB_HOST=127.0.0.1,
   DB_PORT=$REHEARSAL_PORT, DB_DATABASE=$REHEARSAL_DB, the new runtime/migrator
   credentials, HOUSEHOLDS_ENABLED=false, METADATA_PROVIDERS=none,
   SESSION_SECURE_COOKIE=false, and TEMP_PATH/BACKUP_PATH/COVER_STORAGE_PATH under
   $REHEARSAL_ROOT. Clear all conflicting *_FILE settings. Use a private current
   configuration file or wrapper so passwords are not command-line arguments or
   shell history. The application also reads repository .env: explicitly override
   its corresponding keys/files if using bin/console directly. Prefer the exact
   wrapper in Appendix A, which constructs Config from JSON only and never reads
   repository .env or inherited environment. In the command blocks below define
   `rehearsal()` as shown there and use it instead of direct bin/console.
5. Verify `SELECT VERSION(), DATABASE(), @@port, @@hostname, CURRENT_USER()` via
   the explicitly selected local instance, and verify the account cannot see the
   normal development/production schema. Abort on any identity mismatch. Record
   schema/index sizes from information_schema.TABLES, free space and clock time.

### B. Restore and prove restore integrity before migration

With the exact isolated environment above, from this project, run these commands
sequentially (variables refer only to the owner-selected local paths/database):

```bash
rehearsal maintenance:on --reason=household-rehearsal
rehearsal backup:verify --file="$ARCHIVE"
rehearsal backup:restore --file="$ARCHIVE" --confirm="$REHEARSAL_DB" --database="$REHEARSAL_DB" --covers-dir="$REHEARSAL_ROOT/covers"
rehearsal maintenance:status
rehearsal migrate:status
```

Use an empty target/covers directory for the first restore; do not pass --replace,
--no-safety-backup or --allow-production. Exit 2/incomplete restore is failure,
never permission to remove maintenance. No writers may run during reconciliation.
Record elapsed restore time, peak free-space change and archive/extraction sizes.

Compare restored per-table counts with manifest counts, allowing exactly the
documented omitted login_attempts rows. Account for the new backup.restored audit
event separately. Compare primary-key sets and per-row/per-column fingerprints
against owner-captured source evidence from the same quiescent backup window.
For confidential evidence, store hashes and counts privately, not raw user data
in this repository. Verify every referenced cover and thumbnail hash; retain the
restore FK-check results and perform the semantic checks below.

The exact fingerprint algorithm is: enumerate BASE TABLES and ordered PRIMARY
KEY columns from information_schema; record column names/types in ordinal order;
SELECT each table ordered by its complete PRIMARY KEY; encode every cell as NULL
or hex of its fetched string bytes, with its PK tuple; hash newline-delimited JSON
using SHA-256 per column and for the PK stream; record count. Use PDO with native
prepares, UTC and consistent fetch settings at source/restore. Take all reads in
one REPEATABLE READ read-only snapshot. A later comparison selects **the baseline
column list**, so newly added/generated columns do not create false differences.
Keep schema_migrations/audit/transient-table expected differences separate. Do
not use SUM(id), COUNT alone or GROUP_CONCAT as evidence of unchanged IDs/data.
Appendix B supplies an owner-executable implementation. Review it before running
it on source; it has not been executed against production or restored real data.

### C. Rehearse ordered schema migration with writers stopped

1. Save the restored baseline fingerprints, complete schema/index/FK definitions,
   counts and migration history outside the repository. Compare every applied
   checksum with its selected same-engine file; abort on modified/other-engine
   history. Explicitly verify the default household if H1 is already applied.
2. Keep local web/CLI writers stopped. Run `rehearsal config:check`, then
   `/usr/bin/time -v php "$REHEARSAL_ROOT/rehearsal.php" "$REHEARSAL_ROOT/config.json" migrate`, capture private stdout/stderr,
   status and free-space/time samples during the run. Run `migrate:status` again;
   every expected version must be applied/unmodified. Run migrate a second time;
   no additional versions may execute. Never rewrite migration history.
3. Reconcile baseline PK sets, counts and every baseline column digest. Only
   expected differences are allowed: new tables; H1 NULL copy/location assignments
   to default; default household and active previously-signed-in memberships;
   H3 NULL shared-collection assignments; new generated scope values and location
   version=1. Existing ownership, edition IDs, loan history, inventory codes,
   location parent/copy references, collection items and invitation state must
   match exactly. If household schema already existed, distinguish preserved
   assignments from new backfill targets. No IDs may be renumbered.
4. Record each migration's duration, maximum observed storage consumption,
   blocking/lock waits and total outage. Provision real release headroom from
   those measurements plus explicit margin and backup/recovery needs; 16 MiB
   restore preflight margin is not a DDL capacity budget.

Run these read-only checks on the isolated migrated target (expected mismatch
and duplicate counts are zero unless a documented baseline exception exists):

```sql
SELECT c.id FROM copies c JOIN locations l ON l.id=c.location_id
WHERE NOT (c.household_id <=> l.household_id);
SELECT l.id FROM locations l JOIN locations p ON p.id=l.parent_id
WHERE NOT (l.household_id <=> p.household_id);
SELECT inventory_scope_id, inventory_code, COUNT(*) FROM copies
WHERE inventory_code IS NOT NULL
GROUP BY inventory_scope_id, inventory_code HAVING COUNT(*)>1;
SELECT id FROM copies WHERE inventory_scope_id <> COALESCE(household_id,0);
SELECT copy_id, COUNT(*) FROM loans WHERE returned_on IS NULL
GROUP BY copy_id HAVING COUNT(*)>1;
SELECT c.id FROM copies c LEFT JOIN loans l
ON l.copy_id=c.id AND l.returned_on IS NULL
WHERE (c.status='on_loan') <> (l.id IS NOT NULL);
SELECT household_id, COUNT(*) FROM copies GROUP BY household_id;
SELECT household_id, COUNT(*) FROM locations GROUP BY household_id;
SELECT inventory_code, COUNT(*) FROM copies WHERE inventory_code IS NOT NULL
GROUP BY inventory_code HAVING COUNT(*)>1;
SELECT * FROM schema_migrations ORDER BY version;
```

Also validate every FK via LEFT JOIN orphan checks for all constraints, including
composite FKs; enabling FOREIGN_KEY_CHECKS alone does not retroactively validate
rows. Fetch all `(id,parent_id)` location pairs and walk each node to NULL with a
visited set: any repeated ancestor is a cycle, and any absent ancestor is an
orphan. Check sibling names and root names by household using DB collation.
Review all NULL rows, inactive/nonmember owners, free-text/account borrowers,
private/shared collections, pending invitation assignments and default members.
Global duplicate codes are diagnostic: they may be legal after separation but
must be listed for rollback planning. Never silently normalize/rename codes.

### D. Rehearse behavior and explicit assignment on the restored data

1. With enforcement still false, start only a private loopback rehearsal web
   process with outbound traffic blocked and private sessions. Compare baseline
   behavior for active admin/member/viewer, never-signed-in and inactive users;
   catalogue/loans/collections/invitations/exports/covers must match the approved
   disabled-mode expectations. Stop it again after testing.
2. Owner approves exact destination households and memberships, including broad
   default members. Prepare a signed-off per-copy/per-location mapping keyed by
   existing IDs, plus separate owner/collection/invitation decisions. Detect
   active loans, any loan history blocking owner changes, ineligible destination
   owners, oversized subtrees/batches and code/name collisions before applying.
3. On **this isolated restored clone only**, set HOUSEHOLDS_ENABLED=true with
   APP_ENV=testing, keep writers stopped/maintenance on, and run:

```bash
rehearsal inventory:check --actor="$ADMIN_ID" --writers-stopped --confirm=LOCAL-INVENTORY-CHECK
```

4. The present CLI cannot assign inventory or run in production. For local
   assignment rehearsal, temporarily start the private web process and leave
   maintenance only for that local exercise; use the existing admin UI's dry run,
   saved preview and exact confirmation for bounded transfers. Reconcile each
   operation to the approved mapping. Ownership with history and oversized
   subtrees are stop conditions requiring a separately reviewed solution, not
   reasons to delete history or bypass limits. Return to maintenance/stopped
   writers after each controlled exercise. Do not stage transfers with disabled
   copy editing. A final production assignment procedure still needs approval.
5. Test two disjoint households plus a multi-household user using owner-controlled
   rehearsal accounts: every GET/POST IDOR, searches/counts, ISBN notices, covers
   with cached ETags, loans/borrowers, collections/wishlist links, exports and H4
   admin/history. Remove membership in an existing session and confirm next-request
   denial; deactivate/demote actors and exercise F1 regressions after remediation.
   Confirm strangers cannot infer codes, locations, owners or physical counts.
6. Reconcile fingerprints again. Transfers may change only approved household,
   location/parent/owner fields and expected versions/timestamps/audit/plan rows.
   Loans, copy IDs, bibliography, acquisition fields, codes, collection items and
   invitation grants must remain unchanged except independently approved actions.
   Re-run semantic/FK/cycle checks and read-only diagnostics.

### E. Rehearse recovery and decide release gates

Create and verify a post-migration backup with nonempty transfer history, restore
it into another owner-selected empty disposable same-engine target and repeat
fingerprints/FK/cover checks. Separately restore the pre-migration backup to
rehearse the recovery point; measure duration and record lost later writes.
Simulate an interrupted migration/restore only in disposable rehearsal targets,
verify maintenance/incomplete markers and recovery instructions. Never assume
rerunning a partly applied DDL file works (F4).

Do not test flag-off on a shared/live separated installation. A private clone may
demonstrate the expected global-read/global-code-check regression as negative
evidence. Record the approved household-aware recovery SHA, recovery point,
maximum data-loss window, measured restore time and owner stop/go criteria.
Production activation cannot proceed until the owner signs the full evidence,
all high findings are fixed, medium risks have dispositions, browser policy is
met and the separately approved production assignment/activation release exists.

## 9. Tests executed in this audit

| Executed check | Result |
|---|---|
| Existing unit suite, PHP 8.4.27 | 634 tests, 7,481 assertions passed |
| Existing integration suite, disposable MySQL 8.4.13 | 350 tests, 5,613 assertions passed; --fail-on-skipped, no skips |
| Existing total (separate suites) | 984 tests, 13,094 assertions passed |
| Three temporary audit reproductions | 3 tests, 6 assertions passed, demonstrating F1 (two cases) and F2 |
| PHP/Composer lint | composer lint, additional templates/lang/config PHP lint, and composer validate --no-check-publish passed |
| Proposed owner helpers | Both embedded PHP helpers passed php -l only; not functionally exercised on real data |
| Whitespace and migration history comparisons | git diff --check passed; released-file comparisons described above were empty |
| Feature-guard config reproduction | development/web/true enables enforcement and yields zero HOUSEHOLDS_ENABLED validation errors, confirming F3's shared premise |

The first integration attempt had 28 failures and 3 warnings because the audit
runner set METADATA_PROVIDERS=none. That disabled metadata behavior expected by
the tests. The corrected run used open_library,google_books with the suite's
existing injected FakeHttpClient; the full 350-test rerun passed. Offline/network
failure strings in its log came from those fakes, not provider connections.

The three temporary tests used the normal IntegrationTestCase DB guard and
dedicated test accounts. F1 deliberately asserted the current undesirable
behavior: capture admin, change stored role to Member, successfully grant another
member access; capture admin, deactivate stored account, successfully create an
Admin account. F2 committed member addition, then forced an audit INSERT FK
failure with a nonexistent audit actor and verified membership persisted with no
event. This injects a post-commit exception; it does not reproduce a full disk or
claim an attacker can supply that audit actor through HTTP. Future regression
tests must invert those success assertions after remediation.

MySQL used only a new `/tmp` datadir, loopback 33951 and final_audit_test. The
runtime account had SELECT/INSERT/UPDATE/DELETE only on that database; the
migrator had DDL only there. Neither could access the normal development DB.
Local socket/network testing required sandbox approval; no remote DB was used.
No MariaDB test binary/isolated instance was available in the inspected locations;
MariaDB was not rerun. nginx/PHP-FPM binaries were not found on PATH, so the
nginx harness was not rerun. No browser, PHP 8.3, Docker or production rehearsal
pass is claimed. Prior H4/CI evidence is not substituted for local execution.

Cleanup: the exact temporary instance reported its expected datadir/33951 port
and no other clients before normal SQL SHUTDOWN. Its process exited and shutdown
completed; the audit-created datadir was removed. Small local evidence/scripts
remain in `/tmp/dla-final-audit.g0Y3cA` (including FinalAuditTest.php and execution
logs); they contain synthetic test settings only and are not release artifacts.
The only repository change is this new report. Application/test/migration/config
files remain unchanged; no staged changes or feature activation occurred.

## 10. Prioritized remediation and final verdict

1. Resolve F1 across administrative mutation paths with fresh transactional
   authorization and concurrency regressions. This is the merge hold recommendation.
2. Resolve or explicitly accept F2–F4 with owner-recorded rationale and recovery
   procedures; test H1's account-state races before claiming complete write
   serialization. Keep production guards/flag intact.
3. Complete owner-run real-data restore/migration/assignment/recovery rehearsal;
   do not treat synthetic DB or browser tests as production-data validation.
4. Complete the supported Firefox/WebKit/accessibility and runtime matrix, and
   reconcile current documentation/evidence (F6). F5 is optional usability work.
5. Prepare a separate, explicitly approved activation release and production
   assignment procedure. No activation is authorized by merging this branch.

**PR merge:** hold for F1 and explicit medium-risk dispositions. The household
architecture is suitable to continue toward merge; a passing existing CI alone
does not clear the confirmed gap. **Production activation:** not ready, regardless
of a later merge. Real-data safety, activation safeguards, supported-browser
coverage and an owner-reviewed operational/recovery release remain outstanding.

Audit ends with this report. Implementation changes require the owner's next
authorization.

## Appendix A — exact isolated console wrapper for the owner

These are proposed owner-run helpers, not application changes or authorization
to access production. Save the following as `$REHEARSAL_ROOT/rehearsal.php` with
mode 0600. Run from the existing repository. Put the string-valued settings from
section 8A into `$REHEARSAL_ROOT/config.json`, mode 0600, including fresh local
DB_USERNAME/DB_PASSWORD and DB_MIGRATION_USERNAME/DB_MIGRATION_PASSWORD. No
production secret or inherited environment is needed. Create its configured
cover/temp/backup directories privately first.

```php
<?php
declare(strict_types=1);
require getcwd() . '/vendor/autoload.php';
$v = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
foreach ($v as $k => $value) {
    if (!is_string($value) || str_ends_with($k, '_FILE')) {
        throw new RuntimeException('Use explicit string settings, no *_FILE keys.');
    }
}
if (($v['APP_ENV'] ?? '') !== 'testing' || ($v['APP_ROLE'] ?? '') !== 'ops'
    || ($v['DB_HOST'] ?? '') !== '127.0.0.1'
    || !preg_match('/^[0-9]+$/D', $v['DB_PORT'] ?? '')
    || (int)$v['DB_PORT'] < 1024 || (int)$v['DB_PORT'] > 65535
    || (int)$v['DB_PORT'] === 3306
    || !preg_match('/^[a-z0-9_]+_rehearsal$/D', $v['DB_DATABASE'] ?? '')
    || ($v['METADATA_PROVIDERS'] ?? '') !== 'none') {
    throw new RuntimeException('Unsafe rehearsal configuration.');
}
$services = new DigitalLibrary\Core\Services(
    new DigitalLibrary\Core\Config($v), getcwd()
);
exit((new DigitalLibrary\Console\Console($services))->run(
    ['bin/console', ...array_slice($argv, 2)]
));
```

```bash
rehearsal() {
  php "$REHEARSAL_ROOT/rehearsal.php" "$REHEARSAL_ROOT/config.json" "$@"
}
```

Owner verifies local DB identity and grants before the first operation; the
wrapper validates the configured destination but cannot establish which process
actually listens on that port. Do not point any helper at the normal database.
For an enforcement-enabled diagnostic, deliberately change only the local JSON's
HOUSEHOLDS_ENABLED to "true". Production activation remains prohibited.

## Appendix B — exact read-only fingerprint helper for the owner

Save as a private `fingerprint.php` outside the repository. It accepts a private
JSON file with DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD. At the
source, **only the owner** may supply a read-only source account and execute it
during the quiescent backup window. Locally, use the rehearsal configuration and
account. It prints no raw cells, but its hashes/counts remain confidential. It
does not migrate or mutate rows. It reads whole tables in order; budget runtime
and memory against the restored data before use on the source.

```php
<?php
declare(strict_types=1);
$cfg = json_decode(file_get_contents($argv[1]), true, 32, JSON_THROW_ON_ERROR);
$p = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    $cfg['DB_HOST'], (int)$cfg['DB_PORT'], $cfg['DB_DATABASE']),
    $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_STRINGIFY_FETCHES => true,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
$ident = static function (string $s): string {
    if (!preg_match('/^[A-Za-z0-9_]+$/D', $s)) {
        throw new RuntimeException('Unexpected identifier.');
    }
    return '`' . $s . '`';
};
$hex = static fn($x) => $x === null ? null : bin2hex((string)$x);
$p->exec("SET time_zone = '+00:00'");
$p->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
$p->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
try {
    $schema = [];
    if (isset($argv[2])) {
        $schema = json_decode(file_get_contents($argv[2]), true, 64,
            JSON_THROW_ON_ERROR)['schema'];
    } else {
        $tables = $p->query("SHOW FULL TABLES WHERE Table_type='BASE TABLE'")
            ->fetchAll(PDO::FETCH_COLUMN);
        sort($tables, SORT_STRING);
        foreach ($tables as $t) {
            $q = $p->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? ORDER BY ORDINAL_POSITION');
            $q->execute([$t]);
            $cols = $q->fetchAll(PDO::FETCH_COLUMN);
            $q = $p->prepare("SELECT COLUMN_NAME FROM information_schema.STATISTICS
                WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME='PRIMARY'
                ORDER BY SEQ_IN_INDEX");
            $q->execute([$t]);
            $pk = $q->fetchAll(PDO::FETCH_COLUMN);
            if (!$pk) { throw new RuntimeException('Table has no primary key: ' . $t); }
            $schema[$t] = ['columns' => $cols, 'pk' => $pk];
        }
    }
    $result = [];
    foreach ($schema as $t => $s) {
        $contexts = [];
        foreach ($s['columns'] as $c) { $contexts[$c] = hash_init('sha256'); }
        $keys = hash_init('sha256');
        $q = $p->query('SELECT ' . implode(',', array_map($ident, $s['columns']))
            . ' FROM ' . $ident($t) . ' ORDER BY '
            . implode(',', array_map($ident, $s['pk'])));
        $n = 0;
        while ($r = $q->fetch()) {
            $key = array_map(static fn($c) => $hex($r[$c]), $s['pk']);
            hash_update($keys, json_encode($key, JSON_THROW_ON_ERROR) . "\n");
            foreach ($s['columns'] as $c) {
                hash_update($contexts[$c], json_encode([$key, $hex($r[$c])],
                    JSON_THROW_ON_ERROR) . "\n");
            }
            ++$n;
        }
        $result[$t] = ['count' => $n, 'pk_sha256' => hash_final($keys),
            'columns_sha256' => array_map(static fn($h) => hash_final($h), $contexts)];
    }
    echo json_encode(['schema' => $schema, 'tables' => $result],
        JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT), "\n";
} finally {
    $p->exec('ROLLBACK');
}
```

Run with umask 077 and stopped writers. For source capture use only the owner's
approved source config; never copy it into the rehearsal config or repository.
`source.json` means fingerprint output; `source-config.json` means credentials.

```bash
umask 077
# Owner only, during the same quiescent window as the source backup:
php fingerprint.php source-config.json > source.json
# Local restored target, selecting precisely the source columns:
php fingerprint.php rehearsal-config.json source.json > restored.json
# After local ordered migrations, still selecting the baseline columns:
php fingerprint.php rehearsal-config.json source.json > migrated.json
```

Compare every count, PK digest and column digest programmatically. Expected
exceptions are limited to the documented omitted transient rows, added audit
events/migration rows and explicit migration/assignment changes listed in section
8. Reject unexplained differences. To inspect newly added columns/tables, take
another snapshot without the baseline argument and run the semantic SQL checks.
Inspect SHOW CREATE TABLE separately to verify types/indexes/FKs; cell hashes do
not prove schema correctness. If source already has household assignments,
maintain an ID-keyed exception ledger instead of ignoring whole household columns.

For each pair, this exact comparison reports every difference and exits nonzero
until the owner has reconciled the expected exceptions (do not silently suppress
whole tables or fields):

```bash
python3 - source.json migrated.json <<'PY'
import json, sys
before, after = [json.load(open(p))['tables'] for p in sys.argv[1:]]
differences = []
for table, old in before.items():
    new = after.get(table, {})
    for key in ('count', 'pk_sha256'):
        if old[key] != new.get(key):
            differences.append((table, key))
    for column, digest in old['columns_sha256'].items():
        if digest != new.get('columns_sha256', {}).get(column):
            differences.append((table, column))
for table, field in differences:
    print(table, field)
raise SystemExit(bool(differences))
PY
```


## Remediation addendum — 2026-11-15

The original audit above is preserved. Its original untracked contents had SHA-256
`68eb8659f88bb73d0838275e8398378fb56a083daa02a09cd740b3e9004970ee`.
The owner subsequently authorized focused remediation on household-access-h4.
See [H1_H4_REMEDIATION.md](H1_H4_REMEDIATION.md) for root cause, transaction/lock
design, complete dispositions, evidence, remaining decisions and commit commands.

| Finding | Remediation status |
|---|---|
| F1 High | Confirmed and fixed: fresh transactional admin authorization; account locks retained through commit; stale objects/forms cannot authorize writes. Two-process commit-order regressions pass. |
| F2 Medium | Domain DB mutation/audit atomicity fixed across administration, invitations and ordinary catalogue/inventory workflows. Existing H4 atomic audit preserved. Session/filesystem/operational side effects and audit tamper resistance remain separately documented owner decisions. |
| F3 Medium | Production image marker independently forces production; Compose pins APP_ENV and clears APP_ENV_FILE. Existing household prohibitions remain. Owner rebuild/deployment required; no actual production setting changed. |
| F4 Medium | Applied checksums now fail closed; durable migration-attempt metadata refuses incomplete replay. Released SQL/checksums untouched. Manual same-engine recovery documented; no automatic object deletion/recreation or history rewriting. |
| F5 Low | Reproduced; conservative stale-plan refusal retained and regression-tested. Fresh preview remains the recovery. |
| F6 Low | Current security/architecture guidance corrected; historical phase evidence explicitly superseded. |
| H1 borrower/owner hypothesis | Reproduced using the original committed services, then fixed with sorted pre-record account locks. In-flight target-deactivation regression passes. |
| Other contention/restored-data/browser hypotheses and gates | Individually classified in the remediation report; not silently discarded or claimed verified. |

Final local verification: full PHPUnit on **MariaDB 11.4.13** and **MySQL 8.4.13**,
each **998 tests / 13,344 assertions, no skips, PASS**, PHP 8.4.27. Chrome and Edge
each passed 95 checks; native nginx/PHP-FPM passed 54. PHP/Composer lint/validation,
frozen migrations and git diff --check passed. No Docker, remote CI, production
rehearsal, PHP 8.3 or Firefox/WebKit/accessibility certification is claimed.

Updated PR verdict: F1's merge blocker is resolved; ready for owner review/new CI,
with explicit residual decisions described in the report. **Production activation
remains NOT READY / NOT AUTHORIZED**. All remediation is uncommitted.
