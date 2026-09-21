<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Índices para as consultas que hoje varrem a tabela inteira.
 *
 * Medido com EXPLAIN em 21/09/2026 (deploy/explain/antes.txt):
 *   - VoiceTranscribeTick: type=ALL, 63.885 linhas, filtered 0.10%, Using filesort — e isso
 *     roda A CADA MINUTO, para achar no máximo 5 áudios sem transcrição.
 *   - AutoReplyTick: type=ALL em conversations, sem nenhum índice em auto_reply_due_at.
 *   - Lista de conversas: varre por last_message_at e filtra company_id/hidden_at depois.
 *
 * No tamanho de hoje o custo é CPU, não disco (a base cabe no buffer pool) — e CPU é
 * exatamente o que precisa sobrar antes de somar worker de fila na máquina de 2 vCPU.
 *
 * Tudo aditivo: nenhuma coluna muda, nenhum dado é reescrito.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            // Fila de transcrição de áudio (type + is_out + ordem por ts).
            $t->index(['type', 'is_out', 'ts'], 'messages_tipo_saida_ts_index');
            // Recorte por empresa em ordem cronológica (relatórios e varreduras).
            $t->index(['company_id', 'ts'], 'messages_empresa_ts_index');
            // Entregabilidade: quantas saíram e quantas falharam na janela.
            $t->index(['is_out', 'status', 'ts'], 'messages_saida_status_ts_index');
        });

        Schema::table('conversations', function (Blueprint $t) {
            // Lista do chat: empresa + não arquivada, ordenada pela última mensagem.
            $t->index(['company_id', 'hidden_at', 'last_message_at'], 'conversas_empresa_visivel_ultima_index');
            // Atendimento automático vencido.
            $t->index(['auto_reply', 'auto_reply_due_at'], 'conversas_auto_reply_index');
            // Funil e triagem por empresa (contagens do /marketing e do /pipeline).
            $t->index(['company_id', 'stage'], 'conversas_empresa_etapa_index');
            $t->index(['company_id', 'qualified'], 'conversas_empresa_qualificado_index');
            $t->index(['company_id', 'created_at'], 'conversas_empresa_criacao_index');
        });
    }

    public function down(): void
    {
        Schema::table('messages', function (Blueprint $t) {
            $t->dropIndex('messages_tipo_saida_ts_index');
            $t->dropIndex('messages_empresa_ts_index');
            $t->dropIndex('messages_saida_status_ts_index');
        });

        Schema::table('conversations', function (Blueprint $t) {
            $t->dropIndex('conversas_empresa_visivel_ultima_index');
            $t->dropIndex('conversas_auto_reply_index');
            $t->dropIndex('conversas_empresa_etapa_index');
            $t->dropIndex('conversas_empresa_qualificado_index');
            $t->dropIndex('conversas_empresa_criacao_index');
        });
    }
};
