FROM openresty/openresty:1.27.1.2-0-alpine
COPY openresty/nginx.conf /usr/local/openresty/nginx/conf/nginx.conf
COPY openresty/lua /etc/openresty/lua
EXPOSE 8081
CMD ["/usr/local/openresty/bin/openresty", "-g", "daemon off;"]
