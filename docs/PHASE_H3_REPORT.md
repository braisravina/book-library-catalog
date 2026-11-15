# Phase H3 verification and delivery report

Date: 2026-11-14. Workspace: `/home/brais/Projects/book-library-catalog/`.
Branch: `household-access-h2`. HEAD/base: `5f1b8df12fb45eef245bb1cdc5e2de2ccb1d0b51`.

## A. Implementation summary

The existing uncommitted H3 implementation was inspected and preserved. It was
not restarted or replaced. H1/H2 remain committed; H4 and media work were not
started. Production infrastructure, remote Git, Docker and SSH were not used.

Implemented H3 behavior:

- WriteGuard opens READ COMMITTED transactions, refreshes the active actor and
  memberships under locks, and services repeat object authorization. It refuses
  nested guarded transactions, rolls back failures and retries deadlock victims.
- Shared work/edition edits require an administrator, or the creator while the
  record is exclusive to their authorized households. Covers, including provider
  imports, follow edition authorization. Authors/publishers remain admin-only.
- CopyService handles creation, editing and deletion, explicit household selection,
  ownership, matching locations, optimistic locking, loan constraints and scoped
  inventory codes. Copy ownership remains distinct from household membership.
- Add-copy-by-ISBN reuses shared bibliographic records without exposing physical
  inventory or accepting an inaccessible edition id as an alternative shortcut.
- Lending/returns revalidate household access under copy/loan locks; account
  borrowers must be active household members, including for administrator lenders.
  Free-text borrowers remain supported; active loans prevent household transfers.
- Shared collections have one household; private collections remain private.
  Names/descriptions obey collection visibility; item reads/counts and ordering
  respect edition visibility. Collections do not grant access to inventory.
- Wishlist entries remain owner-only; ISBN links use visible editions and links
  that become inaccessible are hidden and refreshed on a later edit.
- Location trees belong to households, children inherit their parent's household,
  and cross-household joins/reassignment are refused while enabled.
- Administrator-created accounts receive only explicitly selected memberships;
  invitations store assignments and redeem them transactionally. Default is none.
- Administrator catalogue exports add household id/name only while enabled;
  personal exports retain household filtering. Memberships are not exported.
- Routes, forms, translations, environment examples and documentation reflect H3.

Focused corrections made in this session:

1. Reproduced concurrent duplicate root creation: the original service accepted
   an uncommitted duplicate because nullable `parent_id` is not protected by the
   sibling unique index. Location mutations now lock household rows exclusively
   before account/location locks, even while disabled. This also serializes
   disjoint subtree moves that could form a cycle. Added three regression cases
   (enabled/disabled roots and disjoint moves).
2. Invitation replacement/redemption now acquire shared household locks before
   invitation/account rows. This removes the inverse order with household
   deletion and its cascading invitation assignments; assignment checks do not
   upgrade those locks afterwards.
3. Pending invitation assignments are ignored when households have subsequently
   been disabled, including replacement invitations. Added a regression covering
   redemption and replacement after disabling the feature.
4. Tightened MigrationFilesTest's permitted DROP INDEX exception to a complete
   identifier match. A name with the allowed name as a prefix no longer qualifies.
   Released SQL and pinned checksums were not changed.
5. Corrected the household-deletion matrix description to include shared collections;
   documented location serialization and rollback/disable collisions explicitly.

## B. Changed-file inventory

`M` means modified relative to HEAD; `N` means new/untracked. This includes the
complete pre-existing H3 working tree and this report. No files are staged.

### Documentation and environment examples

- `M .env.example`
- `M .env.production.example`
- `M ARCHITECTURE.md`
- `M CLAUDE.md`
- `M README.md`
- `M SECURITY.md`
- `M docs/DEPLOYMENT.md`
- `M docs/OPERATIONS.md`
- `N docs/PHASE_H3_REPORT.md`

### Routing, wiring and database support

- `M config/household_access.php`
- `M config/routes.php`
- `M src/Core/Application.php`
- `M src/Core/ConfigValidator.php`
- `M src/Core/Services.php`
- `M src/Database/Engine.php`

### Database migrations (new)

- `N database/migrations/2026_11_14_100000_scope_inventory_codes_to_households.sql`
- `N database/migrations/2026_11_14_100001_add_household_to_collections.sql`
- `N database/migrations/2026_11_14_100002_backfill_shared_collection_households.sql`
- `N database/migrations/2026_11_14_100003_create_invitation_households.sql`

### Forms, translations and browser behavior

- `M lang/en.php`
- `M lang/es.php`
- `M lang/gl.php`
- `M public/assets/js/app.js`
- `M templates/admin/invitations/form.php`
- `M templates/admin/invitations/index.php`
- `M templates/admin/tree.php`
- `M templates/admin/users/create.php`
- `M templates/catalogue/book_form.php`
- `M templates/catalogue/copy_form.php`
- `M templates/catalogue/duplicate.php`
- `M templates/catalogue/edition_show.php`
- `M templates/catalogue/work_show.php`
- `M templates/collections/form.php`
- `M templates/collections/index.php`
- `M templates/collections/show.php`
- `N templates/partials/household_checkboxes.php`
- `N templates/partials/location_options.php`

### Domain services, repositories and authorization

- `M src/Auth/UserAdministration.php`
- `M src/Auth/UserRepository.php`
- `M src/Catalogue/BookService.php`
- `N src/Catalogue/CopyHasHistoryException.php`
- `N src/Catalogue/CopyService.php`
- `N src/Catalogue/CopyValidationException.php`
- `N src/Catalogue/RecordInUseException.php`
- `M src/Catalogue/Repository/CopyRepository.php`
- `M src/Catalogue/Repository/CoverRepository.php`
- `M src/Catalogue/Repository/EditionRepository.php`
- `M src/Catalogue/Repository/LocationRepository.php`
- `M src/Catalogue/Repository/WorkRepository.php`
- `M src/Collections/CollectionException.php`
- `M src/Collections/CollectionInput.php`
- `M src/Collections/CollectionRepository.php`
- `M src/Collections/CollectionService.php`
- `M src/Export/CatalogueExport.php`
- `M src/Export/ExportSource.php`
- `M src/Households/HouseholdRepository.php`
- `M src/Households/HouseholdService.php`
- `N src/Households/LocationException.php`
- `N src/Households/LocationService.php`
- `N src/Households/WriteActor.php`
- `N src/Households/WriteBusyException.php`
- `N src/Households/WriteDeniedException.php`
- `N src/Households/WriteGuard.php`
- `M src/Invitations/InvitationRepository.php`
- `M src/Invitations/InvitationService.php`
- `M src/Loans/LoanRepository.php`
- `M src/Loans/LoanService.php`
- `M src/Metadata/CoverImporter.php`
- `M src/Security/Authorizer.php`
- `M src/Security/HouseholdScope.php`
- `M src/Security/Visibility.php`
- `M src/Wishlist/WishlistRepository.php`
- `M src/Wishlist/WishlistService.php`

### HTTP controllers

- `M src/Http/Controllers/Admin/HouseholdController.php`
- `M src/Http/Controllers/Admin/InvitationController.php`
- `M src/Http/Controllers/Admin/LocationController.php`
- `M src/Http/Controllers/Admin/TreeAdminController.php`
- `M src/Http/Controllers/Admin/UserController.php`
- `M src/Http/Controllers/Catalogue/CatalogueBaseController.php`
- `M src/Http/Controllers/Catalogue/CatalogueController.php`
- `M src/Http/Controllers/Catalogue/CopyController.php`
- `M src/Http/Controllers/Catalogue/CoverController.php`
- `M src/Http/Controllers/Catalogue/EditionController.php`
- `M src/Http/Controllers/Catalogue/WorkController.php`
- `M src/Http/Controllers/Library/CollectionController.php`
- `M src/Http/Controllers/Library/ExportController.php`
- `M src/Http/Controllers/Library/LoanController.php`
- `M src/Http/Controllers/Library/WishlistController.php`
- `M src/Http/Controllers/RegistrationController.php`

### Tests and test support

- `M tests/Integration/BackupTest.php`
- `M tests/Integration/HouseholdAdminTest.php`
- `N tests/Integration/HouseholdFeatureDisabledTest.php`
- `M tests/Integration/HouseholdMigrationTest.php`
- `N tests/Integration/HouseholdPhaseThreeMigrationTest.php`
- `M tests/Integration/HouseholdReadIsolationTest.php`
- `N tests/Integration/HouseholdWriteAuthorizationTest.php`
- `N tests/Integration/HouseholdWriteConcurrencyTest.php`
- `M tests/Integration/IntegrationTestCase.php`
- `M tests/Integration/InvitationTest.php`
- `N tests/Support/concurrency_worker.php`
- `M tests/Unit/Database/MigrationFilesTest.php`
- `M tests/Unit/Security/AuthorizerTest.php`
- `M tests/Unit/Security/HouseholdAccessMatrixTest.php`

## C. Database migrations

All four are new, forward-only migrations following committed H1/H2. Their
actual SQL runs unchanged on MariaDB 11.4.13 and MySQL 8.4.13; no overrides were
added. Full migration tests exercise upgrade/data preservation on both engines.
No migration was applied outside the disposable local test databases.

| Exact filename | Schema/data change and preservation | Rollback implications |
|---|---|---|
| `2026_11_14_100000_scope_inventory_codes_to_households.sql` | One ALTER adds stored generated `copies.inventory_scope_id INT UNSIGNED AS (COALESCE(household_id, 0))`, adds `uq_copies_scope_inventory_code (inventory_scope_id, inventory_code)` and removes `uq_copies_inventory_code`. Existing codes, rows, households, timestamps and versions are preserved. NULL households share scope 0; NULL codes retain their previous semantics. | Old named-column INSERT/UPDATE statements remain schema-compatible. Global uniqueness cannot simply be restored after cross-household duplicates exist. |
| `2026_11_14_100001_add_household_to_collections.sql` | Nullable household_id, lookup index `(household_id, visibility, name)`, FK to households with DELETE RESTRICT. No existing rows changed. | Old writers omit the nullable column; a shared unassigned collection is visible only to owner/admin once households are enabled. |
| `2026_11_14_100002_backfill_shared_collection_households.sql` | Idempotent data UPDATE assigns only shared collections whose household is NULL to the system default household. Private lists, contents, names, visibility and timestamps stay intact. | No automatic reversal; restoring a pre-migration backup also loses later writes. Requires the H1 default-household backfill and preceding collection column. |
| `2026_11_14_100003_create_invitation_households.sql` | New empty table with composite PK `(invitation_id, household_id)`, reverse lookup index and CASCADE FKs to invitations and households. Existing invitations grant no memberships by default. | Older application can ignore the table. Deleting a pending household removes its assignment rather than preserving an invalid grant. |

Migration 100000 relies on both supported engines' InnoDB atomic DDL. The generated
column is numeric, avoiding the MariaDB CHAR-generated-column incompatibility.
The household FK remains RESTRICT; household_id stays nullable. The migration
rebuilds the copies table: allow maintenance time and space for the rebuild.
Existing globally unique codes necessarily satisfy the new per-scope index.
Tests demonstrate cross-household duplicates succeed, same-household and NULL-
household duplicates fail, and conflicting transfers are rejected.

With households disabled the application deliberately retains its global code
pre-check; the database index remains scoped. Disabling after cross-household
codes have been duplicated, or rolling back application code, can therefore
refuse copy edits even with an unchanged code. Schema compatibility is not full
behavioral rollback. Resolve collisions explicitly before restoring a global
index; never delete copies or silently rewrite codes. Direct SQL or older racing
writers are protected only by the scoped index, not a global database constraint.

Production dependencies for every migration: explicit owner release approval,
H4 decisions, reviewed deployment order (H1 then H3 schema/backfills before H3
writers), a verified same-engine backup/restore plan, appropriate migrator DDL
privileges and maintenance/disk capacity. These are future owner actions, not
executed or authorized by this delivery. HOUSEHOLDS_ENABLED remains false in
both examples and production enablement remains blocked by ConfigValidator.

## D. Security assessment

Guarded household-dependent mutations refresh authorization inside the write
transaction. Household membership changes take an exclusive household lock;
ordinary guarded writes hold shared locks in id order. Once a removal commits,
a later guarded write sees it. Writes already holding the shared lock finish
before removal can commit. Metadata writes lock edition/work exclusively;
inventory writes lock them shared, serializing edits against new holdings.
Lock order is households, users, collections, editions, works, copies, loans,
locations, skipping unused levels. Location mutations use exclusive household
locks; invitation replacement/redemption take household locks before invitation
and new account rows. Locks and transactions protect application-mediated
operations; arbitrary direct SQL is outside these service guarantees.

The tests exercise stale forms, revocation before/in-flight writes, lending,
concurrent inventory codes, real deadlocks, lock timeouts and no partial writes.
CSRF middleware remains active. Work/edition/copy edits retain row_version
checks after authorization. Out-of-scope records yield the same 404 handling as
missing records; visible records without editing permission may yield 403.
Authorization failures do not persist domain changes. Cover upload/import
removes staged image files if the authorized database write fails.

Shared metadata has a residual disclosure: a creator can observe that editing
permission was lost and infer that the exclusivity condition no longer holds.
The current generic refusal and hidden edit controls disclose no household,
owner, location, code or count. They mitigate detail but cannot hide the result
of an attempted edit while retaining the approved rule that exclusive records
are editable and shared ones are not. A 404 or generic form error would still
expose success versus refusal. Fully hiding the transition requires a policy or
workflow change and was not done. Unassigned copies also count as held outside
scope; the signal is not proof of a particular household holding the book.

Inventory-code validation queries only the selected household while enabled;
a same-scope unique-index race becomes the same generic validation message,
without returning another household's code or raw database details. Add-by-ISBN
returns shared bibliography and the actor's own placement options only.
Collections/wishlists filter accessible editions and cannot grant inventory
visibility. Borrower eligibility is checked inside the loan transaction under
the household/copy locks. Location reparenting cannot cross households; root
name/cycle races are now serialized. Invitation memberships are created only
with successful account redemption, in the same transaction, and disabled mode
ignores stored intentions. Export permission gates remain in place.

Scope limits: passing tests establish the exercised cases, not a formal proof
against every possible schedule. Broad household locking favors correctness
for small installations and can increase contention; location changes briefly
block other guarded writes. The existing administrative account/household
services remain admin-only; this session did not redesign general account-role
administration or the H1 policy. No production security state was inspected.

The four requested decisions are confirmed: enabled roots require explicit
households; administrators select account borrowers from the copy's household;
shared collections block household deletion; inventory codes are scoped per
household. Consequences needing owner awareness are cross-household duplicate
codes during rollback/disable, explicit admin root selection, admin borrowers
outside the household requiring a free-text borrower or membership change, and
moving/privatizing collections before deleting their household. No policy was
silently redesigned.

## E. Fresh final verification

| Engine | Tests | Assertions | Failures | Errors | Skipped | Runtime |
|---|---:|---:|---:|---:|---:|---|
| MySQL 8.4.13 | 939 | 12586 | 0 | 0 | 0 | 02:19.390 |
| MariaDB 11.4.13 | 939 | 12586 | 0 | 0 | 0 | 01:27.345 |

Both final runs executed `vendor/bin/phpunit --fail-on-skipped --log-junit ...`
after the last PHP/test-source edit. Each contains 627 unit tests / 7,253
assertions and 312 integration tests / 5,333 assertions. Temporary logs were
summarized here before cleanup; no historical result was substituted.

| Included suite (each engine) | Tests | Assertions | Failures/errors/skips |
|---|---:|---:|---|
| HouseholdReadIsolationTest | 15 | 1337 | 0 / 0 / 0 |
| HouseholdWriteAuthorizationTest | 23 | 653 | 0 / 0 / 0 |
| HouseholdWriteConcurrencyTest | 12 | 161 | 0 / 0 / 0 |
| HouseholdPhaseThreeMigrationTest | 2 | 244 | 0 / 0 / 0 |
| HouseholdFeatureDisabledTest | 6 | 96 | 0 / 0 / 0 |
| MigrationFilesTest | 10 | 248 | 0 / 0 / 0 |

These subsets are included in the totals, not extra tests. Household migration,
copy assignment, admin, backup, invitation and route matrix checks also ran in
the complete suites. Migration checksum tests passed; no released migration or
MariaDB override was edited.

| Additional final check | Result |
|---|---|
| Composer lint | Passed (all PHP under src/public/bin/tests/database) |
| Affected PHP/templates/translations/config lint | 89 files passed |
| Nginx harness | 54 passed, 0 failed |
| git diff --check | Passed |
| UTF-8 and conflict-marker scan | All 103 changed/new files passed, including report |



Historical Claude results are not used as fresh evidence. Initial fresh runs
here passed 935 tests / 12,572 assertions per engine before focused corrections.
The added root regression first failed as intended (1 test, 1 assertion,
1 failure), demonstrating the defect; after correction the three location cases
passed (3 tests, 9 assertions). Invitation focused verification passed 2 tests /
42 assertions. The final full runs below supersede intermediate runs.

JavaScript syntax execution with Node could not run: neither node nor nodejs
is installed. The 25-line browser change was reviewed, and asset/route/template
checks ran within PHPUnit; no browser end-to-end session was performed.
No Docker build, production test, deployment or remote infrastructure check was
run. Initial Nginx sandbox execution failed because loopback binding was blocked;
its complete rerun outside the sandbox passed. Test HTTP provider errors printed
by the suite are intentional offline-fake outcomes, not production requests.

## F. Route authorization matrix

`config/household_access.php` classifies all registered routes; matrix tests check
coverage. New routes are GET `/books/copies/new` (`books.copies.create`) and POST
`/books/copies` (`books.copies.store`), guarded by authentication/copy permission.

| Route family | GET behavior | POST behavior |
|---|---|---|
| Works/editions | Visibility first; editing also requires shared-record authorization | BookService repeats authorization under metadata locks, then row_version |
| Copies | Visible edition/copy required; own placement options | CopyService household/owner/location/code/loan checks under transaction |
| Add-copy-by-ISBN | Shared bibliography only for hidden holdings | Resolve ISBN in transaction; ordinary placement rules; viewers refused |
| Covers | Visible edition before image/ETag handling | Upload/delete/import follows edition editing rule |
| Loans/returns | Visible copy, lender permission, eligible borrower options | Revalidate membership/ownership and borrower under household/copy/loan locks |
| Collections | Household sharing plus owner/admin exception; filtered items/counts | Collection lock, explicit household, visible item checks; visible-only ordering |
| Wishlist | Own items and visible catalogue links | Owner-only guarded writes; visible ISBN linking |
| Locations | Admin-only household trees | Explicit root, inherited child, no cross-household move; serialized tree edits |
| Accounts/invitations | Admin-only assignments; no default membership | Account/assignment atomicity; redemption valid-token transaction; ignored when disabled |
| Exports | Personal scope or administrator catalogue permission | No export mutation endpoint |
| Household administration | Administrator-only | H1 permissions/locks; shared collections additionally block deletion |

No H3 route is deliberately left awaiting H4 authorization. H4 needs newly
reviewed routes/workflows for bulk household transfers and location-subtree
reassignment; they have not been implemented. Admin vocabularies, authentication,
audit viewer and owner-only wishlist status routes retain their existing guards.

## G. Owner commit plan (commands not executed)

Run only after reviewing this diff and report, in the existing project directory
on household-access-h2 at the stated base. Option B: the owner performs every
commit, push, merge and deployment. No identity/signing/hook changes are needed.
Code, schema, UI and their tests should be committed together because these
changes are interdependent. Documentation is a separate reviewable commit.
The commands explicitly name files; they do not stage .env, vendor or secrets.

### H3 implementation and verification

Exact files to stage are the explicit paths in this command:

```bash
git add -- \
  config/household_access.php \
  config/routes.php \
  database/migrations/2026_11_14_100000_scope_inventory_codes_to_households.sql \
  database/migrations/2026_11_14_100001_add_household_to_collections.sql \
  database/migrations/2026_11_14_100002_backfill_shared_collection_households.sql \
  database/migrations/2026_11_14_100003_create_invitation_households.sql \
  lang/en.php \
  lang/es.php \
  lang/gl.php \
  public/assets/js/app.js \
  src/Auth/UserAdministration.php \
  src/Auth/UserRepository.php \
  src/Catalogue/BookService.php \
  src/Catalogue/CopyHasHistoryException.php \
  src/Catalogue/CopyService.php \
  src/Catalogue/CopyValidationException.php \
  src/Catalogue/RecordInUseException.php \
  src/Catalogue/Repository/CopyRepository.php \
  src/Catalogue/Repository/CoverRepository.php \
  src/Catalogue/Repository/EditionRepository.php \
  src/Catalogue/Repository/LocationRepository.php \
  src/Catalogue/Repository/WorkRepository.php \
  src/Collections/CollectionException.php \
  src/Collections/CollectionInput.php \
  src/Collections/CollectionRepository.php \
  src/Collections/CollectionService.php \
  src/Core/Application.php \
  src/Core/ConfigValidator.php \
  src/Core/Services.php \
  src/Database/Engine.php \
  src/Export/CatalogueExport.php \
  src/Export/ExportSource.php \
  src/Households/HouseholdRepository.php \
  src/Households/HouseholdService.php \
  src/Households/LocationException.php \
  src/Households/LocationService.php \
  src/Households/WriteActor.php \
  src/Households/WriteBusyException.php \
  src/Households/WriteDeniedException.php \
  src/Households/WriteGuard.php \
  src/Http/Controllers/Admin/HouseholdController.php \
  src/Http/Controllers/Admin/InvitationController.php \
  src/Http/Controllers/Admin/LocationController.php \
  src/Http/Controllers/Admin/TreeAdminController.php \
  src/Http/Controllers/Admin/UserController.php \
  src/Http/Controllers/Catalogue/CatalogueBaseController.php \
  src/Http/Controllers/Catalogue/CatalogueController.php \
  src/Http/Controllers/Catalogue/CopyController.php \
  src/Http/Controllers/Catalogue/CoverController.php \
  src/Http/Controllers/Catalogue/EditionController.php \
  src/Http/Controllers/Catalogue/WorkController.php \
  src/Http/Controllers/Library/CollectionController.php \
  src/Http/Controllers/Library/ExportController.php \
  src/Http/Controllers/Library/LoanController.php \
  src/Http/Controllers/Library/WishlistController.php \
  src/Http/Controllers/RegistrationController.php \
  src/Invitations/InvitationRepository.php \
  src/Invitations/InvitationService.php \
  src/Loans/LoanRepository.php \
  src/Loans/LoanService.php \
  src/Metadata/CoverImporter.php \
  src/Security/Authorizer.php \
  src/Security/HouseholdScope.php \
  src/Security/Visibility.php \
  src/Wishlist/WishlistRepository.php \
  src/Wishlist/WishlistService.php \
  templates/admin/invitations/form.php \
  templates/admin/invitations/index.php \
  templates/admin/tree.php \
  templates/admin/users/create.php \
  templates/catalogue/book_form.php \
  templates/catalogue/copy_form.php \
  templates/catalogue/duplicate.php \
  templates/catalogue/edition_show.php \
  templates/catalogue/work_show.php \
  templates/collections/form.php \
  templates/collections/index.php \
  templates/collections/show.php \
  templates/partials/household_checkboxes.php \
  templates/partials/location_options.php \
  tests/Integration/BackupTest.php \
  tests/Integration/HouseholdAdminTest.php \
  tests/Integration/HouseholdFeatureDisabledTest.php \
  tests/Integration/HouseholdMigrationTest.php \
  tests/Integration/HouseholdPhaseThreeMigrationTest.php \
  tests/Integration/HouseholdReadIsolationTest.php \
  tests/Integration/HouseholdWriteAuthorizationTest.php \
  tests/Integration/HouseholdWriteConcurrencyTest.php \
  tests/Integration/IntegrationTestCase.php \
  tests/Integration/InvitationTest.php \
  tests/Support/concurrency_worker.php \
  tests/Unit/Database/MigrationFilesTest.php \
  tests/Unit/Security/AuthorizerTest.php \
  tests/Unit/Security/HouseholdAccessMatrixTest.php
git commit -m 'feat: enforce household write authorization and workflows'
git log -1 --format=fuller
git cat-file -p HEAD
```

### H3 documentation and delivery report

Exact files to stage are the explicit paths in this command:

```bash
git add -- \
  .env.example \
  .env.production.example \
  ARCHITECTURE.md \
  CLAUDE.md \
  README.md \
  SECURITY.md \
  docs/DEPLOYMENT.md \
  docs/OPERATIONS.md \
  docs/PHASE_H3_REPORT.md
git commit -m 'docs: document household H3 behavior and verification'
git log -1 --format=fuller
git cat-file -p HEAD
```

## H. Final Git state

Branch `household-access-h2`; HEAD `5f1b8df12fb45eef245bb1cdc5e2de2ccb1d0b51`.
Working tree: 81 tracked modified files and 22 untracked files (103 total,
including this report), zero staged changes. The complete inventory is above.

No commits, pushes, merges, resets, stashes, branch changes, worktrees or Git
identity/signing/hook edits were performed. All pre-existing H3 work and the
focused corrections remain uncommitted for owner review. Released migrations
have no tracked diff and their existing pinned checksum checks pass.

## I. Temporary database cleanup

Before testing, inspected process owners/arguments, socket/port/version/data
paths, namespace bindings and active database sessions outside the sandbox.
Verified MySQL PID 30192 (brais, port 33841, version 8.4.13) and MariaDB PID
56469 (brais, port 33842, version 11.4.13), with bubblewrap parent PID 56464.
Both were detached under the user's systemd session, with no other client
sessions or TCP connections. The only MySQL background connection was its
internal event scheduler. They were reused for isolated local tests; no new
database server was needed.

After all tests, rechecked that only the inspecting root connection and the
internal scheduler remained, then issued normal SQL SHUTDOWN through each exact
socket. Both shutdown logs confirmed completion; PIDs 30192/56469/56464 exited
and ports 33841/33842 had no listener. System mysqld PID 1392 (mysql, port 3306)
was left running and was not connected to or modified.

Removed only the two verified disposable instance directories, including their
data/init/error logs, under:

`/tmp/claude-1000/-home-brais-Projects-book-library-catalog/4784deb7-8649-49c0-9de5-cee7ba663bf2/scratchpad/db/`

- `mysql84/`
- `mariadb114/`

Their servers removed the corresponding sockets, socket lock and PID files
normally. Retained the now-empty `/run/user/1000/dla-h3/` directory; it was not
necessary to remove a directory outside the workspace/temp writable roots.
Retained the prior session's launch/environment scripts and MariaDB binary/image
assets, and the surrounding scratchpad directories: they are not exclusively
live instance state and deleting the shared parent was not authorized.

Removed this session's test-cover directories for terminated PHPUnit PIDs
348137, 373864, 401498, 413517, 453435, 453585, 479921, 505504 and 531871,
identified by the test helper's PID naming, owner, run times and absent processes.
Older test-cover directories predating this session were retained because their
exclusive ownership by these runs was not established. Temporary Codex logs,
JUnit reports and review scratch files were removed after extracting these
results. No new local database resources remain.

## J. Remaining work

H4 remains unstarted: bulk copy transfers, household location/subtree transfers,
NOT NULL/backfill and rollback decisions, retiring the temporary default
household, release review and an explicit owner enablement decision. No current
route provides bulk reassignment. These tasks must preserve loan constraints,
consistent lock ordering, scoped uniqueness and read isolation.

DVD/CD/Blu-ray work remains unstarted. Future media integration must decide its
bibliographic identifiers independently from ISBN, while carrying the same
household physical-inventory, ownership, location, lending and export boundaries.
No media tables, forms, migrations or routes were added.

## K. Deployment readiness

H3 is ready for owner review and committing as a development-only phase,
with the explicit rollback/disable collision limitation and observable metadata
permission transition described above. The confirmed defects found during this
session have focused corrections and final passing verification on both engines.
Review the four behavioral decisions and the extra serialization/disabled-mode
invitation behavior before committing. Browser JavaScript execution remains
unverified because a JavaScript runtime/browser harness is unavailable.

Passing tests are not authorization to deploy. The combined H1-H3 household
feature is NOT ready for production enablement: H4 and explicit owner release
approval remain outstanding. ConfigValidator's production restriction is intact;
HOUSEHOLDS_ENABLED must remain disabled. No production migrations, remote access,
feature activation or deployment occurred.
