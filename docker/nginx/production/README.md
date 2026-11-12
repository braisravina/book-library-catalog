# Production client IP policy (installed by the operator)

`docker-compose.prod.yml` mounts this directory read-only at
`/etc/nginx/dla/` in the production nginx container. The container's
entrypoint (`docker/nginx/entrypoint-prod.sh`) refuses to start until:

1. `client-ip.conf` exists here, without a placeholder or a
   trust-everything range:
   - first start: a copy of the trust-nothing `../client-ip.conf`, with
     `DLA_TRUSTED_PROXY=none`;
   - then: `../client-ip.cloudflared.conf.example` with its placeholder
     replaced by `DLA_EDGE_GATEWAY`, with `DLA_TRUSTED_PROXY=required`
     (the entrypoint refuses any other or additional trusted address);
2. `DLA_HTTP_BIND` is a loopback address (`127.0.0.1`).

The file is not committed. It is installed on the Docker host after the free
port and `edge` subnet are checked, and the gateway is confirmed from the
`peer=` field of the access log (docs/DEPLOYMENT.md, section 0).
