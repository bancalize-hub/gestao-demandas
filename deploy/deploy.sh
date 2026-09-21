#!/usr/bin/env bash
# Executado no VPS pelo GitHub Actions (ou manualmente) a cada deploy.
set -euo pipefail

APP_DIR=/var/www/gestao

# ---- Rede de segurança ANTES de qualquer coisa ----
# `git reset --hard` e `migrate` são as duas linhas deste arquivo que não têm volta.
# Um dump imediatamente antes custa ~20 segundos e é a diferença entre "voltamos em 5
# minutos" e "perdemos o dia". Se o dump falhar, o deploy NÃO acontece.
if ! /root/bin/backup-diario.sh; then
    echo "ABORTADO: o backup pré-deploy falhou — corrija antes de publicar." >&2
    exit 1
fi

cd "$APP_DIR"
git fetch origin main
git reset --hard origin/main

# ---- API (Laravel) ----
cd "$APP_DIR/api"
composer install --no-dev --optimize-autoloader --no-interaction
php artisan migrate --force
php artisan storage:link || true
"$APP_DIR/deploy/refresh.sh" "$APP_DIR"

# ---- Front (Nuxt) ----
cd "$APP_DIR/web"
npm ci
npm run build

# Reinicia o servidor Node do Nuxt (porta 3000)
pm2 reload gestao-web 2>/dev/null || pm2 start .output/server/index.mjs --name gestao-web
pm2 save

echo "Deploy concluido em $(date)"
