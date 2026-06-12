FROM debian:bookworm-slim

LABEL org.opencontainers.image.title="CDNLite GeoIP MMDB Updater" \
      org.opencontainers.image.description="Downloads and refreshes GeoIP data for CDNLite PowerDNS"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl tar gzip unzip coreutils file tini \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /usr/local/bin/cdnlite-mmdb-updater.sh
COPY healthcheck.sh /usr/local/bin/mmdb-healthcheck.sh
RUN chmod +x /usr/local/bin/cdnlite-mmdb-updater.sh /usr/local/bin/mmdb-healthcheck.sh && mkdir -p /mmdb

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/cdnlite-mmdb-updater.sh"]
