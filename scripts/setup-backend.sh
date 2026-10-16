#!/bin/bash
# =========================================================
# CourtQ バックエンド初期セットアップスクリプト
# 依存関係のインストール・.env 作成・マイグレーションを行う
# 実行: プロジェクトルートで bash scripts/setup-backend.sh
# =========================================================
set -e

echo "▶ 1/4 依存関係をインストール中..."
docker compose run --rm php composer install --no-interaction

echo "▶ 2/4 .env を設定中..."
if [ ! -f backend/.env ]; then
  cp backend/.env.example backend/.env
fi
sed -i.bak \
  -e 's/^DB_HOST=.*/DB_HOST=mysql/' \
  -e 's/^DB_DATABASE=.*/DB_DATABASE=courtq_db/' \
  -e 's/^DB_USERNAME=.*/DB_USERNAME=courtq_user/' \
  -e 's/^DB_PASSWORD=.*/DB_PASSWORD=courtq_pass/' \
  -e 's/^QUEUE_CONNECTION=.*/QUEUE_CONNECTION=database/' \
  backend/.env && rm backend/.env.bak

grep -q '^APP_FRONTEND_URL=' backend/.env || printf '\nAPP_FRONTEND_URL=http://localhost:5173\n' >> backend/.env

echo "▶ 3/4 アプリケーションキーを生成中..."
if ! grep -q '^APP_KEY=base64:' backend/.env; then
  docker compose run --rm php php artisan key:generate
fi

echo "▶ 4/4 マイグレーション実行中..."
docker compose run --rm php php artisan migrate --force

echo "✅ バックエンドのセットアップが完了しました"
echo "   API: http://localhost/api"
echo "   キューワーカーを再起動してください: docker compose restart queue"
