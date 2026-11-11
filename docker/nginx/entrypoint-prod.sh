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
set -eu

policy=/etc/nginx/dla/client-ip.conf

fail() {
    echo "dla-nginx: refusing to start: $*" >&2
    exit 1
}

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
