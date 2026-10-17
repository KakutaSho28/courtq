#!/bin/sh
# web サービスの起動: ポート設定 → 環境変数の確認 → DB の待機 → migrate → キャッシュ → nginx + php-fpm
set -e

: "${PORT:=8080}"
export PORT
envsubst '${PORT}' < /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf

cd /var/www/html

# 環境変数の取り違え（DB_PASSWORD が空、CORS の末尾スラッシュ、ローカルの値など）を、日本語で直し方つきで知らせて止める。
# 何も知らせずに migrate が落ち続ける再起動ループを避けるため。MySQL が web より遅れて起動する場合に備えて、DB に接続できるまで最大 60 秒待つ
php artisan app:deploy-check --wait-db=60

php artisan migrate --force

# 公開デモ用のデータ（デモユーザー・会場・試合・ブース・メニュー）を投入する。冪等なので毎回起動時に実行してよい
if [ "${SEED_DEMO:-}" = "true" ]; then
    php artisan db:seed --force
fi

php artisan config:cache
php artisan route:cache

chown -R www-data:www-data storage bootstrap/cache

exec supervisord -c /etc/supervisor/conf.d/courtq.conf
