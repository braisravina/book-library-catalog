# Phase H4 implementation and verification report

Workspace: `/home/brais/Projects/book-library-catalog/`.
Branch: `household-access-h4`.
Verified H3 baseline/HEAD: `a54a02b921dfb3a89412266a70e67a4d627edccb`.
Baseline tree: `3d88da00a95fa36dae94cee7a04594b464d22e99`.
H3 implementation commit: `134a03ae505fac50271a8755646dc524e28b5c44`.

## 1. Implementation summary

Implemented the approved H4 scope on the clean, committed H3 baseline. All
changes remain uncommitted. No additional branch, worktree or project directory
was created. No production remote, SSH, Cloudflare, Raspberry Pi, Docker daemon
or production database was accessed. No Docker installation was performed.

H4.1: new additive operation/item tables, immutable snapshots and digest-bound
confirmation, a read-only shared validation engine and explicit administrative
plan saving. NULL source is distinct from the protected default household.
Selection carries exact ids and versions; no destination is inferred from
owner/title. Saving a plan changes only plan/item rows, not inventory or audit.

H4.2: one atomic engine for individual/bulk transfers, 1–100 configurable copies
per batch (default 100). All conditions are rechecked under locks. Copy identity,
metadata references, identifiers, acquisition fields, condition, notes, ownership
and every loan relationship are preserved. Application and completion/audit share
one transaction. Completed confirmation is idempotent, including parallel submits.
Failed validation never skips items or commits a subset. Cancel is explicit;
a lost response is resolved by reopening the durable operation id.

Existing copy-edit endpoints reject household and owner changes while enabled,
including for administrators. Members retain same-household own-copy editing.
Edit forms offer the fixed household/owner and only that household's locations.
Initial copy creation retains H3 placement rules. Cross-household assignment uses
CopyHousehold resolution in the H4 service, not raw request values.

Ownership is a separately confirmed, single-copy operation within the same
household and location. It never grants membership. The new owner must be active
and a household member or administrator. Any loan history, including returned
loans, blocks reassignment to prevent disclosure under the current owner-based
historical-loan authorization model. Legacy feature-disabled ownership editing
also cannot transfer a copy with loan history to another owner. No loan record
is erased or rewritten to make an assignment possible.


For an ownership-only operation that preserves a NULL household, the replacement
owner must be an active administrator: NULL is no household to which a member
can belong. Assign to a real household through the reviewed workflow before
reassigning to an eligible non-administrator.

H4.3: complete location subtree movement, preserving ids, hierarchy and contained
copy references. Limits are 100 nodes and the configured copy cap. Mixed trees,
cycles/invalid destination ancestry, name conflicts, active loans, ineligible
owners, changed descendants and changed contained-copy sets reject the whole
operation. An oversized subtree is refused, never silently divided. Additive
location row_version supports missing/stale HTTP edit refusal and is incremented
by current location writers. Transfer snapshots check every node and copy.

H4.4: server-rendered inventory overview, default/NULL views, individual/bulk and
subtree selection, dry run, saved preview, confirmation, cancellation, result and
history. Administrator diagnostics show dependencies and code/mapping conflicts.
English, Spanish and Galician strings, accessible labelled forms, mobile layouts
and essential no-JavaScript operation are covered. Every new route is classified.

H4.5: guarded local-only read-only inventory:check diagnostics, full operational
and deployment documentation, restored-data/migration safety guidance and final
verification. No mutating production-preparation CLI or flag bypass was added.
Default retention and nullable household columns are deliberate approved outcomes.
No NOT NULL migration, arbitrary backfill, default retirement, media feature,
production activation or deployment is included.

## 2. Complete changed-file inventory

M = modified from HEAD; N = new/untracked. The list includes this report. Nothing
is staged. The previous H3 report remains an unchanged historical artifact.

### Documentation and environment examples

- `M .env.example`
- `M .env.production.example`
- `M ARCHITECTURE.md`
- `M CLAUDE.md`
- `M README.md`
- `M SECURITY.md`
- `M docs/DEPLOYMENT.md`
- `N docs/HOUSEHOLD_INVENTORY.md`
- `M docs/OPERATIONS.md`
- `N docs/PHASE_H4_REPORT.md`

### HTTP endpoints and authorization matrix

- `M config/household_access.php`
- `M config/routes.php`
- `N src/Http/Controllers/Admin/InventoryController.php`
- `M src/Http/Controllers/Admin/LocationController.php`
- `M src/Http/Controllers/Catalogue/CopyController.php`
- `M src/Http/Request.php`

### Additive migrations

- `N database/migrations/2026_11_15_100000_create_inventory_transfers.sql`
- `N database/migrations/2026_11_15_100001_add_location_row_versions.sql`

### Administrator interface and translations

- `M lang/en.php`
- `M lang/es.php`
- `M lang/gl.php`
- `M templates/admin/households/index.php`
- `N templates/admin/inventory.php`
- `N templates/admin/transfer.php`
- `M templates/admin/tree.php`
- `M templates/catalogue/copy_form.php`

### Domain services, repositories, wiring and preparation command

- `M src/Catalogue/CopyService.php`
- `M src/Catalogue/Repository/LocationRepository.php`
- `M src/Console/Console.php`
- `M src/Core/ConfigValidator.php`
- `M src/Core/Services.php`
- `N src/Households/InventoryTransferService.php`
- `M src/Households/LocationService.php`
- `N src/Households/TransferException.php`
- `N src/Households/TransferRepository.php`
- `M src/Households/WriteGuard.php`

### Integration, unit, concurrency and browser verification

- `M tests/Integration/AdminTreeTest.php`
- `M tests/Integration/BackupRecoveryTest.php`
- `M tests/Integration/BackupTest.php`
- `M tests/Integration/HouseholdCopyAssignmentTest.php`
- `M tests/Integration/HouseholdFeatureDisabledTest.php`
- `N tests/Integration/HouseholdPhaseFourMigrationTest.php`
- `M tests/Integration/HouseholdPhaseThreeMigrationTest.php`
- `N tests/Integration/HouseholdTransferTest.php`
- `M tests/Integration/HouseholdWriteAuthorizationTest.php`
- `M tests/Support/concurrency_worker.php`
- `N tests/Support/h4_browser_fixture.php`
- `N tests/Support/h4_browser_router.php`
- `N tests/Support/h4_browser_test.py`
- `N tests/Unit/Core/HouseholdTransferConfigTest.php`
- `N tests/Unit/Database/HouseholdMigrationFilesTest.php`
- `M tests/Unit/Security/HouseholdAccessMatrixTest.php`

## 3. New migrations and compatibility

| Exact migration filename | Changes | Preservation and rollback |
|---|---|---|
| `2026_11_15_100000_create_inventory_transfers.sql` | Adds inventory_transfers and inventory_transfer_items, status/kind checks, actor FK, operation/item relationship and lookup index. Payload is application-decoded text, without engine-specific JSON query operators. | Existing tables/data are untouched. Inventory/household/location identifiers in evidence are snapshots, deliberately not cascading FKs that would erase history. Actor deletion is RESTRICT; accounts are normally deactivated. Older code can ignore these tables. No automatic down migration. |
| `2026_11_15_100001_add_location_row_versions.sql` | Adds locations.row_version INT UNSIGNED NOT NULL DEFAULT 1. | Existing location ids, edges, household values, names and descriptions survive. The dedicated upgrade test compares every prior inventory field/relationship. Old named-column writers remain schema-compatible but do not provide H4 version guarantees. Household_id stays nullable on copies and locations. |

Both migrations execute unchanged on MariaDB 11.4.13 and MySQL 8.4.13; no new
MariaDB override is needed. Migration idempotency, preserved nullable compatibility,
legacy omitted-column INSERTs and nonempty transfer-plan backup/restore are tested.
Frozen checksums were added for all seven released H1/H3 migrations; the existing
MigrationFilesTest pins/exception remain intact. No released SQL was modified.

The H3 stored generated inventory_scope_id and scoped unique index remain in
place. Scope 0 still protects NULL-household copies. H4 updates household_id only
after explicit validation, never writes the generated column and never changes
inventory codes. Code comparisons use actual database collation equality and
check both the destination and selected batch. Case/accent-equivalence conflicts
are tested on both engines. Do not assume trailing-space equality is identical
between engine/collation variants. The database constraint remains authoritative.

New table creation and location ALTER need migrator DDL privileges; runtime
accounts remain DML-only. Measure location ALTER runtime, lock behavior and disk
headroom on an owner-controlled restored copy rather than assuming online/zero-
cost DDL. A deployment that has not applied H1/H3 must also budget their existing
copies rebuild. H4 itself does not rebuild or rewrite copy records during migration.
Schema rollback is not automatic. Retain additive schema for compatible code
rollback, recognizing the behavioral limitations below.

## 4. Security and concurrency assessment

H4 entry points require the household feature and administrator permissions;
InventoryTransferService repeats permission checks using the fresh actor inside
WriteGuard. The service also refuses calls while the feature is disabled.
Changing a session, hidden field, operation id or confirmation digest grants no
authority. GETs, POSTs, validation errors and history are administrator-only;
member/viewer tests exercise known and nonexistent ids without inventory canaries.
CSRF stays active. All SQL values are bound; selection/versions/types are strict
and bounded, with duplicate/nested/malformed identifiers rejected. Output is
escaped and no new inline scripts/styles, uploads or external HTTP are introduced.

Lock order is household rows (exclusive for H4), all required user accounts in
ascending id order, transfer operation, all editions, all works, all copies, then
locations. Metadata locks are shared for inventory changes. Loan checks follow
the copy lock; LoanService must lock the copy/households before changing a loan.
H4 does not update loan rows. The new operation lock level does not invert H3
services, which never acquire an operation row after holding inventory locks.
Extra account ids determine lock order only; actor authorization is always
rebuilt from database state. Membership removal/household deletion serialize with
H4 on household locks, and owner/account eligibility is revalidated at confirmation.

New holdings and metadata-edit exclusivity serialize through the existing
edition/work locks. Ordinary enabled H3 writers share household locks; location
writes already use exclusive household locks. Deadlock retry remains database-
only; final timeout/exhaustion rolls back. Broad exclusive locking favors
correctness for small installations and increases contention: measure before
raising scale; the supported copy cap cannot exceed 100.

Transactional evidence: each copy emits inventory.transferred; one
inventory.transfer_completed records reason/kind/copy/location ids. AuditLogger
uses the same PDO transaction as inventory/status updates. The audit actor is
the administrator who actually applies, while the plan actor records its creator.
Audit failure rolls back all copy/location/status changes. Cancel emits its own
transactional event; repeat cancellation/completion does not duplicate writes.
Concurrent confirmation is exercised with separate worker processes and commits
one result. Stored plans are immutable and can become stale; they never serve as
an exemption from current authorization, loans, membership or uniqueness rules.

Active loans and contradictory on_loan status block transfers. Returned history
is retained, including borrower references. After movement, old household URLs
lose physical-copy visibility on the next request; the same owner remains able
to manage permitted history, and H3 borrower/owner/admin privacy rules remain.
Reassignment of historical ownership is intentionally unsupported until a new
historical-access model is separately approved.

Residual H3 policy disclosures are unchanged: duplicate ISBN entry can reveal
shared bibliography, and losing shared-record edit permission signals loss of
exclusivity. Neither identifies another household's physical inventory. Tests
are evidence for exercised schedules and boundaries, not a formal proof of all
possible concurrent database activity. Arbitrary direct SQL and mixed old/new
writers are outside service-lock guarantees. Production was not inspected.

## 5. Final database verification

| Engine | Tests | Assertions | Failures | Errors | Skipped | Runtime |
|---|---:|---:|---:|---:|---:|---|
| MariaDB 11.4.13 | 983 | 13080 | 0 | 0 | 0 | 01:49.619 |
| MySQL 8.4.13 | 983 | 13080 | 0 | 0 | 0 | 02:28.880 |

Each engine executed 633 unit tests / 7,469 assertions and 350 integration
tests / 5,611 assertions, with --fail-on-skipped and JUnit output. Both complete
runs began after the last PHP/template/test source modification. The final
NULL-household ownership regression passed as part of these totals.

| Included subset (each engine) | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| HouseholdTransferTest | 36 | 222 | 0 / 0 / 0 |
| HouseholdPhaseFourMigrationTest | 1 | 15 | 0 / 0 / 0 |
| HouseholdReadIsolationTest | 15 | 1337 | 0 / 0 / 0 |
| HouseholdWriteAuthorizationTest | 23 | 653 | 0 / 0 / 0 |
| HouseholdWriteConcurrencyTest | 12 | 161 | 0 / 0 / 0 |
| HouseholdFeatureDisabledTest | 6 | 96 | 0 / 0 / 0 |
| HouseholdAccessMatrixTest | 7 | 1533 | 0 / 0 / 0 |
| HouseholdMigrationFilesTest | 1 | 7 | 0 / 0 / 0 |
| HouseholdTransferConfigTest | 5 | 8 | 0 / 0 / 0 |
| MigrationFilesTest | 10 | 259 | 0 / 0 / 0 |
| BackupTest | 9 | 144 | 0 / 0 / 0 |

These subsets are included, not additional tests. The full runs also cover
legacy migrations, invitations, lending, backup recovery, deployment, CSRF,
metadata HTTP fakes and optimistic locking. Logs/JUnit evidence were summarized
here before removing temporary resources.

A final HTTP parser review also tightened malformed array handling for location,
owner and related identifiers: an array must be rejected, never interpreted as
a blank/unlocated choice. The route tests cover that refusal in all three
languages. H3's 939-test results were historical, not substituted. Earlier full H4 runs
identified two outdated expectations: member transfers through copy editing and
the operations-command enumeration. Tests were updated to assert the approved H4
workflow and include the new command. Focused tests also exposed the generic
integer validator's warning-only behavior; H4 limits now have strict validation
in ConfigValidator and service construction. A trailing-space test assumption
was corrected to actual database-collation behavior. Failures were investigated;
the delivery runs supersede intermediate results.

## 6. Browser and additional verification

| Check | Executed result |
|---|---|
| Google Chrome 157.0.8103.78 | 95 checks passed |
| Microsoft Edge 157.0.4341.46 | 95 checks passed |
| Browser matrix, each browser | JS on/off × English/Spanish/Galician; desktop 1366 px and mobile 390 px |
| Covered browser behavior | Admin/member/viewer boundaries, multi-household canaries, exact transfer preview/confirmation, repeat submit, stale subtree refusal, mobile overflow/navigation, H3 location filtering, member same-household edit persistence |
| JavaScript syntax | V8 Runtime.compileScript successfully compiled public/assets/js/app.js in both browsers; JS-enabled page execution reported no runtime exceptions |
| Composer lint | Passed |
| Affected PHP/config/templates/translations | 39 files passed lint |
| Nginx | 54 passed, 0 failed |
| git diff --check | Passed |
| Python browser harness | Syntax parsed successfully |

Browser support uses installed local binaries and Python websockets 15.0.1 over
loopback CDP; no new runtime or Composer dependency was installed. The helper
uses the integration test database guard before fixture resets, temporary browser
profiles/sessions, and a loopback-only PHP test router with FakeHttpClient. CDP
blocks non-test-origin page requests, and background networking is disabled.
No production URLs or live metadata providers were tested. The fixture reader
was corrected to be source-only; the transfer-eligible owner is a separate
multi-household account. Harness selector and note-display assertions were
corrected based on actual fixture/database behavior rather than weakening the
application's checks.

Firefox and WebKit browser matrices were NOT executed; the supplied CDP harness
supports installed Chromium browsers. Node was not available, so V8 compilation
and real execution supplied JavaScript verification instead of node --check.
No Docker builds, PHP 8.3 runtime run, production restore rehearsal, production
security probes or production performance/capacity measurements were performed.
Browser checks used synthetic data only. These limits remain explicit for release.

Reproduce on an isolated loopback test instance, never the normal database:
configure DB_TEST_* dedicated accounts and DB_TEST_DATABASE ending _test; start
PHP with public as document root and tests/Support/h4_browser_router.php as the
router, APP_ENV=testing, HOUSEHOLDS_ENABLED=true, SESSION_SECURE_COOKIE=false,
matching loopback APP_URL and temporary storage/session paths. The web process
must use the dedicated test runtime database/account. Run:

```bash
python3 tests/Support/h4_browser_test.py
H4_BROWSER_BIN=/opt/microsoft/msedge/msedge python3 tests/Support/h4_browser_test.py
```

The default browser-test origin is http://127.0.0.1:38941; H4_BROWSER_ORIGIN can
select a different loopback port. Run fixtures sequentially with PHPUnit, since
both reset the test database. New Chromium profiles are removed by the harness.

## 7. Updated route authorization matrix

All routes are named and classified in config/household_access.php (phase H4,
kind admin); the matrix test validates coverage and authentication/permissions.

| Method | Route | Authorization/behavior |
|---|---|---|
| GET | /admin/inventory | Administrator plus feature gate; overview/dependencies/history only |
| POST | /admin/inventory/preview | Administrator, CSRF; read-only dry-run intent or explicit administrative-plan persistence |
| GET | /admin/inventory/transfers/{id} | Fresh administrator; id/digest never grants access |
| POST | /admin/inventory/transfers/{id}/apply | Fresh locked administrator, CSRF, exact digest and confirmation; atomic revalidation/application or recorded completed result |
| POST | /admin/inventory/transfers/{id}/cancel | Fresh locked administrator and CSRF; planned operations only |

Existing copy-update rules now explicitly require H4 for household/owner changes;
same-household editing retains H3 record checks and optimistic locking. Location
HTTP edits require their new expected version. New routes return no inventory to
members/viewers, and administrators get 404 while the feature is disabled. The
local diagnostic command has a separate restrictive environment/role/lock gate.

## 8. Transfer and migration safety

Every reviewed batch is atomic; a larger inventory project consists of separately
confirmed batches, not a hidden multi-transaction operation. Any invalid member
rejects the complete batch. A subtree is indivisible. Expected versions, current
source, destination, owners, locations, codes, loan state and subtree membership
are rechecked before updates. Narrow UPDATEs preserve copy identities and
business fields. Transfer and loan audit/history are never recreated or deleted.

Default inventory and NULL inventory are separate. Neither collections,
invitations nor memberships are reassigned automatically. The system default is
retained, and household columns remain nullable. Existing H1 broad default
memberships must be reviewed before production enforcement, since members can
read residual default inventory. Unassigned rows are administrator-only when
enforced. Dependency reporting is advisory; actual apply validation is decisive.

Cross-household duplicate codes remain legal but complicate global validation
on feature disablement/older-code rollback. No global index is restored, and no
code is silently changed. Turning the feature off also restores catalogue-wide
reads, so it is not a privacy-safe rollback after separation. Prefer maintenance
and recovery to a reviewed household-aware release. Reversing a transfer requires
a new preview and may be blocked by subsequent loans/codes/memberships.

The inventory:check CLI is a local-only read-only rehearsal tool with an exclusive
operations lock, maintenance mode, explicit administrator, confirmation and
stopped-writer acknowledgement. It refuses production environments, normal port
3306, non-loopback hosts and database names outside *_test/*_rehearsal. The
acknowledgement is not automated proof that writers stopped. No mutating CLI is
supplied; production assignment preparation remains a separately owner-reviewed
procedure, without any production feature bypass in H4.

## 9. Remaining release blockers and readiness

Development H4 scope is implemented for review, subject to the bounded-operation
and historical-ownership restrictions above. Full H1–H4 database tests and the
executed browser checks are reported here; owner review/commit remains pending.

NOT authorized for production migration or enablement. Before a later release:

- Owner review and commit of H4; deliberate review of accepted residual disclosures.
- Verified same-engine backup, protected restored-production-data rehearsal,
  row/id/field/FK reconciliation and measured DDL/disk/transaction budgets.
- Administrator-reviewed assignment, membership, default inventory, collection
  and invitation decisions. No inference or automated distribution.
- A reviewed production assignment procedure; current CLI is intentionally local
  and read-only. Oversized subtree procedures need separate review.
- Explicit rollback/recovery point and data-loss expectations, plus the duplicate-
  code and feature-disable privacy limitations.
- Firefox/WebKit coverage and broader real-device/accessibility review as needed
  for the owner's supported-browser release policy; PHP 8.3 runtime coverage if
  required for the deployment/tooling support matrix.
- Separate owner-authorized activation release addressing BOTH ConfigValidator
  and Services restrictions; neither safeguard was weakened here.

There are no H4 NOT NULL/default-retirement/media migrations waiting to be run:
those were deliberately excluded, not silently performed. Future books/DVDs/
Blu-rays/CDs/magazine issues should reuse stable physical copy IDs. Magazine
barcode/ISSN/issue metadata validation and approved provider lookup are future
work and were not implemented.

## 10. Recommended owner commit groups

Commands below are proposals only. Run in the existing development directory,
after review, on household-access-h4 at the stated base. No identity/signing/hook
options are used. Every file is explicitly named; .env, vendor and secrets are
excluded. Implementation stages were reviewed/tested incrementally, but the final
schema/services/UI/regressions share files and dependencies: keep them in one
coherent functional commit, followed by documentation.

### H4 implementation, schema, UI and tests

Exact files to stage:

```bash
git add -- \
  config/household_access.php \
  config/routes.php \
  database/migrations/2026_11_15_100000_create_inventory_transfers.sql \
  database/migrations/2026_11_15_100001_add_location_row_versions.sql \
  lang/en.php \
  lang/es.php \
  lang/gl.php \
  src/Catalogue/CopyService.php \
  src/Catalogue/Repository/LocationRepository.php \
  src/Console/Console.php \
  src/Core/ConfigValidator.php \
  src/Core/Services.php \
  src/Households/InventoryTransferService.php \
  src/Households/LocationService.php \
  src/Households/TransferException.php \
  src/Households/TransferRepository.php \
  src/Households/WriteGuard.php \
  src/Http/Controllers/Admin/InventoryController.php \
  src/Http/Controllers/Admin/LocationController.php \
  src/Http/Controllers/Catalogue/CopyController.php \
  src/Http/Request.php \
  templates/admin/households/index.php \
  templates/admin/inventory.php \
  templates/admin/transfer.php \
  templates/admin/tree.php \
  templates/catalogue/copy_form.php \
  tests/Integration/AdminTreeTest.php \
  tests/Integration/BackupRecoveryTest.php \
  tests/Integration/BackupTest.php \
  tests/Integration/HouseholdCopyAssignmentTest.php \
  tests/Integration/HouseholdFeatureDisabledTest.php \
  tests/Integration/HouseholdPhaseFourMigrationTest.php \
  tests/Integration/HouseholdPhaseThreeMigrationTest.php \
  tests/Integration/HouseholdTransferTest.php \
  tests/Integration/HouseholdWriteAuthorizationTest.php \
  tests/Support/concurrency_worker.php \
  tests/Support/h4_browser_fixture.php \
  tests/Support/h4_browser_router.php \
  tests/Support/h4_browser_test.py \
  tests/Unit/Core/HouseholdTransferConfigTest.php \
  tests/Unit/Database/HouseholdMigrationFilesTest.php \
  tests/Unit/Security/HouseholdAccessMatrixTest.php
git commit -m 'feat(households): add reviewed inventory transfers and migration diagnostics'
git log -1 --format=fuller
git cat-file -p HEAD
```

### H4 documentation and delivery

Exact files to stage:

```bash
git add -- \
  .env.example \
  .env.production.example \
  ARCHITECTURE.md \
  CLAUDE.md \
  README.md \
  SECURITY.md \
  docs/DEPLOYMENT.md \
  docs/HOUSEHOLD_INVENTORY.md \
  docs/OPERATIONS.md \
  docs/PHASE_H4_REPORT.md
git commit -m 'docs: document H4 transfer workflows and release safeguards'
git log -1 --format=fuller
git cat-file -p HEAD
```

## 11. Temporary-resource cleanup

Created two new disposable instances, never the normal local databases:

| Resource | Verified identity | Cleanup |
|---|---|---|
| MySQL 8.4.13 | brais PID 561546, loopback 33941, /tmp/dla-h4-tests/mysql/data | Normal SQL SHUTDOWN via its exact socket; shutdown log confirmed completion; PID and listener absent |
| MariaDB 11.4.13 | brais PID 561609, bwrap parent 561606, loopback 33942, /work bound to /tmp/dla-h4-tests/mariadb | Normal SQL SHUTDOWN via exact socket; shutdown log confirmed completion; both PIDs and listener absent |
| PHP browser server | brais PID 670012, loopback 38941, tests/Support/h4_browser_router.php | Verified exact uid/command and sent SIGTERM after tests; PID/listener absent |
| Browser profiles | /tmp/dla-h4-chrome-* created by the harness | Removed by harness/final cleanup; residual profile dla-h4-chrome-9_diopb1 was verified owned and unused by browser/crash-handler processes, then removed |

Before shutdown, database process lists contained no test or other application
clients: only the inspecting connection and MySQL's internal scheduler. TCP
connection inspection was empty. Instance versions, paths, owners and ports were
verified. System mysqld PID 1392 (mysql, port 3306) remained running; it was not
connected to, stopped, reconfigured or modified.

After normal exits, removed the exclusive /tmp/dla-h4-tests tree, including both
data directories, temporary browser sessions/storage, sockets and logs. Its
complete top-level contents were checked against the known created resource list
before removal. Also removed the verified generated test-cover directories for
terminated test PIDs 587495, 590196, 590380, 616106, 642448, 671680, 673699,
674143, 698173, 704757, 733875, 759902, 785881, 815448, 841391, 867355 and
893370. They were owned by the test user, followed IntegrationTestCase's PID
naming, and dated after this session's database initialization. Older cover
artifacts and the prior H3 MariaDB binary/image assets and scratchpad were
retained: they were not created exclusively for H4 and were not deleted.

Temporary H4 launch/environment scripts, PHPUnit/JUnit/browser/lint logs and the
rules-read scratch file were removed after extracting this report's results.
No owned test database, browser server or test port remains. Source tests and
browser harnesses in the repository are deliverables and remain uncommitted.


## 12. Final Git state

Branch household-access-h4; HEAD a54a02b921dfb3a89412266a70e67a4d627edccb.
Working tree: 35 tracked modified files, 17 new/untracked files including this
report; no staged changes. No commit, push, merge, reset, stash, branch/worktree
operation, Git remote access, identity/signing/hook edit or deployment occurred.
The owner performs all commits and releases. H4 ends here for owner review;
media expansion and production activation have not begun.
