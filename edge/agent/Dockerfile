FROM alpine:3.20
RUN apk add --no-cache curl openssl coreutils
WORKDIR /agent
COPY agent/*.sh /agent/
RUN chmod +x /agent/*.sh
CMD ["/agent/run.sh"]
