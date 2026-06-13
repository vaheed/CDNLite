FROM postgres:17-bookworm

LABEL org.opencontainers.image.title="CDNLite PowerDNS PostgreSQL Primary" \
      org.opencontainers.image.description="TLS-enabled PostgreSQL primary with CDNLite PowerDNS replication support"

RUN apt-get update \
    && apt-get install -y --no-install-recommends openssl ca-certificates \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint-wrapper.sh /usr/local/bin/cdnlite-pdns-postgres-entrypoint.sh
COPY ensure-tls.sh /usr/local/bin/cdnlite-pdns-ensure-tls.sh
COPY configure-primary.sh /usr/local/bin/cdnlite-pdns-configure-primary.sh
COPY initdb/ /docker-entrypoint-initdb.d/

RUN chmod +x /usr/local/bin/cdnlite-pdns-postgres-entrypoint.sh \
    /usr/local/bin/cdnlite-pdns-ensure-tls.sh \
    /usr/local/bin/cdnlite-pdns-configure-primary.sh \
    /docker-entrypoint-initdb.d/*.sh

ENTRYPOINT ["/usr/local/bin/cdnlite-pdns-postgres-entrypoint.sh"]
CMD ["postgres"]
