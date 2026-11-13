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
        --> nginx:8080 --FastCGI (internal network)--> php-fpm --(internal)--> MariaDB
```

- **Only nginx is published, on `127.0.0.1` only.** The nginx entrypoint
  refuses any other bind address, so the origin is never reachable from the
  LAN or the internet. PHP-FPM and MariaDB have no host port.
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
   Set `DLA_TRUSTED_PROXY=none`, then sections 3–7.
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
- The release to deploy pushed to the bare repository
  `/home/brais/git/digital-library.git` and checked out from it into the
  working tree `/opt/digital-library` (3.2; section 4 for updates). Pushing
  to the bare repository deploys nothing by itself.
- The directories of section 3 initialised: on the system SSD the database data,
  secrets and guard directories in `/opt/digital-library-data` (outside
  the working tree), and the root-owned installed preflight;
  on the storage HDD (mounted at `/mnt/bravina-shared`) covers, backups and
  storage, plus room for one restore (see
  [Resource planning](#resource-planning)).
- Your password manager or vault for the secrets below.
- The values of section 0.1 checked on the Docker host (free port, free
  `edge` subnet).

## 2. Production secrets

One random value per file, in `/opt/digital-library-data/secrets` on the
system SSD. That is outside the working tree, so it is never committed,
never part of a build context, and never touched by a deployment (a
`git checkout` run as you, which also cannot write there: root-only,
`0700`). Each value is used once. Create the directories first
(3.2), then:

```bash
data=/opt/digital-library-data
for name in db_root_password db_password db_migration_password; do
  sudo sh -c "umask 077 && openssl rand -hex 32 > '$data/secrets/$name'"
done
sudo chown 999:999 "$data/secrets/db_root_password"
sudo chmod 0400 "$data/secrets/db_root_password"
sudo chown 82:999 "$data/secrets/db_password" "$data/secrets/db_migration_password"
sudo chmod 0440 "$data/secrets/db_password" "$data/secrets/db_migration_password"
```

Store copies in your password manager: restoring a backup on a new host
needs them. An optional `GOOGLE_BOOKS_API_KEY` is added the same way, as
another secret file plus an entry in `docker-compose.prod.yml` (not yet
present). The owners are `999`, the `mysql` user of the MariaDB image, and
`82`, the `www-data` user of the PHP image. Docker reads the files as root
and shows each container the file's own owner and mode. The preflight
checks owners and modes, never the values. 🧪 CI (same commands) ·
⚠ production

## 3. Persistent storage, database and filesystem permissions

- **Database accounts** (created by `docker/mysql/init/01-create-users.sh` on
  the first start of an empty data directory): `library_app` (SELECT,
  INSERT, UPDATE, DELETE) for the web container; `library_migrator` (plus
  CREATE, ALTER, DROP, INDEX, REFERENCES) for the ops container;
  `root@localhost` only inside the db container (`MARIADB_ROOT_HOST`, so
  no `root@'%'`). The MariaDB image also creates `healthcheck@localhost`,
  `@127.0.0.1` and `@::1` with USAGE only (no privilege; password in the
  data directory), and the locked `mariadb.sys@localhost`. The script
  writes `.dla-init-complete` last; the guard refuses a data directory
  without it. ✅ local (script; real MariaDB 11.4.13 init) · 🧪 CI
- **Roles in code:** the web container (`APP_ROLE=web`) refuses migration or
  root credentials and every operations command; production operations run
  only in the `ops` container. ✅ local

### 3.1 Layout: system SSD and storage HDD

**Confirmed by you.**

- **Storage HDD:** ext4, UUID `ad62670f-861d-47a4-a68d-967272d27866`,
  mounted at `/mnt/bravina-shared` (`rw,relatime`; fstab `defaults,nofail`).
  The mountpoint is `root:root` 0755. About 907 GB free.
- **Split:** the database lives on the system SSD, and covers, backups and
  application storage on the HDD.
- **Never used:** `/mnt/dockerdata`.
- **Mounts:** all bind mounts with `create_host_path: false`. No named
  volumes.
- **Code:** the bare repository `/home/brais/git/digital-library.git` holds
  the Git objects only. Its working tree is `/opt/digital-library` itself,
  checked out with `--work-tree` (no `.git` inside, no nested `app/`), and
  is the Compose project directory. Same convention as `/opt/bravina`. It
  holds code and configuration only.
- **Data on the SSD:** `/opt/digital-library-data` (`DLA_DATA_ROOT`), a
  sibling of the working tree, never inside it.

| Path | Owner | Mode | Mounted at | Containers |
|---|---|---|---|---|
| **Git** `/home/brais/git/digital-library.git` (bare) | you | as created | (never) | none |
| **SSD** `/opt/digital-library`: the working tree, Compose project directory, build context | you (the deploying administrator; not root, not 82 or 999) | `0755` | (not mounted) | none |
| tracked files (`docker-compose.prod.yml`, `docker/`, `src/`, …) | you | from Git, never group- or world-writable | read-only repository files (`docker/…:ro`), images | |
| `.env.production`, `docker/nginx/production/client-ip.conf` (untracked) | you | `0640` / `0644` | | |
| **SSD** `/opt/digital-library-data` (`DLA_DATA_ROOT`) | `root:root` | `0755` | (not mounted) | none |
| `mysql/` | `999:999` | `0700` | `/var/lib/mysql` | db only |
| `secrets/` (the three secret files, section 2) | `root:root` | `0700` | `/run/secrets/<name>` (files) | as in section 2 |
| `mysql-init-guard/` | `root:root` | `0755` | `/run/dla-db-init` (read-only) | db only |
| `/usr/local/sbin/digital-library-preflight` (installed copy of `docker/storage/preflight.sh`) | `root:root` | `0755` | | run by `digital-library.service` |
| **HDD** `/mnt/bravina-shared/digital-library` (`DLA_STORAGE_ROOT`) | `root:root` | `0700` | (not mounted) | none |
| `storage/` (tmp, cache, logs, maintenance flag, operation lock) | `82:82` | `0750` | `/var/www/html/storage` | php, ops |
| `storage/covers/` (mountpoint stub, hidden by the covers mount) | `root:root` | `0755` | | |
| `storage/backups/` (mountpoint stub; the web container sees this empty, unusable directory) | `root:root` | `0000` | | |
| `covers/` | `82:82` | `0750` | `/var/www/html/storage/covers` | php, ops |
| `backups/` | `82:82` | `0700` | `/var/www/html/storage/backups` | ops only |
| `books/` | reserved for future PDF/EPUB support | | **not created, not mounted** | |

**Code and data are physically separate.** The existing Compose file,
Dockerfiles, unit and runbook all treat the repository root as the Compose
project directory and build context (relative `./docker/…` mounts,
`context: .`), so the repository root *is* `/opt/digital-library`, with no
extra `app/` or `docker/` level. Production data is in
`/opt/digital-library-data`, outside it:

1. **Git:** a checkout writes only inside `/opt/digital-library`, and only
   tracked paths; nothing under `mysql`, `secrets` or `mysql-init-guard`
   is tracked (a unit test checks `git ls-files`), and `.gitignore` lists
   them anyway in case a copy ever appears in the tree.
2. **Docker builds:** the build context is `/opt/digital-library`, which
   does not contain the data. The Dockerfiles `COPY` explicit paths only
   (never `COPY .`), and `.dockerignore` excludes those names too, with no
   exception (`!`) patterns.
3. **Permissions:** you deploy as yourself, not root. The data root is
   `root:root`; `mysql/` (999, `0700`) and `secrets/` (root, `0700`) are
   neither writable nor readable by you.
4. **Preflight:** refuses `mysql`, `secrets`, `mysql-init-guard`, `data`
   or `.git` inside `/opt/digital-library`, and checks the data root's
   owners and modes (3.3).

Covers, uploads and backups are on the HDD, outside the working tree.

Where the owners come from:

- **`82:82`**: `docker/php/Dockerfile`, `prod` stage, runs as `USER www-data`.
  The base image is `php:8.4.27-fpm-alpine3.24`, where `www-data` is uid 82,
  gid 82. CI checks `id -u` = 82.
- **`999:999`**: the `mariadb:11.4.13-noble` image (no Dockerfile of ours)
  creates the `mysql` user and group with uid/gid 999 (checked in the image's
  `/etc/passwd`). Its entrypoint starts as root, fixes ownership inside
  `/var/lib/mysql` and runs `mariadbd` as `mysql`. The directory keeps the
  name `mysql/` (MariaDB also uses `/var/lib/mysql`).
- **nginx** (`USER nginx`, uid 101) mounts no storage.
- The roots' modes only affect users on the host. Containers see their
  mounts directly and never walk the host path.

**Database isolation.**

- Only db mounts `/opt/digital-library-data/mysql`. That directory is `0700` for
  uid 999, which no application container uses. php and nginx mount nothing
  from the SSD (CI checks that neither can see `/var/lib/mysql`).
- It is outside the working tree and every build context (above).
  `.gitignore` and `.dockerignore` also exclude database file patterns
  (`*.ibd`, `ibdata*`, binlogs, `data/`) anywhere, in case one is ever
  copied into the tree.

✅ local (static and behaviour tests; the bare-repository deployment
rehearsed on a local copy) · 🧪 CI (same layout: the checkout as the
working tree, the data root `/srv/dla-ci-data` outside it, the HDD under
`/srv/dla-ci`) · ⚠ production

### 3.2 One-time initialisation (you, on the Docker host)

Each command creates one directory explicitly. Nothing is recursive, and
nothing that exists inside the HDD or the database directory is modified.
The HDD mountpoint `/mnt/bravina-shared` keeps `root:root` 0755, and
`books/` is not created. Run it as yourself (the deploying administrator,
the owner of the bare repository); `sudo` only where shown.

**a. The bare repository** (Git objects only). If you have not created it
yet, for example:

```bash
git init --bare --initial-branch=main /home/brais/git/digital-library.git
```

Then push the reviewed release to it from your workstation (your remote
name and host). **A push deploys nothing:** there is no hook, and the working
tree changes only when you run the checkout below (or section 4).

**b. Directories and the working tree:**

```bash
# 1. The storage HDD is mounted with the exact UUID. If not, stop here.
findmnt --mountpoint /mnt/bravina-shared --output SOURCE,UUID,FSTYPE,OPTIONS
test "$(findmnt --mountpoint /mnt/bravina-shared --noheadings --output UUID)" = ad62670f-861d-47a4-a68d-967272d27866
app=/opt/digital-library
data=/opt/digital-library-data
root=/mnt/bravina-shared/digital-library
repo=/home/brais/git/digital-library.git
# 2. First time only: never re-initialise an existing database or HDD directory.
test ! -e "$data/mysql"
test ! -e "$root"
# 3. The working tree /opt/digital-library (code only), owned by you, 0755.
sudo install -d -o "$(id -un)" -g "$(id -gn)" -m 0755 "$app"
# 4. The code, from the bare repository (no clone, no .git in the tree).
#    The release must not contain the data directories:
git --git-dir="$repo" log -1 --format='%H %s' main      # the commit you reviewed
test -z "$(git --git-dir="$repo" ls-tree --name-only main -- mysql secrets mysql-init-guard)"
git --git-dir="$repo" --work-tree="$app" checkout -f main
test ! -e "$app/.git"
# 5. System SSD data root, outside the working tree.
sudo install -d -o root -g root -m 0755 "$data"
sudo install -d -o 999 -g 999 -m 0700 "$data/mysql"
sudo install -d -o root -g root -m 0700 "$data/secrets"
sudo install -d -o root -g root -m 0755 "$data/mysql-init-guard"
# 6. The privileged preflight: a root-owned copy outside the working tree,
#    from the release you reviewed (repeat after any change to it: section 4).
sudo install -o root -g root -m 0755 "$app/docker/storage/preflight.sh" /usr/local/sbin/digital-library-preflight
# 7. Storage HDD (owners and modes: 3.1).
sudo install -d -o root -g root -m 0700 "$root"
sudo install -d -o 82 -g 82 -m 0750 "$root/storage"
sudo install -d -o 82 -g 82 -m 0750 "$root/storage/tmp" "$root/storage/logs" "$root/storage/cache"
sudo install -d -o root -g root -m 0755 "$root/storage/covers"
sudo install -d -o root -g root -m 0000 "$root/storage/backups"
sudo install -d -o 82 -g 82 -m 0750 "$root/covers"
sudo install -d -o 82 -g 82 -m 0700 "$root/backups"
# 8. No production data in the working tree:
for d in mysql secrets mysql-init-guard; do test ! -e "$app/$d"; done
# 9. Secrets: section 2. Then check (3.3); the HDD mountpoint must still be root:root 0755.
stat -c '%U:%G %a' /mnt/bravina-shared
```

The index of the working tree lives in the bare repository
(`/home/brais/git/digital-library.git/index`), so every Git command for the
deployed tree names both: `git --git-dir="$repo" --work-tree="$app" …`.
Never run `git clean -x`/`-X`, `rsync --delete` or a fresh `git clone`
into `/opt/digital-library`.

If a step fails, fix that one path with `chown`/`chmod` on that path alone.
Never use `chmod 777`, `-R`, or any change to the whole disk. ⚠ production

### 3.3 Preflight (read-only): first deployment or existing installation

`docker/storage/preflight.sh` only reads (`findmnt`, `stat`, `find`, `df`,
`grep`, `cmp`). It never writes, never reads secret values, and never talks
to Docker or systemd. It runs as root, so the copy that runs is the
root-owned **installed** one, `/usr/local/sbin/digital-library-preflight`
(3.2, step 6), never the working tree's (3.4 explains why).
`digital-library.service` runs it before every start (3.4). Run it
yourself before every manual `up`, update or restore. **You must name the
situation:**

```bash
sudo /usr/local/sbin/digital-library-preflight --env-file /opt/digital-library/.env.production --first-deployment       # only before the very first database start
sudo /usr/local/sbin/digital-library-preflight --env-file /opt/digital-library/.env.production --existing-installation  # every time after that
```

It exits 1 on any failure (then do not start the stack). It checks:

- **Configuration:** `DLA_STORAGE_ROOT` and `DLA_DATA_ROOT` each set exactly
  once, to exactly the confirmed paths; `DB_DATABASE` a plain schema name.
- **Storage HDD:**
  - exactly one filesystem at `/mnt/bravina-shared`, with the exact UUID,
    `ext4` and `rw`;
  - the mountpoint still `root:root` 0755;
  - `storage/`, `covers/`, `backups/` and the stubs on *that* filesystem
    (no other mount on top), not symlinks, with their owners and modes.
- **Installed copy:** `/usr/local/sbin/digital-library-preflight` exists,
  root-owned, not group- or world-writable. A note (not a failure) when
  the working tree's `docker/storage/preflight.sh` differs from the copy
  that runs: review the change and install it again (section 4).
- **Working tree (code and configuration only):**
  - `/opt/digital-library` is a directory, `0755`, not owned by a container
    user (82, 999); the env file is `/opt/digital-library/.env.production`
    (it refuses another `--env-file`);
  - no `.git`, `mysql`, `secrets`, `mysql-init-guard`, `mysql-data` or
    `data` in it (Git objects stay in the bare repository, data in
    `/opt/digital-library-data`);
  - `.env.production`, `docker-compose.prod.yml`, `docker/`,
    `docker/mysql/`, `docker/mysql/db-guard.sh`, `docker/mysql/init/` and
    `docker/mysql/my.cnf` (used by `docker compose` as root, or inside db)
    are owned by the tree's owner or root, not symlinks, and not group- or
    world-writable.
- **Data root (system SSD):**
  - `/opt/digital-library-data` `root:root` 0755; `mysql/`, `secrets/` and
    the three secret files (non-empty), and `mysql-init-guard/`, each with
    its owner and mode;
  - `mysql/` on the system filesystem, not on the HDD and not under
    `/mnt/dockerdata`.
- **Database state:**
  - `--first-deployment`: `mysql/` must be **empty**, and the root-owned
    authorisation file `mysql-init-guard/allow-empty-init` must
    exist (section 6).
  - `--existing-installation`: the MariaDB system schema `mysql/mysql/`,
    the completion marker `mysql/.dla-init-complete` **and** the application
    schema directory (`mysql/<DB_DATABASE>`) must exist; no MySQL 8 file
    (`mysql/mysql.ibd`) and no authorisation file may be present.
- **Space:** at least 20 GB free on the HDD (`--min-free-gb`).

✅ local (behaviour tests with simulated `findmnt`/`stat`/`find`) ·
⚠ production

### 3.4 Boot and restarts: `digital-library.service`

`docker.service` is **not** modified, so Docker, Jellyfin and your other
workloads start and run whether or not the storage HDD is there. The
Digital Library has its own systemd unit, and that unit is its **only**
boot path:

- **Docker never starts these containers by itself.** Every service has
  restart policy `"no"`. After a reboot, a Docker restart or an unclean
  shutdown (power loss), the daemon restores none of them, so no existing
  container can bypass the preflight. 🧪 CI (policies checked with
  `docker inspect`) · ⚠ production
- **The unit starts them after the checks.**
  `docker/storage/systemd/digital-library.service`, with the confirmed
  paths:
  - `BindsTo=` and `After=` the HDD's fstab mount unit
    `mnt-bravina\x2dshared.mount`;
  - `RequiresMountsFor=/opt/digital-library /opt/digital-library-data`;
  - `AssertPathIsMountPoint=/mnt/bravina-shared`;
  - `ExecStartPre=` the root-owned installed preflight
    (`/usr/local/sbin/digital-library-preflight`) with
    `--existing-installation`;
  - only then, `docker compose … up -d --no-recreate --no-build --pull never
    --wait`. This starts exactly the containers you last deployed and
    builds or downloads nothing.
- **Fail closed.** If any step fails, the unit fails and
  `ExecStopPost=` runs `docker compose … stop`.
- **Still fail closed without the unit.**
  - Every data mount is a bind with `create_host_path: false`. Compose
    refuses to create a container with a missing source, and Docker
    refuses to *start* one whose source is missing. The source is never
    created.
  - The database guard (`docker/mysql/db-guard.sh`, the db entrypoint) runs
    however db is started. It refuses to let MariaDB initialise a new, empty
    database unless a first deployment is authorised (section 6), and it
    refuses a data directory whose initialisation did not finish (no
    `.dla-init-complete`) or that holds MySQL 8 files (`mysql.ibd`).
  - 🧪 CI (HDD root removed; SSD database directory replaced by an empty
    one; SSD database directory removed: each refused, nothing created) ·
    ⚠ production

Install it once, **after the first deployment** (sections 4–7), from
`/opt/digital-library`:

```bash
command -v docker                          # the unit calls /usr/bin/docker; adjust ExecStart*/ExecStopPost if different
systemctl status 'mnt-bravina\x2dshared.mount'     # the fstab mount unit exists (systemd-escape --path --suffix=mount /mnt/bravina-shared)
sudo systemd-analyze verify docker/storage/systemd/digital-library.service
sudo install -o root -g root -m 0644 docker/storage/systemd/digital-library.service /etc/systemd/system/digital-library.service
sudo systemctl daemon-reload
dc up -d --wait                            # once, so the containers carry restart policy "no"
sudo systemctl enable digital-library.service
sudo systemctl restart digital-library.service && systemctl status digital-library.service
docker inspect -f '{{.Name}} {{.HostConfig.RestartPolicy.Name}}' $(dc ps -q)    # every line: no
```

Its `WorkingDirectory` is `/opt/digital-library`, the actual Compose
project directory, so the project name and the relative `./docker/…`
mounts are the same as when you run `dc` there by hand.

**What runs as root, and from where.**

- **The preflight is the only script systemd executes directly as root on
  the host.** Running it from the working tree would let anything that can
  write there (you, or a bad commit you checked out) change what root runs
  at the next boot, unreviewed, and could also make the checks pass
  silently. So the unit runs the root-owned installed copy
  `/usr/local/sbin/digital-library-preflight`: a checkout never changes it,
  and a new version takes effect only when you review it and run the
  `sudo install` of 3.2 again. The preflight itself fails if that copy is
  missing, not root-owned or writable by others, and notes when the
  working tree's copy differs. Nothing else on the host changes
  permissions: one new file in `/usr/local/sbin`.
- **`docker compose` still reads the working tree as root** (Compose file,
  `.env.production`, the `docker/mysql` files mounted into db). Whoever
  can edit those can run anything through Docker at the next start; that
  is inherent to Docker (membership of the `docker` group is equally
  root-equivalent). It is acceptable only because the working tree is
  writable by you alone, an administrator who already has `sudo`. The
  preflight refuses group- or world-writable files and symlinks there. Do
  not give other accounts write access to `/opt/digital-library`.
- **Alternative (stricter, your decision):** a root-owned working tree
  updated by running the Git checkout as root. That removes the remaining
  path, but runs Git as root against your user-owned bare repository (Git's
  `safe.directory` check, root-owned index files in it); not chosen here.

⚠ production

| Event | What happens |
|---|---|
| Boot, HDD mounted and correct, database present | Docker starts (no Digital Library containers). The HDD mount, then the unit: preflight ok, `up --wait`, the stack runs. |
| Boot, HDD missing (fstab `nofail`) | The mount unit fails, so the unit fails ("dependency failed"). **No container starts, db included**, although the database is on the SSD. Other workloads are unaffected. |
| Boot, a different or misconfigured HDD at the mountpoint | The preflight fails (UUID, type, `ro`, owner/mode, missing directory), the unit fails, and the stack stays stopped. |
| Boot, the SSD database directory is empty, replaced or missing | The preflight fails (`mysql/`, `.dla-init-complete` or schema missing, MySQL 8 files present, or the directory missing). Even if db is started some other way, the guard refuses to initialise or to start an incomplete database, or Docker refuses the missing mount source. |
| Power loss, then boot | Same as the rows above. No container has a restart policy, so none starts before the unit. |
| `sudo systemctl restart docker` (e.g. a Docker package upgrade) | Docker restarts your other workloads per their own policies. This unit is restarted with it (`Requires=`), so the preflight runs again. |
| The Docker daemon crashes | Containers stop and are not restarted by Docker. Run `sudo systemctl restart digital-library`. |
| A container crashes or is OOM-killed while running | Docker does **not** restart it (policy `"no"`). The site answers 502/500 until you run `sudo systemctl restart digital-library` (preflight, then start). Watch `/health` externally (section 10). |
| `dc stop php` (restore procedure) | php stays stopped until you start it. |
| `sudo systemctl stop digital-library` | `docker compose stop`: the stack stops and the containers are kept. |

Trade-off: automatic restart of a crashed container is given up so that no
container can ever start without the preflight.

**Recovery after a boot without the HDD:** `systemctl status
digital-library` shows the failed dependency or preflight line. Mount the
disk (`sudo mount /mnt/bravina-shared`), run 3.3 with
`--existing-installation`, then `sudo systemctl start digital-library`.
**Drill (recommended once, in a maintenance window):**
1. `sudo systemctl stop digital-library`.
2. `sudo umount /mnt/bravina-shared`.
3. `sudo systemctl start digital-library` must fail.
4. `ls -A /mnt/bravina-shared` must print nothing.
5. `sudo mount /mnt/bravina-shared`, then `sudo systemctl start
   digital-library`.

⚠ production

**Optional (owner decision, maintenance window):** with the stack stopped
and the HDD unmounted, `sudo chattr +i /mnt/bravina-shared` on the SSD's
empty directory. Nothing, root included, can then create files there
while the disk is absent. Owner and mode do not change. Remount and rerun
3.3. ⚠ production

### 3.5 If the HDD disappears while the stack is running

The database is on the SSD and keeps running. Covers, backups and
application storage are on the HDD, and the containers hold their own bind
mounts of it, so they **never write HDD data to the SSD**. ⚠ production
(kernel behaviour, not testable in CI)

- **Clean unmount via systemd** (`systemctl stop mnt-bravina\x2dshared.mount`):
  `BindsTo=` stops `digital-library.service` first (`docker compose stop`,
  db included), then the disk is unmounted.
- **`umount /mnt/bravina-shared` by hand:** systemd sees the mount unit stop,
  and `BindsTo=` stops the unit and the whole stack. Until then the
  containers kept using the disk through their own mounts.
- **The HDD physically fails or is disconnected:** I/O errors on covers,
  uploads, backups and the maintenance flag. Pages with covers fail, and
  uploads and backups fail. The database is unaffected. Nothing restarts
  anything (policy `"no"`). The kernel may leave the mount in place (still
  "mounted" but failing), so the unit may stay "active" until you act.
  - Recovery:
    1. `sudo systemctl stop digital-library`.
    2. Unmount.
    3. Reconnect the disk.
    4. `sudo e2fsck -f /dev/disk/by-uuid/ad62670f-861d-47a4-a68d-967272d27866`
       (unmounted).
    5. `sudo mount /mnt/bravina-shared`.
    6. 3.3 with `--existing-installation`.
    7. `sudo systemctl start digital-library`.
  - Covers written in the last moments may be missing. A restore of the
    latest verified backup writes back the cover files it contains
    (OPERATIONS.md).
- **Backups and the database are on different disks now.** A failed SSD
  loses the database but not the backups on the HDD, and the reverse. Off-host
  copies (OPERATIONS.md) are still needed against losing the host.
- **The database directory on the SSD is lost or emptied:** the
  `--existing-installation` preflight fails, and the guard refuses to
  initialise a new database. Restore the latest verified backup into a
  deliberately re-initialised database: follow section 6's first-deployment
  authorisation, then OPERATIONS.md, "Manual recovery checklist".
- **Detection:** external monitoring of `https://library.bravina.co.uk/health`
  (section 10), plus `journalctl -u digital-library` and `dmesg` for I/O
  errors.

## 4. Deployment preparation

Deploying a release is an explicit checkout from the bare repository into
the working tree (first time: 3.2 did it). You stop if a tracked file was
edited locally (`git diff` compares the tree with the last deployed
release; `git status` would also list the newly pushed changes). The
checkout never removes untracked or ignored files, and cannot write into
`mysql/` or `secrets/` (you are not root):

```bash
cd /opt/digital-library
repo=/home/brais/git/digital-library.git
P=/usr/local/sbin/digital-library-preflight
g() { git --git-dir="$repo" --work-tree=/opt/digital-library "$@"; }
g diff --exit-code --stat     # prints nothing: no local edits to tracked files (else stop and look)
rev=$(git --git-dir="$repo" rev-parse --verify 'main^{commit}'); echo "$rev"   # the commit you reviewed and pushed
test -z "$(git --git-dir="$repo" ls-tree --name-only "$rev" -- mysql secrets mysql-init-guard)"
g checkout -f main && test "$(g rev-parse HEAD)" = "$rev"
test -z "$(g status --porcelain --untracked-files=no)" && test ! -e .git
cmp docker/storage/preflight.sh "$P"   # differs: review the change (diff -u "$P" docker/storage/preflight.sh), then: sudo install -o root -g root -m 0755 docker/storage/preflight.sh "$P"
cp .env.production.example .env.production  # first time only; then review every value
# Section 0.4, step 1: in .env.production set DLA_HTTP_PORT, DLA_EDGE_SUBNET,
# DLA_EDGE_GATEWAY and DLA_TRUSTED_PROXY=none (DLA_HTTP_BIND=127.0.0.1 is
# preset), and copy the trust-nothing policy to docker/nginx/production/.
export APP_IMAGE_TAG="$(g rev-parse --short HEAD)"   # one image tag per release (rollback)
dc() { docker compose -f docker-compose.prod.yml --env-file .env.production "$@"; }
dc config --quiet && dc config | less        # review: no unexpected ports, networks or mounts
dc config | grep -B2 -A3 'published'         # exactly one: nginx, host_ip 127.0.0.1, target 8080
dc --profile ops config | grep -A5 'type: bind' | grep -E 'source|create_host_path'   # /opt/digital-library-data/{mysql,mysql-init-guard} and /mnt/bravina-shared/digital-library/..., all false
sudo "$P" --env-file /opt/digital-library/.env.production --existing-installation    # section 3.3 (first deployment: section 6)
```

`.env.production` holds no secrets (only `*_FILE` paths) but is still
private: `chmod 0640`. It is untracked and ignored, so a checkout never
changes it; compare it with `.env.production.example` after each update.
⚠ production

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

### 5.1 Database engine and the production CPU

Production runs **MariaDB 11.4 LTS** (`mariadb:11.4.13-noble`, pinned by
index digest). The Docker host's CPU (Intel Pentium E5300) has SSE2, SSE3
and SSSE3 but not SSE4.1, SSE4.2 or POPCNT. The `mysql:8.4` image is built
on Oracle Linux 9, whose glibc requires x86-64-v2 (SSE4.2, POPCNT), so it
cannot start there ("Fatal glibc error: CPU does not support x86-64-v2").
The MariaDB image is Ubuntu 24.04 based: its glibc and `mariadbd` require
only the x86-64 baseline, and faster CRC-32 code is used only when `cpuid`
reports the instructions. Never use the `-ubi` (RHEL 9) MariaDB tags.
ARCHITECTURE.md, "Production database", has the evidence and the
compatibility rules.

Before the first deployment, prove it **on the Docker host** (read-only:
it starts no database, mounts nothing, and the container is removed):

```bash
cd /opt/digital-library
img=$(docker compose -f docker-compose.prod.yml --env-file .env.production config --format json | jq -r .services.db.image)
docker run --rm --network none --entrypoint mariadbd "$img" --version      # prints "mariadbd  Ver 11.4.13-MariaDB..."; no glibc error
docker run --rm --network none --entrypoint php "digital-library-php:${APP_IMAGE_TAG:-prod}" -r 'echo PHP_VERSION, PHP_EOL;'
docker run --rm --network none --entrypoint nginx "digital-library-nginx:${APP_IMAGE_TAG:-prod}" -v
```

A "Fatal glibc error" or "Illegal instruction" in any of them means
stop: do not start the stack. 🧪 CI (static CPU-baseline check of the
images) · ⚠ production (the only check on the real CPU)

**A server prepared for the MySQL image** (prepared, never started): the
directories, owners, secrets and `.env.production` stay exactly as they
are (MariaDB uses uid/gid 999 too, and the same secret files). Only the
release changes. Check that the database directory is still empty and
install the reviewed preflight again (section 3.2), because it changed:

```bash
sudo find /opt/digital-library-data/mysql -mindepth 1 -print -quit    # must print nothing
sudo install -o root -g root -m 0755 /opt/digital-library/docker/storage/preflight.sh /usr/local/sbin/digital-library-preflight
docker image rm mysql:8.4.13 2>/dev/null || true                      # optional: the unused MySQL image
```

**After a MariaDB image update** (a new pinned digest): with a backup
taken, start db and run `mariadb-upgrade` once (MARIADB_AUTO_UPGRADE is
deliberately off): docs/OPERATIONS.md, "Database engine".

## 6. Database: first deployment or existing installation

**First deployment only: a new, empty database, explicitly authorised.**
Start db once with the authorisation, then withdraw it:

```bash
sudo touch /opt/digital-library-data/mysql-init-guard/allow-empty-init     # authorise ONE new database
sudo /usr/local/sbin/digital-library-preflight --env-file /opt/digital-library/.env.production --first-deployment   # mysql/ empty, authorised
dc up -d --wait db                         # the guard logs: "initialising a NEW database"
sudo rm /opt/digital-library-data/mysql-init-guard/allow-empty-init        # withdraw it immediately
dc run --rm ops php bin/console config:check --strict
dc run --rm ops php bin/console migrate
dc run --rm ops php bin/console migrate:status        # "Database engine: MariaDB"; nothing pending, MODIFIED or OTHER-ENGINE
dc run --rm ops php bin/console user:create --email=<you> --name="<name>" --role=admin   # password from STDIN
sudo /usr/local/sbin/digital-library-preflight --env-file /opt/digital-library/.env.production --existing-installation   # from now on, always this mode
```

**Existing installation (every later deployment):** take a backup
(section 8) before migrating, then:

```bash
sudo /usr/local/sbin/digital-library-preflight --env-file /opt/digital-library/.env.production --existing-installation   # never start db if it fails
dc up -d --wait db
dc run --rm ops php bin/console config:check --strict
dc run --rm ops php bin/console migrate
dc run --rm ops php bin/console migrate:status        # nothing pending, MODIFIED or OTHER-ENGINE
```

The guard in the db container (`docker/mysql/db-guard.sh`) is the safety
net. If `/opt/digital-library-data/mysql` is empty and no authorisation file is
present, db refuses to start ("refusing to initialise a new, empty
database") instead of silently creating a new database. It also refuses a
directory that has files but no database, a database whose initialisation
did not finish (no `.dla-init-complete`: the accounts may be missing) and
MySQL 8 files. Migrations are forward-only; one released migration has a
MariaDB variant (ARCHITECTURE.md, "Migrations").
✅ local (guard behaviour tests) · 🧪 CI · ⚠ production

## 7. Health checks

```bash
dc up -d --wait                       # waits for db, php and nginx to be healthy
dc ps
dc exec -T nginx wget -q -O - http://127.0.0.1:8080/health   # {"status":"ok"}: nginx -> PHP -> MariaDB
dc exec -T php php bin/console config:check --strict
```

The containers' health checks: nginx `GET /health` every 30 s; php a TCP
connect to PHP-FPM every 30 s; MariaDB `mariadb-admin ping` every 10 s (no
credentials: it succeeds whenever the server answers, even "access denied"). `/health`
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
  `export APP_IMAGE_TAG=<previous>; g checkout -f <previous>; dc up -d --wait`
  (`g` as in section 4; this detaches the bare repository's `HEAD` until
  the next `g checkout -f main`).
  If it did migrate: migrations are forward-only, so restore the backup taken
  before the migration (section 6), then start the previous images. ⚠ production

## 10. Monitoring and troubleshooting

| Symptom | Look at |
|---|---|
| A container restarts or is unhealthy | `dc ps`, `dc logs --tail 200 <service>` |
| php exits at start: "refusing to start PHP-FPM" | `dc run --rm ops php bin/console config:check` (names keys, never values) |
| Compose: "required variable DLA_… is missing a value" | set `DLA_HTTP_PORT`, `DLA_EDGE_SUBNET`, `DLA_EDGE_GATEWAY` in `.env.production` (0.1); `DLA_STORAGE_ROOT` (3.1) |
| Stack down after a reboot; `systemctl status digital-library` failed | dependency failed: the storage disk is not mounted; preflight FAIL lines: wrong disk, owner or mode. Section 3.4, "Recovery after a boot without the disk". Never create the directory by hand on the system disk |
| A container exited and stays down | expected (restart policy `"no"`): `dc logs <service>`, then `sudo systemctl restart digital-library` (3.4) |
| nginx exits: "dla-nginx: refusing to start" | the message: `DLA_HTTP_BIND` not loopback, the client IP policy, `DLA_TRUSTED_PROXY`, or a policy address other than `DLA_EDGE_GATEWAY` (section 0) |
| "port is already allocated" / "address already in use" at `up` | the port is taken; choose another free port (0.1) and update the tunnel route |
| "Pool overlaps with other one on this address space" at `up` | the `edge` subnet is in use; choose another free `/29` (0.1) |
| Cloudflare 502/530, cloudflared log "connection refused" | the route's URL must be `http://127.0.0.1:<DLA_HTTP_PORT>`; `dc ps nginx`; `journalctl -u cloudflared` |
| 403 on every page through the tunnel | `peer=` in the access log is not `DLA_EDGE_GATEWAY` (0.4, step 3) |
| Every page 503 | `dc run --rm ops php bin/console maintenance:status` (maintenance mode or an incomplete restore) |
| 400 on every page | the `Host` header the tunnel forwards (route "HTTP Host Header" must be empty or `library.bravina.co.uk`) |
| An operations command says "Another operation is running" | wait; the lock is released when that process exits |
| Disk filling up | `df -h /mnt/bravina-shared`, `sudo du -sh /mnt/bravina-shared/digital-library/*`, `docker system df`, `cleanup` (dry run first) |

Logs are `json-file`, 10 MB × 5 per container. Application errors go to the
php container's stderr; the nginx access log records `peer=` (the TCP peer)
for every request. Watch for non-zero exit codes from scheduled backups.
External uptime monitoring of `https://library.bravina.co.uk/health` is
recommended once the ingress exists. ⚠ production

## 11. Updating the application

> **Households (phases H1 and H2) are not to be deployed.** The household
> code and its three migrations (`2026_11_13_18000*`) are development-only:
> reads are isolated per household (H2), but the rules for changing shared
> records are not implemented (H3). Deploy household functionality only as
> the complete release that includes phase H3. `HOUSEHOLDS_ENABLED` must
> stay `false` (`config:check --strict` fails otherwise); while it is
> false the application behaves as it did before households. Phase H2 adds
> no migration.

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
| Stop everything | `sudo systemctl stop digital-library` (or `dc stop`; `dc down` without `-v`) | kept (host directories) |
| Keep it down across reboots | `sudo systemctl disable --now digital-library` | kept |
| Cut public access at the edge | remove or disable only the `library.bravina.co.uk` route of the tunnel (your Cloudflare console); the other sites keep running | kept |

**Never** run `dc down -v` on production. The data lives in host
directories under `/mnt/bravina-shared/digital-library`, which Compose never
deletes, but `-v` is the habit that destroys data on stacks with volumes.
Never delete or move those directories while the stack runs. After a suspected compromise: stop the stack, keep the
volumes and logs for investigation, rotate every secret (section 2; the
database passwords must also be changed inside MariaDB), and restore from a
backup made before the incident onto a rebuilt host. ⚠ production

## Resource planning

The production host has limited capacity. The values below are starting
points with their reasoning; measure (`docker stats`, `free -m`) before
changing anything. No CPU or memory limits are set in Compose: none has been
measured, and a wrong limit causes OOM kills during backups or restores.

| Component | Current setting | Notes and conservative starting point |
|---|---|---|
| PHP-FPM | `pm = dynamic`, `max_children = 10`, `start_servers = 2`, `max_requests = 500` | Each worker typically uses tens of MB; `memory_limit = 256M` is a per-request ceiling, not usage. A sign-in hashes with Argon2id at `ARGON2_MEMORY_KIB=65536` (64 MiB for its duration). Size `max_children` ≈ (RAM you can give PHP) ÷ (measured p95 worker RSS + 64 MiB); on a 2 GB host start around 5–6. |
| MariaDB 11.4 | image defaults (`innodb_buffer_pool_size` 128 MiB, `max_connections` 151, Performance Schema off) | Options for a small host, in `docker/mysql/my.cnf` after measuring: `max_connections = 30` (PHP-FPM workers + ops + margin), `innodb_buffer_pool_size` ≈ data size + 25 %. |
| Health checks | nginx/php 30 s, MariaDB 10 s | Negligible: one small request, one TCP connect, one ping. |
| `/tmp` tmpfs | nginx 64 MB, php/ops 256 MB | Counts against RAM only when used: uploads (≤ 8 MB per request), sessions. |
| Backups | single process, gzip level 6, 1 MiB chunks, 1,000-row batches | CPU-bound for a few seconds to minutes; schedule at night. Disk in `BACKUP_PATH`: the uncompressed dump plus the archive while running. |
| Restore | streamed | `TEMP_PATH`: the whole uncompressed backup; covers: the files written; MariaDB: the restored tables; with a safety backup, a second backup in `BACKUP_PATH`. |
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
