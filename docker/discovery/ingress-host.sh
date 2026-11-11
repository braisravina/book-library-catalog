#!/usr/bin/env bash
# Digital Library Archive - production topology discovery: INGRESS HOST
# (the machine running cloudflared and/or Traefik, e.g. the Raspberry Pi).
#
# READ-ONLY. Run as your normal user. It changes nothing: no docker
# exec/run/pull, no cloudflared commands, no network probes (the optional
# route lookup sends no packets), no configuration or firewall changes. Its
# only write is the report file (created new, mode 0600; an existing file
# is never overwritten).
#
#   bash ingress-host.sh [DOCKER_HOST_ADDRESS]
#   DLA_REPORT=/path/report.txt DLA_SUDO=no bash ingress-host.sh 192.168.1.50
#
# DOCKER_HOST_ADDRESS (optional): the Docker server's LAN address, used only
# for "ip route get" (which interface/source address this host would use).
#
# sudo: only "sudo -n" (never prompts) for read-only commands: socket
# owners, firewall listings and root-owned configuration. Run "sudo -v"
# first to include them; otherwise they are reported as UNAVAILABLE.
#
# Secrets: never printed - no environment values (names only), no tunnel
# token or credentials file, no certificate or key files, no JSON files, no
# full configuration files or command lines (argument NAMES only, values for
# an allow-list of harmless options), no public client addresses. Matching
# lines that mention tokens, passwords, secrets, keys, users or
# authentication are withheld (counted only). Long opaque strings are masked
# as a second layer. Automatic filtering is not a guarantee: read the report
# before sharing it (see the checklist at its end).
#
# Output conventions: lines are observations. "NOT OBSERVED" means this
# script did not find something; it does not prove absence. "UNAVAILABLE"
# means a command could not run (permission, missing tool, timeout).

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

DOCKER_HOST_ADDR="${1:-}"
if [ -n "$DOCKER_HOST_ADDR" ] && ! printf '%s' "$DOCKER_HOST_ADDR" | grep -qE '^[0-9A-Za-z.:-]{1,253}$'; then
    echo "Invalid DOCKER_HOST_ADDRESS" >&2
    exit 1
fi

# Traefik/cloudflared configuration keys worth reporting.
PROXY_PAT="$PROXY_PAT|hostname|service|servers|loadBalancer|middlewares|httpHostHeader|originServerName|noTLSVerify|proxyType"

# safe_args: argument names of a command line, with values only for an
# allow-list of options that cannot hold secrets. Positional words other
# than known subcommands become <arg>.
safe_args() {
    awk '{
        for (i = 1; i <= NF; i++) {
            w = $i; lw = tolower(w)
            if (i == 1) { n = split(w, parts, "/"); out = parts[n]; continue }
            if (w ~ /^--?[A-Za-z]/) {
                name = w; sub(/=.*/, "", name); lname = tolower(name)
                keep = (lname ~ /^--(config|url|protocol|edge-ip-version|loglevel|log\.level|metrics|no-autoupdate|entrypoints\.[^.]+\.(address|forwardedheaders\.(trustedips|insecure)|proxyprotocol\.(trustedips|insecure)|http\.redirections\..*)|providers\.(docker|file)\.(endpoint|exposedbydefault|network|directory|filename|watch)|providers\.docker|providers\.file|api|api\.(dashboard|insecure)|accesslog|accesslog\.(filepath|format)|ping|serverstransport\.insecureskipverify|global\..*)$/)
                if (keep && w ~ /=/) { out = out " " w }
                else if (keep && i < NF && $(i + 1) !~ /^-/) { out = out " " w " " $(i + 1); i++ }
                else if (w ~ /=/ || (i < NF && $(i + 1) !~ /^-/ && lname ~ /token|cred|secret|key|pass|email|origincert/)) {
                    out = out " " name "=<withheld>"
                    if (w !~ /=/) i++
                }
                else { out = out " " name }
            } else if (lw ~ /^(tunnel|run|service|proxy-dns|access|traefik)$/) { out = out " " w }
            else { out = out " <arg>" }
        }
        print out
    }'
}

# cloudflared_config FILE: ingress-related keys only; identifiers and the
# credentials path are replaced with <present>.
cloudflared_config() {
    p grep -nE '^[[:space:]]*(-[[:space:]]*)?(hostname|service|path|ingress|originRequest|httpHostHeader|originServerName|noTLSVerify|disableChunkedEncoding|proxyType|http2Origin|warp-routing|protocol|metrics|tunnel|credentials-file|origincert)[[:space:]]*:' "$1" 2>/dev/null \
        | sed -E 's/^([0-9]+:[[:space:]]*(-[[:space:]]*)?(tunnel|credentials-file|origincert)[[:space:]]*:).*/\1 <present>/' \
        | grep -viE 'token|secret' | sed 's/^/  /'
}

{
echo "Digital Library Archive - topology discovery (ingress host: cloudflared / Traefik)"
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
    if [ -n "$DOCKER_HOST_ADDR" ]; then
        echo "-- route to the Docker host (lookup only, no packets sent):"
        t ip route get "$DOCKER_HOST_ADDR" | head -n 1 | sed 's/^/  /'
    fi
else
    unavailable "ip command"
fi

section "2. Listening TCP sockets"
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

detect_docker || true
names=""
if [ "${#DOCKER[@]}" -gt 0 ]; then
    names=$(d ps --format '{{.Names}}' | sort)
fi

section "3. cloudflared"
seen_cf=0
if command -v systemctl >/dev/null 2>&1; then
    for unit in $(t systemctl list-units --all --plain --no-legend --type=service 'cloudflared*' 2>/dev/null | awk '{ print $1 }'); do
        seen_cf=1
        echo "-- systemd unit $unit"
        t systemctl show "$unit" -p ActiveState -p SubState -p User -p FragmentPath 2>/dev/null | sed 's/^/  /'
        printf '  ExecStart (argument names):'
        t systemctl show "$unit" -p ExecStart --value 2>/dev/null | grep -oE 'argv\[\]=[^;]*' | sed 's/^argv\[\]=//' | safe_args | sed 's/^/ /'
        printf '  environment variable NAMES:'
        t systemctl show "$unit" -p Environment --value 2>/dev/null | tr ' ' '\n' | sed -n 's/=.*//p' | tr '\n' ' '
        echo
        for envfile in $(t systemctl show "$unit" -p EnvironmentFiles --value 2>/dev/null | awk '{ print $1 }'); do
            printf '  environment file %s, variable NAMES:' "$envfile"
            p grep -oE '^[[:space:]]*(export[[:space:]]+)?[A-Za-z_][A-Za-z0-9_]*=' "$envfile" 2>/dev/null | sed -E 's/^[[:space:]]*(export[[:space:]]+)?//; s/=$//' | tr '\n' ' '
            echo
        done
    done
fi
for pid in $(pgrep -x cloudflared 2>/dev/null); do
    seen_cf=1
    echo "-- process (user, arguments): $(ps -o user= -p "$pid" 2>/dev/null) $(ps -ww -o args= -p "$pid" 2>/dev/null | safe_args)"
    cfg=$(ps -ww -o args= -p "$pid" 2>/dev/null | grep -oE -- '--config[= ][^ ]+' | sed -E 's/^--config[= ]//' | head -n 1)
    [ -n "$cfg" ] && echo "   uses --config $cfg"
done
cf_configs="/etc/cloudflared/config.yml /etc/cloudflared/config.yaml /usr/local/etc/cloudflared/config.yml /usr/local/etc/cloudflared/config.yaml $HOME/.cloudflared/config.yml $HOME/.cloudflared/config.yaml /root/.cloudflared/config.yml /root/.cloudflared/config.yaml"
for f in $cf_configs; do
    if p test -f "$f" 2>/dev/null; then
        seen_cf=1
        echo "-- locally managed configuration $f (ingress keys only):"
        cloudflared_config "$f"
    fi
done
for c in $names; do
    image=$(d inspect "$c" --format '{{.Config.Image}}')
    case "$(printf '%s' "$image" | tr '[:upper:]' '[:lower:]')" in *cloudflared*) ;; *) continue ;; esac
    seen_cf=1
    echo "-- container $c ($image)"
    d inspect "$c" --format '  network-mode={{.HostConfig.NetworkMode}} restart={{.HostConfig.RestartPolicy.Name}}
  networks:{{range $k, $v := .NetworkSettings.Networks}} {{$k}}={{if $v.IPAddress}}{{$v.IPAddress}}{{else}}-{{end}}{{if $v.IPAMConfig}}{{if $v.IPAMConfig.IPv4Address}}(static){{end}}{{end}}{{end}}'
    printf '  arguments:'
    d inspect "$c" --format 'cloudflared{{range .Config.Entrypoint}} {{.}}{{end}}{{range .Config.Cmd}} {{.}}{{end}}' | safe_args | sed 's/^cloudflared//'
    printf '  environment variable NAMES:'
    d inspect "$c" --format '{{range .Config.Env}}{{println .}}{{end}}' | sed -n 's/=.*//p' | tr '\n' ' '
    echo
    echo "  mounts:"
    d inspect "$c" --format '{{range .Mounts}}    {{.Type}} {{.Source}} -> {{.Destination}}{{"\n"}}{{end}}'
    for src in $(d inspect "$c" --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}}{{"\n"}}{{end}}{{end}}'); do
        for f in "$src" "$src/config.yml" "$src/config.yaml"; do
            if p test -f "$f" 2>/dev/null && printf '%s' "$f" | grep -qE '\.ya?ml$'; then
                echo "  configuration $f (ingress keys only):"
                cloudflared_config "$f"
            fi
        done
    done
done
[ "$seen_cf" -eq 0 ] && not_observed "cloudflared (no systemd unit, process, standard config file or container)"
echo "Remotely managed tunnels (a --token argument or TUNNEL_TOKEN variable"
echo "above) keep their ingress rules in the Cloudflare dashboard, not on disk."

section "4. Traefik"
seen_tr=0
for c in $names; do
    image=$(d inspect "$c" --format '{{.Config.Image}}')
    case "$(printf '%s' "$image" | tr '[:upper:]' '[:lower:]')" in *traefik*) ;; *) continue ;; esac
    seen_tr=1
    echo "-- container $c ($image)"
    d inspect "$c" --format '  network-mode={{.HostConfig.NetworkMode}} restart={{.HostConfig.RestartPolicy.Name}}
  networks:{{range $k, $v := .NetworkSettings.Networks}} {{$k}}={{if $v.IPAddress}}{{$v.IPAddress}}{{else}}-{{end}}{{if $v.IPAMConfig}}{{if $v.IPAMConfig.IPv4Address}}(static){{end}}{{end}}{{end}}'
    d port "$c" 2>/dev/null | sed 's/^/  published: /'
    echo "  static configuration arguments (allow-listed values only):"
    d inspect "$c" --format 'traefik{{range .Config.Cmd}} {{.}}{{end}}' | safe_args | tr ' ' '\n' | grep -E '^--' | sed 's/^/    /'
    echo "  mounts:"
    d inspect "$c" --format '{{range .Mounts}}    {{.Type}} {{.Source}} -> {{.Destination}}{{if not .RW}} (ro){{end}}{{"\n"}}{{end}}'
    echo "  matching configuration lines (static and file-provider configuration):"
    for src in $(d inspect "$c" --format '{{range .Mounts}}{{if eq .Type "bind"}}{{.Source}}{{"\n"}}{{end}}{{end}}'); do
        case "$src" in /var/run/docker.sock|/run/docker.sock|/etc/localtime|/etc/timezone|/proc*|/sys*|/dev*) continue ;; esac
        grep_config "$src"
    done
    echo "  client addresses in the last 300 log lines (access log, if it goes to stdout):"
    d logs --tail 300 "$c" 2>&1 | client_classes
done
if command -v systemctl >/dev/null 2>&1 && [ "$(t systemctl is-active traefik 2>/dev/null)" = active ]; then
    seen_tr=1
    echo "-- systemd service traefik is active"
    printf '  ExecStart (allow-listed values):'
    t systemctl show traefik -p ExecStart --value 2>/dev/null | grep -oE 'argv\[\]=[^;]*' | sed 's/^argv\[\]=//' | safe_args | sed 's/^/ /'
fi
for dir in /etc/traefik /usr/local/etc/traefik; do
    if p test -d "$dir" 2>/dev/null; then seen_tr=1; echo "-- $dir"; grep_config "$dir"; fi
done
[ "$seen_tr" -eq 0 ] && not_observed "Traefik (no container, active systemd service or /etc/traefik)"
echo "-- routing labels on containers of this host:"
for c in $names; do
    d inspect "$c" --format '{{range $k, $v := .Config.Labels}}{{$k}}={{$v}}{{"\n"}}{{end}}' \
        | grep -E '^traefik\.(enable|docker\.network|http\.routers\.[^.]+\.(rule|entrypoints|middlewares|service|tls)|http\.services\.[^.]+\.loadbalancer\.(server\.(port|scheme)|passhostheader))=' \
        | grep -viE "$WITHHOLD_PAT" | sed "s/^/  $c: /"
done

section "5. Other proxies on this host"
for svc in nginx apache2 caddy haproxy; do
    if command -v systemctl >/dev/null 2>&1 && [ "$(t systemctl is-active "$svc" 2>/dev/null)" = active ]; then
        echo "-- service $svc is active"
        [ -d "/etc/$svc" ] && grep_config "/etc/$svc"
    fi
done
for c in $names; do
    image=$(d inspect "$c" --format '{{.Config.Image}}')
    case "$(printf '%s' "$image" | tr '[:upper:]' '[:lower:]')" in *traefik*|*cloudflared*) continue ;; *nginx*|*caddy*|*haproxy*|*httpd*|*apache*|*proxy*|*swag*) echo "-- container $c ($image)" ;; esac
done

if [ "${#DOCKER[@]}" -gt 0 ]; then
    section "6. Docker networks on this host"
    for n in $(d network ls --format '{{.Name}}' | sort); do
        d network inspect "$n" --format '-- {{.Name}} driver={{.Driver}} internal={{.Internal}} attachable={{.Attachable}}
  subnets:{{range .IPAM.Config}} {{.Subnet}}{{if .Gateway}} (gw {{.Gateway}}){{end}}{{end}}
  members:{{range $id, $m := .Containers}} {{$m.Name}}={{$m.IPv4Address}}{{end}}'
    done
    echo "-- all containers (name, image, status):"
    d ps -a --format '  {{.Names}} {{.Image}} {{.Status}}'
else
    section "6. Docker"
    unavailable "docker (not installed, not running, or no permission)"
fi

section "7. Host firewall (rule listings)"
if [ "$SUDO_OK" -eq 1 ]; then
    if command -v iptables >/dev/null 2>&1; then
        echo "-- iptables INPUT (first 40):"; p iptables -S INPUT 2>&1 | head -n 40 | sed 's/^/  /'
        echo "-- iptables DOCKER-USER:"; p iptables -S DOCKER-USER 2>&1 | sed 's/^/  /'
    fi
    command -v nft >/dev/null 2>&1 && { echo "-- nftables tables:"; p nft list tables 2>&1 | sed 's/^/  /'; }
    command -v ufw >/dev/null 2>&1 && { echo "-- ufw:"; p ufw status 2>&1 | sed 's/^/  /'; }
else
    unavailable "firewall listings need sudo (run sudo -v first to include them)"
fi

cat <<'CHECKLIST'

==== To add by hand (not visible on disk for dashboard-managed tunnels)
If section 3 shows --token / TUNNEL_TOKEN, open Cloudflare Zero Trust >
Networks > Tunnels > (tunnel) > Public hostnames and note, for each Bravina
hostname: the hostname, the Service (e.g. http://localhost:80 or
https://traefik:443), and the "HTTP Host Header" / "No TLS Verify" settings
under Additional application settings. Never copy the tunnel token.

==== Before sharing this report: manual review checklist
1. Read the whole file. Automatic filtering reduces, but does not remove,
   the risk of exposing something sensitive.
2. Search it:  grep -nEi 'token|secret|passw|key|auth|BEGIN|eyJ|masked' <this file>
   Every hit must be harmless (a directive or variable NAME, "<withheld>",
   "<present>"), or delete the line.
3. Replace anything you do not want to share (public IP addresses, e-mail
   addresses, unrelated host names) with a placeholder such as <PUBLIC_IP>.
   Keep private addresses (10.x, 172.16-31.x, 192.168.x), Docker network
   names and the Bravina hostnames: the trusted-proxy design depends on them.
4. Do not attach configuration files, tunnel credentials (*.json, cert.pem),
   .env files or docker inspect output beyond this report.
CHECKLIST
} 2>&1 | mask > "$REPORT"

echo "Wrote $REPORT (mode 0600). Review it before sharing; see the checklist at its end."
