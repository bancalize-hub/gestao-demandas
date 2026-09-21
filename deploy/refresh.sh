#!/usr/bin/env bash
# Recarrega os caches do Laravel a partir do código que está no disco AGORA.
#
# Existe porque /var/www/gestao É a produção ao vivo: um `git merge` ali troca o código
# que o PHP-FPM já está servindo, mas NÃO o config cache. Config velho não quebra nada de
# forma visível — ele só faz uma chave sumir. Foi assim que `services.nudge` ficou de fora
# do cache e a retomada ativa parou de rodar em silêncio: o scheduler chamava o tick a cada
# 5 min, o tick lia `delays_minutes` como null e voltava na primeira linha, sem erro nenhum.
#
# E roda como www-data, NUNCA como root: `php artisan config:cache` grava
# bootstrap/cache/config.php com o dono de quem chamou. Rodado como root, o arquivo nasce
# root:root e o PHP-FPM (www-data) não consegue mais reescrevê-lo — o site inteiro passa a
# servir o cache velho e, no `route:cache` seguinte, quebra com "failed to open stream".
# O deploy chama este script como root, então a troca de usuário tem que estar AQUI.
set -euo pipefail

APP_DIR=${1:-/var/www/gestao}

# composer/artisan querem um HOME gravável; o de www-data (/var/www) não é dele.
HOME_WWW=/tmp/wwwhome-deploy
mkdir -p "$HOME_WWW" && chown www-data:www-data "$HOME_WWW"

artisan() {
    if [ "$(id -u)" = 0 ]; then
        sudo -u www-data env HOME="$HOME_WWW" php artisan "$@"
    else
        php artisan "$@"
    fi
}

cd "$APP_DIR/api"
artisan config:cache
artisan route:cache
artisan view:cache
