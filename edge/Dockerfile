FROM openresty/openresty:1.27.1.2-0-alpine

RUN apk add --no-cache lua-cjson libmaxminddb openssl ca-certificates \
  && command -v mmdblookup \
  && mkdir -p /var/cache/cdnlite /var/log/openresty \
  && chown -R nobody:nobody /var/cache/cdnlite /var/log/openresty

ENV LUA_PATH="/etc/openresty/lua/?.lua;/etc/openresty/lua/?/init.lua;/usr/local/openresty/lualib/?.lua;/usr/local/openresty/lualib/?/init.lua;/usr/local/share/lua/5.1/?.lua;/usr/local/share/lua/5.1/?/init.lua;/usr/share/lua/5.1/?.lua;/usr/share/lua/5.1/?/init.lua;;"
ENV LUA_CPATH="/usr/local/openresty/lualib/?.so;/usr/local/lib/lua/5.1/?.so;/usr/lib/lua/5.1/?.so;;"

COPY openresty/lua /etc/openresty/lua

RUN /usr/local/openresty/bin/openresty -V \
  && /usr/local/openresty/luajit/bin/luajit -e "assert(require('cjson.safe')); assert(require('resty.maxminddb'))"

COPY docker-entrypoint.sh /docker-entrypoint.sh
COPY openresty/nginx.conf /usr/local/openresty/nginx/conf/nginx.conf.template
RUN chmod +x /docker-entrypoint.sh
EXPOSE 8081 8443
ENTRYPOINT ["/docker-entrypoint.sh"]
CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
