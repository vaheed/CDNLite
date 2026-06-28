FROM php:8.3-fpm-alpine

ENV PHP_FPM_PM_MAX_CHILDREN=24 \
    PHP_FPM_PM_START_SERVERS=4 \
    PHP_FPM_PM_MIN_SPARE_SERVERS=2 \
    PHP_FPM_PM_MAX_SPARE_SERVERS=8 \
    PHP_FPM_PM_MAX_REQUESTS=500

RUN apk add --no-cache nginx libpq supervisor \
  && apk add --no-cache --virtual .build-deps $PHPIZE_DEPS postgresql-dev \
  && docker-php-ext-install opcache pdo pdo_pgsql \
  && apk del .build-deps

WORKDIR /app
COPY . /app
COPY docker/nginx/default.conf /etc/nginx/http.d/default.conf
COPY docker/nginx/nginx.conf /etc/nginx/nginx.conf
COPY docker/php/php.ini /usr/local/etc/php/conf.d/99-cdnlite.ini
COPY docker/php/opcache.ini /usr/local/etc/php/conf.d/10-opcache.ini
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/zz-cdnlite.conf
COPY docker/supervisor/supervisord.conf /etc/supervisord.conf
RUN mkdir -p /app/storage /app/bootstrap/cache /run/nginx /var/log/supervisor \
  && chown -R www-data:www-data /app/storage /app/bootstrap/cache
RUN chmod +x /app/docker-entrypoint.sh
EXPOSE 8080
ENTRYPOINT ["/app/docker-entrypoint.sh"]
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisord.conf"]
