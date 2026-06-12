FROM debian:bookworm-slim

LABEL org.opencontainers.image.title="CDNLite PowerDNS Recursor" \
      org.opencontainers.image.description="Dedicated resolver for CDNLite authoritative ALIAS expansion"

ENV DEBIAN_FRONTEND=noninteractive

RUN apt-get update \
    && apt-get install -y --no-install-recommends ca-certificates curl tini \
    && install -d /etc/apt/keyrings \
    && curl -fsSL https://repo.powerdns.com/FD380FBB-pub.asc -o /etc/apt/keyrings/rec-51-pub.asc \
    && echo "deb [signed-by=/etc/apt/keyrings/rec-51-pub.asc] http://repo.powerdns.com/debian bookworm-rec-51 main" > /etc/apt/sources.list.d/pdns.list \
    && printf "Package: pdns-*\nPin: origin repo.powerdns.com\nPin-Priority: 600\n" > /etc/apt/preferences.d/rec-51 \
    && apt-get update \
    && apt-get install -y --no-install-recommends pdns-recursor \
    && apt-get purge -y --auto-remove curl \
    && rm -rf /var/lib/apt/lists/*

COPY entrypoint.sh /usr/local/bin/cdnlite-pdns-recursor-entrypoint.sh
COPY healthcheck.sh /usr/local/bin/recursor-healthcheck.sh
RUN chmod +x /usr/local/bin/cdnlite-pdns-recursor-entrypoint.sh /usr/local/bin/recursor-healthcheck.sh \
    && mkdir -p /var/run/pdns-recursor \
    && chown pdns:pdns /var/run/pdns-recursor

ENTRYPOINT ["/usr/bin/tini", "--", "/usr/local/bin/cdnlite-pdns-recursor-entrypoint.sh"]
