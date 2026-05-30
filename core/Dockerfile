FROM php:8.3-cli-alpine

RUN docker-php-ext-install pdo pdo_pgsql pdo_sqlite

WORKDIR /app
COPY . /app
RUN mkdir -p /app/storage
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "public_index.php"]
