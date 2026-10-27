FROM composer:2 AS composer

FROM python:3.12-bookworm
RUN apt-get update \
    && apt-get install -y --no-install-recommends php-cli php-xml php-mbstring git unzip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer /usr/bin/composer /usr/local/bin/composer
WORKDIR /app
COPY pyproject.toml README.md ./
COPY src ./src
COPY php ./php
COPY bin ./bin
COPY fixtures ./fixtures
COPY tests ./tests
COPY .ub-mode.yaml ./
RUN composer install --working-dir=php/analyzer --no-interaction --prefer-dist --no-progress \
    && pip install --no-cache-dir -e '.[dev]'
ENTRYPOINT ["ub-php"]
