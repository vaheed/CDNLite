FROM postgres:17-bookworm

LABEL org.opencontainers.image.title="CDNLite PowerDNS PostgreSQL Replica" \
      org.opencontainers.image.description="Optional read-only PostgreSQL replica for CDNLite PowerDNS nodes"

COPY entrypoint-replica.sh /usr/local/bin/cdnlite-pdns-replica-entrypoint.sh
RUN chmod +x /usr/local/bin/cdnlite-pdns-replica-entrypoint.sh

ENTRYPOINT ["/usr/local/bin/cdnlite-pdns-replica-entrypoint.sh"]
CMD ["postgres"]
