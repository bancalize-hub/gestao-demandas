#!/usr/bin/env bash
# Executado no VPS pelo GitHub Actions (ou manualmente) a cada deploy.
set -euo pipefail

APP_DIR=/var/www/gestao

cd "$APP_DIR"
git fetch origin main
git reset --hard origin/main

# ---- API (Laravel) ----
cd "$APP_DIR/api"
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan storage:link || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

# ---- Front (Nuxt) ----
cd "$APP_DIR/web"
npm ci
npm run build

# Reinicia o servidor Node do Nuxt (porta 3000)
pm2 reload gestao-web 2>/dev/null || pm2 start .output/server/index.mjs --name gestao-web
pm2 save

echo "Deploy concluido em $(date)"
