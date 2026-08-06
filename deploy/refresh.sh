#!/usr/bin/env bash
# Recarrega os caches do Laravel a partir do código que está no disco AGORA.
#
# Existe porque /var/www/gestao É a produção ao vivo: um `git merge` ali troca o código
# que o PHP-FPM já está servindo, mas NÃO o config cache. Config velho não quebra nada de
# forma visível — ele só faz uma chave sumir. Foi assim que `services.nudge` ficou de fora
# do cache e a retomada ativa parou de rodar em silêncio: o scheduler chamava o tick a cada
# 5 min, o tick lia `delays_minutes` como null e voltava na primeira linha, sem erro nenhum.
set -euo pipefail

APP_DIR=${1:-/var/www/gestao}

cd "$APP_DIR/api"
php artisan config:cache
php artisan route:cache
php artisan view:cache
