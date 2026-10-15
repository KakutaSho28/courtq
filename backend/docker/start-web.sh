#!/bin/sh
# web サービスの起動: ポート設定 → migrate → キャッシュ → nginx + php-fpm
set -e

: "${PORT:=8080}"
export PORT
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

cd /var/www/html
php artisan migrate --force

# 公開デモ用のデータ（デモユーザー・会場・試合・ブース・メニュー）を投入する。冪等なので毎回起動時に実行してよい
if [ "${SEED_DEMO:-}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/conf.d/courtq.conf
