FROM php:8.4-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libicu-dev libzip-dev libonig-dev libsqlite3-dev unzip git \
    && docker-php-ext-install -j$(nproc) pdo_pgsql pdo_sqlite mbstring intl zip bcmath pcntl \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
RUN useradd --create-home --uid 1000 app && mkdir -p /app/vendor && chown -R app:app /app
WORKDIR /app
COPY --chmod=755 docker/php/entrypoint.sh /usr/local/bin/dev-entrypoint
USER app
ENTRYPOINT ["dev-entrypoint"]
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
