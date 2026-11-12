# syntax=docker/dockerfile:1
# Digital Library Archive - production nginx image.
#
# Static assets (public/assets) are baked in; PHP code is not (nginx only
# forwards /index.php to PHP-FPM). Runs as the unprivileged "nginx" user
# (uid 101) on port 8080 with a read-only root filesystem. The client IP
# policy is NOT included: it depends on the verified production ingress and
# is mounted at run time; without it the container refuses to start.
#
# Base image pinned by version and index digest (multi-architecture). To
# update: pick the new tag, resolve its digest on the Docker host with
#   docker buildx imagetools inspect nginx:<tag>
# and update both here and in docker-compose.yml (development parity).
ARG NGINX_IMAGE=nginx:1.30.6-alpine3.24@sha256:583b7f2fce1cdb8da5ad5c39ebd3c33b98b87d493190e548bdee1fa8c619c311

FROM ${NGINX_IMAGE}

COPY docker/nginx/nginx.prod.conf /etc/nginx/nginx.conf
COPY docker/nginx/default.conf /etc/nginx/conf.d/default.conf
COPY docker/nginx/entrypoint-prod.sh /usr/local/bin/dla-nginx-entrypoint
COPY public/assets /var/www/html/public/assets

# Configuration and assets are root-owned and read-only to nginx. The
# IPv6-listen helper is removed: it rewrites default.conf, which is
# read-only here and must stay exactly as tested.
RUN set -eux; \
    chmod 0755 /usr/local/bin/dla-nginx-entrypoint; \
    chown -R root:root /etc/nginx /var/www/html; \
    chmod -R u=rwX,go=rX /etc/nginx /var/www/html; \
    install -d -o root -g root -m 0755 /etc/nginx/dla; \
    rm -f /docker-entrypoint.d/10-listen-on-ipv6-by-default.sh; \
    test -f /var/www/html/public/assets/css/app.css

USER nginx
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/dla-nginx-entrypoint"]
CMD ["nginx", "-g", "daemon off;"]
