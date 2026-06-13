FROM postgres:17-bookworm

LABEL org.opencontainers.image.title="CDNLite PowerDNS Database Initializer" \
      org.opencontainers.image.description="Creates only the SQL schema and roles required by CDNLite PowerDNS"

COPY db-init.sh /usr/local/bin/cdnlite-pdns-db-init.sh
COPY sql/ /sql/
RUN chmod +x /usr/local/bin/cdnlite-pdns-db-init.sh

ENTRYPOINT ["/usr/local/bin/cdnlite-pdns-db-init.sh"]
