# Production deployment runbook (operator-controlled)

Every command in this document is for **you, the operator,** to review and
run on your own machines. Nothing here has been run against the production
host. Backups, restores and scheduling are covered in depth in
[OPERATIONS.md](OPERATIONS.md); the design is in
[ARCHITECTURE.md › Deployment](../ARCHITECTURE.md#deployment).

**Verification status** used below:

| Mark | Meaning |
|---|---|
| ✅ local | Verified on the development machine (tests, local nginx/PHP-FPM, static checks) |
| 🧪 CI | Exercised by the `production-stack` CI job, which **has not run yet** |
| ⚠ production | Never tested on the production host; verify when you do it |

## 0. Blocked until the network topology is verified

The production stack is deliberately **fail-closed** and has **no ingress**:
nginx joins only an internal network, publishes no port, and refuses to start
until a client IP policy and `DLA_TRUSTED_PROXY` are set. These decisions are
open and must not be guessed:

| Decision | Where it will be set |
|---|---|
| Which peer nginx trusts (proxy address) | `docker/nginx/production/client-ip.conf` (`set_real_ip_from`) |
| Which header carries the client address (`CF-Connecting-IP` or `X-Forwarded-For`) | same file (`real_ip_header`, `real_ip_recursive`) |
| How traffic crosses from the Raspberry Pi (cloudflared/Traefik) to the Docker server | a Compose override: published port on a specific address, or a dedicated network |
| How nginx is exposed on the Docker server | same override |
| Which `Host` header the proxy forwards (must be `library.bravina.co.uk`) | Traefik / cloudflared configuration |
| Whether direct requests are refused with 403 | `$dla_require_trusted_proxy` in the policy; only after routing and health checks are verified |

**What you run (read-only; review the scripts first):**

```bash
# From your workstation (repository checkout):
scp docker/discovery/docker-host.sh  <you>@192.168.1.50:
scp docker/discovery/ingress-host.sh <you>@192.168.1.52:
# On the Docker server (192.168.1.50):
sudo -v            # optional: lets the script read firewall rules and socket owners
bash docker-host.sh
# On the Raspberry Pi (192.168.1.52):
sudo -v
bash ingress-host.sh 192.168.1.50
```

Each writes `~/dla-discovery-<host>-<time>.txt` (mode 0600) and changes
nothing else. Read each report, follow the checklist at its end, redact what
you do not want to share, and share the sanitized reports. If the tunnel is
managed from the Cloudflare dashboard, also note each public hostname's
Service URL and "HTTP Host Header" setting (never the token). A trusted-proxy
design will then be proposed for your approval.

## 1. Prerequisites

- Debian Docker server with Docker Engine and the Compose plugin
  (`docker compose version`); a current Docker Engine is recommended (since
  version 28, Docker blocks routed access to unpublished container ports from
  other hosts by default; check the release notes of your version). ⚠ production
- The repository at the release to deploy, on that server
  (`git status` clean; `git rev-parse HEAD` noted).
- Disk: MySQL data, covers, the backups volume, plus room for one restore
  (see [Resource planning](#resource-planning)).
- Your password manager or vault for the secrets below.
- The ingress decisions of section 0 approved and implemented.

## 2. Production secrets

One random value per file, never committed (`secrets/` is git- and
docker-ignored), never reused:

```bash
cd /srv/library                       # your checkout
install -d -m 0750 secrets
for name in db_root_password db_password db_migration_password; do
  openssl rand -hex 32 > "secrets/$name"
done
sudo chown 999:999 secrets/db_root_password && sudo chmod 0400 secrets/db_root_password
sudo chown 82:999 secrets/db_password secrets/db_migration_password
sudo chmod 0440 secrets/db_password secrets/db_migration_password
```

Store copies in your password manager: restoring a backup on a new host needs
them. Optional `GOOGLE_BOOKS_API_KEY` goes the same way, as another secret
file (and an entry in `docker-compose.prod.yml`, not yet present).
Owners: `999` is the `mysql` user of the MySQL image, `82` the `www-data`
user of the PHP image. 🧪 CI (same owners and modes) · ⚠ production

## 3. Database and filesystem permissions

- **Database accounts** (created by `docker/mysql/init/01-create-users.sh` on
  the first start of an empty data volume): `library_app` (SELECT, INSERT,
  UPDATE, DELETE) for the web container; `library_migrator` (plus CREATE,
  ALTER, DROP, INDEX, REFERENCES) for the ops container; `root@localhost`
  only inside the db container. ✅ local (script) · 🧪 CI
- **Roles in code:** the web container (`APP_ROLE=web`) refuses migration or
  root credentials and every operations command; production operations run
  only in the `ops` container. ✅ local
- **Volumes:** `app-storage` (covers, tmp, cache, logs, maintenance flag;
  web + ops), `app-backups` (ops only), `db-data`. Created from the images on
  first use, owned by `www-data` (82), mode 0750. For backups on a host
  directory instead, create it as `82:82`, mode 0750. 🧪 CI · ⚠ production

## 4. Deployment preparation

```bash
cd /srv/library
git fetch && git checkout <release>        # e.g. the phase-5a commit
cp .env.production.example .env.production  # first time only; then review every value
# After the ingress design is approved (section 0):
#   - put the approved policy at docker/nginx/production/client-ip.conf
#   - set DLA_TRUSTED_PROXY=required (or none) in .env.production
#   - add the approved ingress override
export APP_IMAGE_TAG="$(git rev-parse --short HEAD)"   # one image tag per release (rollback)
dc() { docker compose -f docker-compose.prod.yml --env-file .env.production "$@"; }
dc config --quiet && dc config | less        # review: no unexpected ports, networks or mounts
```

`.env.production` holds no secrets (only `*_FILE` paths) but is still
private: `chmod 0640`. ⚠ production

## 5. Image verification

```bash
dc build                       # base images are pinned by digest in the Dockerfiles and the Compose file
docker image inspect "digital-library-php:$APP_IMAGE_TAG" --format '{{.Config.User}}'    # www-data
docker image inspect "digital-library-nginx:$APP_IMAGE_TAG" --format '{{.Config.User}}'  # nginx
docker run --rm --entrypoint php "digital-library-php:$APP_IMAGE_TAG" -m | grep -Ei '^(gd|pdo_mysql)$'
```

To refresh a base image, change tag and digest together
(`docker buildx imagetools inspect <image>:<tag>`), rebuild, and let CI pass.
🧪 CI (build) · ⚠ production

## 6. Database migration

Before the new images take traffic:

```bash
dc up -d db
dc run --rm ops php bin/console config:check --strict
dc run --rm ops php bin/console migrate
dc run --rm ops php bin/console migrate:status        # nothing pending or MODIFIED
# First deployment only: the first administrator (password read from STDIN)
dc run --rm ops php bin/console user:create --email=<you> --name="<name>" --role=admin
```

Migrations are forward-only. Take a backup (section 8) before migrating an
existing database. 🧪 CI · ⚠ production

## 7. Health checks

```bash
dc up -d --wait                       # waits for db, php and nginx to be healthy
dc ps
dc exec -T nginx wget -q -O - http://127.0.0.1:8080/health   # {"status":"ok"}: nginx -> PHP -> MySQL
dc exec -T php php bin/console config:check --strict
```

The containers' health checks: nginx `GET /health` every 30 s; php a TCP
connect to PHP-FPM every 30 s; MySQL `mysqladmin ping` every 10 s. `/health`
reports only up/down and is exempt from the Host and trusted-proxy checks so
probes work from inside the container. Through the real ingress (after
section 0), also check `https://library.bravina.co.uk/login` and that a
forged `X-Forwarded-For`/`CF-Connecting-IP` sent directly to the origin does
not change the client address in the audit log. ✅ local (application and
nginx behaviour) · 🧪 CI (containers) · ⚠ production (ingress)

## 8. Backup creation and verification

```bash
dc run --rm ops php bin/console backup:create          # writes a verified archive
dc run --rm ops php bin/console backup:verify --file=storage/backups/<archive>
```

Then copy the archive **and** its `.sha256` off the host, verify the copy,
and schedule both (OPERATIONS.md, "Recommended schedule"). Backups run
online; operations never overlap (a second one is refused). ✅ local · 🧪 CI

## 9. Restore and rollback

- **Restore data:** follow OPERATIONS.md, "Manual recovery checklist":
  maintenance on, stop the web `php` service, `backup:restore … --replace
  --allow-production` (a safety backup of the current state is taken
  automatically), checks, start `php`, maintenance off. A failed restore
  exits 2, prints the rollback command and keeps the site in maintenance
  mode. A restore is not atomic. ✅ local · 🧪 CI (drill)
- **Roll back a release (code):** images are tagged per release
  (`APP_IMAGE_TAG`). If the new release did not migrate the schema:
  `export APP_IMAGE_TAG=<previous>; git checkout <previous>; dc up -d --wait`.
  If it did migrate: migrations are forward-only, so restore the backup taken
  before the migration (section 6), then start the previous images. ⚠ production

## 10. Monitoring and troubleshooting

| Symptom | Look at |
|---|---|
| A container restarts or is unhealthy | `dc ps`, `dc logs --tail 200 <service>` |
| php exits at start: "refusing to start PHP-FPM" | `dc run --rm ops php bin/console config:check` (names keys, never values) |
| nginx exits: "dla-nginx: refusing to start" | the client IP policy and `DLA_TRUSTED_PROXY` (section 0) |
| Every page 503 | `dc run --rm ops php bin/console maintenance:status` (maintenance mode or an incomplete restore) |
| 400 on every page | the `Host` header the proxy forwards (must match `APP_URL`) |
| An operations command says "Another operation is running" | wait; the lock is released when that process exits |
| Disk filling up | `docker system df`, the backups volume, `cleanup` (dry run first) |

Logs are `json-file`, 10 MB × 5 per container. Application errors go to the
php container's stderr; the nginx access log records `peer=` (the TCP peer)
for every request. Watch for non-zero exit codes from scheduled backups.
External uptime monitoring of `https://library.bravina.co.uk/health` is
recommended once the ingress exists. ⚠ production

## 11. Updating the application

1. Read the release notes and the diff of `docker-compose.prod.yml`,
   `.env.production.example` and the migrations.
2. `dc run --rm ops php bin/console backup:create` and copy it off-host.
3. Section 4 (new `APP_IMAGE_TAG`), section 5 (build), then
   `dc run --rm ops php bin/console config:check --strict`.
4. For a release with migrations: `dc run --rm ops php bin/console maintenance:on`,
   `dc stop php`, `dc run --rm ops php bin/console migrate`.
5. `dc up -d --wait`, section 7 checks, `maintenance:off` if it was on.
6. Keep the previous images until the new release is confirmed
   (`docker image ls digital-library-*`).

⚠ production

## 12. Emergency shutdown

| Goal | Command | Data |
|---|---|---|
| Stop serving but keep the database | `dc stop nginx php` | kept |
| Friendly 503 while investigating | `dc run --rm ops php bin/console maintenance:on --reason="incident"` | kept |
| Stop everything | `dc stop` (or `dc down` without `-v`) | volumes kept |
| Cut public access at the edge | disable the public hostname in Cloudflare / Traefik (your consoles) | kept |

**Never** run `dc down -v` on production: `-v` deletes the database, covers
and backup volumes. After a suspected compromise: stop the stack, keep the
volumes and logs for investigation, rotate every secret (section 2; the
database passwords must also be changed inside MySQL), and restore from a
backup made before the incident onto a rebuilt host. ⚠ production

## Resource planning

The production host has limited capacity. The values below are starting
points with their reasoning; measure (`docker stats`, `free -m`) before
changing anything. No CPU or memory limits are set in Compose: none has been
measured, and a wrong limit causes OOM kills during backups or restores.

| Component | Current setting | Notes and conservative starting point |
|---|---|---|
| PHP-FPM | `pm = dynamic`, `max_children = 10`, `start_servers = 2`, `max_requests = 500` | Each worker typically uses tens of MB; `memory_limit = 256M` is a per-request ceiling, not usage. A sign-in hashes with Argon2id at `ARGON2_MEMORY_KIB=65536` (64 MiB for its duration). Size `max_children` ≈ (RAM you can give PHP) ÷ (measured p95 worker RSS + 64 MiB); on a 2 GB host start around 5–6. |
| MySQL 8.4 | image defaults (`innodb_buffer_pool_size` 128 MiB, `max_connections` 151, Performance Schema on) | Performance Schema alone can take a few hundred MB. Options for a small host, in `docker/mysql/my.cnf` after measuring: `performance_schema = OFF` (loses diagnostics), `max_connections = 30` (PHP-FPM workers + ops + margin), `innodb_buffer_pool_size` ≈ data size + 25 %. |
| Health checks | nginx/php 30 s, MySQL 10 s | Negligible: one small request, one TCP connect, one ping. |
| `/tmp` tmpfs | nginx 64 MB, php/ops 256 MB | Counts against RAM only when used: uploads (≤ 8 MB per request), sessions. |
| Backups | single process, gzip level 6, 1 MiB chunks, 1,000-row batches | CPU-bound for a few seconds to minutes; schedule at night. Disk in `BACKUP_PATH`: the uncompressed dump plus the archive while running. |
| Restore | streamed | `TEMP_PATH`: the whole uncompressed backup; covers: the files written; MySQL: the restored tables; with a safety backup, a second backup in `BACKUP_PATH`. |
| Logs | 10 MB × 5 per container | At most 200 MB for the four services. |

## First CI run (after you commit and push)

CI has been written and statically tested only. Steps:

1. Commit with the Phase 5A commit script (Option B), then push the branch
   yourself: `git push -u origin phase-5a`.
2. Open a pull request to `main` (the `pull_request` trigger runs all four
   jobs). Pushes to `main` also trigger it; `schedule` runs only from the
   default branch, so the weekly job starts once the workflow is on `main`.
3. Watch: GitHub → Actions → CI, or `gh run list --workflow CI` and
   `gh run watch`.
4. On failure: `gh run view <id> --log-failed`. Expected first-run risks:
   shellcheck warnings (never run locally), runner differences (nginx install,
   PHP-FPM path), Docker secret-file permissions in `production-stack`.
   Fix the cause; do not weaken a check to make it pass.
5. When all four jobs are green, tick "CI workflow executed and green" in
   SECURITY.md's checklist.

The logs contain only throwaway CI passwords; no repository secrets are used.
