#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${CD_APP_DIR:-/home/staging.example.com/app/current}"
BRANCH="${CD_BRANCH:-develop}"

cd "$APP_DIR"

echo "[1/8] Fetch & reset"
git fetch origin
git reset --hard "origin/${BRANCH}"

echo "[2/8] Clean vendor/cache"
rm -rf vendor bootstrap/cache/*.php || true

echo "[3/8] Composer install"
composer install --no-interaction --prefer-dist --no-dev

echo "[4/8] Clear caches"
php artisan optimize:clear || true

echo "[5/8] Rebuild caches"
php artisan config:cache
php artisan route:cache || true
php artisan view:cache || true

echo "[6/8] Migrate"
php artisan migrate --force

echo "[7/8] Storage link (optional)"
php artisan storage:link || true

echo "[8/8] Done"
