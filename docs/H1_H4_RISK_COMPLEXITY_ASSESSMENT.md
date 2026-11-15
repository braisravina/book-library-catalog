# H1–H4 remaining-risk complexity and audit-integrity assessment

Date: **2026-11-15 (Europe/Madrid)**. Source:
`5574531f4a330c48f4ea161866618b65f3435a88`, `household-access-h4`.
Starting working tree: clean. Scope: independent source-based investigation and
planning, **not implementation, a new penetration test, or risk acceptance**.
Only this document is added. Historical reports remain unchanged.

## 1. Executive summary

**Merge: CONDITIONAL. Production activation: NOT READY / NOT AUTHORIZED.**
The evidence does not justify another architectural project as a prerequisite
to merging dormant H1–H4 code. The existing conditions remain: explicit owner
dispositions, exact-candidate CI/review, and owner verification that external
automation cannot turn a merge into an unapproved production action. No newly
demonstrated ordinary-user authorization or data-corruption blocker was found
by this static investigation. That is a bounded conclusion, not certification.

The most useful security improvement is **least-privilege audit access before
activation**. The normal application needs audit INSERT; the administrative
viewer and current backup path need SELECT. No normal audit UPDATE or DELETE
requirement was found. These privileges can be removed without moving the table
or weakening same-PDO domain/audit transactions. This is a coordinated
provisioning/test/release change, not a one-line table REVOKE: schema-level grants
currently confer those privileges on every table. A maintained per-table write
allowlist is the preferred first proposal for this small installation.

There is also an indirect modification path to examine: the released audit FK
uses `ON DELETE SET NULL` for the actor. Permission denial on audit UPDATE alone
must not be advertised as complete immutability. Deny runtime user deletion,
test referential actions with genuinely restricted accounts on both engines,
and keep exceptional retention/restore privileges out of the web role.

Hash chaining, a separate audit service, and transactional sessions have much
higher costs than their immediate benefit here. They cannot make a compromised
application honestly report every action. Independent off-host evidence becomes
worthwhile if the owner requires forensics surviving DBA/host compromise; local
permissions cannot provide that assurance. No such requirement is assumed.

Prioritize real backup/restore and data-assignment rehearsal, production artifact
verification, privacy-safe recovery, policy decisions and lock/disk measurements
ahead of optional plan-invalidation or logging redesigns. A privacy regression
from switching enforcement off after separation is **High conditional risk**,
even though turning it off is not an allowed production operation today.

## 2. Evidence, scope and interpretation

Read CLAUDE.md and the
[infrastructure-access-boundary skill](../.claude/skills/infrastructure-access-boundary/SKILL.md),
the complete audit/remediation/merge reports, SECURITY.md, ARCHITECTURE.md and
deployment/operations guidance. Source inspection followed logger/repository,
authentication, operational SQL, dynamic dump/restore SQL, grant initialization,
Compose/entrypoints, guards, migration metadata and relevant tests. No Git remote,
production infrastructure, database or browser was accessed. No services were
started; no privilege-changing test was executed. Public official database and
privacy documentation was consulted for semantics; it is not production evidence.

Evidence labels throughout:

- **V**: verified in the source at the commit above, with file/line anchors below.
- **H**: historical executed evidence from the preserved reports, not rerun here.
- **E**: engineering estimate/inference; not a measured exploit probability.
- **T**: proposed test or unverified runtime hypothesis; not a reproduced defect.

Historical verification is strong but bounded: MariaDB 11.4.13 and MySQL 8.4.13
each passed **998 tests / 13,344 assertions**, PHP 8.4.27; Chrome and Edge each
passed 95 checks; nginx/PHP-FPM passed 54; PHP lint/Composer validation passed.
The owner reports subsequent green GitHub Actions. No live run/SHA/PR was queried.
The prior merge review records 32 focused tests / 911 assertions. These are not
new runs and do not establish production data, grants, host compatibility or
Firefox/WebKit/accessibility results.

### Source index

Line anchors are starting locations at the source commit; links name real files.

| Ref | Evidence |
|---|---|
| E1 | [Original audit](FINAL_H1_H4_AUDIT.md), sections 3–8 and remediation addendum; [remediation](H1_H4_REMEDIATION.md), finding dispositions and residual architecture tables; [merge gates](H1_H4_MERGE_READINESS.md), sections 3–6 |
| E2 | [AuditLogger](../src/Audit/AuditLogger.php):15, 43; [AuditLogRepository](../src/Audit/AuditLogRepository.php):19, 60, 85; [AuditDetails](../src/Audit/AuditDetails.php); [AuditController](../src/Http/Controllers/Admin/AuditController.php); [audit schema](../database/migrations/2026_10_10_000004_create_audit_log.sql):4–19 |
| E3 | [User initialization](../docker/mysql/init/01-create-users.sh):84–105; [CI](../.github/workflows/ci.yml):120–121, 204–205, 444–446; [ConnectionFactory](../src/Database/ConnectionFactory.php):14–42; [production Compose](../docker-compose.prod.yml):124–139, 179–225 |
| E4 | [AuthService](../src/Auth/AuthService.php):35–88, 121–139; [AuthController](../src/Http/Controllers/AuthController.php):47–54; [Session](../src/Security/Session.php); [LoginThrottle](../src/Security/LoginThrottle.php) |
| E5 | [AdministratorGuard](../src/Auth/AdministratorGuard.php):29–74; [WriteGuard](../src/Households/WriteGuard.php); [UserAdministration](../src/Auth/UserAdministration.php); [HouseholdService](../src/Households/HouseholdService.php); [InvitationService](../src/Invitations/InvitationService.php) |
| E6 | [BookService](../src/Catalogue/BookService.php):175–205, 242–258; [CoverController](../src/Http/Controllers/Catalogue/CoverController.php):75–95; [CoverImageProcessor](../src/Upload/CoverImageProcessor.php):78–128, 150; [Cleanup](../src/Maintenance/Cleanup.php):48–58, 180–218 |
| E7 | [Operations](../src/Console/Operations.php):47–70, 187–230, 264–289, 330–348; [BackupRestorer](../src/Backup/BackupRestorer.php):145–180, 219; [DatabaseDumper](../src/Backup/DatabaseDumper.php):46–82; [OperationLock](../src/Console/OperationLock.php); [operations guide](OPERATIONS.md):110–249, 251–314, 640–697 |
| E8 | [ExportController](../src/Http/Controllers/Library/ExportController.php):40–75, 105–133; [AuthorRepository](../src/Catalogue/Repository/AuthorRepository.php):152–163; [InventoryTransferService](../src/Households/InventoryTransferService.php):235, 263–332; [HouseholdRepository](../src/Households/HouseholdRepository.php):23–41 |
| E9 | [Migrator](../src/Database/Migrator.php):53–93, 99–126, 250–291; [MigrationRecoveryTest](../tests/Integration/MigrationRecoveryTest.php); [frozen migration pins](../tests/Unit/Database/MigrationFilesTest.php):74; [household guide](HOUSEHOLD_INVENTORY.md) |
| E10 | [Config](../src/Core/Config.php):123–127; [ConfigValidator](../src/Core/ConfigValidator.php):309–320; [Services](../src/Core/Services.php):399–402; [PHP Dockerfile](../docker/php/Dockerfile):62–72; [PHP entrypoint](../docker/php/entrypoint-prod.sh):11–19; [deployment guide](DEPLOYMENT.md):930–975 |
| E11 | [Revocation regressions](../tests/Integration/AdministratorRevocationTest.php); [audit rollback/retry regressions](../tests/Integration/MutationAuditAtomicityTest.php); [transfer tests](../tests/Integration/HouseholdTransferTest.php); [read isolation](../tests/Integration/HouseholdReadIsolationTest.php); [write authorization](../tests/Integration/HouseholdWriteAuthorizationTest.php) |
| E12 | [IntegrationTestCase](../tests/Integration/IntegrationTestCase.php):406–429; [DatabaseEngineTest](../tests/Integration/DatabaseEngineTest.php):212–250; [SchemaTest](../tests/Integration/SchemaTest.php):107–121; [CleanupTest](../tests/Integration/CleanupTest.php); [BackupRecoveryTest](../tests/Integration/BackupRecoveryTest.php); [browser harness](../tests/Support/h4_browser_test.py) |
| E13 | [SECURITY.md](../SECURITY.md):357–367, 659–765, 881–922; [ARCHITECTURE.md](../ARCHITECTURE.md):647–989, 1454–1595; [deployment resource planning](DEPLOYMENT.md#resource-planning); [production release gates](H1_H4_MERGE_READINESS.md#6-outstanding-decisions-and-release-checklists) |

### Classification and effort conventions

Severity measures the residual consequence, not the cost of fixing it:
Critical / High / Medium / Low / Informational. Likelihood is qualitative for a
small private installation with trusted global administrators, prepared SQL,
authenticated writes, limited ingress and owner-only operations. “Low; conditional
on compromise” is not “safe after compromise.” No new SQL injection is alleged.

Implementation complexity: **Trivial** localized docs/config; **Low** isolated
code; **Medium** multiple components/integration; **High** architecture or complex
concurrency/migration; **Very High** broad redesign. Testing complexity uses the
same scale for validation work. Regression risk is separate. Priorities: **P1**
release/activation gate; **P2** useful focused follow-up; **P3** defer/monitor.
Group A means a justified dormant-merge hold; B means before activation (some
also before any deployment); C means proposed acceptance/deferral, not approval.

Effort figures are **E estimates in engineer-days (about eight hours each)**,
not guarantees or elapsed schedules. Implementation includes design/docs; testing
includes harness/fault-injection work; rollout is owner rehearsal/change effort.
Waiting for approvals, access, purchases or infrastructure is excluded. Ranges
overlap and must not be added as an exact project budget.

## 3. Complete residual-risk decision table

R01–R32 map the outstanding material in E1, including the audit's release
rehearsal constraints. Fixed F1–F4/F6 and the borrower/owner race are reconciled
in section 4. Supplemental existing security boundaries are listed in section 8;
they are not silently reclassified as H4 defects.

| Issue | Severity | Likelihood | Implementation complexity | Testing complexity | Regression risk | Priority | Recommendation |
|---|---|---|---|---|---|---|---|
| R01 Runtime audit modification privileges / indirect actor FK | Medium | Low before compromise; easy with SQL control | Medium | High | Medium | P1 / B | Harden grants; test indirect writes; retain transaction |
| R02 Session/auth audit inconsistency | Medium | Low; outage/crash | High for full redesign | High | High | P2 / C | Define semantics; consider narrow logout failure handling separately |
| R03 Cover filesystem orphans | Low | Occasional process failure | Low for reconciliation | Medium | Medium if deleting | P2 / C | Use existing dry run; quiesce before deletion |
| R04 Operational side effects without final event | Medium | Low; failed final insert/crash | Medium for durable operation records | High | Medium | P2 / C | Focused operation ledger if needed; no generic resume |
| R05 Export event proves request, not receipt | Informational | Expected semantics | Trivial for documentation | Low | Low | P3 / C | Retain request meaning; completion optional |
| R06 Ordinary request retry duplication | Low | Low; lost responses/repeated POST | Medium per selected action | Medium | Medium | P2 / C | Add keys only after demonstrated harm; H4 already covered |
| R07 Legacy merge lock inversion contention | Low | Low; exact schedule unproven | Medium | High | Medium | P2 / C | Reproduce before lock-order change |
| R08 Export-held actor locks delay revocation | Medium | Low at current scale; deliberate admin possible | Medium | High | Medium–High | P1 measure / B; redesign C | Set latency budget; bound work without dropping fresh authorization |
| R09 Broad locks / household insertion and absent-default assumptions | Medium conditional integrity; T | Unknown abnormal data; low normal concurrency | High if protocol changed | High | High | P1 validate / B; redesign C | Rehearse missing/default/insertion cases; no blind lock narrowing |
| R10 Unrelated inventory invalidates saved plan (F5) | Low | Plausible routine contention | Medium | Medium | Medium | P3 / C | Keep conservative refusal/fresh preview |
| R11 Direct SQL / mixed old or flag-off writers | High conditional integrity/privacy | Low if writer boundary enforced | Medium for operational controls; High for arbitrary-writer support | High | High for redesign | P1 / B | Stop all old writers; do not promise arbitrary SQL safety |
| R12 Shared bibliography/ISBN existence | Low policy disclosure | Deliberate authorized probing feasible | High if isolation redesigned | High | High | P1 decision / B | Confirm shared model; retain implementation |
| R13 Creator edit-exclusivity inference | Low policy disclosure | Deliberate probing feasible | High if removed fully | High | High | P1 decision / B | Confirm disclosed signal; no partial cosmetic fix |
| R14 Borrower's historical title/date access | Medium policy-dependent privacy | Expected for former borrowers | Medium | High | Medium–High | P1 decision / B | Confirm own-history rules and retention |
| R15 Global administrator access/trust | Medium policy-dependent | Expected authorized access | High for scoped administration | High | High | P1 decision / B | Confirm trusted global administrators |
| R16 Default members / NULL / collection / invitation policy | Medium; High if misassigned | Unknown until restored-data review | Low for review; High if model changes | High | High if reassigned wrongly | P1 / B | Approve actual ID-keyed assignments |
| R17 Partial DDL and old unjournaled failures | Medium availability/integrity | Low, material during upgrade | Low–Medium for better preflight | High | Low for read-only checks | P1 / B | Keep fail closed; rehearse manual same-engine recovery |
| R18 Checksums, engine-specific restore and schema drift | Medium integrity/availability | Low controlled release; unknown real history | Medium for schema preflight | High | Medium for false acceptance | P1 / B | Preserve pins/history; verify actual objects, not checksums alone |
| R19 Real backup usability, RPO/RTO and stopped writers | High conditional data loss | Unknown; no real drill | Low for procedure; Medium automation | High | Low on disposable target | P1 / B | Verified off-host backup and measured restore drill |
| R20 Restored-data semantic integrity / collation | High conditional integrity/privacy | Unknown actual data | Medium rehearsal tooling | High | Low read-only; High assignments | P1 / B | Reconcile fingerprints, FKs and semantic checks |
| R21 Transfer bounds/history and assignment procedure | Low availability; Medium release practicality | Data-dependent | Low review; High if semantics changed | Medium–High | High if restrictions relaxed | P1 assess / B; redesign C | Keep indivisible bounds/history; resolve oversized cases explicitly |
| R22 Flag-off / old-code incident rollback | High conditional privacy | Operator error possible | Low runbook; High safe downgrade | High | High if wrong rollback | P1 / B | Household-aware recovery version; never flag-off after separation |
| R23 Firefox coverage | Informational verification gap | Unknown bugs | Low manual; Medium automation | Medium | Low test-only | P1 / B | Define support and exercise Gecko |
| R24 WebKit / actual Safari coverage | Informational verification gap | Unknown bugs | Medium | Medium–High | Low test-only | P1 / B | Test selected WebKit target; do not equate it with all Safari |
| R25 Accessibility / focus / translation usability | Medium usability/access risk; unverified | Unknown | Medium assessment/fixes | High | Medium for UI fixes | P1 / B | Keyboard, screen reader, errors, zoom/reflow, language review |
| R26 PHP 8.3 deployment compatibility | Informational verification gap | Not current production target | Low–Medium | Medium | Low test-only | P3 / C; B if target adopted | Keep tooling/production distinction; test full target if adopted |
| R27 Production image/host/old-artifact verification | Medium conditional safeguard failure | Unknown live artifacts | Low verification | Medium–High | Low isolated; Medium rollout | P1 / B | Verify intended artifact on authorized target; guards stay intact |
| R28 Capacity, storage, export and lock contention | Medium availability | Unknown production size/host | Medium measurement | High | Low isolated; Medium tuning | P1 / B | Measure space, RSS, waits, duration and failure margins |
| R29 External automation, reference freshness, exact CI SHA | Medium release-control uncertainty | Unknown outside repository | Trivial–Low evidence collection | Low–Medium | Low read-only | P1 / A gate | Owner verifies before merge; no remote assertion here |
| R30 Audit PII, retention, growth and identity stability | Medium confidentiality/accountability | Accumulates normally; misuse conditional | Medium policy/tooling | Medium–High | Medium–High deletion risk | P1 decision / B | Minimize, approve retention and exceptional deletion model |
| R31 Historical/superseded documentation | Low operational confusion | Plausible if older commands copied | Trivial | Low | Low | P2 / C | Use current SHA and supersession; preserve history |
| R32 Outstanding risk/activation approval | Informational governance gate | Approval absent, not hypothetical | Trivial documentation | Low | Low | P1 / A gate + B | Explicit owner decisions; never infer acceptance from a proposal |

### Effort, deployment dependencies and isolation

“None” means no schema migration is required for the recommended improvement;
not that it can be changed on production without authorization. Each row can be
tracked independently unless the dependency column explicitly couples it.

| Issue | Implementation E | Testing E | Rollout/rehearsal E | Schema/deployment dependency and isolation |
|---|---|---|---|---|
| R01 | 2–4 d | 3–5 d | 0.5–2 d | Grants/provisioning + CI coordinated; no table move needed; couples R30 |
| R02 | 5–10 d DB outcome/outbox; 10–20 d transactional session redesign | 4–8 d; 7–12 d respectively | 1–3 d | New tables/session handler/worker for redesign; narrow logout handling instead 0.5–1 d + 1–2 d tests; separable from domain audit |
| R03 | 0.5–2 d reconciliation improvements | 1–3 d | 0.5–1 d | No schema for report/recheck; durable staging registry adds migration; isolate cleanup |
| R04 | 2–5 d focused operation records | 3–6 d | 1–2 d | Filesystem or separate durable store survives target restore; avoid a ledger solely inside replaced DB |
| R05 | 0.25–0.5 d semantics; 1–2 d optional completion | 0.5–1 d | 0.25 d | None; no transaction across network added |
| R06 | 2–4 d per action; general framework 10–20 d | 2–4 d; general 5–10 d | 0.5–1 d | Durable keys/constraints/expiry usually new migration; endpoint-local possible |
| R07 | 1–3 d only after schedule | 2–4 d | 0.5–1 d | None expected; affects shared catalogue lock order |
| R08 | 2–5 d bounded export/cancellation prototype | 3–5 d | 0.5–2 d | None for limits; artifact export adds storage/lifecycle; couples R28 |
| R09 | 1–2 d diagnostics; 5–10 d protocol redesign | 2–4 d; redesign 4–8 d | 1–2 d | Possible guard-row migration only if justified; test H1/H3/H4 together |
| R10 | 1–2 d | 2–3 d | 0.5 d | None expected; saved-plan compatibility must be explicit |
| R11 | 0.5–2 d controls/docs | 1–3 d | 0.5–1 d | Owner process supervision; universal SQL enforcement would be a separate architecture |
| R12 | 0.5–1 d policy; 10–25 d isolated bibliography redesign | 0.5–1 d policy; 8–15 d redesign | 0.5–3 d | Redesign affects ISBN uniqueness/relationships/imports and migration |
| R13 | 0.5 d policy; 5–15 d redesign | 0.5 d policy; 5–10 d redesign | 0.5–2 d | Coupled metadata/copy authorization; hiding a button is insufficient |
| R14 | 0.5–1 d policy; 2–5 d changed history rules | 1–3 d | 0.5–1 d | None for decision; possible retention migration if changing stored history |
| R15 | 0.5 d policy; 8–20 d scoped admin redesign | 0.5–1 d policy; 6–12 d redesign | 0.5–2 d | Permission model/schema/CLI/admin recovery; not isolated patch |
| R16 | 1–3 d review/mapping preparation | 2–5 d rehearsal | 1–2 d | Actual confidential data required; assignments owner-approved, not automatic SQL |
| R17 | 1–3 d preflight/recovery tooling | 2–4 d | 0.5–2 d | No edits to released SQL/pins; same-engine disposable targets |
| R18 | 2–4 d canonical schema comparison | 2–4 d | 0.5–1 d | Engine-specific metadata normalization; R17/R20 shared tooling |
| R19 | 1–2 d procedure/automation | 2–4 d | 1–3 d | Authorized backup, private storage, target, key management; real size can extend duration |
| R20 | 2–4 d automation | 2–5 d | 1–3 d | Owner-supplied sanitized/confidential clone; couples R16/R19 |
| R21 | 0.5–2 d assessment | 1–3 d | 0.5–2 d | New semantics for oversized/history cases need separately estimated design; no automatic chunking |
| R22 | 0.5–1 d recovery plan | 1–3 d | 0.5–1 d | Reviewed recovery artifact and backup point; no safe automatic downgrade promised |
| R23 | 0.5–1 d manual plan; 2–4 d automation | 1–2 d | 0.25–0.5 d | Approved driver/harness install later; isolated synthetic DB |
| R24 | 1–3 d harness | 1–3 d | 0.5–1 d | Matching WebKit driver/runtime or approved Safari device; dependency approval |
| R25 | 1–2 d assessment; fixes unknown, initial 2–5 d allowance | 2–4 d | 0.5–1 d | Approved support/accessibility target; discoveries may require new estimates |
| R26 | 0.5–2 d extra target setup | 1–3 d | 0.5–1 d | Only if 8.3 deployment becomes supported; current image remains PHP 8.4 |
| R27 | 0.5–2 d verification plan | 1–3 d | 0.5–2 d | Authorized isolated build/runtime host, correct CPU/storage/network; no workstation Docker |
| R28 | 1–3 d workload/instrumentation | 2–5 d | 1–2 d | Production-sized private clone/host baseline; tuning discoveries separate |
| R29 | 0.25–1 d owner inspection | 0.25–0.5 d evidence review | 0.25 d | GitHub/server settings owner-only; no new code required |
| R30 | 1–3 d policy/design; 2–5 d bounded maintenance tooling | 2–4 d | 0.5–2 d | Owner retention/legal scope; tools may need migration, independent maintenance identity |
| R31 | 0.25–0.5 d | 0.25–0.5 d | negligible | None; historical commands remain historical |
| R32 | 0.25–0.5 d recording decisions | 0.25 d review | owner-dependent | Owner approval required; separate future activation release |

## 4. Disposition of original findings and detailed operational assessment

F1's root cause was trusting a request-cached User before the mutation
transaction. E5 now reloads active administrator authority under account locks
and commits only while holding them. Household locks precede sorted accounts;
account administration locks the existing account set exclusively, preserving
last-active-admin and self-change protections. E11/H tests both serialization
orders. F2's domain events now share the domain PDO/transaction: audit insertion
failure rolls back mutations, and deadlock retries roll back earlier events.
F3 has independent production-image identity plus pinned Compose identity; F4
has durable attempt records and checksum refusal. F6 is superseded guidance,
not missing security implementation. The borrower/owner timing hypothesis was
reproduced and fixed with sorted required-account locks. **None of these is
reopened merely because operational guarantees are narrower.**

### R02 — Authentication, sessions and audit

**V, E4:** success clears throttle state, records login, regenerates/sets the
session, then inserts `auth.login`; failures record throttle state before audit.
Logout inserts `auth.logout` before invalidating the session. Thus audit storage
failure may interrupt logout before invalidation; login/session/DB crash windows
also exist. Exact persisted-cookie outcomes depend on PHP session shutdown and
must be tested (T). This is an accountability/availability boundary with a
possible shared-device logout consequence, not a reproduced privilege bypass.
An attacker has no demonstrated ordinary route to force audit insert failure;
storage exhaustion or process loss can trigger it accidentally.

A **narrow** review of always invalidating on explicit logout despite failed
audit could improve security, with failure reporting and no false success event;
it needs tests and an owner decision on failure semantics. A transaction around
login-related DB state and a durable attempt/outcome record can improve DB
consistency without moving sessions. A DB outbox only makes DB intent durable;
it cannot atomically commit PHP files or a browser cookie. A transactional session
handler can co-commit server session rows and audit but still cannot guarantee
cookie receipt or exactly-once authentication. Expiry, regeneration, CSRF rotation,
concurrent session locking, account revocation and cleanup become new maintenance
responsibilities. Existing next-request account/auth_version checks and guarded
writes contain stale authorization. Prefer defined outcomes and focused failure
tests over a session-store redesign at this scale. Never reclassify the existing
atomic password mutation as non-atomic just because session refresh follows it.

### R03 — Cover filesystem consistency

**V, E6:** immutable random cover names, pre-DB staging, removal on caught failure
and post-commit old-file deletion leave an orphan window on process death.
Normal operation can accumulate files; a malicious uploader could increase disk
use within existing authorization/upload limits, but no cross-household access
or valid-image deletion exploit is established. Existing cleanup **already is
dry-run by default**, tracks originals and derived thumbnails, filters safe cover
paths, and uses a minimum-age cutoff (24 h default). It requires both `--apply`
and `--delete-orphan-covers` to delete. Do not propose implementing that again.

Improve reports to reconcile referenced-but-missing files, exact candidate
identities and a second observation/recheck. A dry run can safely report
candidates without deleting valid images; it is not proof that concurrent later
deletion is safe. The current reference snapshot and age cutoff are conservative,
not a universal concurrency proof. For deletion, stop all cover writers/restore
operations, re-read references immediately, and reject changed/symlink candidates.
Quarantine plus delayed owner-approved deletion is an alternative. A durable
staging registry is more invasive and itself needs reconciliation. Regression
tests must cover thumbnails, failed DB writes, delayed staging, reference races,
symlinks, shared references and backups; deletion is the principal regression risk.

### R04 — Backup, restore, cleanup and maintenance auditing

**V, E7:** backup publishes/verifies an archive then inserts `backup.created`.
Restore replaces DB objects/files, then directly inserts `backup.restored` on
the migration PDO; only afterwards does it clear the incomplete marker. Cleanup
may delete files/rows before `maintenance.cleanup`. Maintenance toggles are not
an all-events transactional ledger. Restore-marker acknowledgement deliberately
audits **before** reopening and refuses if logging fails. Do not weaken it.
Crash/disk/audit outages can cause completed or partial effects without the
final event. There is no ordinary web endpoint for these effects; malicious
exploitation requires operations capability or a separately established failure.

Focused improvement: durable operation ID and `started / verified / failed /
interrupted` records, archive hashes, phase boundaries and a post-operation
reconciler. Keep them somewhere that the target restore cannot erase, and treat
stdout/exit status plus markers as evidence today. A record in the DB being
replaced cannot be the sole restore ledger. An outbox captures a committed DB
phase, not a filesystem rename/DDL transaction. Resume only demonstrably
idempotent verification/copy phases; never automatically resume destructive
restore DDL from an ambiguous checkpoint. Current operations lock, safety backup,
verification and incomplete marker are meaningful mitigations. Test failure at
every phase and preserve failure status; adding a falsely authoritative ledger
would be a worse regression than honest “outcome unknown.”

### R05–R06 — Export semantics and HTTP idempotency

**V, E8:** export events durably record an authorized request. Server generation
completion is different from client receipt; even a successful socket write is
not proof the recipient saved the file. Optional correlated completion/abort
events would improve diagnosis, not close an authorization defect. Ordinary
users cannot turn an initiation event into unauthorized export access. Keep
the present truthful meaning. Do not add a transaction held for delivery just
to log completion: catalogue export already has a lock-lifetime issue (R08).

**V/H, E8/E11:** H4 completion is already durable and idempotent. Reopening the
operation resolves a lost response. General request duplicates can result from
retries/double submission and may be deliberate by an authorized user. Candidate
actions for a future key are expensive multi-record creation or a demonstrated
duplicate-copy/loan workflow, not all GET/POSTs. Loans have active-copy uniqueness;
invitations have consumption state; ordinary edits/returns have checks/versions.
A key needs actor/action/payload binding, unique reservation in the mutation
transaction, result replay, conflict/expiry semantics and authorization on replay.
Reusing keys across users, reserving before a failed transaction, or replaying
stale permissions can introduce security bugs. UI button disabling alone is not
idempotency, but broad infrastructure is not justified without duplicate harm.

### R07–R11 — Concurrency and cooperating-writer boundaries

**R07, V/T, E8:** author merge updates works before editions, opposite other
paths. The exact legacy deadlock schedule remains unproven. Enabled household
locks serialize vocabulary and inventory; bounded guard retries and full
rollback mitigate victims. Two authorized concurrent operators can cause a 409
accidentally or deliberately; no corruption was demonstrated. First construct
two-connection barriers on a shared work/edition under feature-off and enabled
modes, including retries/audit uniqueness. Only then standardize locks if needed.
Reordering a single UPDATE without the whole graph can introduce another cycle.

**R08, V, E8/E5:** catalogue streaming starts a consistent snapshot, performs a
current locking active-admin read and retains the shared actor lock until the
last batch. Account administration's all-user exclusive locks can therefore wait
behind another user's export too. Large exports/slow reads can accidentally
delay revocation; an already authorized malicious admin could deliberately
prolong contention. Revocation waiting is not committed revocation: no later
write is authorized after a successful revocation commit. PHP script time limits
are not a dependable wall-clock drain guarantee. Current streaming limits memory,
not total duration, and no export rate limit is claimed. Measure normal and
adversarial duration/waits first. Focused options: explicit size/concurrency and
wall-clock budgets, cancellation between bounded batches, or generating a private
artifact before delivery. Artifact delivery needs fresh authorization, TTL,
cleanup and a defined revocation policy. Merely releasing the actor lock early
would weaken the security contract. Test slow-output/revocation schedules and
partial-output behavior before changing it.

**R09, V/T, E5/E1:** broad household/account locks simplify order but serialize
unrelated work. Locking existing rows at READ COMMITTED is not proof of locking
all future row insertions or ensuring a missing default exists. Default deletion
is protected in services; absent/default insertion and abnormal restored states
remain targeted hypotheses, not demonstrated last-admin or household bypasses.
Test actual cooperating create/assign/default/account schedules and refusal on
bad state before choosing a singleton guard row, stronger isolation or narrower
locks. These alternatives affect the entire order and phantom behavior. Do not
trade proven revocation guarantees for hypothetical throughput.

**R10, V/H, E8:** whole household plan snapshots include counters/timestamps.
Unrelated inventory makes strict comparison refuse confirmation. Fresh preview
is safe; a busy operator can accidentally or deliberately invalidate another
admin's plan, but no partial write occurs. Comparing only relevant eligibility
fields could improve usability; it risks omitting a changed subtree, location,
membership or owner constraint and handling old saved plans incorrectly. Keep
the conservative behavior until actual contention justifies the regression work.

**R11, V, E1/E5:** authorization locks are a cooperating-writer protocol, not a
DB-enforced household policy. SQL credentials permit bypassing services; old and
feature-off writers can omit assignments or versions. Owner/credential compromise
can deliberately bypass it; a stray CLI/cron/old release can do so accidentally.
Maintenance alone does not stop previously running requests. Inventory writers
must be enumerated, stopped and version-controlled through migration/assignment.
Restrict operational SQL access and verify startup/recovery versions. Supporting
arbitrary SQL safely would require a separate DB-access design; do not expand H4
into it. Any reproduced bypass among **current cooperating writers** is a new
merge blocker, not an accepted direct-SQL limitation.

### R12–R16 — Visibility and assignment policy

**V, E1/E13:** these are deliberate model choices, not automatically acceptable
privacy outcomes. Ordinary catalogues remain visibility-filtered; no ordinary
cross-household physical inventory IDOR is established by shared bibliography.

- **R12:** one global bibliographic identity and unique ISBN allow an authenticated
  probe to learn shared existence/metadata. Existing visibility hides the other
  household's physical counts, codes, locations and owners. Truly hiding existence
  needs household-specific identities/uniqueness, duplicate resolution and imports;
  changing only a response can leave other uniqueness side channels. Confirm the
  shared model before activation; accidental discovery is possible too.
- **R13:** a creator may infer another household acquired a record from loss of
  edit exclusivity. Locking prevents unauthorized metadata edits but cannot hide
  the changed decision. Cosmetic denial messages do not eliminate the signal.
  Stronger separation needs a different shared-metadata contribution model, with
  substantial regression risk. Confirm this bounded inference explicitly.
- **R14:** former borrowers keep their own historical title/date information,
  with limited details, after copy visibility changes. This supports useful
  personal history but can conflict with an expectation of immediate forgetting.
  Owners/admins/borrowers have deliberately different history views. Test title,
  link and export rules for former members if policy changes; never delete loan
  history simply to permit ownership transfer. An ordinary former borrower can
  intentionally revisit retained history; that is current policy, not proof of
  access to another user's private list.
- **R15:** global admins are trusted for all households; private wishlists remain
  an explicit exception. A stolen admin session can exercise that large authority,
  and an admin can misuse it deliberately. Route/service checks, session revocation,
  CSRF and auditing mitigate access, not a malicious authorized admin. A scoped
  administrator system would affect permissions, invitations, operations, exports
  and last-admin recovery. Confirm trust; do not casually redesign roles.
- **R16:** H1 backfills NULL inventory to the protected default, grants membership
  only to active previously signed-in users, and H3 handles shared collections.
  Later memberships/invitation grants are explicit. Remaining NULL inventory is
  admin-only when enabled; disabled reads are global. Incorrect assignments can
  accidentally expose inventory without any bypass, and a privileged operator
  could misassign it deliberately. FK validity is insufficient. Review exact
  member/owner/copy/location/collection/invitation IDs with affected users' policy,
  preserving unrelated fields/history. Current dependency checks and explicit
  transfer previews help, but cannot select the owner's intended sharing policy.

### R17–R22 — Migration, recovery and production data

**R17, V/H, E9:** intent commits before SQL; history INSERT and intent removal
commit together after all statements. Later-DDL/history failure leaves durable
intent and refusal. An intent might describe an active migrator; inspect before
recovery. Old partial runs can lack it. Individual atomic DDL is not a transaction
around a multi-statement file on either supported engine. Application tests have
injected later-statement/history failures, not simulated power loss. Normal
upgrade interruptions cause availability/partial-schema risk; a privileged
attacker can cause worse effects directly. Better read-only preflight and exact
object/datum reconciliation are practical. Never automatically delete objects,
replay ambiguous files, clear intent or rewrite applied checksums. Generic DDL
rollback is not recommended. Recovery requiring newer writes is a separately
reviewed version-specific repair, otherwise verified same-engine restore.

**R18, V, E9/E7:** checksums protect release-file/history correspondence, not live
schema correctness after direct ALTER or incomplete old runs. MySQL/MariaDB dump
DDL and generated/collation metadata are not interchangeable; cross-engine restore
is refused. Compare normalized columns, indexes, FKs and generated expressions
with the correct engine release; a name existing is not sufficient. Table metadata
normalization must not erase meaningful differences or create false assurance.
`migrate:status` can create metadata tables and is not a purely read-only command;
status also is not a replacement for migrate's complete history validation.
Keep released files and pinned hashes immutable. Explicit engine drift is a stop
condition, not a reason to convert dumps or pin new checksums.

**R19, V/T, E7:** full backup verification and safety/incomplete markers exist,
but actual production restore usability, off-host protection and recovery budgets
remain unknown. Lost disks, corrupt archives, lost keys or a mistaken replacement
can lose data without an attacker; a host attacker can also destroy local copies.
Hashes alongside an archive detect accidental corruption, not a malicious party
replacing both. Independently retained hashes/copies, protected/encrypted off-host
storage and an actual restore are necessary. Stop web **and** background/CLI
writers; the 35-second drain is explicitly best effort. Establish RPO/RTO and
later-write loss, restart sessions after restore, and never use synthetic test
fixtures against restored real data. No new backup architecture is needed to
perform this gate.

**R20, V/T, E1/E7:** unknown restored data may include location cycles/orphans,
copy/location/parent household mismatches, unexpected NULLs, inactive/nonmember
owners, invitation/collection anomalies and collation/trailing-space conflicts.
Synthetic passing tests do not establish absence. Deliberately inconsistent SQL
and ordinary historical data can both produce anomalies. Compare counts, primary
keys and per-column fingerprints, approved backfill exceptions, FK LEFT JOIN
checks and semantic tree/ownership checks before and after migration/transfers.
`FOREIGN_KEY_CHECKS=1` alone does not retrospectively validate loaded rows. Check
same-engine equality and indexes; do not rename codes/normalize data silently.
Read-only diagnostic automation is relatively safe; automatic repair is not.

**R21, V, E1/E8:** whole subtrees are indivisible (100 nodes), transfers bounded
to at most 100 copies, active loans block movement, and ownership changes with
any loan history are refused. Oversized real inventory may make an assignment
plan impractical. There is no applying production transfer CLI/automatic chunking;
the diagnostic CLI is deliberately local/rehearsal-only. These are integrity
constraints, not vulnerabilities. Assess actual data and approve separate
procedures for exceptions; loosening history or silently splitting subtrees could
break accountability/tree safety. Current small batches preserve explicit review
and independent commit/recovery boundaries.

**R22, V, E1/E10:** disabling enforcement after separation restores global reads
and global application code prechecks; old writers also ignore location versions.
This is a substantial conditional privacy risk from operator error, or deliberate
misconfiguration by someone with deployment authority. Current production guards
prevent activation, not a future unsafe rollback. Preserve maintenance, stop
writers and choose a tested household-aware recovery release or an agreed
same-engine backup point. A SQL-compatible additive schema is not a behaviorally
safe old-code rollback. Rehearse this negative case only in a private clone.

### R23–R32 — Verification, capacity, retention and release control

**R23/R24, H/T, E12/E1:** Chrome/Edge are both Chromium. Missing Gecko/WebKit
coverage is a gap, not evidence of broken authorization. Manual Firefox testing
is cheaper first; the CDP harness needs WebDriver/BiDi or another matching driver
to automate other engines. A WebKit runtime alone is not an automation harness,
and WebKitGTK/Playwright cannot certify every Safari/device. Define supported
versions/platforms; test JS on/off, all roles/locales, errors, stale plans and
visibility canaries. Use synthetic disposable fixtures only after new authorization.
Browser automation dependencies are not installed or run in this task.

**R25, V/T, E1:** native forms, labels/headings and server validation help but
do not verify keyboard order/focus after errors, screen-reader announcements,
checkbox confirmation, zoom/reflow/mobile or translation quality. Impact can be
exclusion or mistaken administrative action, not necessarily access bypass.
Combine automated checks with manual assistive technology; content/UI fixes
need cross-language/browser regressions. Scope against an owner-approved
accessibility target; do not claim certification from existing browser counts.

**R26, V/H, E13:** PHP 8.4 is the production target; PHP 8.3 has a CI lint/unit
tooling job, reportedly green. A full 8.3 DB/FPM/browser pass is not established.
Do not hold the current 8.4 release for an unadopted target. If the owner promises
8.3 deployment, reproduce both-engine integration, extensions, FPM/image and
dependency compatibility before that deployment. No actual 8.3 bug is alleged.

**R27, V/T, E10/E3:** production marker, pinned `APP_ENV=production`, empty
`APP_ENV_FILE`, validation refusal and Services forced-off are present. Normal
deployment does not enable households; application startup does not migrate.
New images are needed to obtain the marker; old running artifacts are unknown.
Custom mounts/overrides or a wrong image can invalidate assumptions, accidentally
or with deployment authority. Verify actual CPU baseline, non-root/read-only
mounts, credentials, entrypoints, refusal and health on an authorized isolated
target. Hosted CI builds are supporting evidence, not real-host certification.
Never remove marker/guards as a test workaround. Any future activation requires
a separately reviewed release deliberately revisiting **both** prohibitions.

**R28, V/T, E13/E7/E8:** broad locks, full backups, H3 index/table rebuilds,
snapshot retention and streamed exports have unmeasured real-size costs. Argon2
memory plus FPM concurrency, DB redo/undo/binlog/temp, covers, extraction and
safety backups compete on a small host. A large authorized export/workload could
be intentional denial of service; ordinary growth can also exhaust resources.
Existing batching, bounded transfers and queries constrain some work, not total
duration/storage. Measure p95/p99 waits, deadlocks/409s, revocation latency, RSS,
free-space peaks and rebuild/restore times on the intended engine/CPU scale.
Set budgets before tuning; premature lock narrowing and arbitrary memory limits
can introduce worse failures. No online-DDL or capacity guarantee is claimed.

**R29, V/T, E3/E1:** tracked CI triggers on main and builds/migrates disposable
runner stacks. It has no tracked production deployment/publish/SSH/webhook path.
That does not prove external integrations, bare-repo hooks or timers absent.
Local main freshness and exact live CI/PR head are unverified. An unexpected
external trigger could accidentally deploy or migrate on merge. Owner-only
inspection of GitHub apps/webhooks/org automation/runners and server hooks,
timers/watchers/update agents is a real merge gate; inspect policies/names,
not disclose secret values. If any automatic production path exists, hold merge
until its approval boundary is resolved.

**R30, V, E2/E13:** audit contains actor/entity IDs, timestamps, IP/browser strings
and action details (including some account/profile diffs and catalogue text);
the viewer joins mutable current user identity and displays email on detail.
It is sensitive, not an immutable identity snapshot. Redaction and admin-only
bounded escaped views help; older raw rows may be masked only on display.
Wishlists intentionally have no change audit. No default audit pruning means
storage/backup growth and indefinite personal-data retention need a decision.
Ordinary operation accumulates this; stolen admin/runtime access can disclose it.
See section 7: append-only for the runtime need not mean retain forever or prevent
authorized corrections/deletions through a separate controlled process.

**R31/R32, V, E1/E13:** historical dirty-tree/old test-count/“CI never run” text
has explicit newer evidence; do not run historical base-SHA commit recipes as
current procedures. Use the current release record and exact tested SHA. A–F
acceptances in merge readiness are explicitly proposals. Their presence in a
committed document is not owner acceptance. Updating the decision record after
owner approval is small work; neither unattended merging nor activation follows
from this analysis. These gates do not imply the remediated High defect persists.

## 5. Audit SQL and credential trace

### Operations found, including dynamically constructed SQL

| Operation on audit_log | Normal web/runtime | Operations / migration / restore | Tests / assessment |
|---|---|---|---|
| SELECT | AuditLogRepository page/detail/distinct filters (E2), through admin GET viewer; no public audit mutation route | DatabaseDumper enumerates every base table, SHOW CREATE and batched SELECT (E7); backup needs audit SELECT. Restorer post-checks row counts/FKs; safety backup uses migrator PDO | Many assertions/fixtures/fingerprints; no reason to remove SELECT from the current runtime design |
| INSERT | AuditLogger only; callers include services, auth, HTTP and CLI. Domain callbacks use the existing PDO transaction | Operations backup/cleanup/acknowledgement use logger; successful restore directly inserts on target migration PDO (Operations:222). Restore dump replay inserts historical audit rows | Fixture inserts, engine JSON validation and rollback tests are legitimate INSERT users; restore is privileged historical replacement, not web append |
| UPDATE | No direct audit UPDATE found in src | No shipped audit correction/retention UPDATE routine; future approved maintenance might need it | No normal requirement inferred from grants. Actor FK may indirectly change audit.user_id on user DELETE; see below |
| DELETE | No direct audit DELETE found in src | Cleanup explicitly excludes audit; no audit-pruning command. Replacing restored state uses DROP/CREATE rather than requiring runtime DELETE | AuditViewerTest:70 explicitly deletes a sign-in event using migrationPdo; resets use migrator TRUNCATE. Unit malicious-dump DELETE strings are rejection fixtures, not runtime SQL |
| TRUNCATE | None; DROP privilege is absent from intended runtime grants | No ordinary audit retention job | IntegrationTestCase:418–426 truncates all non-excluded tables, including audit, using migrationPdo. Browser fixture inherits this guarded reset. Never point either at restored production data |
| ALTER | None | No released ALTER of audit_log found; migrations create its schema, future schema changes belong to migrator | Generic migration fault tests can change disposable metadata/objects; UPDATE privilege is not needed for ALTER, nor a reason to grant DDL to runtime |
| DROP | None authorized; SchemaTest:116 deliberately expects denial for runtime DROP audit_log | BackupRestorer:149–152 dynamically drops existing tables for authorized replacement, then replays CREATE/INSERT with migrator. Initial audit CREATE is released SQL, same selection on MariaDB | IntegrationTestCase:406–413 drops all fixture tables with migrator; BackupRecoveryTest injects missing audit. Atomicity/revocation/transfer tests RENAME audit using migrator (ALTER/DROP/CREATE-class operation, never runtime dependency) |

Search covered explicit `audit_log`, generic table enumeration/dump restoration,
all source audit services and test scripts. No TRUNCATE/UPDATE/DELETE capability
is needed merely to allocate AUTO_INCREMENT IDs or to roll back an INSERT.
There is no application-supplied privileged stored routine or audit trigger
providing an alternative write path in the inspected schema. Audit readbacks
join users; SELECT on audit alone is not all viewer permissions.

### Effective grants and existing separation

**V, E3:** initialization grants runtime `SELECT, INSERT, UPDATE, DELETE` on
the application database wildcard. The migration account additionally has
`CREATE, ALTER, DROP, INDEX, REFERENCES`. Neither intended account gets GRANT
OPTION, FILE or database-administration powers. Runtime already lacks DDL:
“remove runtime schema privileges” is a **verify-and-preserve requirement**,
not an unimplemented fix to the intended grant set. TRUNCATE needs DROP.
The production web container clears migration/root settings and mounts runtime
credentials only; ops mounts runtime and migration credentials. Backup uses
runtime; restore/migrate use migration PDO. ConnectionFactory has a local fallback
to runtime if no migration username is supplied; it cannot manufacture DDL rights.

**V, E3/E12:** dedicated integration accounts and CI use the same broad
schema-level runtime DML policy as intended production, not root for service
calls. DDL/reset/fault injection deliberately use migration credentials. Thus
these are real restricted accounts relative to root, but **not** restricted
enough to prove audit append-only access. Existing tests prove no runtime schema/
GRANT/system-table access, not denial of audit UPDATE/DELETE. The CI production
drill and ContainerScriptsTest also assert the old grant strings; both must
change with a hardening patch. No production SHOW GRANTS was obtained; live
accounts may have extra historical grants/roles. CREATE USER IF NOT EXISTS and
additional GRANTs do not remove old privileges, and first-init scripts do not
re-run to retrofit existing installations.

MySQL combines applicable global/database/table/column grants; a narrow table
grant is not a deny over a database grant. MySQL partial revokes restrict global
grants by schema, **not one table within a schema**.
[MySQL request verification](https://dev.mysql.com/doc/refman/8.4/en/request-access.html),
[partial revokes](https://dev.mysql.com/doc/refman/8.4/en/partial-revokes.html).
MariaDB likewise grants privileges at global/database/table/column scopes;
database DML remains applicable to its tables. Use removal of broad write grants
and explicit table write grants as the portable model, not a supposed table
REVOKE override or a MySQL-only server switch.
[MariaDB GRANT](https://mariadb.com/docs/server/reference/sql-statements/account-management-sql-statements/grant).

### Indirect modifications, identities and the limit of INSERT-only

**V:** audit's released FK is `users(id) ON DELETE SET NULL`. There is no user
deletion workflow in UserAdministration; accounts are deactivated. Other user
references may RESTRICT deletion, but an account with no restricting references
cannot be assumed protected. **T:** test whether runtime parent DELETE nulls an
audit actor despite absent child UPDATE permission on each exact engine. Do not
claim table grants alone prevent referential effects. Both engines document
automatic FK actions; MySQL also documents that cascades do not invoke triggers.
[MySQL foreign keys](https://dev.mysql.com/doc/refman/8.4/en/create-table-foreign-keys.html),
[MariaDB foreign keys](https://mariadb.com/docs/server/ha-and-performance/optimization-and-tuning/optimization-and-indexes/foreign-keys).

Deny runtime DELETE on users in the proposed allowlist (normal code does not
need it), and check all actual parent/child actions. Retain ordinary profile
updates, but do not mistake a join to a changed display name/email for immutable
historical identity. Stronger identity snapshots/retention would be a separate
design. Do not edit the released FK migration; any justified constraint change
requires a new migration and both-engine restore tests.

INSERT/SELECT only still allows a compromised writer to fabricate actions,
actor/entity/details/timestamps allowed by the schema, flood storage, and omit
events by bypassing services. It also allows disclosure of existing audit data.
Explicit primary-key/duplicate-insert forms must be tested: REPLACE or UPSERT
must not become a way to overwrite existing records with insufficient privileges.
This is protection of previously committed rows from a class of runtime SQL
tampering, **not trustworthy event provenance after arbitrary PHP execution**.

### Recommended least-privilege design (proposal, not executed)

1. Keep audit in the same InnoDB database and on the same service PDO. Retain
   audit SELECT/INSERT; remove all effective runtime UPDATE/DELETE on it. Preserve
   no runtime CREATE/ALTER/DROP/INDEX/TRIGGER/GRANT/FILE privileges.
2. Smallest enforceable grant shape: schema SELECT/INSERT may remain initially
   to support existing dumps, while **UPDATE and DELETE become explicit per-table
   allowlists** excluding audit (and user DELETE). Full per-table SELECT/INSERT
   can follow if justified. Broad SELECT/INSERT does not permit updating existing
   audit rows, but is not the absolute minimum across unrelated tables. Enumerate
   each required operation rather than automatically grant DML on every table.
   Keep migration-history writes out of runtime where feasible; never conceal
   that broad INSERT could still inject metadata/forged records. Prefer a complete
   reviewed privilege manifest when the modest table inventory permits it.
3. Keep provisioning authority outside runtime **and** migrator; no GRANT OPTION
   added to either. For existing installs the owner must replace/reconcile broad
   privileges, including global grants, roles/default roles, alternate matching
   user@host accounts, routines/definers and historical extras. For first installs,
   tables are created by migrations after initialization: stage minimal grants
   then apply the owner-controlled table manifest after schema creation and before
   opening writers. Rehearse table-grant timing on both engines. Do not assume
   the empty-database init can enumerate future tables correctly.
4. Couple each new migration/table with a manifest update and owner provisioning
   step; fail closed if grants/schema differ. Validate backup table inventory
   against the expected schema: SHOW TABLES visibility can otherwise conceal an
   omitted table after narrowing SELECT. Preserve SELECT for all backed-up tables,
   including bookkeeping tables, or split a read-only backup identity in a later
   scoped change. Do not make backups appear complete by silently skipping data.
5. Approved restore/retention remains an ops-only identity with no web-mounted
   secret. Restore necessarily can replace history; that exception must be named
   and separately evidenced. Do not strip migrator DDL needed by authorized restore.
6. Coordinate init, both CI provisioning blocks, production grant assertions,
   deployment unit tests, real restricted-account regressions and operations docs.
   Use fresh verified connections after grants change; quiesce/restart app workers
   during the owner rollout rather than depend on cached privilege refresh timing.
   No production settings/grants are changed by this proposal.

This is **Medium implementation / High testing** because of bootstrapping,
effective grants, backup completeness and referential paths. It is isolated from
permissions logic and released migrations, and is likely cheaper than moving
audit storage. SECURITY.md's older separate-database recommendation is a proposal,
not an established necessity; this assessment recommends evaluating the smaller
allowlist first. Do not weaken grants automatically on failure or restore broad
audit writes as an unnoticed rollback. Keep the site closed, diagnose missing
legitimate privileges and require an explicit temporary exception if necessary.

## 6. Five audit-protection models

The options overlap: an append-only access model can be implemented by Option 1.
They are not five cumulative requirements. All estimates are E and exclude
unapproved infrastructure acquisition. Preserve the current transactional local
event regardless of optional extra delivery.

| Model | Benefit and uncovered threats | Complexity / effort: implementation; testing; rollout | Runtime/operations/failure behavior | Compatibility, dependencies and rollback |
|---|---|---|---|---|
| 1. Permission hardening, same DB | Blocks direct runtime UPDATE/DELETE of old audit rows, accidental bad SQL and part of SQLi/password/PHP compromise. Does not prevent forged INSERT, omission, reads, DBA/host tamper or untested FK effects | Medium / High testing; 2–4 d; 3–5 d; 0.5–2 d | Negligible query cost; manifest/grant maintenance. Missing INSERT fails domain transaction closed. Missing SELECT breaks viewer/backup. No new service | Both engines support table grants. No row migration or logger API change. Provisioning/CI/connection refresh required. Rollback should repair allowlist, not silently regrant audit writes |
| 2. Append-only access plus separate maintenance/retention | Makes row preservation an explicit contract including parent actions and authorized exceptions. Same runtime protection as 1 unless stronger independent boundary added; cannot make all DB accounts append-only while retaining restore/retention | Medium; 3–6 d; 3–6 d; 1–2 d (includes much of 1, not additive) | Very small steady-state cost. Explicit reviewed purge/correction process, external operation evidence and retention checks. Runtime never prunes; full disk still fails writes closed | Same DB/transaction on both engines. Potential new migration only if FK/identity policy changes. Don't introduce triggers requiring broader server powers. Maintenance rollback must preserve records and explain authorized transformations |
| 3. Hash chain / verification | Detects alteration if a trusted independent checkpoint/key remains. Plain unkeyed chain can be recomputed by a writer; a tail can be removed without an external anchor. HMAC key in PHP is lost to PHP/host compromise. No prevention of omitted or false events | High; 6–12 d; 5–10 d; 2–4 d | Canonical representation, ordering, checkpoints/key rotation, restore/retention verification. Global tail lock serializes writes and must fit existing lock order; throughput/deadlock risk. Verification failures need response policy | New schema/backfill/versioned algorithm on both engines; normalize JSON/encoding/time representations in app, not engine serialization. Rollback preserve original events/checkpoints; no automatic re-chain to hide a mismatch |
| 4. Separate audit storage/credentials | A separate schema on the same server simplifies broad data grants vs audit grants, but has little extra host/DBA isolation. Separate credentials limit some accidental use; PHP RCE reads any web-mounted writer secret. Independent server adds isolation only with narrower administration | Medium–High same server; 4–8 d; 4–7 d; 1–3 d. Independent server High; roughly 8–15 d; 6–10 d; 2–5 d | Same-server qualified InnoDB statements on **one PDO** can retain transaction. A second PDO/autocommit logger does not. More databases/backup inventory, FK references, test schemas and grant management | New migration/data move/viewer/restore/dump changes. A distinct server loses simple local atomic commit; needs outbox or much heavier distributed commit. Restore must reconcile both stores. Rollback quiesced, reconcile/copy records and preserve evidence; no two active divergent writers |
| 5. Off-host durable delivery | Independent append-only retention can preserve already received evidence after migrator/DBA/host compromise. Sender can forge or suppress future events and destroy pending local events. Plain syslog/HTTP send without durability is insufficient | High to Very High for custom system; 5–10 d integration with an existing suitable service, 10–20 d custom; 4–8 d tests; 2–5 d rollout plus service administration | Local transactional outbox plus worker, stable event IDs, at-least-once delivery/remote deduplication, lag alerts, authenticated encrypted transport, backoff, bounded spool. Outage policy must choose backpressure/refusal vs continued operation. No remote network call in domain tx | Both engines can persist local outbox; no simultaneous atomic DB+remote success promised. Independent service/retention/backup/credentials required. Preserve local audit on rollback, drain/archive queued events and retain remote copies; don't disable current logging |

For off-host ordering, AUTO_INCREMENT order is not necessarily commit order under
concurrency. Define per-operation order, causal/event IDs and gap monitoring
instead of promising a universal delivery order from row IDs. Retries can duplicate
delivery; receiver idempotency must not drop distinct events. A remote collector
acknowledgement must mean durable acceptance under its own retention contract.
Local audit/outbox together provide atomic **local** persistence; eventual remote
delivery has a distinct failure window. Synchronous fail-closed remote delivery
couples every mutation to collector availability and still has ambiguous network
outcomes. Neither model proves client receipt of an export.

**Recommendation:** Option 1 with the explicit retention/maintenance contract of
Option 2, before activation. Defer 3 and independent-server 4 at current scale.
Option 5 is justified only if independent forensic survival is an owner requirement
or a suitable managed collector already exists; first price its operation and
privacy obligations. Same-server separate schema (4) is a valid fallback if the
owner finds grant-manifest upkeep unacceptable, not an automatic security upgrade
over an equally enforced allowlist. These are proposals requiring approval.

## 7. Threat-specific assessment and retention

| Attacker capability | Current exposure | What least-privilege audit access changes | What needs stronger isolation |
|---|---|---|---|
| Ordinary application user | No audit edit endpoint; cannot invoke SQL by design. Authorized actions may create events; ordinary audit viewing is denied | Defense in depth against a future SQL flaw, not a new role restriction | Existing authorization/CSRF/escaping still decisive; no confirmed user audit-tamper route |
| Compromised administrator session | Can exercise global admin workflows and read audit; no log-delete UI. Can deliberately perform harmful authorized operations and claim to be the victim | Does not reduce web admin authority; events remain recorded through current service transactions | Session protection/revocation, trusted admin policy and optional independent observation/MFA plan; permissions cannot identify the real person behind a stolen session |
| SQL injection executing runtime SQL | Conditional future flaw could read/change domain data and currently edit/delete audit; no such injection established here | Protects old audit rows from direct modification, subject to FK tests. Still forge/omit events, read sensitive data and damage allowed domain data | Narrow SQL surface and effective grants; independent collector for received-history survival, not prevention of arbitrary domain changes |
| Runtime DB password obtained | Same SQL rights **if** attacker also has a network/socket path. Password alone is not proof of DB reachability; DB is unpublished/internal in intended Compose | Same direct-row protection; cannot escalate to DDL/GRANT under intended accounts | Credential rotation and network controls; remote audit not writable with that password |
| Arbitrary PHP execution in web container | Can use runtime secret/session/files, bypass logging, forge events and make allowed DB writes; code/read-only layout reduces persistence, not these capabilities | Preserves old rows against direct writes while DB privilege boundary holds. Parent deletion must also be excluded | Separate append-only receiver/admin credentials; RCE cannot be made honest by a hash key or second writer password available in PHP. Web has no intended migrator/backup secret mount |
| Migration-account compromise | DDL/DML over app schema; can drop/replace audit/history, alter schema, compromise auth data | Runtime restrictions do not bind it | Minimize ops credential exposure/time, control restore/migrate access, independent evidence. Same-server audit schema helps only if this account is actually denied it, complicating schema/restore operations |
| DBA-account compromise | Can change grants/data/schema and usually defeat local restrictions | No effective defense against the controlling DBA | Independently administered off-host immutable retention/checkpoints; trusted historical copies and incident response |
| Docker-host compromise | Can access secrets, DB files, container state, backups, network; can alter future events and locally held keys | No reliable protection from the host owner | Independently controlled receiver/backup keys/admin and protected retention. Already delivered evidence may survive; pending/suppressed events cannot be guaranteed |

Audit stores personal data even without passwords/tokens: IP/browser strings,
user IDs/identities, timestamps and some before/after values can identify people.
The viewer's extra redaction is not deletion from raw rows, backups or replicas.
Compromise of SELECT remains a confidentiality issue after write hardening.
Append-only should mean **untrusted runtime cannot alter retained records**, not
“every person-related field must be retained forever.”

Owner decisions: purpose, applicable jurisdiction/exemptions, retention periods,
authorized viewers, minimum fields, retention of backup/off-host copies, legal
holds, and how approved correction/anonymization/deletion is recorded and
verified. If GDPR applies, storage limitation and erasure rules/exceptions need
assessment; no applicability or mandatory indefinite audit retention is inferred
here. See [official GDPR text, Articles 5 and 17](https://eur-lex.europa.eu/eli/reg/2016/679/oj).
Use a separately authorized maintenance process with bounded selection, dry run,
backup/restore consequences and independent operation evidence. Do not allow the
web role to prune as a convenience. Hash chains/WORM retention complicate deletion;
plan segments/anchors and minimal identifying data before adopting them. Anonymizing
only user_id is insufficient if details/IPs still identify the person.

## 8. Other inherited security/operational boundaries

These are explicitly documented in SECURITY.md/operations, and intersect the
release gates without establishing new H1–H4 defects. They should not disappear
when accepting A–F. The same E effort/day convention applies; implementation,
testing and rollout are separate in the final column.

| Boundary / evidence | Classification, trigger, mitigation and recommendation | Complexity / regression / estimated effort |
|---|---|---|
| Query-format invitation tokens in intermediary/error request logs; SECURITY:161–232, ConfigValidator:327 | Medium conditional confidentiality; valid single-use token exposed if a log reader has access. Both accidental upstream errors and malicious use of disclosed token possible. Fragment flow exists; owner chooses fragment delivery/log access/retention before production. No new link architecture needed | Trivial configuration decision; Medium end-to-end testing of JS/no-JS and intermediaries; Low regression. 0.25–0.5 d / 0.5–1 d / 0.25 d; B. No configuration change now |
| Trusted proxy/local-host IP spoofing and unpublished FastCGI reachability; SECURITY:679–700, DEPLOYMENT:60–89 | Medium conditional abuse/availability; a wrong trusted peer can permit forged rate-limit/audit IPs or shared lockout; trusted local processes can send the expected header. Production nginx fail-closed policy, loopback/internal ports mitigate. Verify actual route/host rules; host compromise is not solved by audit grants | Low verification, Medium testing; Low regression for inspection. 0.5–1 d / 1–2 d / 0.5 d; B. Owner-only actual-host checks |
| Allowlisted-domain DNS/egress trust; SECURITY:158–159 | Low conditional SSRF/network boundary, not a demonstrated bypass. Fixed HTTPS allowlists and response/redirect limits help; owner host egress policy is defense in depth. No arbitrary URL feature introduced | Medium network policy; Medium testing, Medium provider-breakage risk. 1–2 d / 1–2 d / 0.5–1 d; B verify existing boundary, C additional redesign |
| No MFA, fixed account throttling, manual invitation/password delivery; SECURITY:659–710 | Medium conditional account compromise/availability; deliberate lockout possible for known email. Strong hashing, generic errors, CSRF/session defenses and trusted delivery mitigate. MFA would improve stolen-password threats but not already compromised sessions; no new H4 failure demonstrated. Owner policy review before wider audience | MFA Medium–High, High recovery testing/regression: 4–8 d / 3–6 d / 1–2 d; C separate project. Delivery policy Low: 0.5 d / 0.5 d / 0.25 d; B decision |
| File sessions/single PHP replica and volatile sessions; SECURITY:709–710, OPERATIONS:231–234 | Low operational limitation; restart signs users out, replicas can disagree accidentally. Intended single instance plus revalidation is suitable. Do not scale replicas without shared-session/stickiness and CSRF/revocation design | Shared sessions High, High testing/regression; 5–10 d / 4–8 d / 1–2 d; C until scaling, coupled R02 |
| Admin vocabulary last-write-wins and large upload “form expired”; SECURITY:716–724 | Low concurrent lost edit / Informational usability. Versions protect core inventory; audit/admin restriction helps vocabulary accountability. Oversize request loses body and fails CSRF safely. No authorization issue demonstrated. Add versions or better oversize handling only for real operator pain | Medium vocabulary, Low upload; Medium tests/regression. Versions 2–4 d / 2–3 d / 0.5 d; upload 0.5–1 d / 0.5–1 d / 0.25 d; C |
| Leading-wildcard catalogue/detail searches and no export rate limit; SECURITY:671–675, 725–727, 357–366 | Low–Medium scale availability; authorized repeated work or ordinary growth can raise load. Bounded audit time/window, keyset exports and small scale mitigate; measure first under R28. New search index/schema is not automatically required | Medium alternative indexing/limits, Medium–High testing, Medium regression; 2–5 d / 2–4 d / 0.5–1 d if measurements justify; B measure, C redesign |
| Unencrypted app archives, unscheduled retention/monitoring, no auto container restart; OPERATIONS:251–314, DEPLOYMENT:549, 823–907 | High conditional recovery loss / Medium confidentiality and availability. Permissions, verified archives and fail-closed disk/boot behavior mitigate; off-host encryption/copies, tested key recovery, schedules/alerts are owner responsibilities. Do not “fix” restart policy by bypassing disk preflight | Low–Medium operational setup, Medium verification, Medium misconfiguration risk; 1–3 d / 1–3 d / 1–2 d; B, shared R19/R28 rather than duplicate budget |

These rows introduce no authorization to touch the proxy, Docker host, secret
store, network policy or deployment settings. Wishlist non-auditing and shared
copy display names/locations **within authorized visibility** remain deliberate
privacy/model choices; do not expand logs or claim global pre-household read
statements describe enabled household isolation.

## 9. Proposed deterministic validation plan — not executed

Prerequisite for **every DB/behavior test below**: new explicit authorization,
session-owned disposable databases on isolated MariaDB **11.4.13** and MySQL
**8.4.13**, loopback/non-3306, `_test`/`_rehearsal` names as appropriate, dedicated
verified accounts with no access to normal development/production, no Docker on
this workstation, and agreed resource cleanup. No grants against live databases.
Use existing TestDatabaseGuard for synthetic tests. A restored-data rehearsal
must use its own non-resetting tooling, never PHPUnit/browser fixtures.

| Test | Exact proposed schedule/assertions | Additional prerequisites |
|---|---|---|
| P1 Effective privilege composition | Provision broad-DML baseline; prove a narrow table SELECT/INSERT grant alone does not remove UPDATE/DELETE; replace broad writes with proposed manifest; reconnect and assert SELECT/INSERT allowed, UPDATE/DELETE/TRUNCATE/ALTER/DROP/RENAME/GRANT denied for runtime | Disposable DBA/provisioning identity only; inspect CURRENT_USER, SHOW GRANTS and active roles/account host. Include global/role grant contamination as a negative configuration |
| P2 Insert-only and indirect paths | Snapshot audit rows; attempt UPDATE, DELETE, REPLACE duplicate PK and INSERT ON DUPLICATE KEY UPDATE with runtime; require no old-row change. Seed a user referenced only by audit, test parent DELETE under broad vs proposed user-delete denial; compare audit.user_id and bytes. Test nullable/system actor, actor profile changes and foreign-key-check settings; record both engines' actual behavior | Deliberately minimal synthetic users, no other RESTRICT refs hiding cascade. Test permissions, not a mocked PDO. No production SQL |
| P3 Domain rollback/retry with real restrictions | Execute administrative grants/creation, lending, cover DB records and H4 apply under restricted runtime. Reuse separate-connection revocation barriers for both commit orders. Deny audit INSERT temporarily on disposable account and require full rollback; force deadlock victim/retry and require one domain outcome/event; concurrent distinct events must not be lost | Existing E11 workers plus distinct provisioning account; permission changes outside application transaction; restore fixture grants explicitly |
| P4 Bootstrap/upgrade/backup/restore | Fresh empty DB init → migrations → manifest → web service calls. Existing broad account → reconcile → reconnect → same checks. Compare expected tables with dump/manifest and restored fingerprints, including audit and migration-attempt metadata. New-table omission must fail preflight rather than silently produce partial backup. Restore historical audit and append restoration event with ops identity | Both engines; production init/CI grant fixtures updated in future implementation. Runtime SELECT for all dumped tables, migrator DDL for restore |
| P5 Auth/session failure windows | Inject audit exception before/after login/session writes and logout audit; inspect persisted session/cookie, throttle/last-login and response on next request. For any proposed narrow logout fix require invalidation even on audit failure, no claimed successful event, CSRF/auth_version intact | Isolated PHP session files; deterministic failure hooks in a future test change; no assumption that an in-memory session proves native shutdown behavior |
| P6 Files/operations | Kill worker after cover file write/before DB commit and after DB delete/before unlink; dry-run/reconcile must preserve referenced originals/thumbnails, reject symlinks and report missing files. Interrupt backup/restore/cleanup before final audit and verify markers, exit/outcome evidence and no unsafe automatic resume | Private file trees; writers stopped for deletion test; each restore into disposable schema only |
| P7 Contention and exports | Barrier author merge after work lock vs inventory after edition lock in both flag modes; record actual deadlock/timeout, complete rollback and audit uniqueness. Hold export output/batches while another account revocation starts; measure waits/timeout, then finish/cancel and verify committed revocation refuses subsequent work | Separate workers/connections, deterministic barriers, bounded wall-clock watchdog, controlled small and production-sized data. No guessed sleeps as proof |
| P8 Missing/default/phantom and plans | Controlled concurrent household/account creation vs membership/transfer/default reads; missing-default restored fixture must refuse safely. Saved plan + unrelated write retains current no-partial-write refusal. Any proposed narrower comparison must still reject every relevant owner/loan/member/tree/version/code change | New fixtures/schedules only, no production manipulations; classify schedules not reproduced as hypotheses |
| P9 Migration interruption/recovery | Fail each relevant statement/history commit, terminate a client around DDL on disposable DB, inspect intent/schema/history, and assert refusal; simulate an older partial state without intent. Compare exact engine schema/rows, rehearse backup restore/manual reconciliation and confirm checksums/pins unchanged | Verified same-engine backup; no generic DROP/clear-intent recovery helper; power-loss claims require a separate authorized crash environment |
| P10 Release matrix/data/capacity | Run approved Firefox/WebKit/accessibility matrix; build intended image on authorized isolated host; verify marker/hostile env/startup refusal/no auto migration. Rehearse real-data assignments, fingerprints, FKs/cycles/collations, recovery version/RPO/RTO; measure peak space/RSS/locks/export latency | Owner-controlled confidential clone and suitable CPU/runtime; no production feature activation; 8.3 matrix only if deployment support adopted |

Success criteria must include denied operations leaving byte-identical retained
audit rows, no partial domain mutations, exactly one committed event per retried
domain action, no false exactly-once delivery claim, complete backups and preserved
existing catalogue IDs/fields. DB permission tests must run using service runtime
connections, not accidentally switch to migrator to make failures disappear.

## 10. Priority groups and merge/activation recommendations

### Group A — Before merging dormant H1–H4

**No additional application/schema fix is justified as a dormant-code merge hold
by the evidence in this investigation.** In particular, optional logging redesign
and conservative stale-plan behavior do not justify delaying merge. This does
not waive the existing conditions:

1. **R29:** owner verifies exact final head/base/main/required CI/reviews and
   external deployment triggers. There is a concrete release-control benefit;
   local source cannot prove this boundary. Stop if a merge can trigger an
   unapproved production action.
2. **R32:** owner explicitly approves/revises the proposed A–F dormant-merge
   dispositions, recording approver/date/scope. They remain pending here.
3. Any newly reproduced current-writer authorization or corruption defect is
   a blocker requiring a separately authorized fix, not an accepted contention
   limit. None was newly demonstrated by this analysis-only task.

R01 hardening is useful and manageable enough for its own focused change, but
merging guarded dormant code does not itself expose the additional privileges
or activate households. Recommend it before activation, not as a newly invented
mandatory code merge hold. An owner may choose to do it sooner.

### Group B — Before deployment/production activation

- **R01/R30:** approve forensic threat/retention scope and implement/test the
  grant manifest proposal, or record an explicit justified time-bounded exception.
  Recommendation is to harden, rather than use an indefinite exception. Benefit
  is real, transaction-preserving reduction of runtime tampering capability.
- **R08/R09/R28:** measure export/revocation/broad-lock waits and missing/default
  schedules at actual scale. Fix only demonstrated unacceptable budgets or
  invariant violations. Availability evidence is necessary; wholesale lock redesign
  is not automatically necessary.
- **R11/R16–R22:** same-engine verified off-host backup, stopped-writer inventory,
  restored-data schema/semantic/assignment/recovery rehearsal and approved RPO/RTO.
  Privacy and existing data preservation justify these gates. No old-version or
  flag-off writer after isolation; no automatic DDL recovery.
- **R12–R16:** explicit shared-bibliography/creator-signal/history/global-admin and
  actual-membership policy confirmation. A policy disagreement calls for a scoped
  redesign before activation, not silent acceptance.
- **R23–R25/R27:** approved browser/accessibility matrix, actual image/CPU/runtime/
  storage/secret/ingress checks. R26 is a gate only if 8.3 deployment is promised.
  Existing source safeguards must remain; a later activation release is separate.
- **R04 operational model and section 8:** approve how to reconcile uncertain
  operations; verify schedules/alerts, confidential backup/key handling, proxy
  trust, invitation delivery/logging and host port boundaries. These are practical
  operating requirements, not permission to redesign logging or access servers.

Some B items are required **before any deployment** (backup/image/host checks),
not merely before enabling households. Merger of dormant code clears none of them.

### Group C — Proposed acceptance or deferral

- **R02:** retain honest session/DB boundary; consider focused logout-failure
  semantics separately. Full transactional sessions/outbox are costly and still
  cannot atomically deliver cookies. No current auth bypass is established here.
- **R03/R04:** use current conservative dry runs, markers and verification;
  improve reconciliation/operation records incrementally if failures warrant it.
  Generic resumable side-effect transactions would have high regression risk.
- **R05/R06:** truthful export initiation and targeted retries are adequate;
  defer client-receipt claims/general HTTP idempotency without harmful duplicates.
- **R07/R10 and redesign portions of R08/R09/R21:** retain rollback/retry and
  conservative constraints, investigate deterministic contention first; do not
  loosen a safe refusal solely for convenience.
- **R26/R31:** no full 8.3 deployment promise; retain explicit supersession.
  Documentation correction can be small future work without rewriting history.
- **Options 3–5 and broad policy/permissions redesigns:** defer until an explicit
  stronger threat model, growth or owner policy requires them. Same-server audit
  separation remains a fallback, not an immediate dependency. MFA and replica/
  search redesigns are separately scoped inherited work, not H4 merge prerequisites.

All C dispositions are **recommendations only**. The owner has not accepted them
by requesting this report. Group B verification may reveal a bug and change the
classification/priority; “unknown” is never a passing result.

## 11. Exact next steps and approvals required

1. Owner reviews this source-based assessment and records A–F merge decisions;
   confirms current remote candidate/check evidence and external automation
   boundary through authorized owner access. No secret values should be shared.
2. Owner selects audit goal: preserve rows against runtime SQL compromise, or
   survive migrator/DBA/host compromise as well. Approve retention/identity/privacy
   rules and Option 1/2 manifest design (or explicit alternative). Decide whether
   the bounded R02 logout/audit failure semantics deserve a separate small task.
3. Only after new authorization, prepare a focused hardening patch covering
   initialization/existing-install provisioning/CI/grant assertions/docs and
   genuine restricted-account tests P1–P4. Keep released migrations unchanged,
   logger on the same PDO and current domain rollback/retry guarantees. First
   validate the actor FK and exact allowlist before claiming append-only behavior.
4. Separately authorize P5–P9 where needed, and owner-controlled release rehearsal
   P10. Approve supported browsers/accessibility/PHP deployment targets, policy
   assignments, contention budgets and recovery point/time. Use disposable targets
   only; no reset-capable test harness on confidential restored production data.
5. Owner plans any subsequent production deployment as its own change with exact
   artifact/source SHA, backup, host verification and rollback. Household activation
   remains prohibited by this release; removing both guards needs a distinct
   authorized design/verification release. No decision here approves that work.

**Final recommendation:** CONDITIONAL for merging dormant code once existing
owner/evidence gates clear; NOT READY and NOT AUTHORIZED for production activation.
No outstanding proposed risk has been converted into owner acceptance. No fix,
grant, runtime setting, migration or production action was implemented.

## 12. Verification of this deliverable

This task ran source/Git inspection and document static checks only. The new
Markdown's file references, fences, inventory coverage and whitespace were
checked, including the untracked file explicitly (ordinary git diff ignores
untracked content). No DB, PHPUnit, browser, container build, grant experiment,
rehearsal or remote CI check is claimed. Only this new document is left unstaged
and uncommitted; no tracked application/test/configuration/history file changed.
