#!/usr/bin/env bash
# Digital Library Archive - production topology discovery: DOCKER HOST.
#
# READ-ONLY. Run as your normal user on the Debian Docker server. It changes
# nothing: no docker exec/run/pull, no network probes, no configuration or
# firewall changes, no package installs. Its only write is the report file
# (created new, mode 0600; an existing file is never overwritten).
#
#   bash docker-host.sh                  # report: ~/dla-discovery-<host>-<UTC>.txt
#   DLA_REPORT=/path/report.txt bash docker-host.sh
#   DLA_SUDO=no bash docker-host.sh      # never use sudo (less complete)
#
# sudo: used only for read-only commands that need it (socket owners,
# firewall listing, reading root-owned proxy configs) and only as
# "sudo -n", which never prompts: if sudo needs a password, those parts are
# reported as UNAVAILABLE. To include them, run "sudo -v" first.
#
# Secrets: environment variables, container commands, full configuration
# files, certificates, key files, JSON files (e.g. tunnel credentials) and
# public client addresses are never printed. Matching configuration lines
# that mention tokens, passwords, secrets, keys, users or authentication are
# withheld (counted only). Long opaque strings are masked as a second layer.
# Automatic filtering is not a guarantee: read the report before sharing it
# (see the checklist at its end).
#
# Output conventions: lines are observations (FACT). "NOT OBSERVED" means
# this script did not find something; it does not prove absence.
# "UNAVAILABLE" means a command could not run (permission, missing tool,
# timeout). The script draws no conclusions.

set -u
set -o pipefail
umask 077
export LC_ALL=C

TIMEOUT="${DLA_TIMEOUT:-20}"
HOST_SHORT="$(hostname -s 2>/dev/null || echo host)"
REPORT="${DLA_REPORT:-$HOME/dla-discovery-${HOST_SHORT}-$(date -u +%Y%m%dT%H%M%SZ).txt}"

if [ -e "$REPORT" ]; then
    echo "Refusing to overwrite existing $REPORT" >&2
    exit 1
fi
for tool in timeout awk sed grep find; do
    command -v "$tool" >/dev/null 2>&1 || { echo "Missing required tool: $tool" >&2; exit 1; }
done

SUDO_OK=0
if [ "${DLA_SUDO:-auto}" != no ] && command -v sudo >/dev/null 2>&1 && sudo -n true 2>/dev/null; then
    SUDO_OK=1
fi

# Configuration lines worth reporting (directive names around client
# addresses, Host handling and upstream targets).
PROXY_PAT='set_real_ip_from|real_ip_header|real_ip_recursive|X-Forwarded-For|proxy_add_x_forwarded_for|CF-Connecting-IP|X-Real-IP|proxy_set_header[[:space:]]+Host|proxy_pass|fastcgi_pass|listen[[:space:]]|server_name|forwardedHeaders|trustedIPs|proxyProtocol|passHostHeader|ipAllowList|ipWhiteList|sourceRange|insecure|entryPoints|entrypoints|address|rule|url|reverse_proxy|trusted_proxies|client_ip_headers|header_up'
# Lines that are never printed, whatever else they match.
WITHHOLD_PAT='token|secret|passw|apikey|api_key|api-key|credential|private|auth|users|key[[:space:]]*[:=]|BEGIN |://[^/@[:space:]]+:[^/@[:space:]]*@'

mask() {
    sed -E 's/eyJ[A-Za-z0-9_-]{10,}[A-Za-z0-9._-]*/<masked-jwt>/g; s/[A-Za-z0-9+_=-]{32,}/<masked-long-string>/g'
}
section() { printf '\n==== %s\n' "$*"; }
unavailable() { printf 'UNAVAILABLE: %s\n' "$*"; }
not_observed() { printf 'NOT OBSERVED: %s\n' "$*"; }

# t CMD...: run with a timeout and no stdin; report timeouts.
t() {
    timeout -k 5 "$TIMEOUT" "$@" </dev/null
    local rc=$?
    [ "$rc" -eq 124 ] && unavailable "timed out after ${TIMEOUT}s: $1"
    return "$rc"
}
# p CMD...: privileged read-only command via "sudo -n", or unprivileged.
p() {
    if [ "$SUDO_OK" -eq 1 ]; then t sudo -n "$@"; else t "$@"; fi
}

DOCKER=()
detect_docker() {
    command -v docker >/dev/null 2>&1 || return 1
    if t docker info --format '{{.ServerVersion}}' >/dev/null 2>&1; then
        DOCKER=(docker)
    elif [ "$SUDO_OK" -eq 1 ] && t sudo -n docker info --format '{{.ServerVersion}}' >/dev/null 2>&1; then
        DOCKER=(sudo -n docker)
    else
        return 1
    fi
}
d() { t "${DOCKER[@]}" "$@"; }

# grep_config PATH...: matching directive lines from config files only.
grep_config() {
    local path withheld=0 line
    for path in "$@"; do
        p test -e "$path" 2>/dev/null || continue
        while IFS= read -r line; do
            if printf '%s' "$line" | grep -qiE "$WITHHOLD_PAT"; then
                withheld=$((withheld + 1))
            else
                printf '  %s\n' "$line"
            fi
        done < <(p find "$path" -maxdepth 4 -type f -size -1024k \
                    ! -name '*.json' ! -name 'mime.types' ! -name '*_params' ! -name 'fastcgi.conf' ! -name 'koi-*' ! -name 'win-utf' ! -name '*.pem' ! -name '*.key' ! -name '*.crt' ! -name '*.p12' \
                    ! -name '*.env' ! -name '.env*' ! -iname '*secret*' ! -iname '*passw*' ! -iname 'acme*' \
                    ! -path '*/.git/*' ! -path '*/node_modules/*' ! -path '*/vendor/*' \
                    -exec grep -IHnE "$PROXY_PAT" {} + 2>/dev/null | grep -vE ':[0-9]+:[[:space:]]*#' | head -n 200)
    done
    [ "$withheld" -gt 0 ] && printf '  (%d matching line(s) withheld: they mention credentials or authentication)\n' "$withheld"
    return 0
}

# client_classes: classify leading client addresses of access-log lines.
# Prints counts per class and only non-public addresses (proxy/peer
# candidates); public client addresses are counted, never printed.
client_classes() {
    awk '
    function cls(a) {
        if (a ~ /^127\./ || a == "::1") return "loopback"
        if (a ~ /^10\./ || a ~ /^192\.168\./ || a ~ /^172\.(1[6-9]|2[0-9]|3[01])\./ || a ~ /^f[cd][0-9a-f][0-9a-f]:/) return "private"
        if (a ~ /^100\.(6[4-9]|[7-9][0-9]|1[01][0-9]|12[0-7])\./) return "cgnat"
        if (a ~ /^fe80:/) return "link-local"
        return "public"
    }
    {
        a = ""
        if (match($0, /"ClientHost":"[^"]+"/)) { a = substr($0, RSTART + 14, RLENGTH - 15) }
        else if ($1 ~ /^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$/ || ($1 ~ /::/ && $1 ~ /^[0-9a-fA-F:.]+$/)) { a = $1 }
        if (a == "") next
        c = cls(a); n[c]++; total++
        if (c != "public") seen[a]++
    }
    END {
        if (total == 0) { print "  no access-log lines with a leading client address in the sampled output"; exit }
        printf "  sampled lines: %d; by class:", total
        for (c in n) printf " %s=%d", c, n[c]
        printf "\n"
        k = 0
        for (a in seen) if (k++ < 15) printf "  non-public address %s seen %d time(s)\n", a, seen[a]
    }'
}

{
echo "Digital Library Archive - topology discovery (Docker host)"
echo "Generated (UTC): $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "Privileged read-only commands: $([ "$SUDO_OK" -eq 1 ] && echo 'yes (sudo -n)' || echo 'no (results marked UNAVAILABLE may be incomplete)')"

section "1. Host"
echo "hostname: $HOST_SHORT"
( . /etc/os-release 2>/dev/null && echo "os: ${PRETTY_NAME:-unknown}" ) || unavailable "/etc/os-release"
echo "kernel/arch: $(uname -r) $(uname -m)"
if command -v ip >/dev/null 2>&1; then
    echo "-- interfaces (address/prefix):"
    t ip -brief address show | awk '{ $2 = ""; print "  " $0 }'
    echo "-- default route:"
    t ip route show default | sed 's/^/  /'
else
    unavailable "ip command"
fi

section "2. Docker engine"
if detect_docker; then
    echo "docker access: ${DOCKER[*]}"
    d version --format 'server {{.Server.Version}} (API {{.Server.APIVersion}})'
    d compose version --short 2>/dev/null | sed 's/^/compose /' || unavailable "docker compose plugin"
    d info --format 'rootless={{.SecurityOptions}} logging-driver={{.LoggingDriver}} cgroup={{.CgroupVersion}} live-restore={{.LiveRestoreEnabled}}'
    echo "-- daemon.json (selected keys only):"
    if p test -r /etc/docker/daemon.json 2>/dev/null; then
        p grep -nE '"(hosts|iptables|ip6tables|ipv6|userland-proxy|ip|bip|default-address-pools|icc|live-restore|log-driver|no-new-privileges|userns-remap)"' /etc/docker/daemon.json | sed 's/^/  /' || not_observed "selected keys in /etc/docker/daemon.json"
    else
        not_observed "/etc/docker/daemon.json (absent or unreadable)"
    fi
    echo "-- dockerd listen flags (TCP API exposure):"
    if command -v systemctl >/dev/null 2>&1; then
        t systemctl show docker.service -p ExecStart --value 2>/dev/null | grep -oE '(-H|--host)[= ][^ ;]+|tcp://[^ ;]+' | sed 's/^/  /' || echo "  no -H/--host/tcp:// flags in ExecStart"
    else
        unavailable "systemctl"
    fi
else
    unavailable "docker (not installed, daemon not running, or no permission; try: sudo -v, then rerun)"
fi

section "3. Listening TCP sockets"
if command -v ss >/dev/null 2>&1; then
    if [ "$SUDO_OK" -eq 1 ]; then
        p ss -H -ltnp | awk '{ print "  " $4, $6 }' | sed -E 's/,pid=[0-9]+//g; s/,fd=[0-9]+//g' | sort -u
    else
        t ss -H -ltn | awk '{ print "  " $4 }' | sort -u
        echo "  (process names need sudo; not shown)"
    fi
else
    unavailable "ss"
fi

if [ "${#DOCKER[@]}" -gt 0 ]; then
    section "4. Containers (running)"
    names=$(d ps --format '{{.Names}}' | sort)
    if [ -z "$names" ]; then
        not_observed "running containers"
    fi
    for c in $names; do
        echo "-- $c"
        d inspect "$c" --format '  image={{.Config.Image}} state={{.State.Status}}{{if .State.Health}} health={{.State.Health.Status}}{{end}} restart={{.HostConfig.RestartPolicy.Name}}
  compose project={{index .Config.Labels "com.docker.compose.project"}} service={{index .Config.Labels "com.docker.compose.service"}}
  user={{if .Config.User}}{{.Config.User}}{{else}}(image default){{end}} privileged={{.HostConfig.Privileged}} read-only-rootfs={{.HostConfig.ReadonlyRootfs}} network-mode={{.HostConfig.NetworkMode}} log-driver={{.HostConfig.LogConfig.Type}}
  networks:{{range $k, $v := .NetworkSettings.Networks}} {{$k}}={{if $v.IPAddress}}{{$v.IPAddress}}{{else}}-{{end}}{{if $v.IPAMConfig}}{{if $v.IPAMConfig.IPv4Address}}(static){{end}}{{end}}{{end}}'
        d port "$c" 2>/dev/null | sed 's/^/  published: /'
        # Routing labels only (rules, ports, entry points, networks); never
        # middleware settings, which can hold credentials.
        d inspect "$c" --format '{{range $k, $v := .Config.Labels}}{{$k}}={{$v}}{{"\n"}}{{end}}' \
            | grep -E '^traefik\.(enable|docker\.network|http\.routers\.[^.]+\.(rule|entrypoints|middlewares|service|tls)|http\.services\.[^.]+\.loadbalancer\.(server\.(port|scheme)|passhostheader))=' \
            | grep -viE "$WITHHOLD_PAT" | sed 's/^/  label: /'
    done
    echo "-- stopped containers (name, image, status):"
    d ps -a --filter status=exited --filter status=created --format '  {{.Names}} {{.Image}} {{.Status}}'

    section "5. Docker networks"
    for n in $(d network ls --format '{{.Name}}' | sort); do
        d network inspect "$n" --format '-- {{.Name}} driver={{.Driver}} scope={{.Scope}} internal={{.Internal}} attachable={{.Attachable}} ipv6={{.EnableIPv6}}
  subnets:{{range .IPAM.Config}} {{.Subnet}}{{if .Gateway}} (gw {{.Gateway}}){{end}}{{end}}
  members:{{range $id, $m := .Containers}} {{$m.Name}}={{$m.IPv4Address}}{{end}}
  compose project={{index .Labels "com.docker.compose.project"}}'
    done

    section "6. Reverse proxies in containers (configuration lines only)"
    found=0
    for c in $names; do
        image=$(d inspect "$c" --format '{{.Config.Image}}')
        case "$(printf '%s' "$image" | tr '[:upper:]' '[:lower:]')" in
            *nginx*|*traefik*|*caddy*|*haproxy*|*httpd*|*apache*|*proxy*|*swag*|*cloudflared*) ;;
            *) continue ;;
        esac
        found=1
        echo "-- $c ($image)"
        echo "  mounts:"
        d inspect "$c" --format '{{range .Mounts}}    {{.Type}} {{.Source}} -> {{.Destination}}{{if not .RW}} (ro){{end}}{{"\n"}}{{end}}'
        echo "  matching configuration lines (from mounted host paths):"
        for src in $(d inspect "$c" --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}}{{"\n"}}{{end}}{{end}}'); do
            case "$src" in /var/run/docker.sock|/run/docker.sock|/etc/localtime|/etc/timezone|/proc*|/sys*|/dev*) continue ;; esac
            grep_config "$src"
        done
        echo "  client addresses in the last 300 log lines:"
        d logs --tail 300 "$c" 2>&1 | client_classes
    done
    [ "$found" -eq 0 ] && not_observed "containers whose image name suggests a proxy (nginx, traefik, caddy, haproxy, httpd/apache, swag, cloudflared)"
fi

section "7. Proxies installed on the host itself"
for svc in nginx apache2 caddy traefik haproxy cloudflared; do
    if command -v systemctl >/dev/null 2>&1; then
        state=$(t systemctl is-active "$svc" 2>/dev/null)
        [ -n "$state" ] && [ "$state" != inactive ] && echo "service $svc: $state"
    fi
done
pgrep -l -x 'cloudflared|nginx|caddy|traefik|haproxy|apache2' 2>/dev/null | awk '{ print "process: " $2 }' | sort -u
for dir in /etc/nginx /etc/apache2 /etc/caddy /etc/traefik /etc/haproxy; do
    if p test -d "$dir" 2>/dev/null; then echo "-- $dir"; grep_config "$dir"; fi
done
if p test -d /etc/cloudflared 2>/dev/null; then
    echo "-- /etc/cloudflared exists on this host (not read: it may hold tunnel credentials; report its layout by hand if asked)"
fi

section "8. Host firewall (rule listings)"
if [ "$SUDO_OK" -eq 1 ]; then
    if command -v iptables >/dev/null 2>&1; then
        echo "-- iptables DOCKER-USER:"; p iptables -S DOCKER-USER 2>&1 | sed 's/^/  /'
        echo "-- iptables INPUT (first 40):"; p iptables -S INPUT 2>&1 | head -n 40 | sed 's/^/  /'
    fi
    command -v nft >/dev/null 2>&1 && { echo "-- nftables tables:"; p nft list tables 2>&1 | sed 's/^/  /'; }
    command -v ufw >/dev/null 2>&1 && { echo "-- ufw:"; p ufw status 2>&1 | sed 's/^/  /'; }
else
    unavailable "firewall listings need sudo (run sudo -v first to include them)"
fi

cat <<'CHECKLIST'

==== Before sharing this report: manual review checklist
1. Read the whole file. Automatic filtering reduces, but does not remove,
   the risk of exposing something sensitive.
2. Search it:  grep -nEi 'token|secret|passw|key|auth|BEGIN|eyJ|masked' <this file>
   Every hit must be harmless (e.g. a directive name), or delete the line.
3. Replace anything you do not want to share (public IP addresses, internal
   host names, e-mail addresses) with a placeholder such as <PUBLIC_IP>.
   Keep private addresses (10.x, 172.16-31.x, 192.168.x) and Docker network
   names: the trusted-proxy design depends on them.
4. Do not attach configuration files, certificates, tunnel credentials,
   .env files or docker inspect output beyond this report.
CHECKLIST
} 2>&1 | mask > "$REPORT"

echo "Wrote $REPORT (mode 0600). Review it before sharing; see the checklist at its end."
