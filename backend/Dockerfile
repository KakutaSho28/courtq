# syntax=docker/dockerfile:1
# CourtQ API 本番イメージ（Railway 用）。nginx + php-fpm を supervisord で1コンテナにまとめる。
# build context: backend/（Railway の Root Directory = backend）

# ---- 依存関係 ----
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --no-scripts \
    --prefer-dist --optimize-autoloader --ignore-platform-reqs

# ---- 実行イメージ ----
FROM php:8.2-fpm

# php:8.2-fpm には mbstring・opcache・ctype・tokenizer など Laravel に必要な拡張が入っている（`php -m` で確認済み）。
# 足すのは MySQL 用の pdo_mysql と、queue:work のタイムアウトに必要な pcntl、bcmath だけ。
# 画像アップロードや zip は使わないので、gd / exif / zip と、そのための -dev パッケージは入れない（ビルドが速く、失敗しにくい）
RUN apt-get update && apt-get install -y --no-install-recommends \
        nginx supervisor gettext-base \
    && docker-php-ext-install pdo_mysql pcntl bcmath \
    && apt-get clean && rm -rf /var/lib/apt/lists/*

COPY docker/php.ini /usr/local/etc/php/conf.d/zz-courtq.ini
COPY docker/php-fpm.conf /usr/local/etc/php-fpm.d/zz-courtq.conf
COPY docker/nginx.conf.template /etc/nginx/nginx.conf.template
COPY docker/supervisord.conf /etc/supervisor/conf.d/courtq.conf
COPY docker/start-web.sh /usr/local/bin/start-web
RUN chmod +x /usr/local/bin/start-web

WORKDIR /var/www/html
COPY . .
COPY --from=vendor /app/vendor ./vendor
RUN php artisan package:discover --ansi \
    && mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs \
    && chown -R www-data:www-data storage bootstrap/cache

ENV APP_ENV=production \
    APP_DEBUG=false \
    LOG_CHANNEL=stderr

# web サービスの既定コマンド。worker サービスは Start Command を上書きする（docs/deployment.md）
CMD ["start-web"]
