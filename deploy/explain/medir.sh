#!/usr/bin/env bash
# EXPLAIN das três consultas que a auditoria de 21/09/2026 pegou varrendo tabela inteira.
#
# Guardado como script (e não só como saída colada) para que "antes" e "depois" sejam
# comparáveis: mesma consulta, mesma máquina, mesmo banco de produção.
#
#   ./medir.sh > antes.txt      # antes da migration dos índices
#   ./medir.sh > depois.txt     # depois
set -euo pipefail

ENV_FILE=${1:-/var/www/gestao/api/.env}
DB=$(grep -E '^DB_DATABASE=' "$ENV_FILE" | cut -d= -f2-)
USER=$(grep -E '^DB_USERNAME=' "$ENV_FILE" | cut -d= -f2-)
PASS=$(grep -E '^DB_PASSWORD=' "$ENV_FILE" | cut -d= -f2- | sed 's/^"//; s/"$//')

echo "# EXPLAIN — $(date '+%Y-%m-%d %H:%M:%S')"

run() { echo; echo "## $1"; MYSQL_PWD="$PASS" mysql -u"$USER" "$DB" -e "EXPLAIN $2"; }

run "VoiceTranscribeTick (roda a cada minuto)" \
  "SELECT * FROM messages WHERE type='voice' AND is_out=0 AND transcript IS NULL
     AND transcribe_attempts < 5 AND wa_id IS NOT NULL
     AND ts > UNIX_TIMESTAMP(NOW() - INTERVAL 2 DAY)
   ORDER BY id DESC LIMIT 20;"

run "Lista de conversas (a cada refetch de aba)" \
  "SELECT * FROM conversations WHERE company_id = 2 AND hidden_at IS NULL
   ORDER BY last_message_at DESC LIMIT 60;"

run "AutoReplyTick" \
  "SELECT * FROM conversations WHERE auto_reply = 1 AND auto_reply_due_at IS NOT NULL
     AND auto_reply_due_at <= NOW();"
