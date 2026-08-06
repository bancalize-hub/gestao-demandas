#!/usr/bin/env bash
# Copia deploy/hooks/* para o .git/hooks do repositório (hooks não são versionados).
# Rodar uma vez no VPS — e de novo se algum hook mudar.
set -euo pipefail

REPO_DIR=${1:-/var/www/gestao}
HOOKS_DIR="$(git -C "$REPO_DIR" rev-parse --git-common-dir)/hooks"

for hook in "$REPO_DIR"/deploy/hooks/*; do
    install -m 0755 "$hook" "$HOOKS_DIR/$(basename "$hook")"
    echo "instalado: $HOOKS_DIR/$(basename "$hook")"
done
