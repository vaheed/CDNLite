ARG POWERADMIN_TAG=stable
FROM poweradmin/poweradmin:${POWERADMIN_TAG}

LABEL org.opencontainers.image.title="CDNLite Poweradmin" \
      org.opencontainers.image.description="PowerDNS administration UI configured for the CDNLite DNS database"

COPY poweradmin-entrypoint-wrapper.sh /usr/local/bin/cdnlite-poweradmin-entrypoint.sh
COPY healthcheck.sh /usr/local/bin/poweradmin-healthcheck.sh
RUN chmod +x /usr/local/bin/cdnlite-poweradmin-entrypoint.sh /usr/local/bin/poweradmin-healthcheck.sh

ENTRYPOINT ["/usr/local/bin/cdnlite-poweradmin-entrypoint.sh"]
CMD ["frankenphp", "run", "--config", "/etc/caddy/Caddyfile"]
