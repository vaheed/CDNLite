FROM openresty/openresty:1.27.1.2-0-alpine

RUN apk add --no-cache lua-cjson

ENV LUA_PATH="/usr/local/openresty/lualib/?.lua;/usr/local/openresty/lualib/?/init.lua;/usr/local/share/lua/5.1/?.lua;/usr/local/share/lua/5.1/?/init.lua;/usr/share/lua/5.1/?.lua;/usr/share/lua/5.1/?/init.lua;;"
ENV LUA_CPATH="/usr/local/openresty/lualib/?.so;/usr/local/lib/lua/5.1/?.so;/usr/lib/lua/5.1/?.so;;"

RUN /usr/local/openresty/bin/openresty -V \
  && /usr/local/openresty/luajit/bin/luajit -e "assert(require('cjson.safe'))"

COPY openresty/nginx.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY openresty/lua /etc/openresty/lua
EXPOSE 8081
CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
