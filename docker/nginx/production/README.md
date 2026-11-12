# Production client IP policy (PENDING)

`docker-compose.prod.yml` mounts this directory read-only at
`/etc/nginx/dla/` in the production nginx container. The container's
entrypoint (`docker/nginx/entrypoint-prod.sh`) refuses to start until:

1. `client-ip.conf` exists here: the approved trusted-proxy policy for
   the verified production ingress (start from
   `../client-ip.cloudflared.conf.example` or
   `../client-ip.reverse-proxy.conf.example`), with no placeholder and no
   trust-everything range; and
2. `DLA_TRUSTED_PROXY` is set in `.env.production`: `required` when the
   policy trusts a proxy, or `none` as an explicit decision that the TCP
   peer is the client.

**Status:** intentionally empty. The trusted proxy address, the header it
sends and whether direct requests are refused (403) are decided only after
the production topology discovery (`docker/discovery/`) has been reviewed
and the design approved. Validate any candidate with `nginx -t` and
`php tests/nginx/run.php` before installing it here.
