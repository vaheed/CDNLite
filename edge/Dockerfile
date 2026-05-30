FROM openresty/openresty:1.27.1.2-0-alpine

RUN /usr/local/openresty/bin/openresty -V \
  && /usr/local/openresty/luajit/bin/luajit -e "assert(require('cjson.safe'))"

COPY openresty/nginx.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY openresty/lua /etc/openresty/lua
EXPOSE 8081
CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
