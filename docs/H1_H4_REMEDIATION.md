# H1–H4 final audit remediation

Date: 2026-11-15, Europe/Madrid. Base: `d1518243e34f9159fb12c8e7665322aba12d3385`,
branch `household-access-h4`. This is an uncommitted remediation of the existing
H1–H4 implementation, not H5 or a production activation release.

## Outcome and finding dispositions

| Original item | Final disposition | Evidence / remaining boundary |
|---|---|---|
| F1 High: stale administrator authorization | Confirmed and fixed | Original committed services replayed: demoted actor grants membership; inactive actor creates Admin. Fresh transactional actor/account locks now cover household, account, invitation and vocabulary mutations; existing WriteGuard covers inventory/location/catalogue operations. Catalogue exports recheck before streaming. Two connections/processes exercise both commit orders. |
| F2 Medium: mutation commits before audit | Confirmed and fixed for domain database mutations | Domain events moved into their mutation transaction on the same PDO, and removed from post-commit controllers. Missing-audit storage rolls back grants/accounts/invitations and the wider mutation matrix. Retry tests show one event after successful retries. Nontransactional operational/session/filesystem architectures are separately listed below for owner decisions. |
| F3 Medium: shared APP_ENV premise | Confirmed and fixed in repository artifacts | Root-owned production image marker independently forces production classification. Production Compose pins APP_ENV and clears APP_ENV_FILE. Both existing household prohibitions remain. New image/Compose rollout is an owner action; old running artifacts are not retrofitted. |
| F4 Medium: migration interruption / checksums | Confirmed; fail-closed detection fixed, automatic repair deliberately refused | migrate rejects changed/unknown applied history before pending work. Durable migration intent blocks replay after later DDL or history-insert failure. Manual same-engine restore/repair procedure documented. Existing released SQL and pinned checksums unchanged. Older, unjournaled partial migrations require explicit object inspection. |
| F5 Low: unrelated counters invalidate plan | Reproduced and documented as an accepted conservative implementation limitation | Saved plan plus unrelated inventory produces a stale refusal with no transfer/audit partial changes. Fresh preview is the recovery. No security constraint was relaxed. This is not acceptance of a new disclosure or data-loss risk. |
| F6 Low: superseded release documentation | Confirmed and fixed | Current SECURITY/ARCHITECTURE guidance updated, historical H4 report receives a supersession note. Original audit is preserved with an appended resolution section. Owner-reported CI remains distinguished from local evidence. |
| H1 Medium hypothesis: borrower/owner account-state race | Reproduced and fixed | Original LoanService/CopyService replay paused immediately before INSERT, after eligibility; another connection deactivated the target and committed, and the original write still committed. Regression pauses at the same point and proves deactivation waits. Borrower/chosen-owner IDs are now locked with the actor in sorted pre-record account locks, including first-book copies. |
| Legacy author merge work-before-edition contention | Deferred with justification; no data loss reproduced | No deterministic original merge/inventory deadlock reproduction was added. Enabled household inventory now serializes with administrative vocabulary writes through exclusive household locks; the administrative callback also has bounded deadlock retries. The legacy repository statement order remains, especially relevant with households disabled. Contention can still cause a rolled-back 409; no claim of universal deadlock freedom. A repository-level locking redesign needs its own targeted schedule. |
| Household insertion / absent default / direct SQL / old writers | Deferred with justification | Arbitrary SQL and simultaneous old/feature-off writers do not follow the guarded protocol. Missing/default and restored-data integrity cases remain owner rehearsal gates; no automatic assignment/default deletion or schema finalization introduced. |
| Shared bibliography, creator edit-exclusivity signal, borrower history, admin global access | Documented existing policy limitations retained | No expansion of physical-inventory disclosure. Continued owner acceptance of the original model is required for activation. |
| Firefox/WebKit, accessibility, PHP 8.3, real-data recovery/capacity | Deferred release verification | Chrome/Edge and PHP 8.4 exercised locally. No Gecko/WebKit/accessibility certification, PHP 8.3 run, production image build or real-data production rehearsal claimed. |
| Audit tamper resistance | Confirmed but requires explicit owner decision | Runtime UPDATE/DELETE grants on audit_log remain. Atomicity is not append-only enforcement; the separate audit database/off-host proposal remains unimplemented. |

## Root cause, authorization and locking

Middleware reloads a User once per request. Earlier household/invitation services
trusted that object; account creation checked it before opening the transaction;
other account and vocabulary mutations relied on route authorization. A request
could therefore resume after demotion/deactivation had already committed and
perform a privileged write. Subsequent-request session refresh did not protect
that in-flight request. Household flags did not mitigate account administration.

`Auth\AdministratorGuard` now opens its own READ COMMITTED transaction and
reloads an active global Admin under account locks before invoking database-only
work. It refuses nesting. It uses the existing household-before-account order:

1. Household rows in id order, shared for account/invitation administration,
   exclusive for household administration and vocabulary mutations.
2. Existing account rows in ascending id order. Ordinary administrative work
   locks the actor and required accounts shared. Account administration locks
   the complete existing account set exclusively before reading actor, target
   and active-admin set. This avoids actor/target upgrades and cross-demotion
   inversions, preserving self-change and last-active-admin safeguards.
3. Domain rows and audit insert, held until the transaction commits/rolls back.

WriteGuard continues to enforce household/object authorization and its existing
catalogue lock order. Lending includes the borrower; copy creation/update and
first-book creation include the selected owner in pre-record sorted locks.
Invitation redemption locks the immutable issuer account before the invitation
because user/membership/audit foreign keys reference it, then verifies that id
under the invitation lock. Issuer deactivation does not retroactively revoke a
valid invitation: explicit revoke/expiry/consumption still govern redemption.

The ordering guarantee is explicit: a worker with only a stale User is denied
when revocation committed first. A worker paused after authorization holds the
relevant account lock; revocation waits or times out/rolls back, the write can
commit, and only then can revocation commit. Every later mutation rechecks again.
Tests use separate connections/processes and stdin barriers immediately before
INSERT, not timing guesses. CSRF, route guards, session refresh and auth_version
invalidation remain in place. An already-open form/session grants no authority.

Administrative and ordinary guarded callbacks retry only deadlock victims, at
most three attempts; timeouts/exhaustion roll back and become a busy refusal.
Callbacks contain database work only. Broad account/household locks intentionally
reduce administrative concurrency at this installation's scale. Long catalogue
exports hold the actor lock until their last streamed batch and can delay
revocation; review contention before expanding scale. No arbitrary SQL or old
application writer is covered by this protocol.

## Audit atomicity assessment

The following domain/database changes and their events now commit together:

- Household create/update/delete and membership add/remove.
- Account create/profile-role-active changes, reset/revoke, self password change
  and CLI account creation; assigned memberships are part of account creation.
- Invitation issue/replace/revoke and redemption's account/membership/events.
- Work/edition creation/update/deletion, copy creation/update/deletion, cover
  record add/import/delete, loans/returns, collections and item add/remove,
  ordinary locations, and administrative author/publisher/category mutations.
- Existing H4 transfer application/status/completion events remain atomic and
  idempotent; completed confirmation still does not replay writes or events.

Controllers no longer insert those domain events after service commit. The
shared AuditLogger retains request IP/user-agent context and redaction. Direct
service callers also record events, using the transaction's PDO. Audit insertion
failure rolls back the mutation, including earlier statements in multi-step
operations. Tests temporarily rename only disposable audit storage and verify
full snapshots, as well as the HTTP membership failure response. Deadlock retries
rollback the earlier event and domain write, then commit exactly one final event.
General HTTP requests have not gained a new idempotency protocol; H4 retains its
existing durable operation identity. Repeating an already committed ordinary edit
may be another action; a lost response is not proof of rollback.

Architectures needing a separate owner decision rather than a logging rewrite:

| Operation | Remaining boundary |
|---|---|
| Login/logout and session/cookie effects | PHP file/session state and database events cannot commit atomically. Existing login/throttle/last-login and session behavior retained. Moving to transactional sessions/outbox is a larger change; no exactly-once session/event claim. Password database mutation itself is now atomic with its event, then the session is refreshed. |
| Cover filesystem lifecycle | New files are staged before guarded DB/audit work and cleaned after failure; obsolete files are removed after commit. Process loss can leave orphan files. No filesystem/database distributed transaction is claimed. |
| Backup/restore/cleanup and maintenance flags | Filesystem changes and restore DDL are inherently nontransactional. Existing safety backups, incomplete-restore marker, dry runs and logs remain. A failed final audit can leave an operational side effect; improving this needs a separately reviewed durable operation/outbox/recovery design. No audit logging was removed. |
| Exports | Audit records an authorized request, durably before streaming, not proof of receipt/completion. Catalogue streams revalidate under an account lock before emitting data and retain it through the final batch. Bytes already sent cannot be undone. Revocation between response preparation and streaming is tested. |
| Audit integrity | The runtime grants still allow UPDATE/DELETE after full application compromise. Separate audit storage/grants/off-host delivery remain an owner decision. |

These residuals are explicitly reported, not silently treated as remediated
transactional domain events. No active membership/account grant retains the
original controller-after-commit audit gap.

## Production safeguards

The original ConfigValidator error and Services forced-off behavior remain.
The production image now creates `/var/www/html/config/production-image` as root
and sets mode 0444. `Config::isProduction()` checks that artifact independently
of environment values. The normal production stack is non-root/read-only and
does not overlay config. This is stronger than an additional mutable env flag.
Production Compose also sets APP_ENV=production and APP_ENV_FILE="" in both php
and ops, overriding env_file values and its secret-file precedence.

Tests cover valid development/testing mistakes, empty/unknown environments and
hostile/unreadable APP_ENV_FILE. Marker tests execute an isolated copy of Config
under a synthetic production-image layout. Explicit local testing remains
possible in a development checkout/image with APP_ENV=testing/development and
HOUSEHOLDS_ENABLED=true; the production artifact cannot be turned into such a
test instance by changing environment alone. No actual production settings were
edited, no household functionality enabled, no images built/deployed. The owner
must build/review/deploy the new artifacts in a separately authorized deployment;
old running artifacts retain the old risk until then. A later activation release
must deliberately revise both prohibitions and the operational model.

## Migration recovery assessment

Released migration files and pinned checksums are untouched. migrate now refuses
modified, missing/unknown or other-engine applied history before pending work.
The migrator creates its own `schema_migration_attempts` bookkeeping table (like
schema_migrations, not a modified released domain migration). It persists version,
selected-file checksum and start time before executing SQL. All SQL statements
must finish before history insertion and intent removal commit together in one
DML transaction. A client/process failure or a history-insert exception leaves
intent, so migrate/status refuse rather than blindly replaying DDL.

Files are still non-atomic on MariaDB 11.4.13 and MySQL 8.4.13. A later statement
can fail after earlier tables/columns/indexes have committed; individual atomic
DDL does not make the file atomic. No automatic DROP/recreation, migration-file
edit, checksum rewrite or production-record deletion is introduced. Disposable
fault injection covers later-DDL failure, history-insert failure, refusal on
retry/status, and a modified applied checksum. It does not simulate power loss.

Recovery is fail-closed: keep writers stopped, preserve failed-release/history/
intent/schema evidence, inspect every affected object and data effect, and
prefer a verified pre-migration same-engine restore rehearsed on an empty
disposable target. Preserving newer writes requires an explicit, version-specific
owner-reviewed repair and reconciliation plan; no generic clear-intent or
mark-applied command is supplied. Older partial runs have no journal, so inspect
pending objects even with an empty intent table. An intent can also describe an
active run; first ensure no migrator is still running. See OPERATIONS.md's exact
inspection/recovery steps. Restore is non-atomic and may lose subsequent writes.

## Regression and verification evidence

All local runs use PHP 8.4.27. Two private instances were initialized under
`/tmp/dla-remediation.0eplIT`, loopback only: MySQL 8.4.13 on 33952 and official
MariaDB 11.4.13 on 33953. Each used its own `remediation_test` database and accounts
restricted to that schema (runtime DML; migrator DML plus DDL). Normal MySQL and
development databases were not touched. Provider traffic used existing fakes.

| Final executed check | Result |
|---|---|
| Full MariaDB 11.4.13 PHPUnit, --fail-on-skipped | 998 tests, 13,344 assertions; PASS, no skips (1m54.128s) |
| Full MySQL 8.4.13 PHPUnit, --fail-on-skipped | 998 tests, 13,344 assertions; PASS, no skips (2m38.288s) |
| Included administrator revocation / household authorization / transfers | Both full engine suites include all new and existing read/write, disabled-feature, transfer atomicity/idempotency and concurrency regressions |
| Included audit rollback / retry / migration recovery / frozen checksums | Both full engine suites PASS |
| Unit component | 636 tests, 7,504 assertions PASS in the separate run; included in both final full runs |
| Original F1/F2 service replay on disposable MySQL | 3 tests, 4 assertions reproduced the original defects |
| Original borrower/owner replay on disposable MariaDB | 1 test, 9 assertions reproduced both original races |
| Existing Chrome browser harness | 95 checks PASS; JS on/off, en/es/gl, admin/member/viewer, layouts/transfers |
| Existing Edge browser harness | 95 checks PASS; same matrix |
| Native nginx + PHP-FPM harness | 54 passed, 0 failed; temporary loopback listeners only |
| composer lint | PASS; PHP syntax over src/public/bin/tests/database |
| Additional template/lang/config PHP lint | PASS |
| composer validate --strict --no-check-publish | PASS |
| git diff --check | PASS |
| Released SQL diff | Empty; pinned migration tests pass on both engines |

New tests: AdministratorRevocationTest (7 cases: all stale admin service families,
separate-process ordering, in-flight borrower/owner, audit failure, HTTP failure,
delayed export), MutationAuditAtomicityTest (19-operation rollback matrix and
retry event uniqueness), MigrationRecoveryTest (checksum plus interrupted DDL/
history), ProductionIdentityTest (Compose and independent image marker), and the
HouseholdTransferTest conservative-counter regression. revocation_worker.php is
test-only; it uses the vetted loopback test configuration and deterministic SQL
barriers. Existing UserAdministrationTest and HouseholdWriteConcurrencyTest were
updated for fresh-actor refusal and the explicit busy exception respectively;
the latter still checks the underlying server timeout and rollback.

Intermediate failures were investigated, not hidden: the existing member-actor
last-admin test now expects forbidden authorization; an ISBN audit detail was
restored while moving its event; the retry fixture needed distinct publisher
names across its two guard cases; the older concurrency test caught raw PDO
instead of WriteBusyException. Both complete final suites passed after these
corrections. The expected missing-audit error in the HTTP fault-injection log is
part of that test; provider error messages are fake responses, not live calls.

Not executed: PHP 8.3 runtime, Docker/image build or Compose runtime, fresh remote
CI, Firefox/WebKit/accessibility matrix, real production/restored-data rehearsal,
production topology/backup integrity/capacity checks. These are not substituted
with historical CI evidence. No owner infrastructure or production service was contacted. The official public
MariaDB binary archive was downloaded solely for isolated local testing.

## Merge and activation verdicts

**PR merge:** the confirmed stale-administrator merge blocker is fixed and both
engine suites pass. Ready for owner review and a new owner-run GitHub Actions
pass. Recommend merge only after the owner records the residual operational
audit/integrity decisions and accepts the documented conservative/deferred
limitations; this report does not silently accept them on the owner's behalf.

**Production activation:** NOT READY and NOT AUTHORIZED. Household guards remain
intact. Real-data same-engine restore/migration/assignment/recovery rehearsal,
membership/default/NULL/invitation/ownership policy review, capacity/lock-contention
measurement, supported-browser/accessibility decisions, new production-artifact
verification and a separate explicit activation release remain required.

## Workspace and delivery

Only the requested current branch/workspace was used. The initially untracked
FINAL_H1_H4_AUDIT.md was preserved byte-for-byte as a prefix and only appended;
its original SHA-256 was
`68eb8659f88bb73d0838275e8398378fb56a083daa02a09cd740b3e9004970ee`.
No preexisting tracked edits were present. All changes remain uncommitted and
unstaged. No branch/worktree, commit, push, merge, production remote access,
identity/signing/hooks change, deployment or media development occurred.
CLAUDE.md changes are limited to the genuinely changed project-wide transactional
audit and administrative-locking guidance.

Cleanup completed: the browser server was terminated; both database identities
were rechecked (exact datadir/port/version), no other clients remained, normal
SQL SHUTDOWN completed and both processes exited with code 0. Only this session's
datadirs, downloaded MariaDB binary/archive, temporary web storage and PHP session
files were removed. Small synthetic reproduction scripts/logs remain under
/tmp/dla-remediation.0eplIT as local evidence, outside Git. Normal services and
other temporary resources were not altered. Exact inventory/commit commands follow.

## Exact changed-file inventory

55 files total. New files are marked `A`; existing tracked changes are `M`.
The audit report was already untracked on entry and has only been appended.

- `M` `ARCHITECTURE.md`
- `M` `CLAUDE.md`
- `M` `SECURITY.md`
- `M` `docker-compose.prod.yml`
- `M` `docker/php/Dockerfile`
- `M` `docs/DEPLOYMENT.md`
- `A` `docs/FINAL_H1_H4_AUDIT.md`
- `A` `docs/H1_H4_REMEDIATION.md`
- `M` `docs/OPERATIONS.md`
- `M` `docs/PHASE_H4_REPORT.md`
- `M` `src/Audit/AuditLogger.php`
- `A` `src/Auth/AdministratorGuard.php`
- `M` `src/Auth/AuthService.php`
- `M` `src/Auth/UserAdministration.php`
- `M` `src/Catalogue/BookService.php`
- `M` `src/Catalogue/CopyService.php`
- `M` `src/Collections/CollectionService.php`
- `M` `src/Console/Console.php`
- `M` `src/Core/Application.php`
- `M` `src/Core/Config.php`
- `M` `src/Core/Services.php`
- `M` `src/Database/Migrator.php`
- `M` `src/Households/HouseholdService.php`
- `M` `src/Households/LocationService.php`
- `M` `src/Households/WriteGuard.php`
- `M` `src/Http/Controllers/AccountController.php`
- `M` `src/Http/Controllers/Admin/HouseholdController.php`
- `M` `src/Http/Controllers/Admin/InvitationController.php`
- `M` `src/Http/Controllers/Admin/LocationController.php`
- `M` `src/Http/Controllers/Admin/TreeAdminController.php`
- `M` `src/Http/Controllers/Admin/UserController.php`
- `M` `src/Http/Controllers/Catalogue/AuthorController.php`
- `M` `src/Http/Controllers/Catalogue/CatalogueController.php`
- `M` `src/Http/Controllers/Catalogue/CopyController.php`
- `M` `src/Http/Controllers/Catalogue/CoverController.php`
- `M` `src/Http/Controllers/Catalogue/EditionController.php`
- `M` `src/Http/Controllers/Catalogue/MergesRecords.php`
- `M` `src/Http/Controllers/Catalogue/PublisherController.php`
- `M` `src/Http/Controllers/Catalogue/WorkController.php`
- `M` `src/Http/Controllers/Controller.php`
- `M` `src/Http/Controllers/Library/CollectionController.php`
- `M` `src/Http/Controllers/Library/ExportController.php`
- `M` `src/Http/Controllers/Library/LoanController.php`
- `M` `src/Http/Controllers/RegistrationController.php`
- `M` `src/Invitations/InvitationService.php`
- `M` `src/Loans/LoanService.php`
- `M` `src/Metadata/CoverImporter.php`
- `A` `tests/Integration/AdministratorRevocationTest.php`
- `M` `tests/Integration/HouseholdTransferTest.php`
- `M` `tests/Integration/HouseholdWriteConcurrencyTest.php`
- `A` `tests/Integration/MigrationRecoveryTest.php`
- `A` `tests/Integration/MutationAuditAtomicityTest.php`
- `M` `tests/Integration/UserAdministrationTest.php`
- `A` `tests/Support/revocation_worker.php`
- `A` `tests/Unit/Core/ProductionIdentityTest.php`

## Recommended commit boundaries and exact owner commands

The four boundaries below keep coupled authorization/audit code and regressions
together, then separate production artifacts, migrator recovery, and guidance.
Review the complete diff and record residual decisions before committing. These
commands run only in the existing owner checkout; no identity/signing overrides,
branch creation, patch application, remote action or deployment is included.
They require the original branch/base and an empty index at the start, while
allowing the delivered uncommitted working tree. Stop on any unexpected path.

```bash
set -eu
cd /home/brais/Projects/book-library-catalog
test "$(git branch --show-current)" = household-access-h4
test "$(git rev-parse HEAD)" = d1518243e34f9159fb12c8e7665322aba12d3385
test -z "$(git diff --cached --name-only)"
git diff --check

git add -- \
  src/Audit/AuditLogger.php \
  src/Auth/AdministratorGuard.php \
  src/Auth/AuthService.php \
  src/Auth/UserAdministration.php \
  src/Catalogue/BookService.php \
  src/Catalogue/CopyService.php \
  src/Collections/CollectionService.php \
  src/Console/Console.php \
  src/Core/Application.php \
  src/Core/Services.php \
  src/Households/HouseholdService.php \
  src/Households/LocationService.php \
  src/Households/WriteGuard.php \
  src/Http/Controllers/AccountController.php \
  src/Http/Controllers/Admin/HouseholdController.php \
  src/Http/Controllers/Admin/InvitationController.php \
  src/Http/Controllers/Admin/LocationController.php \
  src/Http/Controllers/Admin/TreeAdminController.php \
  src/Http/Controllers/Admin/UserController.php \
  src/Http/Controllers/Catalogue/AuthorController.php \
  src/Http/Controllers/Catalogue/CatalogueController.php \
  src/Http/Controllers/Catalogue/CopyController.php \
  src/Http/Controllers/Catalogue/CoverController.php \
  src/Http/Controllers/Catalogue/EditionController.php \
  src/Http/Controllers/Catalogue/MergesRecords.php \
  src/Http/Controllers/Catalogue/PublisherController.php \
  src/Http/Controllers/Catalogue/WorkController.php \
  src/Http/Controllers/Controller.php \
  src/Http/Controllers/Library/CollectionController.php \
  src/Http/Controllers/Library/ExportController.php \
  src/Http/Controllers/Library/LoanController.php \
  src/Http/Controllers/RegistrationController.php \
  src/Invitations/InvitationService.php \
  src/Loans/LoanService.php \
  src/Metadata/CoverImporter.php \
  tests/Integration/AdministratorRevocationTest.php \
  tests/Integration/HouseholdTransferTest.php \
  tests/Integration/HouseholdWriteConcurrencyTest.php \
  tests/Integration/MutationAuditAtomicityTest.php \
  tests/Integration/UserAdministrationTest.php \
  tests/Support/revocation_worker.php
git diff --cached --check
git commit -m 'fix: serialize privileged writes and audit domain mutations'
git log -1 --format=fuller
git cat-file -p HEAD

git add -- \
  src/Core/Config.php \
  docker-compose.prod.yml \
  docker/php/Dockerfile \
  tests/Unit/Core/ProductionIdentityTest.php
git diff --cached --check
git commit -m 'fix: pin production identity and retain household safeguards'
git log -1 --format=fuller
git cat-file -p HEAD

git add -- \
  src/Database/Migrator.php \
  tests/Integration/MigrationRecoveryTest.php \
  docs/OPERATIONS.md
git diff --cached --check
git commit -m 'fix: refuse interrupted migrations and checksum drift'
git log -1 --format=fuller
git cat-file -p HEAD

git add -- \
  ARCHITECTURE.md \
  CLAUDE.md \
  SECURITY.md \
  docs/DEPLOYMENT.md \
  docs/FINAL_H1_H4_AUDIT.md \
  docs/H1_H4_REMEDIATION.md \
  docs/PHASE_H4_REPORT.md
git diff --cached --check
git commit -m 'docs: record final H1-H4 remediation and release gates'
git log -1 --format=fuller
git cat-file -p HEAD

git status --short
```

The owner performs every commit and subsequent CI/push/merge/deployment decision.
No commit command was executed in this session.
