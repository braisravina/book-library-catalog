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

## 0. Ingress through the existing Cloudflare Tunnel

**Topology (confirmed by you).** The tunnel already exists, and cloudflared
runs on the Docker host as the `cloudflared` systemd service. The Digital
Library needs **one new route** on that tunnel. No cloudflared container,
service or tunnel is added, and no other route, website or DNS record
changes.

```
visitor --HTTPS--> Cloudflare edge --existing tunnel--> cloudflared (systemd, Docker host)
        --HTTP, loopback--> http://127.0.0.1:<DLA_HTTP_PORT>   (the one published port)
        --> nginx:8080 --FastCGI (internal network)--> php-fpm --(internal)--> MySQL
```

- **Only nginx is published, on `127.0.0.1` only.** The nginx entrypoint
  refuses any other bind address, so the origin is never reachable from the
  LAN or the internet. PHP-FPM and MySQL have no host port.
- **Fail closed.** Compose refuses to run until `DLA_HTTP_PORT`,
  `DLA_EDGE_SUBNET` and `DLA_EDGE_GATEWAY` are set, because they have no
  defaults. nginx refuses to start without a client IP policy and
  `DLA_TRUSTED_PROXY`. With `required`, the policy must trust exactly
  `DLA_EDGE_GATEWAY` and no other address. ✅ local (entrypoint tests) · 🧪 CI

### 0.1 Values to check on the Docker host (read-only commands)

| Setting | Value | Status |
|---|---|---|
| `DLA_HTTP_BIND` | `127.0.0.1` | decided (set in the template) |
| `DLA_HTTP_PORT` | `8089` | **candidate**: confirm it is free |
| `DLA_EDGE_SUBNET` / `DLA_EDGE_GATEWAY` | `172.30.89.0/29` / `172.30.89.1` | **candidates**: confirm no overlap |
| Tunnel route | `library.bravina.co.uk` → `http://127.0.0.1:<DLA_HTTP_PORT>` | you add it (0.3) |

```bash
ss -Hltn 'sport = :8089'                                   # must print nothing
docker ps --format '{{.Names}} {{.Ports}}' | grep -F ':8089->' || echo "8089 not used by a container"
docker network inspect $(docker network ls -q) --format '{{.Name}} {{range .IPAM.Config}}{{.Subnet}} {{end}}'
ip -4 route                                                # no route may overlap 172.30.89.0/29
```

If either candidate is taken, pick another free port or `/29` and set it in
`.env.production`. If you change the port, use the same one in the tunnel
route.

### 0.2 Client IP: why the edge gateway, and what it means

`CF-Connecting-IP` proves nothing by itself: anyone who can open a TCP
connection to nginx can send one. It is meaningful only on connections from
cloudflared, because the Cloudflare edge sets it (replacing any value a
visitor sends) and cloudflared forwards it.

cloudflared connects to `127.0.0.1:<port>`. Docker's port proxy (or its
loopback NAT) then opens the connection to nginx from the gateway of the
`edge` network. That gateway is therefore the only peer nginx ever sees
through the published port. Its subnet is fixed so the address never
changes. Hence:

- nginx believes `CF-Connecting-IP` only from `DLA_EDGE_GATEWAY` (one
  address), reads only that header, and does not walk it
  (`real_ip_recursive off`).
- Every forwarding header is removed before PHP; the application uses only
  `REMOTE_ADDR`.
- With the policy active, requests through the port that carry no valid
  `CF-Connecting-IP` get 403, except `/health`. This includes a plain `curl`
  on the host.
- The access log shows the client address and `peer=` (the TCP peer).
- **Residual risk:** every process on the Docker host can connect to
  `127.0.0.1:<port>` and set the header. Keep the host's local users and
  services trusted.

Until the policy is installed, use the trust-nothing policy with
`DLA_TRUSTED_PROXY=none` (first start only, 0.4). Every visitor then shares
the gateway address: one per-IP login bucket for everyone, and the gateway
address in the audit log.

### 0.3 Adding the route to the existing tunnel (you, in your console)

Do this once the origin answers (section 7, "On the published port"). Back
up the tunnel configuration first and change nothing else.

**Tunnel managed in the Cloudflare dashboard** (Zero Trust → Networks →
Tunnels; the labels vary between dashboard versions):

1. Open the **existing** tunnel → **Configure** → **Public hostname**
   (newer dashboards: **Published application routes**) → **Add**.
2. Subdomain `library`, domain `bravina.co.uk`, path empty.
3. Service type **HTTP**, URL `127.0.0.1:8089` (your confirmed port).
4. Additional application settings → HTTP settings: leave **HTTP Host
   Header** empty (cloudflared then forwards `library.bravina.co.uk`), or set
   it to exactly `library.bravina.co.uk`; any other value gets 400. Use
   `127.0.0.1`, not `localhost`: `localhost` may resolve to `::1`, where
   nothing listens.
5. Save. The dashboard creates the proxied DNS record for
   `library.bravina.co.uk` only. If a record with that name already exists,
   stop and review it; leave other records alone.

**Tunnel managed by a local `config.yml`** (on the Docker host, usually
`/etc/cloudflared/config.yml`): add one rule **above** the final catch-all
`- service: http_status:404`. Rules match in order, so the existing
hostnames are unaffected:

```yaml
  - hostname: library.bravina.co.uk
    service: http://127.0.0.1:8089
```

```bash
sudo cp -a /etc/cloudflared/config.yml /etc/cloudflared/config.yml.bak-$(date +%F)
sudo cloudflared tunnel --config /etc/cloudflared/config.yml ingress validate
sudo cloudflared tunnel --config /etc/cloudflared/config.yml ingress rule https://library.bravina.co.uk   # must show the new rule
sudo cloudflared tunnel route dns <existing-tunnel-name-or-id> library.bravina.co.uk
sudo systemctl restart cloudflared    # briefly interrupts the other sites on this tunnel
```

**HTTPS at the edge.** `Secure` `__Host-` cookies and HSTS come from the
application's configuration, whatever the origin scheme. A first
plain-HTTP visit must still be redirected by Cloudflare. If "Always Use
HTTPS" is not already on, add a redirect rule for `library.bravina.co.uk`
only, rather than changing zone-wide settings that affect the other sites.

### 0.4 Activation order

1. Set `DLA_HTTP_PORT`, `DLA_EDGE_SUBNET` and `DLA_EDGE_GATEWAY` (0.1).
   Copy the trust-nothing policy:
   `cp docker/nginx/client-ip.conf docker/nginx/production/client-ip.conf`.
   Set `DLA_TRUSTED_PROXY=none`, then sections 4–7.
2. Add the route (0.3), then load `https://library.bravina.co.uk/login`
   once.
3. Confirm the peer:
   `dc logs nginx | grep -o 'peer=[^ ]*' | sort | uniq -c`. It must show
   only `peer=<DLA_EDGE_GATEWAY>`. If it shows anything else, stop and share
   that line.
4. Install the trusted-peer policy and restart nginx:

   ```bash
   gw=$(sed -n 's/^DLA_EDGE_GATEWAY=//p' .env.production)    # e.g. 172.30.89.1
   sed "s/<CLOUDFLARED_ADDRESS>/${gw}/" docker/nginx/client-ip.cloudflared.conf.example \
     > docker/nginx/production/client-ip.conf
   sed -i 's/^DLA_TRUSTED_PROXY=none$/DLA_TRUSTED_PROXY=required/' .env.production
   dc up -d --wait nginx
   ```

5. Verify (section 7, "Through the tunnel").

The read-only `docker/discovery/docker-host.sh` remains available. It is
optional: the checks in 0.1 are enough.

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
- The values of section 0.1 checked on the Docker host (free port, free
  `edge` subnet).

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
# Section 0.4, step 1: in .env.production set DLA_HTTP_PORT, DLA_EDGE_SUBNET,
# DLA_EDGE_GATEWAY and DLA_TRUSTED_PROXY=none (DLA_HTTP_BIND=127.0.0.1 is
# preset), and copy the trust-nothing policy to docker/nginx/production/.
export APP_IMAGE_TAG="$(git rev-parse --short HEAD)"   # one image tag per release (rollback)
dc() { docker compose -f docker-compose.prod.yml --env-file .env.production "$@"; }
dc config --quiet && dc config | less        # review: no unexpected ports, networks or mounts
dc config | grep -B2 -A3 'published'         # exactly one: nginx, host_ip 127.0.0.1, target 8080
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
probes work from inside the container. ✅ local (application and nginx
behaviour) · 🧪 CI (containers)

**On the published port** (on the Docker host; this is what cloudflared
connects to). 🧪 CI · ⚠ production:

```bash
origin="http://127.0.0.1:${DLA_HTTP_PORT}"     # the port from .env.production
ss -Hltn "sport = :${DLA_HTTP_PORT}"          # only 127.0.0.1, never 0.0.0.0 / [::]
# With DLA_TRUSTED_PROXY=none (first start):
curl -fsS -H 'Host: library.bravina.co.uk' "$origin/health"        # {"status":"ok"}
curl -fsS -o /dev/null -D - -H 'Host: library.bravina.co.uk' "$origin/login" | grep -i '^set-cookie'
#   expect: __Host-dla_session=...; path=/; secure; HttpOnly; SameSite=Lax
curl -s -o /dev/null -w '%{http_code}\n' -H 'Host: other.example' "$origin/login"   # 400
```

Once the trusted-peer policy is active (0.4, step 4), `/login` without a
`CF-Connecting-IP` header returns 403 and `/health` still returns 200. Both
are expected.

**Through the tunnel** (after 0.3 and 0.4). 🧪 CI (simulated: a local
client sending the header) · ⚠ production:

- `https://library.bravina.co.uk/login` loads over HTTPS, and `http://`
  redirects to `https://`. The browser shows the `__Host-` session cookie
  as Secure.
- Sign in, then run `SELECT ip_address FROM audit_log ORDER BY id DESC LIMIT 1`
  (ops container). It must show **your** public address. The nginx access
  log line must show your address with `peer=<DLA_EDGE_GATEWAY>`.
- From another machine, `http://<Docker host LAN address>:<port>/` must not
  connect, because the port is on loopback only.
- Invitation links open at `https://library.bravina.co.uk/invite/redeem#…`.

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
| Compose: "required variable DLA_… is missing a value" | set `DLA_HTTP_PORT`, `DLA_EDGE_SUBNET`, `DLA_EDGE_GATEWAY` in `.env.production` (0.1) |
| nginx exits: "dla-nginx: refusing to start" | the message: `DLA_HTTP_BIND` not loopback, the client IP policy, `DLA_TRUSTED_PROXY`, or a policy address other than `DLA_EDGE_GATEWAY` (section 0) |
| "port is already allocated" / "address already in use" at `up` | the port is taken; choose another free port (0.1) and update the tunnel route |
| "Pool overlaps with other one on this address space" at `up` | the `edge` subnet is in use; choose another free `/29` (0.1) |
| Cloudflare 502/530, cloudflared log "connection refused" | the route's URL must be `http://127.0.0.1:<DLA_HTTP_PORT>`; `dc ps nginx`; `journalctl -u cloudflared` |
| 403 on every page through the tunnel | `peer=` in the access log is not `DLA_EDGE_GATEWAY` (0.4, step 3) |
| Every page 503 | `dc run --rm ops php bin/console maintenance:status` (maintenance mode or an incomplete restore) |
| 400 on every page | the `Host` header the tunnel forwards (route "HTTP Host Header" must be empty or `library.bravina.co.uk`) |
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
| Cut public access at the edge | remove or disable only the `library.bravina.co.uk` route of the tunnel (your Cloudflare console); the other sites keep running | kept |

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
