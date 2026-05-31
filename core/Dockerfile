FROM php:8.3-cli-alpine

RUN apk add --no-cache postgresql-dev libpq \
  && docker-php-ext-install pdo pdo_pgsql \
  && apk del postgresql-dev

WORKDIR /app
COPY . /app
RUN mkdir -p /app/storage
EXPOSE 8080
CMD ["php", "-S", "0.0.0.0:8080", "public_index.php"]
