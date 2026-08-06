<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Stage;
use App\Support\Wa;

/**
 * Move uma conversa/negócio de etapa do funil de forma consistente, de um único lugar:
 * atualiza stage + cor + etiqueta exibida (tags), registra na linha do tempo e
 * sincroniza a etiqueta no WhatsApp Business. Usado pelo controller (arraste/edição
 * manual) e pelos automatismos (reunião agendada/realizada).
 */
class StageMover
{
    /**
     * @param  string  $stageKey  chave estável da etapa (coluna stages.key)
     * @param  ?string  $activityTitle  título da atividade na linha do tempo (null = "Movido para …")
     * @return bool true se a etapa mudou de fato
     */
    public static function move(Conversation $conversation, string $stageKey, ?int $userId = null, ?string $activityTitle = null): bool
    {
        $stage = Stage::where('key', $stageKey)->first();
        if (! $stage) {
            return false;
        }

        $oldKey = $conversation->stage;
        if ($oldKey === $stage->key) {
            return false; // já está na etapa — nada a fazer
        }

        $conversation->stage = $stage->key;
        $conversation->stage_color = $stage->color;
        // A etiqueta exibida espelha a etapa atual (mesmo formato usado ao criar a conversa).
        $conversation->tags = [['label' => $stage->name, 'color' => $stage->color]];
        $conversation->save();

        LeadActivity::log(
            $conversation->id,
            'etapa',
            $activityTitle ?: "Movido para “{$stage->name}”",
            null,
            $userId,
        );

        self::syncWhatsAppLabel($conversation, $oldKey, $stage->key);

        // Venda fechada → conversão para a Meta. Fica AQUI, e não no controller, porque
        // este é o único lugar por onde toda mudança de etapa passa: arraste no funil,
        // edição na ficha e automação caem todos neste método.
        if ($stage->key === (string) config('services.crm.stage_won', 'fechado')) {
            \App\Support\MetaConversions::enviarUmaVez(
                $conversation,
                \App\Support\MetaConversions::VENDA,
                array_filter([
                    'currency' => 'BRL',
                    'value' => is_numeric($conversation->deal_value) ? (float) $conversation->deal_value : null,
                ], fn ($v) => $v !== null),
            );
        }

        return true;
    }

    /** Aplica no WhatsApp a etiqueta da nova etapa e remove a da etapa anterior. */
    public static function syncWhatsAppLabel(Conversation $conversation, ?string $oldKey, ?string $newKey): void
    {
        $number = preg_replace('/\D/', '', (string) $conversation->phone);
        if ($number === '') {
            return;
        }
        $old = $oldKey ? Stage::where('key', $oldKey)->first() : null;
        $new = $newKey ? Stage::where('key', $newKey)->first() : null;

        if ($old && $old->wa_label_id) {
            Wa::forConversation($conversation)->handleLabel($number, $old->wa_label_id, 'remove');
        }
        if ($new && $new->wa_label_id) {
            Wa::forConversation($conversation)->handleLabel($number, $new->wa_label_id, 'add');
        }
    }
}
