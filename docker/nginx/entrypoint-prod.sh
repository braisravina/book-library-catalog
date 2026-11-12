#!/bin/sh
# Digital Library Archive - production nginx entrypoint.
#
# Fails closed: the container refuses to start unless an explicit client IP
# policy is installed and acknowledged, so production can never silently run
# with a template, a missing file or a trust-everything rule. Then it hands
# over to the official image's entrypoint.
#
#   /etc/nginx/dla/client-ip.conf  the approved policy (mounted read-only;
#                                  see ARCHITECTURE.md, "Production ingress")
#   DLA_TRUSTED_PROXY=required     the policy must trust a proxy (normal case)
#   DLA_TRUSTED_PROXY=none         the policy must trust no proxy (explicit
#                                  acknowledgement; client addresses are then
#                                  the TCP peers)
#   DLA_HTTP_BIND=127.0.0.1        the host address docker-compose.prod.yml
#                                  publishes port 8080 on: loopback only
#   DLA_EDGE_GATEWAY=<address>     with "required": the one address the
#                                  policy must trust (edge network gateway)
set -eu

policy=/etc/nginx/dla/client-ip.conf

fail() {
    echo "dla-nginx: refusing to start: $*" >&2
    exit 1
}

# The published port must be bound to loopback only: cloudflared runs on
# this host, so the site is never reachable from the network directly.
# Docker publishes it before this script runs, but nothing answers there
# unless nginx starts, so a wrong bind never serves the site.
bind=${DLA_HTTP_BIND:-}
if [ -z "$bind" ]; then
    fail "DLA_HTTP_BIND is not set (the host address of the published port: 127.0.0.1; see docs/DEPLOYMENT.md, section 0)."
fi
case "$bind" in
    *[!0-9.:]*) fail "DLA_HTTP_BIND=$bind is not a loopback address (use 127.0.0.1)." ;;
esac
if ! printf '%s\n' "$bind" | grep -Eq '^127\.([0-9]{1,3}\.){2}[0-9]{1,3}$|^::1$' || [ "$(printf '%s\n' "$bind" | wc -l)" -ne 1 ]; then
    fail "DLA_HTTP_BIND=$bind is not a loopback address (use 127.0.0.1); the published port must never be reachable from the network."
fi

if [ ! -f "$policy" ] || [ ! -r "$policy" ]; then
    fail "no readable client IP policy file at $policy (mount the approved policy; see ARCHITECTURE.md \"Production ingress\")."
fi

# Directives without comments.
directives=$(sed -e 's/#.*$//' "$policy")

if printf '%s\n' "$directives" | grep -q '<[A-Z_]*>'; then
    fail "$policy still contains a template placeholder."
fi
if printf '%s\n' "$directives" | grep -Eq '(^|[[:space:]])(0\.0\.0\.0/0|::/0|0/0)([[:space:];]|$)'; then
    fail "$policy trusts every address."
fi
trusted=$(printf '%s\n' "$directives" | grep -Ec '^[[:space:]]*set_real_ip_from[[:space:]]' || true)

case "${DLA_TRUSTED_PROXY:-}" in
    required)
        [ "$trusted" -gt 0 ] || fail "DLA_TRUSTED_PROXY=required, but $policy trusts no proxy."
        # The only peer of the published port is the edge network gateway:
        # trust exactly that one address and nothing else.
        gateway=${DLA_EDGE_GATEWAY:-}
        printf '%s\n' "$gateway" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' \
            || fail "DLA_TRUSTED_PROXY=required needs DLA_EDGE_GATEWAY (the edge network gateway) as an IPv4 address."
        addresses=$(printf '%s\n' "$directives" | sed -n 's/^[[:space:]]*set_real_ip_from[[:space:]]\{1,\}\([^;[:space:]]*\)[[:space:]]*;.*$/\1/p')
        [ "$addresses" = "$gateway" ] \
            || fail "$policy must trust exactly DLA_EDGE_GATEWAY ($gateway) and no other address."
        ;;
    none)
        [ "$trusted" -eq 0 ] || fail "DLA_TRUSTED_PROXY=none, but $policy trusts a proxy."
        ;;
    *)
        fail "set DLA_TRUSTED_PROXY to \"required\" (behind the approved proxy) or \"none\" (explicitly no trusted proxy)."
        ;;
esac

nginx -t -q || fail "nginx -t reported an invalid configuration."

exec /docker-entrypoint.sh "$@"
