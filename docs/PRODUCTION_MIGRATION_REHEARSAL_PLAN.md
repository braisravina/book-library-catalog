# Production upgrade rehearsal — owner execution plan

Prepared: **2026-11-15, Europe/Madrid**. Status: **PLAN ONLY; NOT EXECUTED**.
No production access, data acquisition, privilege change, migration, Docker
execution, deployment or activation occurred during preparation. Only this new
document is an unstaged/uncommitted change. It authorizes none of those actions.

**Current decision:** ready for owner review, **NO-GO for execution until the
prerequisites/approvals below are complete**. Production deployment remains a
separate decision. **Household activation remains NOT AUTHORIZED**, including
as an implicit consequence of deploying dormant code or running migrations.

## 1. Verified source baseline and provenance

The reviewed **application baseline** is merge commit
`c8806b2274bac180b36d7796ee4533f8fe18f2b0` (PR #13). Its history includes H1–H4
merge `89c2f07`, grant hardening `6f742f9`, logout fix `9212922` and evidence
`b1334c8`. This establishes the locally inspected application code, not the
deployed version.
A later documentation-only commit containing this plan has a **different SHA**;
record its full SHA separately once the owner commits/reviews it. It must not
replace or be confused with the application baseline. No documentation commit
has been created or its future SHA assumed during this review.

**Branch discrepancy:** the actual branch is `security/audit-grants-logout`;
local `main` still names `aee3f3deb08c4791f9fde9cde9cc4f35363fd779`. No fetch,
switch, branch update or merge was performed. This plan targets the merged
**c8806b2 source**, not the stale local main tree. Owner must reconcile/confirm
the intended main/release reference before execution; do not run this procedure
from the old local main or silently change the release SHA. No remote state or
latest GitHub run was independently queried.

The owner reports merged code and green GitHub Actions. Historical hardening
evidence records PHP 8.4.27, MariaDB 11.4.13 and MySQL 8.4.13, each **1,008 tests /
13,450 assertions**, and 54 nginx/PHP-FPM checks. These were **not rerun** for
this planning task and do not certify production data, accounts, artifacts or
capacity. Earlier 998-test reports and uncommitted/hold wording are historical.

Read together:

- [CLAUDE.md](../CLAUDE.md) and the
  [infrastructure-access-boundary skill](../.claude/skills/infrastructure-access-boundary/SKILL.md).
- [Remediation](H1_H4_REMEDIATION.md), [merge gates](H1_H4_MERGE_READINESS.md),
  [risk assessment](H1_H4_RISK_COMPLEXITY_ASSESSMENT.md) and
  [audit/logout hardening](AUDIT_GRANTS_LOGOUT_HARDENING.md).
- [Deployment](DEPLOYMENT.md), [operations](OPERATIONS.md),
  [security](../SECURITY.md) and [architecture](../ARCHITECTURE.md).

The newer hardening report supersedes old R01/R02 implementation descriptions.
It does not imply existing production grants were changed. Merge approval is
not approval of every residual risk or of production activation. Older generic
deployment/rollback snippets omit the new grant gate and privacy constraints;
use the gated sequence here and the current hardening procedure, not isolated
historical commands.

## 2. Prerequisites, missing facts and approvals

Fill this private worksheet before authorizing any execution. Do not send raw
production data, credentials, backups or public-client logs to Codex.

| Required fact/decision | Evidence/owner action |
|---|---|
| Application baseline, approved documentation SHA, build-source SHA, deployed source SHA and recovery image/SHA | Preserve full c8806b2 baseline; record the later plan commit separately. Owner verifies actual main/CI and image provenance; reconcile local branch discrepancy. Reject unreviewed application changes |
| Source engine/version, SQL modes, charset/collations, schema history | Owner-only read-only inspection; repository says MariaDB 11.4, but **actual state is unknown** |
| Existing source migrations and previous interruptions | Export schema_migrations; inspect attempt table if it exists and actual pending objects; absence of attempts does not prove no old partial DDL |
| Backup format/capability | Confirm the deployed release supports the documented dla-backup format/commands and source audit schema. Stop if it does not; obtain a separately reviewed backup procedure rather than run new code against production speculatively |
| Exact source DB/runtime/migrator identities and matching user@host/roles | Owner inspects effective privileges, consumers, network path and mounted credentials. Never assume init scripts describe live grants |
| Isolated lab location/host and capacity | Explicit separate authorization for native MariaDB/PHP or a dedicated VM/host; no Docker installation/execution on desktop |
| Confidentiality/retention | Who may handle production data; encrypted transfer/storage/off-host copy; private evidence; deletion date and legal/operational retention decision |
| Downtime, stopped writers and backup point | Owner-approved source backup window; web, CLI, cron, queues, metadata/cover jobs and old releases enumerated. Maintenance alone is insufficient |
| Recovery budget | Approved RPO/RTO, acceptable later-write loss, headroom and maximum lock/revocation/maintenance durations; no invented numeric SLA |
| Pending H1/H3 data backfills | Explicit approval of exact expected inventory/default-member/shared-collection rows (section 4). No blanket assignment authorization |
| New runtime identity and old-credential retirement | New account name/host/secret delivery, reconnect/recreate procedure, all old consumers and sessions, DBA executor |
| Verification scope | Supported browsers/accessibility; PHP 8.4 deployment target; 8.3 only tooling unless separately adopted/tested |
| Release scope | Explicitly approve a future dormant-code/schema upgrade, not household activation; existing runbook development-only restrictions are not waived by this plan |

Every server/DB/secret/backup/host step is **owner-only**. Codex may inspect source
and subsequently analyze sanitized evidence; it must not obtain backups or
operate the lab/server itself under this planning authorization. All commands
below are drafts for the owner **after** separate approvals. Placeholder values
are not discovered facts, and setting an acknowledgement variable is not approval.

## 3. Current architecture and dependencies

### Engines and migration inventory

Source targets MariaDB **11.4.13-noble**, PHP **8.4.27** and the pinned production
nginx image. MySQL **8.4** is a supported regression engine, **not a substitute
for a MariaDB production-data rehearsal**. Confirm the actual source first;
restore into the same engine, preferably its exact patch/configuration. Rehearse
any patch/system-table upgrade separately if the candidate differs. Never mount
MySQL datadir files into MariaDB, use a MySQL restore as MariaDB evidence, edit
history checksums or silently convert dumps. The documented old production CPU
lacks x86-64-v2; confirm actual CPU and chosen image compatibility independently.

There are **19 ordered migration versions**, one MariaDB replacement of the
wishlist version, and two migrator-managed bookkeeping tables. The replacement
is not a twentieth version. The correct engine-specific file checksum is stored.
H2 and the grant/logout hardening add **no domain migration**.

| Order / version suffix (all under database/migrations/) | Dependencies and effects |
|---|---|
| 1. 2026_10_10_000001_create_identity_tables | roles/users/invitations/login_attempts/settings; seeded roles and disabled registration |
| 2. 2026_10_10_000002_create_catalogue_tables | Identity FKs; bibliography/contributors/categories/covers/metadata, ISBN and primary-cover constraints |
| 3. 2026_10_10_000003_create_holdings_tables | Identity/catalogue; copies/locations/collections/items; global inventory-code index |
| 4. 2026_10_10_000004_create_audit_log | User FK ON DELETE SET NULL; audit table/indexes |
| 5. 2026_10_10_000005_add_catalogue_browse_indexes | Existing catalogue; four additive indexes, multiple statements |
| 6. 2026_10_10_215610_add_row_versions | Existing works/editions/copies; three ALTERs, existing rows start at 1 |
| 7. 2026_10_13_155738_create_loans_table | Users/copies; retained history and generated active-copy uniqueness |
| 8. 2026_10_13_155739_create_wishlist_items_table | Users/catalogue; use mariadb/ version on MariaDB (RTRIM generated ISBN expression); original file for MySQL |
| 9. 2026_10_13_155740_add_collection_indexes | Existing collections/items; two additive indexes |
| 10. 2026_10_14_090000_harden_invitations | Existing invitation data must satisfy open-email uniqueness, role/expiry checks; generated column/index/FKs |
| 11. 2026_11_13_180000_create_households | Users; households/memberships, protected-default key, restrictive inventory-boundary design |
| 12. 2026_11_13_180001_add_household_to_inventory | Households/copies/locations; nullable columns, indexes and FKs in **two ALTERs** |
| 13. 2026_11_13_180002_backfill_default_household | Creates default if absent; NULL copies/locations assigned; missing memberships inserted for active previously signed-in users |
| 14. 2026_11_14_100000_scope_inventory_codes_to_households | Household column; stored numeric inventory_scope_id, scoped unique index replaces global index; possible table/index rebuild |
| 15. 2026_11_14_100001_add_household_to_collections | Existing households/collections; nullable column/index/FK |
| 16. 2026_11_14_100002_backfill_shared_collection_households | Default must exist; only shared collections with NULL assignment receive default |
| 17. 2026_11_14_100003_create_invitation_households | Invitations/households; explicit intention join table; old invitations receive no grants |
| 18. 2026_11_15_100000_create_inventory_transfers | Users; plans and item evidence, **two CREATEs**; no existing inventory transfer or ownership rewrite |
| 19. 2026_11_15_100001_add_location_row_versions | Locations; row_version defaults to 1; identifiers and existing fields preserved |

The complete target has 27 released base tables plus schema_migrations and
schema_migration_attempts (29). Unexpected extra objects must be explained, not
deleted. Actual pending work is **available versions minus verified source
history**, never “all migrations” or “exactly nine” assumed from fixtures.
Execute only the normal migrator's ordered pending chain, once its prerequisites
are approved; never cherry-pick SQL files or mark a version applied manually.

Bookkeeping: migrate creates/checks metadata, takes the DB migration lock,
validates known/applied selected-engine checksums, writes a durable attempt
before execution, then commits history insertion plus attempt removal together.
Files containing DDL are not file-atomic on either engine. Even a data-only file
is not a promise of automatic replay after an interruption. migrate:status can
create metadata tables and refuses retained intent: **not a read-only schema
inspection command**. Older partial runs can have no journal. Inspect before it.

### Accounts, startup and safeguards

- Runtime: schema SELECT for complete backups/reads; 25 reviewed per-table write
  entries in [RuntimeGrants](../src/Database/RuntimeGrants.php). Audit INSERT only;
  users INSERT/UPDATE, no DELETE/REPLACE. Roles/settings/history are read-only.
  No runtime DDL, TRIGGER, FILE, GRANT OPTION or provisioning authority.
- Migrator: schema SELECT/INSERT/UPDATE/DELETE plus CREATE/ALTER/DROP/INDEX/
  REFERENCES; explicit ops migrations/restores, never granted provisioning rights.
  DBA/provisioner separately creates accounts and applies grants. A new identity
  avoids broad-grant inheritance; its effective rights still require verification.
- [Initializer](../docker/mysql/init/01-create-users.sh): first empty initialization
  gives runtime SELECT only; does **not** retrofit an existing database. Tables
  must exist before their write grants; apply manifest after restore/migration.
- [Production Compose](../docker-compose.prod.yml): php/ops pin APP_ENV=production
  and APP_ENV_FILE=""; web has runtime secret only, ops has runtime+migrator,
  root only in DB. Read-only/non-root containers; no code/config overlay intended.
- [PHP image](../docker/php/Dockerfile): root-owned 0444 config/production-image
  independently classifies production. [ConfigValidator](../src/Core/ConfigValidator.php)
  refuses enabled households; [Services](../src/Core/Services.php) forces them off
  in production. Both remain required. Old running artifacts are not retrofitted.
- PHP entrypoint config-checks then starts FPM; Bootstrap does not migrate. Ops is
  profiled and defaults to help; database first-init creates accounts/schema,
  not application migrations. The installed systemd path preflights then starts
  existing containers with no recreation/build/pull; no source checkout/migration.
  No assumption about actual external hooks/timers is made from repository files.
  Compose run ops can start its DB dependency even for a read-only command; this
  plan uses --no-deps after owner verifies the DB is already running.

## 4. Mandatory data-change approval before migration

**Important conflict to resolve explicitly:** a pending released H1 backfill
inserts household memberships automatically as part of that migration. Running
the chain blindly would contradict “never automatically rewrite membership.”
This plan adds **no assignment script** and authorizes no such write. Before a
future migration, owner must approve the exact immutable migration's expected
insertions/backfills, or declare **NO-GO**. If the requirement forbids even these
released backfills, this chain cannot proceed without a separate owner decision;
do not edit/skip released SQL, fake history, or delete memberships as a workaround.

While all source/lab writers are stopped, prepare a private ID-keyed ledger:

| Pending effect | Expected rows / allowed differences |
|---|---|
| Default household | Existing system_key=default retained, or exactly one new default; check name collision with an ordinary “Default household” before execution |
| Inventory backfill | Exact copies/locations whose household is NULL (all existing rows if column not yet present); only their new/NULL household field changes to default; IDs, owners, business fields, timestamps/versions untouched by this backfill |
| Membership backfill | Existing memberships retained; only missing default memberships for users with is_active=1 and last_login_at IS NOT NULL; no role, activity, password/auth_version or owner change |
| Shared collection backfill | Only visibility=shared AND household_id IS NULL; private collections/contents/visibility/updated_at unchanged |
| Version/generated columns | New versions initialize to 1, generated scope equals COALESCE(household_id,0); never write a generated column |
| New evidence tables | Empty household-transfer tables after migration; no transfers, mass reassignment or invitation grants created |

Separate intended **future** household assignments from these migration effects.
Review default membership breadth, inactive/never-signed-in users, NULL inventory,
nonmember owners, borrowers/history, private/shared collections, pending invitations,
shared bibliography/creator signals and global administrator trust. No automatic
owner inference, membership normalization or location-based redistribution.
Keep HOUSEHOLDS_ENABLED=false in **all** lanes in this plan. inventory:check
requires an enabled local diagnostic setup and is not a production/applying CLI;
use read-only SQL here instead. Any enabled isolation/transfer rehearsal is a
separately proposed and authorized future task.

## 5. Isolated rehearsal architecture and confidentiality

Use two separate lanes; do not point unmodified production Compose at a shared
host's production paths and assume a different project name makes it safe.

| Lane | Authorized future design / purpose |
|---|---|
| A: restored-data native lab | Private native MariaDB 11.4 instance, fresh datadir under owner-selected LAB_ROOT, bound to 127.0.0.1 on an explicitly reserved non-3306 port; fresh schema ending _rehearsal and fresh scoped credentials; PHP 8.4 CLI. No Docker or changes to normal desktop services. Verify exact server identity before every mutating operation |
| B: production artifact lab | Owner-controlled **dedicated isolated VM/host** able to run Docker, never desktop and never the actual production service. Fresh secrets, data roots, ports/project/network, no production mounts/routes/DB endpoints. Exercise actual prod image/entrypoints/FPM/nginx/Compose and ordinary UI with households disabled. Review its lab configuration separately before execution |
| Fault-injection fixtures | Separate disposable **synthetic** *_test database(s), only if separately authorized. Never run PHPUnit, h4_browser_fixture.php, resetSchema/truncate helpers, full browser harness or destructive privilege/DDL probes on restored data |

Keep archives, extraction, covers, temp, sessions, backups and evidence outside
the Git checkout/public root, encrypted at rest where appropriate, with private
directories (0700) and files (0600). No production .env, DBA/root password, API
key, session file or DB credential is copied into the lab. Real application user
hashes and personal data **are** in the backup: protect them. Do not upload data
to CI, public paste sites, metadata providers or Codex. Owner transfers the archive
and sidecar through an approved confidential channel and independently retains
the trusted source hash. Disable lab outbound traffic at OS/VM/network level,
METADATA_PROVIDERS=none, no email/jobs/tunnel/ingress, and no production DNS aliases
or tunnels. Browser/client must not have production session cookies available.

Prefer a frozen restore/reference target plus an upgrade target, and later a
round-trip target, all on the private instance with separately scoped accounts.
They can be sequential if capacity demands, but preserve immutable archive and
full baseline evidence before replacing any owned clone. No --replace by default.
Clones with production data remain confidential even if called “disposable.”

## 6. Capacity and recovery budget worksheet

Measure actual sizes/peaks; fill acceptable limits before execution. Archive
compression ratio is not a DB rebuild-space estimate.

| Resource | Required budget/evidence |
|---|---|
| DB data, indexes, tmpdir | Source DATA_LENGTH/INDEX_LENGTH per table; largest altered copies/index rebuild; temporary copies plus undo/redo/binlog and free-space reserve. No online-DDL guarantee. Account for reference/candidate/round-trip copies if concurrent |
| BACKUP_PATH | Tool estimates roughly twice DB size + covers + 64 MiB; actual simultaneous uncompressed dump, archive, safety/post-upgrade backup and protected recovery archive. Tool preflight is a lower bound, not host capacity certification |
| TEMP_PATH | Entire extracted uncompressed backup plus safety margin (tool checks 16 MiB); use disk-backed private storage, not a small RAM tmpfs |
| Cover storage | Originals/thumbnails, staging/replacement and backup copies; verify referenced files, not only count |
| RAM | MariaDB, PHP/ops, decompression, fingerprinting, uploads/sessions/tmpfs plus OS/other workloads. FPM max_children=10, PHP memory_limit=256M and Argon2 ~64 MiB per hash are configured bounds/costs, not measured RSS |
| Time/contention | Backup/restore/rebuild durations, p95/p99 query/account-lock waits, export/revocation delay, deadlocks/409s, stopped-writer drain and health recovery; owner sets limits |
| CPU/runtime | Actual production CPU baseline and PHP extensions; hosted CI success cannot certify that host. No automatic memory-limit tuning or DB-image upgrade |

Source production layout is documented as separate SSD code/data roots and HDD
storage, with fail-closed bind mounts/preflight and restart policy no. Owner must
verify actual mounts/UUID/owners/free space without creating missing production
directories or reinitializing anything. No Docker-host discovery is performed by
Codex. Do not blindly apply suggested resource defaults in older docs.

## 7. Owner-executable sequence — gated, not executed

Do not paste this whole document into a shell. Work stage by stage; inspect
exit status and private evidence before the next stage. Stop on every unexpected
identity, permission, history, data, space or artifact result. Commands labelled
SOURCE affect source availability/files and need an approved source backup window;
LAB commands affect only the independently verified clone. No production
migration/deployment command sequence is executed or authorized here.
Use a dedicated Bash session with strict error handling for command stages;
expected denial SQL probes are reviewed separately, not treated as successful
mutations. Do not continue after a command exits nonzero.

### A. Confirm source and candidate (owner; read-only)

```bash
set -euo pipefail
cd /home/brais/Projects/book-library-catalog
git branch --show-current
git status --short
git rev-parse HEAD
git log -7 --oneline
# Execution gate: owner reconciles main first; these must then succeed.
test "$(git branch --show-current)" = main
APP_BASELINE_SHA=c8806b2274bac180b36d7796ee4533f8fe18f2b0
: "${REVIEWED_DOCUMENTATION_SHA:?full owner-reviewed commit containing this plan}"
[[ "$REVIEWED_DOCUMENTATION_SHA" =~ ^[0-9a-f]{40}$ ]]
test "$(git rev-parse HEAD)" = "$REVIEWED_DOCUMENTATION_SHA"
test -z "$(git status --porcelain)"
git cat-file -e "$REVIEWED_DOCUMENTATION_SHA:docs/PRODUCTION_MIGRATION_REHEARSAL_PLAN.md"
git merge-base --is-ancestor "$APP_BASELINE_SHA" "$REVIEWED_DOCUMENTATION_SHA"
# Only this plan may differ, including changes later reverted within the range.
git diff --no-ext-diff --no-textconv --exit-code "$APP_BASELINE_SHA" "$REVIEWED_DOCUMENTATION_SHA" \
  -- . ':(exclude)docs/PRODUCTION_MIGRATION_REHEARSAL_PLAN.md'
test -z "$(git log --format= --name-only "$APP_BASELINE_SHA..$REVIEWED_DOCUMENTATION_SHA" \
  -- . ':(exclude)docs/PRODUCTION_MIGRATION_REHEARSAL_PLAN.md')"
```

The execution checkout must be clean and contain the approved plan commit; the
current uncommitted plan intentionally cannot pass this gate. The owner supplies
the real documentation SHA, not a guessed value. Any other changed path or
intermediate application change requires a new review, even if later reverted.
Record the exact build-source commit and image digest too; Git tree equivalence
does not verify ignored dependencies, local configuration or image contents.
If application baseline differs, re-pin/review the plan; never update a failing
check to an unreviewed SHA. Owner checks the exact live CI run/merge SHA and
production installed image IDs/digests/source SHA separately. No fetch/commit/merge is needed to read
local evidence, and none was done during preparation.

SOURCE owner uses an already approved DB client/socket/secret method to record:

```sql
SELECT VERSION(), DATABASE(), @@hostname, @@port, @@datadir, CURRENT_USER();
SELECT @@sql_mode, @@character_set_server, @@collation_server;
SELECT DEFAULT_CHARACTER_SET_NAME, DEFAULT_COLLATION_NAME
FROM information_schema.SCHEMATA WHERE SCHEMA_NAME=DATABASE();
SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT TABLE_NAME, COLUMN_NAME, CHARACTER_SET_NAME, COLLATION_NAME
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
  AND CHARACTER_SET_NAME IS NOT NULL ORDER BY TABLE_NAME, ORDINAL_POSITION;
SHOW FULL TABLES;
SELECT * FROM schema_migrations ORDER BY version;
-- Only if the table actually exists:
SELECT * FROM schema_migration_attempts ORDER BY version;
SHOW GRANTS;
```

Inspect source account/role/host grants via the owner's DBA, including global,
schema/table/column grants, default/inherited roles, alternate matched accounts
and definer/proxy access. Inventory views/triggers/routines/events too: the app
backup copies base tables, **not** arbitrary extra DB objects, grants or jobs.
Unexpected objects/history are a stop/review condition, not permission to drop.

### B. Quiescent source backup and independent evidence (SOURCE; owner only)

Define SOURCE_CODE/SOURCE_ENV/SOURCE_PROJECT/CONTAINER_ARCHIVE using **actual
verified installed values**. Do not use the candidate checkout to guess them.
The documented commands below require that the deployed release supports them.

```bash
: "${OWNER_SOURCE_BACKUP_APPROVAL:?record owner approval and downtime window first}"
: "${SOURCE_CODE:?verified installed checkout}"
: "${SOURCE_ENV:?verified installed environment file}"
: "${SOURCE_PROJECT:?verified existing Compose project}"
: "${SOURCE_DOCKER_SOCKET:?verified local source daemon socket path}"
test -S "$SOURCE_DOCKER_SOCKET"
source_dc() {
  env -u DOCKER_CONTEXT -u DOCKER_HOST -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
    docker --host "unix://$SOURCE_DOCKER_SOCKET" compose --project-directory "$SOURCE_CODE" -p "$SOURCE_PROJECT" \
    -f "$SOURCE_CODE/docker-compose.prod.yml" --env-file "$SOURCE_ENV" "$@"
}
# Save pre-window service state, maintenance/restore-marker state and image IDs.
source_dc ps
source_dc run --rm -T --no-deps ops php bin/console maintenance:status
# STOP: owner records whether traffic/jobs were originally serving and healthy.
source_dc run --rm -T --no-deps ops php bin/console maintenance:on --reason="owner-approved backup for rehearsal"
source_dc stop php
# STOP: owner also stops/verifies every CLI/scheduled/metadata/cover/old writer.
```

Capture source schema/counts/PK sets/column fingerprints and cover hashes at this
quiescent point **before backup:create**. The read-only fingerprint helper in
[FINAL_H1_H4_AUDIT.md, Appendix B](FINAL_H1_H4_AUDIT.md#appendix-b--exact-read-only-fingerprint-helper-for-the-owner)
provides a concrete per-column/PK method. Save it privately as fingerprint.php,
review it, and use an owner-controlled read-only source connection. It is not a
test fixture. Budget its ordered-table reads/memory (PDO buffering can be large);
prove memory suffices on the lab first, or review bounded/unbuffered reads before
running on source. If safe source fingerprint capture is unavailable, stop for
an approved alternative; row counts alone do not prove all cells survived.

Where an approved native PHP CLI/read-only connection exists, the owner command
shape is:

```bash
umask 077
: "${SOURCE_EVIDENCE:?private owner evidence directory, outside repository/public}"
php "$SOURCE_EVIDENCE/fingerprint.php" "$SOURCE_EVIDENCE/source-readonly.json" \
  > "$SOURCE_EVIDENCE/source-baseline.json"
```

On a container-only source host, owner must review a private read-only helper
mount/execution arrangement in ops (correct UID/read permissions) instead; do
not install host tooling or copy secrets into application code ad hoc. This
environment prerequisite is unresolved until the owner chooses it.

```bash
# SOURCE, after baseline capture and confirmed stopped writers:
source_dc run --rm -T --no-deps ops php bin/console backup:create
# Owner records the exact newly reported path; never select an arbitrary newest file.
: "${CONTAINER_ARCHIVE:?exact archive path printed by backup:create}"
source_dc run --rm -T --no-deps ops php bin/console backup:verify --file="$CONTAINER_ARCHIVE"
```

Require VALID, no missing-cover warning, archive+sidecar, source engine/schema
manifest and an independently saved hash. No --allow-missing-covers for release
evidence. Archive checksum verifies byte consistency, not origin by itself.
backup.created is inserted **after** the archive snapshot; it is not in its own
archive. Record this expected source audit addition, not a missing-event defect.
Owner manually protects/transfers a copy and verifies it again at destination.
#### B.1. Resume and verify the original source service (SOURCE; owner only)

This checkpoint closes the source backup window **before lab work proceeds**.
Backup success does not prove application health. There is no automatic EXIT
trap that clears maintenance, including after a failed/ambiguous backup. Owner
must investigate failures and decide safe resumption separately; never assume
that restarting is safe because the backup returned zero.

For a source that was healthy and serving before the window, after confirming
no restore-incomplete marker, no unplanned source/schema/configuration changes
and adequate capacity, owner explicitly approves restoring that original state:

```bash
: "${OWNER_SOURCE_RESUMPTION_APPROVAL:?approve original-source resumption after state review}"
# Start the same stopped existing PHP container; no up/build/pull/recreate/migrate.
source_dc start php
source_dc ps
# Owner waits within an agreed readiness deadline; failure/timeout means STOP.
source_dc exec -T nginx wget -q -O- http://127.0.0.1:8080/health
source_dc run --rm -T --no-deps ops php bin/console maintenance:status
# STOP: inspect status text/restore marker, not just exit code; record approval.
# Only if previously serving, healthy now, and maintenance may safely be removed:
source_dc run --rm -T --no-deps ops php bin/console maintenance:off
```

Require `/health` HTTP 200 and JSON `{"status":"ok"}`; in current source this
checks runtime `SELECT 1`, while bypassing normal Host/session/maintenance checks.
It is necessary but insufficient. The owner must verify the installed release's
probe behavior, PHP/nginx/database health, unchanged image IDs, normal HTTPS
login/logout, catalogue access, expected audit events and sanitized error logs
using the existing owner-controlled ingress. Do not change tunnels/proxies.
`maintenance:status` can exit zero with an incomplete-restore warning; never
acknowledge that marker merely to resume service. If service was already in
maintenance or unhealthy before the window, preserve that state and resolve it
explicitly instead of running maintenance:off.

Restart only previously running scheduled/background jobs after normal serving
checks pass. Record the actual healthy time/downtime and owner sign-off. On any
failure, keep/reinstate maintenance under owner control, hold background writers,
investigate the source incident and do not continue the lab as if source health
were established. No new application version, grant change or migration belongs
to this resumption step.

A resumed source makes the clone a dated snapshot, never a current cutover
backup. A fresh quiescent backup, fresh preflight and a re-approved current
ID/backfill ledger are required for a later upgrade; source changes can invalidate
rehearsal assumptions and require another drill.

### C. Authorize/provision the native lab (LAB; owner only)

Owner reserves a private instance/datadir and creates empty *_rehearsal schema,
fresh runtime and migrator identities, and protected client option files.
Use the confirmed same engine/configuration. These are new lab resources only;
never change the normal MySQL service or run the production DB first-init guard
against an existing directory. The exact native server setup/package path is
owner/environment-specific and must be approved before execution.

```bash
: "${OWNER_LAB_AUTHORIZATION:?approve exact isolated host/resources first}"
: "${LAB_ROOT:?new private absolute path outside checkout/public/normal DB data}"
: "${LAB_DB:?fresh lower-case name ending _rehearsal}"
: "${LAB_PORT:?reserved loopback port, not 3306}"
: "${LAB_WEB_PORT:?separate reserved loopback HTTP port}"
: "${RECEIVED_ARCHIVE:?owner-obtained verified archive}"
: "${EXPECTED_SHA256:?independently recorded source archive SHA-256}"
umask 077
[[ "$LAB_DB" =~ ^[a-z0-9_]+_rehearsal$ ]]
[[ "$LAB_PORT" =~ ^[1-9][0-9]{3,4}$ ]]
[[ "$LAB_WEB_PORT" =~ ^[1-9][0-9]{3,4}$ ]]
(( LAB_PORT >= 1024 && LAB_PORT <= 65535 && LAB_PORT != 3306 ))
(( LAB_WEB_PORT >= 1024 && LAB_WEB_PORT <= 65535 && LAB_WEB_PORT != LAB_PORT ))
[[ "$LAB_ROOT" = /* ]]
# STOP unless owner confirms LAB_ROOT is absent/new and is not a mount/data path.
test ! -e "$LAB_ROOT"
mkdir -m 0700 -- "$LAB_ROOT"
mkdir -m 0700 -- "$LAB_ROOT/input" "$LAB_ROOT/covers" "$LAB_ROOT/tmp" \
  "$LAB_ROOT/backups" "$LAB_ROOT/sessions" "$LAB_ROOT/evidence"
test -f "$RECEIVED_ARCHIVE"
test ! -L "$RECEIVED_ARCHIVE"
test -f "$RECEIVED_ARCHIVE.sha256"
test ! -L "$RECEIVED_ARCHIVE.sha256"
cp -- "$RECEIVED_ARCHIVE" "$LAB_ROOT/input/"
cp -- "$RECEIVED_ARCHIVE.sha256" "$LAB_ROOT/input/"
ARCHIVE="$LAB_ROOT/input/$(basename -- "$RECEIVED_ARCHIVE")"
actual_hash=$(sha256sum -- "$ARCHIVE")
test "${actual_hash%% *}" = "$EXPECTED_SHA256"
```

Do not put LAB_ROOT in /opt production roots, a shared production storage mount,
the repository or public/. Native DB binds only 127.0.0.1:LAB_PORT with explicit
fresh datadir and its own PID/socket/log. No reused mysql system schema. The
provisioner grants bootstrap schema SELECT to runtime and schema DML/DDL to the
separate migrator, **only on this lab schema**, with no GRANT OPTION. Use the
literal-underscore schema-grant convention from hardening; no inherited roles,
global write grants or access to normal databases. DBA credentials are never
put into runtime/console JSON. Accounts/privileges are not restored from backup.

Owner provisions the native binary/system tables as a distinct approved step,
matching the recorded engine/configuration, **not** by invoking a desktop service
manager or pointing an installer at normal data. Bootstrap administrator access
must use private socket authentication or a fresh protected secret; no passwordless
TCP administrator while confidential data is present. If compatible native
tooling/secure bootstrap is unavailable, stop for that prerequisite. A subsequent
explicit native start has this owner-reviewed shape (no implicit defaults):

```bash
: "${LAB_MARIADBD:?absolute approved MariaDB 11.4 binary}"
: "${LAB_BASEDIR:?matching approved native distribution}"
: "${LAB_DB_CLIENT:?absolute matching native MariaDB client binary}"
: "${LAB_DATADIR:?fresh initialized private LAB_ROOT/db directory}"
test "$LAB_DATADIR" = "$LAB_ROOT/db"
test -x "$LAB_MARIADBD"
test -x "$LAB_DB_CLIENT"
test "$(id -u)" -ne 0
# Canonical spelling must be identical: reject symlink ancestors and /../ aliases.
test "$(realpath -e -- "$LAB_ROOT")" = "$LAB_ROOT"
test "$(realpath -e -- "$LAB_DATADIR")" = "$LAB_DATADIR"
test ! -L "$LAB_ROOT"
test ! -L "$LAB_DATADIR"
test -d "$LAB_DATADIR/mysql"
# Pin this directory BEFORE starting the server; copy these values to lab.json.
REHEARSAL_DATADIR_DEVICE=$(stat -c %d -- "$LAB_DATADIR")
REHEARSAL_DATADIR_INODE=$(stat -c %i -- "$LAB_DATADIR")
test ! -e "$LAB_ROOT/db.pid"
test ! -L "$LAB_ROOT/db.pid"
"$LAB_MARIADBD" --no-defaults --basedir="$LAB_BASEDIR" --datadir="$LAB_DATADIR" \
  --bind-address=127.0.0.1 --port="$LAB_PORT" --socket="$LAB_ROOT/db.sock" \
  --pid-file="$LAB_ROOT/db.pid" --log-error="$LAB_ROOT/evidence/db.log" &
LAB_SERVER_JOB=$!
# STOP: owner checks this exact instance's readiness/identity and secure admin
# bootstrap, never a fallback client connection or fixed sleep as proof.
```

Add only explicitly reviewed source-compatible charset/collation/SQL-mode/resource
options; --no-defaults intentionally ignores the normal desktop/server config.
An occupied port is a failure, not permission to reuse the process listening there.
The owner then uses the verified **lab** DBA client with history disabled and
private secret input. Example SQL shapes below contain replacement placeholders,
not production facts or passwords; replace all of them before execution:

```sql
-- LAB ONLY: each account/name must be absent. No IF NOT EXISTS to hide collisions.
CREATE DATABASE `replace_candidate_rehearsal`
  CHARACTER SET <observed_source_schema_charset> COLLATE <observed_source_schema_collation>;
CREATE USER 'replace_lab_runtime'@'%' IDENTIFIED BY '<fresh private runtime password>';
CREATE USER 'replace_lab_migrator'@'%' IDENTIFIED BY '<different private migrator password>';
GRANT SELECT ON `replace\_candidate\_rehearsal`.* TO 'replace_lab_runtime'@'%';
GRANT SELECT, INSERT, UPDATE, DELETE, CREATE, ALTER, DROP, INDEX, REFERENCES
  ON `replace\_candidate\_rehearsal`.* TO 'replace_lab_migrator'@'%';
```

The angle-bracket charset/collation tokens are deliberately **not executable SQL**.
Fill them from the source-engine/schema inspection in A, not the server default
or a MySQL example. Inspect source table/column collations and SHOW CREATE TABLE,
and verify availability/meaning on the exact same-engine target (`SHOW COLLATION`).
In particular, do not blindly choose `utf8mb4_0900_ai_ci` for MariaDB: aliases and
comparison behavior must be established on the actual versions. A schema default
does not override existing table/column definitions restored from the archive.
Preserve their case/accent/trailing-space comparison and uniqueness semantics;
check actual duplicate/index behavior, not only identical collation names.
Released migrations contain explicit collation clauses: leave them/checksums
unchanged and inspect their selected engine path. Unsupported collations or
semantic drift mean STOP and review, never automatic CONVERT, substitution,
normalization or dropped uniqueness constraints. There is no DB_CHARSET setting
in ConnectionFactory: application connections use utf8mb4, UTC and fixed strict
session SQL modes, which must be assessed separately from server/schema defaults.

Use MYSQL_HISTFILE=/dev/null/private input, not passwords in shell argv/history;
keep fresh secrets out of the report. `%` matches the source manifest's account
style, not network exposure: only the private loopback lab instance is reachable.
If the owner chooses a narrower host, use that same verified account host in the
manifest and identity record. This SQL must never be applied to normal or
production databases. Prefer existing approved provisioning tooling if it
provides the same explicit identities/grants and safety checks.

Create a flat, private mode-0600 lab.json with fresh credentials and **explicit
string values**, no *_FILE or DB_TEST_* keys. Required values:

```text
APP_ENV=testing; APP_ROLE=ops; APP_DEBUG=false
HOUSEHOLDS_ENABLED=false; METADATA_PROVIDERS=none
APP_URL=http://127.0.0.1:<LAB_WEB_PORT>; SESSION_SECURE_COOKIE=false
DB_HOST=127.0.0.1; DB_PORT=<LAB_PORT>; DB_DATABASE=<LAB_DB>
DB_USERNAME=<new lab runtime>; DB_PASSWORD=<new private runtime password>
DB_MIGRATION_USERNAME=<different lab migrator>; DB_MIGRATION_PASSWORD=<new private password>
COVER_STORAGE_PATH=<LAB_ROOT>/covers; TEMP_PATH=<LAB_ROOT>/tmp
BACKUP_PATH=<LAB_ROOT>/backups; MAINTENANCE_FLAG_PATH=<LAB_ROOT>/maintenance.flag
REHEARSAL_ROOT=<canonical LAB_ROOT>; REHEARSAL_DATADIR=<canonical LAB_ROOT>/db
REHEARSAL_DATADIR_DEVICE=<pre-start stat device number>
REHEARSAL_DATADIR_INODE=<pre-start stat inode number>
REHEARSAL_HOSTNAME=<observed isolated server hostname>
REHEARSAL_VERSION=<observed exact MariaDB version string>
REHEARSAL_RUNTIME_ACCOUNT=<observed exact runtime user@host>
REHEARSAL_MIGRATION_ACCOUNT=<observed exact migrator user@host>
```

This is a worksheet, **not .env syntax to source**. Owner writes a JSON object
with those keys/values, privately. Do not copy a production .env or source
read-only config. Before any mutation compare VERSION/DATABASE/@@port/@@hostname/
@@datadir/CURRENT_USER on **both** new connections with the owner's approved
identity record. Loopback alone is insufficient; the normal service is local too.
The helper and native server must share the same host/filesystem namespace.
Owner must rule out bind-mount aliases, external tablespaces/system-table links
and concurrent filesystem/server replacement; canonical paths/inodes cannot
protect against a compromised host. No tunnels, container datadir translations,
normal-service datadirs or repurposed running instances are supported.

Use the private console guard in Appendix A below. It bypasses .env and inherited
environment values with `new Config($json)` and permits only this plan's commands.
It does not create/start/provision a database or establish owner approval.

### D. Verify archive, restore empty target, capture baseline (LAB)

```bash
# Owner has saved/reviewed Appendix A as LAB_ROOT/rehearsal.php, mode 0600.
rehearsal() {
  php "$LAB_ROOT/rehearsal.php" "$LAB_ROOT/lab.json" "$@"
}
rehearsal identity
rehearsal config:check --strict
rehearsal backup:verify --file="$ARCHIVE"
rehearsal maintenance:on --reason="isolated production-data rehearsal"
rehearsal backup:restore --file="$ARCHIVE" --confirm="$LAB_DB"
rehearsal maintenance:status
```

Require an empty target and private cover path; no --replace, --database,
--covers-dir, --skip-maintenance-check or --allow-production. Verify archive
engine from the manifest as well as the actual target identity. The restorer
uses the migrator PDO, checks row counts/FKs/covers, inserts backup.restored, then
clears its incomplete marker; maintenance remains on. A failure after restoration
but before its final audit may leave effects and a marker even with a generic
nonzero code: inspect state, never infer “no writes” solely from exit 1.

Before migrate:status, capture SHOW CREATE/columns/indexes/FKs, table counts,
PK sets and per-column fingerprints. Reconcile source/archive records, allowing
only omitted login_attempts rows and the explicit backup.restored addition;
verify every original audit ID/field, not merely total audit count. Preserve
source audit maximum/PK set separately so new operation events cannot conceal
altered/deleted original events. Optional frozen reference clone helps direct
read-only row comparison; its restore adds its own labelled event too.
The Appendix A guard also offers `rehearsal validate-fks`, a read-only wrapper
around the application's existing composite-FK checker. Run it after restore
and again after migration; it must print an empty list and exit zero.

### E. Read-only preflight, approve expected changes, migrate (LAB)

Complete section 8 and the exact ledger in section 4. Unknown history, partial
objects, missing/default anomalies, invalid FK/tree/owner/code states, inadequate
capacity or unapproved backfills mean STOP. Only then:

```bash
: "${OWNER_BACKFILL_APPROVAL:?approve exact pending effects/ID ledger first}"
# All lab web/background/old writers remain stopped; baseline evidence saved.
rehearsal migrate:status
# Review pending/modified/other-engine/incomplete state; do not continue on a refusal.
rehearsal migrate
rehearsal migrate:status
```

Capture selected file hashes and history before/after. Require exact pending
versions applied in order, checksums correct, attempts empty and no unknown
history. Do not rerun after a partial failure. Measure peak disk/RAM/waits and
duration externally; migrator success alone is not data reconciliation.

### F. Apply and verify the runtime manifest (LAB; provisioner only)

After all required tables exist, generate from the exact candidate. This command
only generates SQL; review it before applying with **lab-only DBA authority**:

```bash
umask 077
: "${LAB_RUNTIME_USER:?new runtime identity matching lab.json}"
: "${LAB_ACCOUNT_HOST:?verified matching account host}"
: "${LAB_DBA_OPTIONS:?0600 option file naming ONLY the isolated instance}"
php bin/runtime-grants "$LAB_DB" "$LAB_RUNTIME_USER" "$LAB_ACCOUNT_HOST" \
  > "$LAB_ROOT/evidence/runtime-grants.review.sql"
# STOP: compare every grant to RuntimeGrants and verify DBA target identity.
# --defaults-file must be the first client option; it avoids normal option files.
"$LAB_DB_CLIENT" --defaults-file="$LAB_DBA_OPTIONS" --protocol=TCP --host=127.0.0.1 \
  --port="$LAB_PORT" --database="$LAB_DB" \
  -e 'SELECT VERSION(), DATABASE(), @@hostname, @@port, @@datadir, CURRENT_USER()'
# STOP: compare this DBA connection with the approved lab identity record.
"$LAB_DB_CLIENT" --defaults-file="$LAB_DBA_OPTIONS" --protocol=TCP --host=127.0.0.1 \
  --port="$LAB_PORT" --database="$LAB_DB" < "$LAB_ROOT/evidence/runtime-grants.review.sql"
```

No broad INSERT/UPDATE/DELETE may coexist with these table restrictions. With a
new account created without IF NOT EXISTS there should be no old privileges,
roles or definer access; prove that using effective grants and fresh connections.
Audit SELECT/INSERT and user INSERT/UPDATE are necessary; audit UPDATE/DELETE,
user DELETE/REPLACE, DDL/provisioning must remain unavailable. Retain migrator
permissions for approved migrations/restore/retention, not in the web container.

Safe no-row probes on the restored clone, using its **runtime** credentials:

```sql
SELECT CURRENT_USER(), DATABASE(), VERSION(), @@port, @@datadir;
SHOW GRANTS;
SELECT COUNT(*) FROM audit_log;
UPDATE audit_log SET action=action WHERE 1=0;
DELETE FROM audit_log WHERE 1=0;
DELETE FROM users WHERE 1=0;
```

The three writes must fail with actual permission-denied (1142/1143), not “table
missing”/connection/authentication failure. Success is NO-GO even with zero rows.
Do not test DROP/ALTER/TRUNCATE/REPLACE or delete real users on restored data:
if an unexpected grant allows it, the test itself destroys evidence. Those
negative/duplicate/FK/audit-failure/concurrency tests belong to the separate
synthetic fixture suite, whose existing dual-engine evidence is recorded in the
hardening report. Missing audit INSERT must deny/roll back domain writes; never
disable auditing or grant broad DML as a workaround.

### G. Reconcile, perform bounded smoke checks, back up and restore again

Finish the strict post-migration comparisons in section 8 **before** any smoke
writes. Then owner separately approves synthetic lab accounts/records (labelled
by a unique rehearsal marker) and exact write ledger. Use no real user's unknown
password and do not reset/deactivate/delete real accounts just to test login.
Do not change real ownership/memberships. See section 9 for the UI/artifact lane;
do not serve public/index.php natively with an inherited production/development
.env. A private explicit-Config router would need separate review; none is added
or assumed by this plan.

```bash
# After reconciliation and approved synthetic smoke writes, with writers stopped:
rehearsal backup:create
# Owner records the exact new archive path printed by the command.
: "${POST_ARCHIVE:?exact private post-upgrade archive}"
rehearsal backup:verify --file="$POST_ARCHIVE"
```

Round-trip it into another empty same-engine *_rehearsal target with its own
reviewed JSON/identities/paths. Repeat D/F and all original-record/schema/FK/cover
comparisons, allowing only specifically recorded restore/audit effects. Rehearse
pre-upgrade recovery from the untouched original archive into a separate empty
target too. Record durations/space, approved recovery image, RPO/RTO and which
later writes would be lost. Nothing here replaces or switches production.

## 8. Validation evidence and reconciliation rules

Use three checkpoints: **S** quiescent source/archive; **R** restored target
before status/migrate/smoke; **M** migrated target before smoke. Keep a fourth
**W** after explicitly approved synthetic writes and a round-trip **T**.
Never use approximate information_schema.TABLES.TABLE_ROWS as an exact count.
Preserve private evidence with source/release SHA, engine/configuration, UTC
times and archive hash; send Codex only sanitized summaries if review is needed.

| Evidence | S → R | R → M | M → W → T |
|---|---|---|---|
| Users/roles | Same existing IDs and every original field, including password hashes/auth_version/roles/activity/locale | No existing account changes; membership backfill is separate | Only approved synthetic additions and their recorded login/session effects; no real-user reset/reassignment |
| Bibliography, credits, copies | Exact existing PK sets and fields | Exact original columns; only approved household/new version/generated effects | Only identified synthetic records and explicitly approved actions; real owners/acquisition fields/codes preserved |
| Collections/items/wishlists | Exact rows/relationships; private status preserved | Only approved new/shared-NULL household field effects | No private-list disclosure or disappearance; marked synthetic actions only |
| Locations | Exact IDs/parents/names/fields; covers independent | Approved household/new row_version only; no silent reparent/rename | Synthetic additions only; real tree and household alignment unchanged |
| Loans | Exact IDs, copy/borrower relationships, dates/history/notes/status | Unchanged; no history deletion or ownership rewrite | Synthetic lend/return ledger only; active-copy uniqueness intact |
| Audit | Every original ID/field retained plus one recorded restore event; source backup.created may postdate archive | **Unchanged** by migration (no migration-domain audit event invented) | Recorded smoke/backup/restore additions only; original audit rows verified byte/cell-equivalent |
| login_attempts | Rows intentionally omitted by backup; record exception | No unexplained behavior | Synthetic login/throttle rows allowed; never ignore user/audit changes under this transient exception |
| History/bookkeeping | Original schema_migrations/checksums retained | Only actual pending history additions and empty/created attempt metadata | Same final versions/checksums after startup/round-trip; no startup migration |
| Covers | Every referenced original/thumbnail present and hashes match manifest/source | No rewrite/removal | Marked synthetic additions only; exact original file hashes persist |

Use Appendix B's fingerprint helper to capture R then M with **the R schema
argument**, so new columns do not hide changed original fields:

```bash
# LAB; owner has privately copied/reviewed the read-only helper; no test framework.
php "$LAB_ROOT/fingerprint.php" "$LAB_ROOT/lab.json" > "$LAB_ROOT/evidence/restored.json"
# Run the approved migration only after this baseline, then:
php "$LAB_ROOT/fingerprint.php" "$LAB_ROOT/lab.json" "$LAB_ROOT/evidence/restored.json" \
  > "$LAB_ROOT/evidence/migrated-original-columns.json"
```

Execute `rehearsal identity` first and keep writers stopped. This read-only
helper uses the JSON DB fields, not .env. It needs the memory/read review noted
above and is not asserted functionally tested by this planning task. Retain
every count, PK digest and per-column digest; the comparison helper in the same
audit reports differences and exits nonzero. Do **not** ignore entire copies,
collections, users or audit tables because a single expected column/row changed.
For documented backfilled fields compare exact approved ID→value transitions;
compare all other cells strictly. For audit/new rows compare original PKs/cells
against source/reference evidence and separately enumerate each permitted added
event. Hashes without schema/PK/exception reconciliation are insufficient.

Save SHOW CREATE TABLE for every base table at each checkpoint, plus these
read-only metadata inventories using the chosen lab schema:

```sql
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, AUTO_INCREMENT, DATA_LENGTH, INDEX_LENGTH
FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME;
SELECT TABLE_NAME, COLUMN_NAME, ORDINAL_POSITION, COLUMN_TYPE, IS_NULLABLE,
       COLUMN_DEFAULT, EXTRA, GENERATION_EXPRESSION, COLLATION_NAME
FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME, SUB_PART, INDEX_TYPE
FROM information_schema.STATISTICS WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, INDEX_NAME, SEQ_IN_INDEX;
SELECT TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION, COLUMN_NAME,
       REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, CONSTRAINT_NAME, ORDINAL_POSITION;
SELECT TABLE_NAME, CONSTRAINT_NAME, REFERENCED_TABLE_NAME, UPDATE_RULE, DELETE_RULE
FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA=DATABASE()
ORDER BY TABLE_NAME, CONSTRAINT_NAME;
```

Compare post-migration schema with the selected release's expected same-engine
schema, optionally obtained from a separately authorized **empty** reference
schema. Preserve meaningful types, defaults, checks, collation, generated
expressions, index order/uniqueness and FK actions; AUTO_INCREMENT counter
differences must be explained, not normalized into apparent equality. A column
name existing does not prove an interrupted ALTER completed. Base-table inventory
must match the archive/approved 29-table target plus reviewed extras. App backup
does not preserve grants/triggers/views/routines/events automatically.

Conditional read-only checks (query only tables/columns that actually exist at
that checkpoint; no “add missing object” helper):

```sql
-- Before household backfill: exact eligible accounts (also review existing grants).
SELECT id, is_active, last_login_at FROM users ORDER BY id;
-- Before invitation hardening if pending:
SELECT email, COUNT(*) FROM invitations
WHERE accepted_at IS NULL AND revoked_at IS NULL GROUP BY email HAVING COUNT(*)>1;
SELECT id FROM invitations WHERE role_id NOT IN (2,3) OR expires_at<=created_at;

-- After household columns exist:
SELECT id FROM households WHERE system_key='default';
SELECT id, owner_user_id, household_id, location_id FROM copies
WHERE household_id IS NULL ORDER BY id;
SELECT id, household_id, parent_id FROM locations WHERE household_id IS NULL ORDER BY id;
SELECT c.id FROM copies c JOIN locations l ON l.id=c.location_id
WHERE NOT(c.household_id <=> l.household_id);
SELECT c.id FROM locations c JOIN locations p ON p.id=c.parent_id
WHERE NOT(c.household_id <=> p.household_id);
SELECT household_id, parent_id, name, COUNT(*) FROM locations
GROUP BY household_id, parent_id, name HAVING COUNT(*)>1;
SELECT COALESCE(household_id,0), inventory_code, COUNT(*) FROM copies
WHERE inventory_code IS NOT NULL
GROUP BY COALESCE(household_id,0), inventory_code HAVING COUNT(*)>1;
SELECT inventory_code, COUNT(*) FROM copies WHERE inventory_code IS NOT NULL
GROUP BY inventory_code HAVING COUNT(*)>1;
SELECT c.id, c.owner_user_id FROM copies c JOIN users u ON u.id=c.owner_user_id
LEFT JOIN household_members m ON m.user_id=c.owner_user_id AND m.household_id=c.household_id
WHERE u.is_active=0 OR (c.household_id IS NOT NULL AND m.user_id IS NULL);
SELECT id, owner_user_id, visibility, household_id FROM collections ORDER BY id;
SELECT copy_id, COUNT(*) FROM loans WHERE returned_on IS NULL GROUP BY copy_id HAVING COUNT(*)>1;
SELECT c.id FROM copies c LEFT JOIN loans l ON l.copy_id=c.id AND l.returned_on IS NULL
WHERE (c.status='on_loan' AND l.id IS NULL) OR (c.status<>'on_loan' AND l.id IS NOT NULL);
```

Also enumerate every FK (including composite keys) and check LEFT JOIN parent
orphans; merely enabling FOREIGN_KEY_CHECKS does not retrospectively validate
loaded records. Walk each location and category (id,parent_id) chain with a visited
set: reject missing ancestors/cycles, do not rely on a recursive query truncating
at a depth limit. Compare roots/sibling names and inventory codes using actual
engine collation/trailing-space equality, including conflicts before scoped
index creation. Diagnose private/shared collection contents, pending invitation
assignments, inactive/nonmember owners and historical vs active borrowers.

Review findings by consequence: inactive historical owners/borrowers or a
bounded policy disclosure are not automatically corruption, and no script may
“fix” them. Broken FKs/cycles, conflicting uniqueness, missing required default
or unexplained changed records block the upgrade. Ambiguous intended sharing
requires an explicit owner disposition before a dormant upgrade and remains an
activation blocker. Existing legal cross-household duplicate codes must remain
unchanged and inform recovery planning; never silently normalize/rename them.
Assess real subtrees/batches exceeding 100 nodes/copies and ownership with any
loan history as future assignment constraints, not reasons to delete history.

## 9. Production-artifact and ordinary release verification

Lane A establishes data/migration evidence. Lane B establishes actual image,
FPM/nginx/container behavior on a dedicated authorized isolated VM/host. Neither
alone establishes the other. Keep HOUSEHOLDS_ENABLED=false; do not open a tunnel,
connect Cloudflare, publish public ports or import production secrets.

The owner prepares a **lab-specific reviewed configuration** with unique roots,
fresh credentials, loopback ingress and blocked outbound traffic. The fixed
production Compose project name, .env.production path, storage guards and mount
paths mean `-p` alone does not isolate files. A pristine dedicated VM may mirror
the documented layout only with **new lab disks/UUIDs** and reviewed lab preflight;
never spoof or weaken the installed production disk guard. If safely reproducing
these invariants requires an override/host-specific setup, approve that artifact
separately and record differences. Do not start unreviewed Compose against real
production paths. This plan does not invent a ready-made safe lab Compose file.

Owner artifact checklist:

| Check | Required evidence |
|---|---|
| Release/image provenance | Exact reviewed SHA and image IDs/digests; no mutable-tag-only claim. Keep previous reviewed artifacts. Exclude backups/.env/data from build context |
| CPU/extensions | Run chosen PHP/nginx/MariaDB binaries on authorized target CPU; no illegal instruction/glibc failure; PHP 8.4 with pdo_mysql, gd and required extensions. Do not substitute MySQL image on documented old CPU |
| Config syntax | `php-fpm -t`, `nginx -t`, application config:check --strict in web and ops with fresh lab configuration |
| Production identity | Marker exists at /var/www/html/config/production-image, root-owned 0444, code root-owned, actual intended image. No config/code mount hiding it |
| Environment precedence | Resolved php/ops APP_ENV=production and APP_ENV_FILE empty even if the lab env_file contains contrary values; inspect nonsecret keys only, never dump all secrets |
| Two household prohibitions | Offline config probe shows production classification, a HOUSEHOLDS_ENABLED validation error for a hostile enabled flag and Services::householdsEnabled() false. This is negative validation, not serving an enabled feature |
| Startup | FPM entrypoint validates then starts; profile ops defaults help; metadata/history fingerprints before/after startup show no migration. Never put migrate/grant steps into entrypoint |
| Credentials | Web sees runtime only, not migrator/root/backups; ops sees intended two secrets; DB root local-only. Verify effective grants after recreated connections |
| Storage/health | Correct bind sources/owners/modes/create_host_path=false, read-only/non-root containers, private temp/session paths, health, loopback ingress only; no bare data-directory initialization |
| Ingress/security | Existing production proxy design owner-verified separately; lab uses approved local policy, Host refusals, private/no-store responses, secure cookies/HSTS with its chosen HTTPS arrangement. Plain native HTTP cannot certify production Secure-cookie behavior |
| Capacity/contention | Actual measured section 6 budgets; no OOM/space exhaustion; bounded export/revocation waits and acceptable rollback/409 behavior |

Example **owner-only dedicated LAB host**, using owner-verified image names and
no mounts/data/network for binary/marker probes:

```bash
: "${OWNER_ARTIFACT_LAB_APPROVAL:?dedicated lab host approved; never desktop}"
: "${LAB_DOCKER_SOCKET:?verified local daemon socket on dedicated lab host}"
test -S "$LAB_DOCKER_SOCKET"
lab_docker() {
  env -u DOCKER_CONTEXT -u DOCKER_HOST -u DOCKER_TLS_VERIFY -u DOCKER_CERT_PATH \
    docker --host "unix://$LAB_DOCKER_SOCKET" "$@"
}
: "${PHP_IMAGE:?exact reviewed candidate image ID or digest}"
: "${NGINX_IMAGE:?exact reviewed candidate image ID or digest}"
: "${MARIADB_IMAGE:?exact approved same-engine image ID or digest}"
lab_docker run --rm --pull=never --network none --entrypoint php "$PHP_IMAGE" -r 'echo PHP_VERSION, PHP_EOL;'
lab_docker run --rm --pull=never --network none --entrypoint nginx "$NGINX_IMAGE" -v
lab_docker run --rm --pull=never --network none --entrypoint mariadbd "$MARIADB_IMAGE" --version
lab_docker run --rm --pull=never --network none --entrypoint php "$PHP_IMAGE" -r '
require "/var/www/html/vendor/autoload.php";
$c = new DigitalLibrary\Core\Config(["APP_ENV"=>"testing", "APP_ENV_FILE"=>"/nonexistent", "HOUSEHOLDS_ENABLED"=>"true"]);
$errors = (new DigitalLibrary\Core\ConfigValidator("/var/www/html"))->validate($c)["errors"];
$s = new DigitalLibrary\Core\Services($c, "/var/www/html");
if (!$c->isProduction() || $s->householdsEnabled() || !in_array("HOUSEHOLDS_ENABLED", array_column($errors, 0), true)) { exit(1); }
echo "production marker and both household prohibitions enforced", PHP_EOL;
'
```

The marker probe never serves requests or connects to a DB; unrelated missing
configuration errors cannot replace the explicit household-error assertion.
The Docker wrapper pins an owner-verified **local Unix socket**, ignoring inherited
remote contexts/TLS settings; do not use a production Docker endpoint or desktop
Docker. Binary probes use already-present reviewed images, with no automatic pull.
FPM/nginx checks need the approved lab config/mounts and an already running verified
lab DB; --no-deps prevents an ops command from starting it implicitly. After owner has reviewed
those paths/endpoints, an explicit **LAB-only** wrapper can run the real stack's
checks (LAB_ENV must be the file actually consumed by its reviewed configuration):

```bash
: "${LAB_CODE:?reviewed candidate code on dedicated lab host}"
: "${LAB_ENV:?private fresh lab configuration, not production file}"
: "${LAB_COMPOSE:?reviewed lab Compose artifact, not blindly reused production mounts}"
: "${LAB_PROJECT:?unique lab project}"
lab_dc() {
  lab_docker compose --project-directory "$LAB_CODE" -p "$LAB_PROJECT" \
    -f "$LAB_COMPOSE" --env-file "$LAB_ENV" "$@"
}
# Run only once the owner has provisioned/verified lab data and reviewed startup.
lab_dc run --rm -T --no-deps ops php bin/console config:check --strict
lab_dc config --format json | jq -e '
  .services.php.environment.APP_ENV == "production" and
  .services.ops.environment.APP_ENV == "production" and
  .services.php.environment.APP_ENV_FILE == "" and
  .services.ops.environment.APP_ENV_FILE == "" and
  (.services.php.environment.HOUSEHOLDS_ENABLED | tostring) == "false" and
  (.services.ops.environment.HOUSEHOLDS_ENABLED | tostring) == "false"'
lab_dc exec -T php php-fpm -t
lab_dc exec -T nginx nginx -t
lab_dc ps
```

No build/up/deploy command is automatically inferred by that wrapper; owner
chooses the reviewed lab startup procedure and records exactly what it starts.
If LAB_COMPOSE imports a fixed .env.production, do not assume --env-file replaces
that service env_file; review resolved service configuration too.

To populate Lane B for actual UI checks, the owner can restore the **verified
post-upgrade archive** to its own empty same-engine lab DB, then apply the same
runtime manifest using that lab DB's provisioner. The native Appendix A helper
is **not** for production-classified containers: their internal DB endpoint,
paths and environment differ. A production-image restore requires
--allow-production even on a dedicated lab; here it acknowledges classification,
not permission to use real production. Approve/verify the lab identity/mounts first.

```bash
: "${LAB_B_DB:?verified isolated *_rehearsal schema in dedicated artifact VM}"
: "${LAB_B_ARCHIVE_CONTAINER:?exact verified archive path in LAB ops backup mount}"
lab_dc run --rm -T --no-deps ops php bin/console maintenance:on --reason="isolated artifact rehearsal"
lab_dc stop php
# Owner verifies all other lab writers stopped and target empty.
lab_dc run --rm -T --no-deps ops php bin/console backup:verify --file="$LAB_B_ARCHIVE_CONTAINER"
lab_dc run --rm -T --no-deps ops php bin/console backup:restore \
  --file="$LAB_B_ARCHIVE_CONTAINER" --confirm="$LAB_B_DB" --allow-production
# STOP: reconcile restore, apply/verify LAB runtime grants with its DBA as in F.
lab_dc run --rm -T --no-deps ops php bin/console migrate:status
# Post-upgrade archive must already have complete selected history; pending is STOP.
# Save history/attempt fingerprints now and compare again after startup:
lab_dc up -d --wait php nginx
lab_dc run --rm -T --no-deps ops php bin/console maintenance:off
```

That block is an **owner-only isolated lab** operation after separate startup
authorization, not production deployment. Keep maintenance until reconciliation
and approved synthetic smoke setup are complete; the final maintenance:off is
only for that private exercise. Do not add --replace to make an unexpected target
pass. No archive or clone from Lane B is promoted back into production.

With reconciled data and runtime grants, approved **synthetic** lab accounts and
records exercise ordinary browser/API flows without reset frameworks:

- Login/logout, invalid credentials and CSRF; logout POST rotates session,
  replayed old cookie cannot authorize the next request, audit success is visible.
  Do not force audit storage failure on real restored rows; use synthetic fault
  fixtures for failure cases. Confirm no logged-out audit failure warnings in
  normal smoke, and no raw SQL/secrets in responses.
- Admin/Member/Viewer browsing, search/pagination, covers, locales, collections,
  wishlists and personal/admin export scopes under **disabled** household mode.
  Household routes/controls stay unavailable; normal pre-household global
  catalogue behavior is not advertised as active household isolation.
- Only marked synthetic account/catalogue/copy/location/collection/loan writes,
  stale-edit handling and lend/return. Each domain mutation has its durable event;
  no unexpected event duplicates/partial database writes. Preserve all original
  IDs/relationships/fields and label every intentional addition/change.
- Verify an approved post-upgrade backup includes the complete schema/audit and
  actually restores, not merely exits VALID. No public/ordinary audit-edit route,
  no runtime account deletion and no grant fallback.
- Owner completes supported Firefox/WebKit/accessibility checks or records a
  justified supported-matrix decision; historical Chrome/Edge are not equivalent.
  PHP 8.3 deployment needs separate full evidence only if adopted as a target.

## 10. Credential switch dependency and retirement (future production decision)

Rehearse this order with **lab identities first**. Repeating it on production
needs a new explicit deployment/change authorization and a fresh verified backup.
Do not copy the clone's credentials/data back as a production upgrade.

1. Owner inventories actual old account/user@host/roles/definers/consumers and
   saves private effective grants; choose a new dedicated runtime identity.
2. Stop all writers and establish source backup/recovery point. Make candidate
   ops/image available without starting candidate web writers. Preserve installed
   preflight, volumes/secrets/boot boundary; no force checkout over data.
3. Separate migrator applies the approved pending schema/backfills. No runtime
   or migration credential gets GRANT OPTION. Complete data reconciliation.
4. DBA creates a **new absent** runtime account (CREATE USER without IF NOT
   EXISTS, private password input/history disabled), no inherited broad grants.
   Generate/review/apply the manifest **after required tables exist**. Verify
   actual identity/grants, denied zero-row probes, allowed writes/audit/backup.
   Scope and matching host must fit the intended container network, not guesses.
5. Owner updates the intended runtime username/secret only; web and ops must
   agree on the runtime identity, with migration/root secrets still segregated.
   Recreate/reconnect appropriate workers so stale privileges/sessions cannot
   remain. Starting old workers during this window is prohibited.
6. Verify startup, audit and ordinary behavior plus a complete backup before
   reopening approved traffic. Keep existing household restrictions/false flag.
7. Retire the old broad credential: identify every consumer, lock old identities,
   revoke old privileges and actual roles, terminate stale connections, and verify
   no alternate matching host/definer path bypasses the new restriction. Locking
   an account alone does not terminate existing connections. Only DBA may do it.
8. Retain private evidence, reviewed recovery image/credentials and monitoring;
   never silently reactivate the broad account on an error.

The manifest is additive: narrow grants do not cancel global/schema grants;
REVOKE ALL does not remove inherited roles. New-account replacement is preferred.
Any in-place alternative follows the detailed
[hardening procedure](AUDIT_GRANTS_LOGOUT_HARDENING.md#existing-installations--owner-only-procedure)
with owner-reviewed revocation of broad rights/roles while writers stay stopped.
This plan executes no GRANT/REVOKE, rotation, connection termination or deployment.

## 11. Stop conditions and failure recovery

For every failure: stop writers, keep maintenance/incomplete markers, capture
private schema/history/intent/logs/UTC/resource evidence and preserve the original
archive. Nonzero output is not permission to replay or proceed. Do not auto-repair
records, grants, metadata, files or schema. Existing IDs/relationships are assets,
not disposable obstacles to making a migration pass.

| Failure/stop condition | Safe owner response |
|---|---|
| Wrong/stale release, branch or unknown CI/artifact | Hold; reconcile candidate/evidence locally through owner process and re-review. Do not fetch/switch/merge automatically |
| Wrong engine/port/datadir/database/account or production route reachable from lab | Disconnect/stop the lab process before mutation, quarantine configs, re-provision a genuinely isolated target. No alias/tunnel workaround |
| Missing/incomplete/unsupported backup, wrong trusted hash, missing covers, failed source fingerprints | NO-GO; obtain a new verified owner backup during a new approved source window. Do not use allow-missing-covers or trust a replaceable sidecar alone |
| Changed/unknown/other-engine history; retained intent; old partial objects | Hold before DDL; establish no migrator active, inspect exact object/data effects. Follow OPERATIONS recovery. Do not rewrite pins/history or clear intent |
| Migration fails/interrupts after some DDL | Treat file as partial even if client disconnected or exit is generic. Preserve failed clone; prefer original same-engine backup into another empty target. Version-specific repair requires separate approval; no generic DDL rollback/replay |
| Missing runtime table/audit privilege | Keep writers closed; verify matched identity and effective grant provenance. DBA corrects only reviewed required grants, reconnects and retests. No audit bypass, broad DML or automatic old-account fallback |
| Invalid/ambiguous household mapping or unauthorized default backfill | Stop; owner reviews ID ledger and intended sharing. No inferred owner/membership rewrite, normalization, history deletion or flag-on workaround |
| FK/cycle/code collision or unexplained fingerprint/index/column change | Preserve evidence/reference; no production release until reconciled. Restore a new lab clone rather than deleting “bad” real records |
| Incomplete restore/final audit failure | Maintenance marker stays; inspect all effects. Restore verified archive into a new empty target if needed. Do not acknowledge incomplete restore merely to pass the exercise or serve unverified data |
| Insufficient disk/RAM, OOM, unacceptable waits/lockouts | Stop further operations/writers; DDL may already have committed. Budget more isolated capacity or reviewed tuning, then restart from verified recovery point. Never delete source/catalogue/audit to free space |
| FPM/nginx/config/marker/secret/CPU incompatibility | No traffic or migration to compensate. Preserve logs/artifacts, correct the separately reviewed lab artifact/config or select a compatible reviewed release. Do not remove marker, weaken guards or mount over config |
| Unexpected error, audit warning or partial smoke result | Freeze clone, compare exact write/audit ledger, investigate. A lost response is not proof of rollback; do not replay ordinary POST blindly. H4 has durable operation IDs but is not exercised/enabled here |
| Need old image after schema change | SQL compatibility is insufficient: assess scoped-code validation, location versions, credentials and privacy. Prefer reviewed compatible recovery release; otherwise approved same-engine pre-upgrade restore with explicit later-write loss |

No down migrations or automatic object deletion/recreation are proposed. Recovery
by restoration means a separate owner-approved replacement procedure, safety
backup, stopped writers and reconciliation, never running an unqualified DROP on
production. Fault-injection/power-loss experiments, if requested, use a separate
synthetic instance, not the only restored reference or production installation.

**Privacy-safe rollback:** after inventories have been separated, turning the
flag off restores global reads; old code can omit assignments/versions and global
code checks can reject legal scoped codes. Never use flag-off as generic recovery.
Keep maintenance and use a reviewed household-aware release or approved backup
point. In this plan the flag stays false throughout and no separation is applied;
that does not approve a future flag-off rollback after activation.

## 12. Go/no-go and evidence handoff

### GO to execute a rehearsal only when

- Owner records explicit source-backup/lab/data-handling authorization and exact
  source/candidate/recovery versions; local main discrepancy is resolved.
- Same-engine isolated target, fresh scoped accounts, private paths, blocked
  production reachability/outbound and resource budgets are demonstrated.
- Verified archive/independent hash plus source fingerprints/schema/PK/cover
  evidence exist; supported restore/source audit format is confirmed.
- Every actual pending migration and exact backfill effect is understood and
  separately approved; no interruption/unknown-history condition is outstanding.
- Guard/helper/client/host setup is privately reviewed, prerequisites exist and
  no command will consume normal .env/default DB/test-reset configuration.

### GO to recommend a later dormant production upgrade only when

- Rehearsal shows preserved original rows/IDs/fields/history/relationships/covers,
  only approved backfills, exact target schema/index/FK/checksum state, empty
  attempt journal and complete post-upgrade backup/restore.
- Effective runtime restrictions and allowed workflows work after fresh
  connections; account switch/old-credential retirement are rehearsed and reviewed.
- Actual production artifact/config/startup/CPU/secret/storage/ingress checks and
  ordinary disabled-household smoke succeed; no startup migration occurs.
- Recovery point/time/data-loss/space/lock budgets are measured and acceptable;
  failures have no unexplained data or authorization consequences.
- Owner approves remaining policy/operational/compatibility dispositions and
  separately authorizes deployment. A successful rehearsal is evidence, not that
  authorization and not an instruction to deploy.

### Always NO-GO for household activation in this phase

No enabling flag, marker removal, guard revision, production inventory transfer,
membership redistribution or activation release is part of this plan. Activation
would require its own approved design, policy/mapping review, household-aware
recovery and verification. No prior proposed risk acceptance is silently approved.

Handoff contains sanitized pass/fail summary, private evidence locations/hashes,
actual candidate/image/source/engine versions, migration/backfill ledger,
schema/data/cover reconciliation, effective-grant checks, smoke outcomes,
resource/time measurements, recovery exercise and unresolved decisions. Keep raw
database/credentials/cell hashes under owner control. Do not claim any of these
checks passed merely because their commands are printed here.

After evidence acceptance, owner closes only the labelled lab resources: verify
datadir/port/version, stopped writers and no other clients, use the private DBA
for graceful SHUTDOWN and verify that exact process exits. Do not stop the normal
MySQL service or delete resources based on a broad path/PID glob. Retain encrypted
archives/evidence under the approved policy, then remove only inventoried lab
copies/secrets/VMs when authorized. No cleanup/destructive command is executed by
this preparation phase.

## Appendix A — private native-console guard (draft for owner review)

Save this **only after lab authorization**, outside the repository, as
LAB_ROOT/rehearsal.php with mode 0600. This is a proposed helper, not a new
application file. No helper or rehearsal was executed while preparing the plan.
Statically lint/review it first; its future functional validation belongs to the
authorized lab. It cannot defend against a malicious owner/compromised host or
make loopback alone trustworthy. Fresh lab-only credentials and verified server
identity are mandatory; configuration acknowledgements are not authorization.

```php
<?php
declare(strict_types=1);
// Draft: native, same-host lab only. Run from the verified candidate checkout.
function directoryPath(string $path): string
{
    $path = rtrim($path, '/'); // @@datadir commonly has a trailing slash.
    if ($path === '' || $path[0] !== '/') {
        throw new RuntimeException('Absolute non-root directory required.');
    }
    clearstatcache(true);
    $prefix = '';
    foreach (explode('/', substr($path, 1)) as $part) {
        if ($part === '' || $part === '.' || $part === '..') {
            throw new RuntimeException('Noncanonical path refused.');
        }
        $prefix .= '/' . $part;
        if (is_link($prefix) || !is_dir($prefix)) {
            throw new RuntimeException('Symlink or missing directory refused.');
        }
    }
    if (realpath($path) !== $path) { throw new RuntimeException('Path changed.'); }
    return $path;
}
function regularFile(string $path): string
{
    directoryPath(dirname($path));
    clearstatcache(true);
    if (is_link($path) || !is_file($path) || realpath($path) !== $path) {
        throw new RuntimeException('Canonical regular file required.');
    }
    return $path;
}
if ($argc < 2) { throw new RuntimeException('Private lab.json argument required.'); }
$base = realpath(getcwd());
if ($base === false) { throw new RuntimeException('Checkout missing.'); }
require $base . '/vendor/autoload.php';
$jsonFile = regularFile($argv[1]);
if ((fileperms($jsonFile) & 0077) !== 0) { throw new RuntimeException('Private config required.'); }
$object = json_decode(file_get_contents($jsonFile), false, 32, JSON_THROW_ON_ERROR);
if (!$object instanceof stdClass) { throw new RuntimeException('JSON object required.'); }
$v = (array)$object;
$keys = explode(' ', 'APP_ENV APP_ROLE APP_DEBUG HOUSEHOLDS_ENABLED METADATA_PROVIDERS APP_URL SESSION_SECURE_COOKIE DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD DB_MIGRATION_USERNAME DB_MIGRATION_PASSWORD COVER_STORAGE_PATH TEMP_PATH BACKUP_PATH MAINTENANCE_FLAG_PATH REHEARSAL_ROOT REHEARSAL_DATADIR REHEARSAL_DATADIR_DEVICE REHEARSAL_DATADIR_INODE REHEARSAL_HOSTNAME REHEARSAL_VERSION REHEARSAL_RUNTIME_ACCOUNT REHEARSAL_MIGRATION_ACCOUNT');
if (array_diff(array_keys($v), $keys) || array_diff($keys, array_keys($v))) {
    throw new RuntimeException('Missing/unknown config key; no file/test/root overrides.');
}
foreach ($v as $value) {
    if (!is_string($value) || $value === '') { throw new RuntimeException('Nonempty string values required.'); }
}
$root = directoryPath($v['REHEARSAL_ROOT']);
if ($v['REHEARSAL_ROOT'] !== $root || $jsonFile !== $root . '/lab.json'
    || $root === $base || str_starts_with($root . '/', $base . '/')
    || str_starts_with($base . '/', $root . '/') || (fileperms($root) & 0077) !== 0
    || $v['APP_ENV'] !== 'testing' || $v['APP_ROLE'] !== 'ops'
    || $v['APP_DEBUG'] !== 'false' || $v['HOUSEHOLDS_ENABLED'] !== 'false'
    || $v['SESSION_SECURE_COOKIE'] !== 'false' || $v['METADATA_PROVIDERS'] !== 'none'
    || $v['DB_HOST'] !== '127.0.0.1' || !preg_match('/^[1-9][0-9]{3,4}$/D', $v['DB_PORT'])
    || (int)$v['DB_PORT'] < 1024 || (int)$v['DB_PORT'] > 65535 || (int)$v['DB_PORT'] === 3306
    || strlen($v['DB_DATABASE']) > 64 || !preg_match('/^[a-z0-9_]+_rehearsal$/D', $v['DB_DATABASE'])
    || !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $v['DB_USERNAME'])
    || !preg_match('/^[a-zA-Z0-9_]{1,32}$/D', $v['DB_MIGRATION_USERNAME'])
    || strtolower($v['DB_USERNAME']) === 'root' || strtolower($v['DB_MIGRATION_USERNAME']) === 'root'
    || $v['DB_USERNAME'] === $v['DB_MIGRATION_USERNAME']
    || !preg_match('~^http://127\.0\.0\.1:([1-9][0-9]{3,4})$~D', $v['APP_URL'], $web)
    || (int)$web[1] < 1024 || (int)$web[1] > 65535 || (int)$web[1] === (int)$v['DB_PORT']) {
    throw new RuntimeException('Unsafe rehearsal configuration.');
}
foreach (['COVER_STORAGE_PATH' => 'covers', 'BACKUP_PATH' => 'backups', 'TEMP_PATH' => 'tmp'] as $key => $leaf) {
    if ($v[$key] !== $root . '/' . $leaf || directoryPath($v[$key]) !== $v[$key]) {
        throw new RuntimeException('Wrong private storage directory.');
    }
}
if ($v['MAINTENANCE_FLAG_PATH'] !== $root . '/maintenance.flag') {
    throw new RuntimeException('Wrong maintenance path.');
}
foreach (['', '.restore-incomplete'] as $suffix) {
    $path = $v['MAINTENANCE_FLAG_PATH'] . $suffix;
    if (is_link($path) || (file_exists($path) && !is_file($path))) {
        throw new RuntimeException('Unsafe maintenance marker.');
    }
}
$datadir = directoryPath($v['REHEARSAL_DATADIR']);
if ($v['REHEARSAL_DATADIR'] !== $root . '/db' || $datadir !== $root . '/db'
    || !ctype_digit($v['REHEARSAL_DATADIR_DEVICE']) || !ctype_digit($v['REHEARSAL_DATADIR_INODE'])) {
    throw new RuntimeException('Wrong canonical datadir or missing pre-start identity.');
}
$checkDatadir = static function (string $reported) use ($v, $datadir): void {
    if (directoryPath($reported) !== $datadir) { throw new RuntimeException('Server uses wrong datadir.'); }
    $stat = stat($datadir);
    if ($stat === false || (string)$stat['dev'] !== $v['REHEARSAL_DATADIR_DEVICE']
        || (string)$stat['ino'] !== $v['REHEARSAL_DATADIR_INODE']) {
        throw new RuntimeException('Datadir replaced since pre-start inspection.');
    }
};
$checkDatadir($datadir);
// Positive per-command grammar, checked BEFORE opening any database connection.
$specs = [
    'identity' => [], 'validate-fks' => [], 'config:check' => ['strict' => 'flag'],
    'backup:verify' => ['file' => 'value'], 'backup:restore' => ['file' => 'value', 'confirm' => 'value'],
    'backup:create' => [], 'maintenance:on' => ['reason' => 'value'],
    'maintenance:off' => [], 'maintenance:status' => [], 'migrate' => [], 'migrate:status' => [],
    'user:create' => ['email' => 'value', 'name' => 'value', 'role' => 'value', 'locale' => 'value'],
];
$command = $argv[2] ?? 'identity';
if (!array_key_exists($command, $specs)) { throw new RuntimeException('Command refused.'); }
$options = [];
foreach (array_slice($argv, 3) as $arg) {
    if (!preg_match('/^--([a-z][a-z0-9-]*)(?:=(.*))?$/sD', $arg, $m)
        || !isset($specs[$command][$m[1]]) || array_key_exists($m[1], $options)) {
        throw new RuntimeException('Unknown, duplicate or positional argument refused.');
    }
    $flag = $specs[$command][$m[1]] === 'flag';
    if (($flag && isset($m[2])) || (!$flag && (!isset($m[2]) || $m[2] === ''))) {
        throw new RuntimeException('Use bare flags and --key=value for values.');
    }
    $options[$m[1]] = $flag ? true : $m[2];
}
$required = match ($command) {
    'backup:verify' => ['file'], 'backup:restore' => ['file', 'confirm'],
    'user:create' => ['email', 'name'], default => [],
};
foreach ($required as $key) {
    if (!isset($options[$key])) { throw new RuntimeException('Required option missing.'); }
}
if (isset($options['confirm']) && $options['confirm'] !== $v['DB_DATABASE']) {
    throw new RuntimeException('Restore confirmation must name the isolated schema.');
}
if (isset($options['file'])) {
    $file = regularFile($options['file']);
    if (!str_starts_with($file, $root . '/input/') && !str_starts_with($file, $root . '/backups/')) {
        throw new RuntimeException('Archive outside private input/backups.');
    }
    regularFile($file . '.sha256');
    $options['file'] = $file; // Forward the verified canonical spelling only.
}
$config = new DigitalLibrary\Core\Config($v); // no .env or inherited settings
if ($config->isProduction()) { throw new RuntimeException('Native checkout only; never alter a prod marker.'); }
$runtime = null;
foreach ([false, true] as $migration) {
    $p = $migration ? DigitalLibrary\Database\ConnectionFactory::forMigrations($config)
                    : DigitalLibrary\Database\ConnectionFactory::fromConfig($config);
    $r = $p->query('SELECT DATABASE(), @@port, @@datadir, @@hostname, VERSION(), CURRENT_USER(), @@pid_file')->fetch(PDO::FETCH_NUM);
    $expectedUser = $v[$migration ? 'DB_MIGRATION_USERNAME' : 'DB_USERNAME'];
    $checkDatadir((string)$r[2]);
    if ($r[0] !== $v['DB_DATABASE'] || (int)$r[1] !== (int)$v['DB_PORT']
        || $r[3] !== $v['REHEARSAL_HOSTNAME'] || $r[4] !== $v['REHEARSAL_VERSION']
        || explode('@', $r[5], 2)[0] !== $expectedUser
        || $r[5] !== $v[$migration ? 'REHEARSAL_MIGRATION_ACCOUNT' : 'REHEARSAL_RUNTIME_ACCOUNT']
        || regularFile((string)$r[6]) !== $root . '/db.pid'
        || DigitalLibrary\Database\Engine::of($p) !== DigitalLibrary\Database\Engine::MARIADB) {
        throw new RuntimeException('Wrong isolated database identity.');
    }
    if (!$migration) { $runtime = $p; }
}
if ($command === 'identity') { echo "Verified configured isolated MariaDB identities.\n"; exit(0); }
if ($command === 'validate-fks') {
    $runtime->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $runtime->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY');
    try {
        $violations = DigitalLibrary\Backup\BackupRestorer::foreignKeyViolations($runtime);
    } finally {
        if ($runtime->inTransaction()) { $runtime->rollBack(); }
    }
    echo json_encode($violations, JSON_THROW_ON_ERROR), "\n";
    exit($violations === [] ? 0 : 1);
}
$args = [];
foreach ($options as $key => $value) { $args[] = '--' . $key . ($value === true ? '' : '=' . $value); }
$services = new DigitalLibrary\Core\Services($config, $base);
$services->set(PDO::class, $runtime); // Reuse the verified runtime connection.
exit((new DigitalLibrary\Console\Console($services))->run(['bin/console', $command, ...$args]));
```

The helper verifies each connection's reported canonical datadir against the
pre-start directory device/inode, plus schema, port, host, exact server version,
matched account, engine and the native PID-file path. The PID path is additional
corroboration, not a cryptographic server identity. Its runtime PDO is reused for
FK checks and Console services. Migrations/restore create another migrator PDO
inside the application; this is a **preflight**, not an atomic proof covering
future reconnects. Keep JSON, server, paths, files and endpoints unchanged through
each operation; stop/recheck after restart/replacement. Protect private paths from
concurrent writers. Canonical checks do not detect malicious host/bind mounts or
all external tablespace arrangements, and cannot eliminate filesystem TOCTOU.

The helper deliberately supports only the documented MariaDB production path.
If owner inspection instead finds a MySQL source, stop/revise the engine/artifact
plan rather than remove its engine guard. Fingerprinting helpers are read-only;
Console guards are not permission to run their commands on production.

## Targeted correction review: concrete helper defects and limits

Static review against [Config](../src/Core/Config.php),
[ConnectionFactory](../src/Database/ConnectionFactory.php),
[Console](../src/Console/Console.php), [Services](../src/Core/Services.php),
[Operations](../src/Console/Operations.php),
[MaintenanceMode](../src/Core/MaintenanceMode.php),
[BackupRestorer](../src/Backup/BackupRestorer.php) and
[HealthController](../src/Http/Controllers/HealthController.php) found:

- Original datadir string-prefix checks accepted traversal/symlink redirection
  and did not pin the initialized directory. Appendix A now canonicalizes every
  directory component, rejects symlinks and verifies the running connections'
  reported datadir against the pre-start device/inode; it also checks PID path.
- Original storage checks accepted LAB_ROOT itself/regular files and forwarded
  unpinned paths. Storage now requires the exact private subdirectories; marker
  leaves cannot be symlinks/nonregular files. Dangling symlinks are rejected too.
- Console's parser ignores unrecognized options/positionals and duplicates use
  the final value. The original helper's denylist did not fail closed on these
  mistakes. Appendix A now positively validates each command's options, required
  values and restore confirmation **before connecting**, rejecting duplicates,
  unknown options, positional arguments and guard overrides. Verified canonical
  archive paths/sidecars are required. No destructive override is added.
- Config accepts arbitrary keys; the original helper lacked a complete object/
  key/value check. It now requires the exact worksheet keys, so typos, *_FILE,
  test/root overrides and missing migrator credentials cannot cause fallback.
  It bounds the web port, separates it from DB, and pins insecure cookies only
  for the explicit native loopback HTTP lab. Production-image classification
  still rejects this native helper. Session SQL mode/charset are imposed by
  ConnectionFactory, not invented JSON options.
- FK validation previously opened an extra unchecked runtime connection. It now
  reuses the verified PDO via Services::set, also used by runtime Console work.
  Application-created migrator reconnects remain subject to the stable-server
  prerequisite above; no application implementation was changed.
- Maintenance status exit zero and a passing health probe are insufficient for
  source resumption. Section B.1 adds the explicit owner-only original-service
  state, restore-marker, readiness, normal-request and job-resumption checkpoint.

These are corrections to a **proposed, unexecuted helper/runbook**, not claims of
new deployed application vulnerabilities. Functional negative tests remain a lab
prerequisite: traversal and symlink ancestors/leaves, replaced datadir inode,
wrong listening server/schema/account/version/PID path, invalid/duplicate options,
config typos, changed archive paths, legitimate allowlisted commands and migrator
reconnect behavior. Use only newly authorized disposable resources; never test
these against normal or source production data. This review runs none of them.

## Preparation checks actually performed

Local Git/source/static-document inspection only; migration inventory/dependencies,
grant generator, migration/restore/backup paths, guards and startup were traced.
Targeted correction validation passed: `php -l` for Appendix A and the inline
production-marker PHP probe; `bash -n` for all 15 Bash fences; balanced Markdown
fences; all 26 relative file references resolved; `git diff --check` plus
`git diff --no-index --check /dev/null docs/PRODUCTION_MIGRATION_REHEARSAL_PLAN.md`
for the untracked file. Section 4's explicit H1/default-household, inventory and
membership backfill approval text was preserved byte-for-byte. Syntax checks do
not execute helpers or establish database/runtime safety. No DB connection, migration, privilege operation,
backup access, image build, container/browser/server startup or remote CI query
was performed. Only this document is delivered, unstaged and uncommitted.
