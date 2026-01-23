#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${CD_APP_DIR:-/home/staging.example.com/app/current}"
BRANCH="${CD_BRANCH:-develop}"

cd "$APP_DIR"

echo "[1/6] Fetch & reset to origin/${BRANCH}"
git fetch origin
git reset --hard "origin/${BRANCH}"

echo "[2/6] Composer install"
composer install --no-interaction --prefer-dist --no-dev

echo "[3/6] Cache"
php artisan config:cache
php artisan route:cache || true
php artisan view:cache || true

echo "[4/6] Migrate"
php artisan migrate --force

echo "[5/6] Optimize (optional)"
php artisan optimize || true

echo "[6/6] Done"
