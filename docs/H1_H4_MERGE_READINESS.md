# H1–H4 pre-merge decision and production release gates

Review date: **2026-11-15 (Europe/Madrid)**. Source commit:
`7804357ca56cdfeec4bb951ed33bc1e67cd1b650`, branch `household-access-h4`.
Scope: documentation and local-source pre-merge review, **not a new audit or
implementation phase**. The starting working tree was clean. No application,
permission, migration, grant, deployment configuration or historical report was
changed. No remote, Docker, database or browser service was accessed or started.

**Merge recommendation: CONDITIONAL. Production activation: NOT READY / NOT
AUTHORIZED.** No unresolved authorization or data-corruption defect was identified
by this focused review. The conditions are explicit owner decisions and external
merge/deployment-boundary checks below; proposed risk acceptance is not approval.
Merging dormant code, deploying a release and activating household isolation are
three separate decisions. This document authorizes none of those actions.

## 1. Source and verification evidence

Local `main` and cached `origin/main` both name
`aee3f3deb08c4791f9fde9cde9cc4f35363fd779` (2026-11-13); it is the merge base
and an ancestor of this branch. Neither reference's current remote freshness is
established: **no fetch or remote query was made**. The comparison is
`git diff main...HEAD`, not an independently verified live GitHub PR diff.

The comparison contains **12 commits / 173 paths (70 added, 103 modified)**:
H1 consolidation `9d613f3`, H2 `d16e96d`, H3 `134a03a`, H4 `9d5daa2`, their reports,
the CI port correction, and remediation commits `361e66c` (authorization/audit),
`7683757` (production identity), `03da74b` (migration recovery), `7804357` (report).
All are ancestors of this head. The inventory/targeted configuration diff fits
H1–H4; no unrelated development or binary additions were found. Bounded added-line
checks found no credential/data/key files or private-key/common-token/URL-password
patterns; examples/tests use placeholders or throwaway credentials. This is not
a comprehensive new secret audit or verification of remote/untracked data.

Pre-household SQL present in local main is unchanged; the three H1, four H3 and
two H4 files match their phase commits byte-for-byte. Existing pinned migration
hashes are preserved; household pins are additional.

| Evidence | Result and provenance |
|---|---|
| Full MariaDB 11.4.13 and MySQL 8.4.13 suites | Each **998 tests / 13,344 assertions, no skips, PASS**, PHP 8.4.27; recorded remediation execution, also confirmed in the owner's latest report |
| Chrome / Edge | **95 checks each PASS**; recorded remediation evidence, not rerun here |
| Nginx/PHP-FPM | **54 checks PASS**; recorded remediation evidence, not rerun here |
| PHP lint / Composer validation | PASS in remediation; not rerun for this documentation-only change |
| GitHub Actions after remediation commits | **All checks passed, owner-reported**; run URLs, exact tested SHA and live PR state not independently queried |
| Focused checks executed in this review | **32 tests / 911 assertions PASS**, no skips/warnings; frozen migrations, production identity/Compose, CI workflow structure and shell-block syntax; no Docker execution |
| This documentation | `git diff --check`, Markdown/link/command checks and documentation-only scope verification passed |

The focused command was:

```bash
php vendor/bin/phpunit --testsuite unit --filter 'MigrationFilesTest|HouseholdMigrationFilesTest|ProductionIdentityTest|ProductionComposeTest|CiWorkflowTest' --fail-on-skipped --fail-on-warning
git diff --check
```

The full suite was not rerun. The [audit](FINAL_H1_H4_AUDIT.md),
[remediation report](H1_H4_REMEDIATION.md) and phase reports remain historical:
original hold/uncommitted-delivery statements describe their review dates.
Older runbook claims that CI has never run or has four jobs are superseded here
by owner-reported green CI and the five-job source; real-host verification remains
separate. No original audit conclusion or historical report was edited.

## 2. Confirmed fixes carried into this PR

| Finding | Reviewed resolution, supported by remediation regressions |
|---|---|
| F1: stale administrator authorization | Fresh active administrator read and account locks inside mutation transactions; household-before-sorted-account order; last-active-admin/self-change safeguards retained. Stale objects/forms cannot authorize writes after committed demotion/deactivation. |
| F2: database mutation without audit | Required domain events share the mutation's PDO/transaction; audit failure rolls back grants and domain writes. Deadlock retries roll back earlier events. Existing H4 completion idempotency remains. |
| F3: APP_ENV-only production classification | Independent production-image marker plus pinned Compose identity; both activation prohibitions retained (section 5). |
| F4: interrupted DDL / checksum drift | Durable attempt metadata, applied-history checksum refusal, no automatic repair/replay of incomplete files; manual recovery retained. |
| H1 borrower/owner timing hypothesis | Reproduced in remediation and fixed with required accounts locked before catalogue rows, including first-book copies. |
| F6: obsolete release guidance | Remediation records corrections/supersession; this document distinguishes committed results, owner-reported CI and pending decisions. |

F5's conservative plan invalidation and the remaining limitations below are not
silently promoted to fixed vulnerabilities. No remediation was repeated here.

## 3. Residual-risk decision register

**Every decision in this table is PROPOSED / OWNER APPROVAL PENDING.** The prompt
requests recording proposals, not accepting risks on the owner's behalf.
Approval for merge would apply only to dormant code and would not authorize
deployment, weaken transactional auditing or clear activation gates.

| ID | Residual risk | Proposed disposition for merge | Production gate / owner decision |
|---|---|---|---|
| A | Login/logout/session state and DB audit are not atomic; cover process failure can leave orphan files; backup/restore/cleanup/maintenance have nontransactional effects; export audit records authorization/request rather than receipt; ordinary HTTP lacks general exactly-once semantics | Accept for merge; defer redesign | Approve the operating/recovery model and any remaining operational audit limitation explicitly for the production release. Retain atomic domain audit and H4 idempotency. |
| B | Runtime account retains UPDATE/DELETE on audit_log | Accept temporarily for merge | Explicit security decision before activation: restrict permissions/separate append-only storage, add off-host evidence, or record a justified residual exception and review point. No grants/schema changed here. |
| C | Legacy author-merge contention, rolled-back 409s; long catalogue exports delay administrator revocation; unrelated inventory changes invalidate saved plans; direct SQL/old writers bypass the protocol | Accept for merge; monitor/investigate separately | Agree concurrency limits, investigate relevant schedules and measure waits before release/scale growth. New credible authorization or corruption evidence is a merge blocker, not an accepted availability limit. |
| D | Shared bibliography, creator edit-exclusivity signal, historical borrower information, global admin access, default/NULL assignments | Retain implementation for merge | Explicit policy and membership/assignment confirmation before activation; see below. |
| E | DDL files are non-atomic; interrupted/old unjournaled states need manual recovery; mixed-version writers are unsafe | Accept fail-closed design for merge | Same-engine backup/restore, interrupted-migration and restored-data rehearsal before production; preserve existing records and approve recovery point/time. |
| F | Firefox/WebKit, accessibility, runtime/production-host compatibility, backup/real-data integrity and capacity evidence incomplete | Defer to production release checklist | Define supported matrix and complete applicable checks. Green hosted CI does not certify the real host or production data. |

### Operational audit and tamper resistance (A/B)

Domain mutation/event transactions and H4 durable completion/idempotency remain
required. Sessions/cookies, filesystem effects and external delivery cannot be
rolled back with InnoDB. Preserve staged-cover cleanup, restore safety backups/
incomplete markers, maintenance and bounded retries. An unknown response is not
proof of rollback: reconcile outcomes; do not blindly replay ordinary requests.

An ordinary account has no audit-write endpoint. SQL injection with runtime
credentials or application code execution could nevertheless modify/erase audit,
forge INSERTs and alter other DML data. Database-admin/host compromise is stronger.
Existing mitigations: application audit writes are insert-only, the viewer is
read-only/redacted, runtime lacks DDL/GRANT, migration/root credentials are
separated, and network/container restrictions reduce exposure. None prevents
UPDATE/DELETE tampering through compromised runtime credentials. Atomic audit is
not forensic integrity; local backups alone are not independent immutable proof.

Options from [SECURITY.md](../SECURITY.md#audit-log-integrity-review):

| Option | Security and operational trade-off |
|---|---|
| Per-table runtime grants; audit SELECT/INSERT only | Replace broad database DML with maintained allowlists. Table-level revocation does not negate database-level grants. New tables need an owner-controlled grant process. |
| Separate audit database with SELECT/INSERT runtime access | Justified for preserving evidence after runtime-credential compromise. A reviewed same-server InnoDB design can retain transaction atomicity; FKs, backups/restores and tests need a separate change. Forged INSERTs and admin/host tampering remain possible. |
| Independent append-only/off-host collection | Justified for evidence surviving local compromise; requires independent access/retention controls and a delivery durability/outbox decision. It supplements domain atomicity and cannot prove events suppressed before collection. |

Before activation, the owner must select the threat model, protection/retention/
access/backup policy, and implementation or explicit time-bounded exception.
Do not relax server privileges for a trigger workaround without separate review.
No append-only/off-host protection, grant change or audit redesign is claimed.

### Concurrency and visibility (C/D)

Legacy author merges retain work-before-edition order; the exact original
merge/inventory deadlock schedule was not deterministically reproduced.
Administrative retries and enabled-mode household locks reduce contention, but
rolled-back 409s remain possible. Long authorized catalogue exports hold actor
locks through the last batch and can delay revocation; later writes after
committed revocation must fail. Conservative H4 snapshots can become stale after
unrelated inventory changes: require fresh preview/review, not bypass. Monitor
export duration, account-lock waits, deadlocks and 409s. Guarantees cover current
cooperating services, not direct SQL or mixed old/enabled/disabled writers; broad
account locks require measurement at the intended scale. No corruption or
unauthorized writes may be accepted merely as contention.

Policies retained, requiring confirmation before activation:

- **Shared works/editions:** one bibliographic identity without household columns;
  ordinary lists still use derived visibility. ISBN probes can reveal shared
  bibliography/existence, not another household's physical inventory.
- **Creator signal:** loss of edit exclusivity can reveal that another household
  holds a record, without household identity, owner, location or count.
- **History:** borrowers retain their own historical title/date information after
  inventory leaves their view; detailed history follows owner/admin/borrower
  rules. Ownership reassignment with any loan history is refused.
- **Administrators:** global household access is deliberate. Membership alone
  never grants management of another member's copy; confirm administrator trust.
- **Default/NULL:** protected default household and nullable assignments remain.
  H1 backfills NULL inventory to default; only active previously signed-in
  accounts receive backfilled membership. Later memberships are explicit. Under
  enforcement, remaining NULL inventory is admin-only; disabled reads are global.
  Approve actual default members, owners, NULLs, collections and invitation grants.

### Migration and compatibility (E/F)

Both engines can commit earlier DDL before a later statement/history INSERT
fails. Durable schema_migration_attempts precedes execution; history insertion
and intent removal commit together. Incomplete intent blocks migrate/status;
changed/unknown/other-engine history blocks migrate. Older partial runs can lack
intent, so inspect objects/data effects. An intent can also belong to an active
migrator: establish that it stopped. Never clear intent, rewrite history/checksums,
edit released SQL or drop/recreate objects to force success. Follow
[manual recovery](OPERATIONS.md#interrupted-migration-and-checksum-recovery-h1h4-remediation).
`migrate:status` can create bookkeeping tables using migration credentials;
it is not purely read-only at the metadata level or a local merge-review command.

Before production, rehearse same-engine backup/restore and interrupted migration,
preserve IDs/owners/loans/codes/catalogue fields except approved backfills/schema
effects, and reconcile fingerprints, FKs, cycles, conflicts and covers. Stop all
web/CLI/scheduled/old-version writers. Restore is non-atomic and an older recovery
point loses later writes; SQL compatibility is not safe mixed-version operation.
After separation, flag-off is not a privacy rollback (global reads): retain
maintenance and a reviewed household-aware recovery version.

Firefox/WebKit, accessibility, host CPU/image runtime, real backup/restored-data
integrity and storage/rebuild/lock capacity remain release gates. PHP 8.4 is the
deployment runtime; 8.3 is documented tooling compatibility with a CI lint/unit
job. Owner-reported green CI supports that job, not a locally rerun full PHP 8.3
deployment certification. If 8.3 becomes a deployment target, approve and test its
required matrix. Hosted production-image CI does not certify the actual host.

## 4. Main-merge versus deployment trace

The only tracked workflow is [CI](../.github/workflows/ci.yml). Its triggers are
push to main, pull_request, weekly schedule and workflow_dispatch. All five jobs
use `ubuntu-24.04` GitHub-hosted runners; production-stack depends on static.
There are no reusable workflow/local-action calls or deployment environments.
The only external actions are SHA-pinned actions/checkout and
shivammathur/setup-php; checkout does not persist credentials. Token permissions
are contents:read; no secrets context, OIDC grant or production credential is
referenced. Their external implementation payloads were not fetched/audited here.
Composer has no install-time deployment script; production dependency installation
uses --no-scripts. Dockerfiles copy/build assets/code and the marker, not deploy.

| Possible consequence of merging to main | Local-source assessment |
|---|---|
| Trigger GitHub Actions | **Yes**, push/main CI, subject to GitHub settings; PR/scheduled/manual CI also exists. |
| Build or publish a production image | **Build: yes**, production-target images on the disposable runner. **Publish: no tracked step**; local image names only, no registry login/push/publishing action. |
| SSH or otherwise deploy to a production machine | **No tracked path**: no SSH/SCP/rsync, remote Docker endpoint or deploy action/call. |
| Run migrations | **Yes in disposable CI**: test suites migrate *_test databases; production-stack explicitly migrates its runner-only database and runs a restore drill. **No tracked production migration trigger**. |
| Restart production containers | **No tracked path**. CI starts/stops/recreates its own containers only. The installed production systemd unit starts existing containers with --no-recreate --no-build --pull never; it does not fetch/check out code or migrate. |
| Change active production configuration | **No tracked path**. CI copies/edits throwaway env/policy files on its runner; templates are not the host's untracked active configuration. |
| Enable HOUSEHOLDS_ENABLED | **No** in the intended production artifacts: examples are false and production guards prohibit true. CI never removes those guards. |
| Invoke an external deployment webhook/workflow | **No tracked invocation**; no webhook, workflow_run/call or repository_dispatch chain. External subscribers/settings remain unknown. |

The workflow diff changes only test ports/client arguments. Main-triggered
runner builds/migrations already existed; no deployment trigger was introduced.
Entry points, Compose, DB init and the installed boot unit were traced. DB
first-init creates schema/accounts/grants under empty-init authorization, not
application migrations. Runbook checkout/build/migrate/up steps require the owner;
they are not executable GitHub deployment automation. Discovery was not executed.

**Overall boundary remains incomplete until the owner verifies external state:**

- Current PR head/base, actual remote main, required checks/reviews and exact CI
  run SHA/results; refresh the review if remote main differs or the PR changes.
- Repository/organization Webhooks, installed GitHub Apps, external CI/CD and
  organization workflows/rules that can react to push/main, checks or completion.
- Actions runner/environment/permission configuration, secret/variable access
  and any external deployment integration. Verify names/access policies, never
  share secret values. Secrets merely existing does not mean this workflow uses
  them; external consumers are the concern.
- Production bare-repository receive hooks, timers/cron/file watchers, external
  checkout/build/migration services and registry/container update agents. The
  runbook says pushing deploys nothing, but the actual server state was not read.

These checks are owner-only. No assurance that *all external automation* is absent
can be derived from local Git alone. If an automatic production path exists,
hold merge until its behavior/approval boundary is explicitly resolved.

## 5. Static production-safeguard assessment

| Safeguard | Confirmed source / limit |
|---|---|
| Independent image identity | [PHP Dockerfile](../docker/php/Dockerfile), prod stage: root-created config/production-image, 0444, code owned by root, USER www-data; [Config::isProduction](../src/Core/Config.php) checks the marker before APP_ENV. Intended Compose mounts storage, not code/config, and uses read-only containers. |
| Production environment pinning | [Production Compose](../docker-compose.prod.yml) explicitly sets APP_ENV=production and APP_ENV_FILE="" for both php and ops, overriding env_file values. File-value precedence cannot select development in that intended configuration; marker is an independent safeguard. |
| Validation prohibition | [ConfigValidator](../src/Core/ConfigValidator.php) rejects HOUSEHOLDS_ENABLED when production is classified; [PHP entrypoint](../docker/php/entrypoint-prod.sh) runs config:check before FPM and refuses errors. [Application](../src/Core/Application.php) retains production validation. |
| Runtime prohibition | [Services::householdsEnabled](../src/Core/Services.php) is always false in production, even if a flag reaches it. Neither prohibition was removed by remediation. |
| Normal deployment remains disabled | [.env.production.example](../.env.production.example) and [.env.example](../.env.example) set HOUSEHOLDS_ENABLED=false. No migration, startup script or CI step changes an active flag; flag true would refuse normal production FPM startup, not enable households. Actual host env/overrides were not inspected. |
| No startup application migrations | PHP entrypoint validates configuration then delegates to the image entrypoint/FPM; nginx validates policy then starts nginx; DB guard delegates only authorized DB startup/init. Compose ops is profiled and defaults to console help. [Console](../src/Console/Console.php) dispatches migrate only explicitly, requires ops in production and takes the operations lock. [Bootstrap](../src/Core/Bootstrap.php) does not migrate. |

This is **static source plus focused unit verification**, not a container build
or host runtime test. The marker affects newly built prod artifacts; it does not
retrofit an already running old image. Unreviewed Compose overrides, mounting
over code/config or using a different image are outside this assessment. Do not
use them to bypass activation restrictions. A future activation release must
deliberately review both prohibitions and the complete operational/privacy model.

## 6. Outstanding decisions and release checklists

### Before merge — conditions for changing CONDITIONAL to READY

- [ ] Owner explicitly approves or revises each proposed A–F merge disposition;
  record approver/date/scope. None is recorded as accepted by this document.
- [ ] Owner confirms the live PR includes the remediation and latest documentation,
  the comparison against current remote main is still applicable, and required
  CI/reviews are green for the exact final candidate/merge result as applicable.
- [ ] Owner completes the external automation checks in section 4; no unresolved
  automatic production action can accompany the merge.
- [ ] Any newly evidenced authorization/corruption vulnerability is resolved as a
  blocker, not folded into these residual availability/policy proposals.

There is no additional confirmed code blocker from this limited review. Pending
owner decisions and unverifiable external deployment/freshness facts are the
remaining merge gates. The source supports merging **dormant** H1–H4 code once
those gates are cleared; compatibility/real-data production gates are not waived.

### After an owner-authorized merge

- [ ] Record the actual main/merge SHA and CI evidence; verify only expected
  runner-local CI effects occurred. Do not turn the merge into deployment.
- [ ] Keep production household functionality disabled and existing safeguards
  intact. Open a separate owner-approved production-release plan/change.
- [ ] Track deferred audit architecture, append-only decision, contention and
  compatibility work without changing the accepted domain audit contract.

### Before any production deployment / later activation

- [ ] Explicit owner deployment authorization; reviewed release SHA/artifacts,
  environment/secret separation, installed preflight and absence of unsafe overrides.
- [ ] Owner verifies actual image build/runtime/CPU, marker, production classification,
  startup refusal with an enabled flag, role separation, storage/ingress and health
  on an authorized isolated target before live use. Hosted CI is supporting evidence.
- [ ] Verify a real same-engine backup and protected off-host copy; rehearse restore,
  migration/interruption/recovery and reconciled catalogue data on a disposable
  restored target. No PHPUnit/browser fixture resets against restored real data.
- [ ] Record storage/rebuild/temp/backup headroom, maintenance duration, lock waits,
  export/revocation latency, RPO/RTO and recovery images; stop all writer versions.
- [ ] Complete supported Firefox/WebKit and accessibility checks; settle PHP 8.3
  deployment support and run the applicable matrix if it becomes a target.
- [ ] Before activation, explicitly resolve B's forensic-security decision and D's
  visibility/default/NULL/membership/borrower policies; approve exact assignments,
  collections/invitations, loans/ownership constraints and privacy-safe recovery.
- [ ] Before activation, approve a **separate activation release** that deliberately
  revisits both production prohibitions, with new tests/runbooks and owner sign-off.
  Current code still blocks activation; this review is not an enablement decision.

See [DEPLOYMENT.md](DEPLOYMENT.md), [OPERATIONS.md](OPERATIONS.md),
[HOUSEHOLD_INVENTORY.md](HOUSEHOLD_INVENTORY.md) and the preserved audit's restored-data
rehearsal for owner-run procedures. No production command was executed here.

## 7. Owner commit commands — documentation only

Leave the risk proposals pending unless separately approved. After reviewing this
file, the owner may run the following in the existing checkout; they perform no
push, merge or deployment and do not override identity, signing or hooks:

```bash
cd /home/brais/Projects/book-library-catalog
git diff --check
git add -- docs/H1_H4_MERGE_READINESS.md
git diff --cached --check
git commit -m "docs: record H1-H4 merge decision and production release gates"
git log -1 --format=fuller
git cat-file -p HEAD
```

This review leaves the document **unstaged and uncommitted**. No other file is
part of this documentation delivery.
