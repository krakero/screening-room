# syntax=docker/dockerfile:1.7

########################################
# PHP dependencies
########################################
FROM composer:2 AS vendor-build
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install \
        --no-dev \
        --no-interaction \
        --no-progress \
        --no-scripts \
        --optimize-autoloader

########################################
# Frontend assets (Vite build)
########################################
FROM node:22-alpine AS node-build
WORKDIR /app
COPY package.json package-lock.json ./
RUN npm ci
COPY resources ./resources
COPY vite.config.js ./
COPY public ./public
COPY --from=vendor-build /app/vendor ./vendor
RUN npm run build

########################################
# Runtime image
########################################
FROM dunglas/frankenphp:1-php8.4 AS app

ARG APP_VERSION=dev
ARG APP_CHANNEL=develop
ARG APP_COMMIT=
ARG TARGETARCH

ENV APP_VERSION=${APP_VERSION}
ENV APP_CHANNEL=${APP_CHANNEL}
ENV APP_COMMIT=${APP_COMMIT}

RUN apt-get update && apt-get install -y --no-install-recommends \
        curl \
        libpq-dev \
        default-mysql-client \
        procps \
        unzip \
        supervisor \
    && install-php-extensions \
        pdo_mysql \
        redis \
        bcmath \
        pcntl \
        intl \
        opcache \
    && apt-get purge -y --auto-remove \
    && rm -rf /var/lib/apt/lists/*

# Install Docker CLI and compose plugin (pinned versions)
RUN DOCKER_VERSION=27.3.1 && \
    COMPOSE_VERSION=v2.29.7 && \
    ARCH_MAP=$([ "$TARGETARCH" = "amd64" ] && echo "x86_64" || echo "aarch64") && \
    curl -fsSL "https://download.docker.com/linux/static/stable/${ARCH_MAP}/docker-${DOCKER_VERSION}.tgz" | tar xz --strip-components=1 -C /usr/local/bin docker/docker && \
    mkdir -p /usr/local/lib/docker/cli-plugins && \
    curl -fsSL "https://github.com/docker/compose/releases/download/${COMPOSE_VERSION}/docker-compose-linux-${ARCH_MAP}" -o /usr/local/lib/docker/cli-plugins/docker-compose && \
    chmod +x /usr/local/lib/docker/cli-plugins/docker-compose

WORKDIR /app

COPY . .
COPY --from=vendor-build /app/vendor ./vendor
COPY --from=node-build /app/public/build ./public/build

COPY docker/Caddyfile /etc/caddy/Caddyfile
COPY docker/php.ini /usr/local/etc/php/conf.d/99-app.ini
COPY docker/supervisord.conf /etc/supervisor/conf.d/supervisord.conf
COPY docker/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh \
    && mkdir -p storage/framework/{cache,sessions,testing,views} storage/logs bootstrap/cache \
    && php artisan package:discover --ansi \
    && chown -R www-data:www-data storage bootstrap/cache

ENV SERVER_NAME=:80
EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["supervisord", "-c", "/etc/supervisor/conf.d/supervisord.conf", "-n"]
