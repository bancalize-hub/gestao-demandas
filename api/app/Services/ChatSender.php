<?php

namespace App\Services;

use App\Models\Conversation;
use App\Models\Material;
use App\Models\Message;
use App\Support\Evolution;
use App\Support\Realtime;
use App\Support\Wa;
use Illuminate\Support\Facades\Storage;

/**
 * Envia mensagem da IA pelo WhatsApp da conversa e espelha a bolha no chat.
 *
 * Existe para o atendimento automático (resposta) e a retomada ativa (nudge)
 * compartilharem exatamente o mesmo caminho de envio: o wa_id do envio já nasce
 * na linha (o eco do webhook não duplica), a conversa fica com preview/hora
 * certos e o painel recebe o push antes de qualquer refetch.
 */
class ChatSender
{
    /** Envia um texto e devolve a mensagem espelhada (null = o WhatsApp recusou). */
    public function text(Conversation $conv, string $text): ?Message
    {
        if (! $conv->phone) {
            return null;
        }

        $waId = Wa::forConversation($conv)->sendText($conv->phone, $text);
        if ($waId === null) {
            return null;
        }

        return $this->espelhar($conv, $waId, $text);
    }

    /**
     * Envia um TEMPLATE aprovado — o único caminho da Cloud API fora da janela de 24h.
     *
     * `$espelho` é a mesma frase do template já com as variáveis trocadas: a Meta recebe
     * nome + parâmetros, e o chat mostra a bolha que o cliente realmente leu (senão o
     * atendente veria um vazio na conversa e responderia sem saber o que foi enviado).
     */
    public function template(Conversation $conv, string $name, string $language, array $bodyParams, string $espelho): ?Message
    {
        if (! $conv->phone || $name === '') {
            return null;
        }

        $waId = Wa::forConversation($conv)->sendTemplate($conv->phone, $name, $language, $bodyParams);
        if ($waId === null) {
            return null;
        }

        return $this->espelhar($conv, $waId, $espelho);
    }

    /** Grava a bolha de saída no chat e avisa o painel. */
    private function espelhar(Conversation $conv, string $waId, string $text): Message
    {
        $ts = time();
        $data = [
            'type' => 'text',
            'is_out' => true,
            'text' => mb_substr($text, 0, 4000),
            'time' => date('H:i', $ts),
            'ts' => $ts,
            'position' => ((int) $conv->messages()->max('position')) + 1,
        ];
        // Já grava com o wa_id do envio → o eco do webhook é ignorado (não duplica).
        // updateOrCreate por wa_id fecha a corrida caso o eco tenha chegado primeiro.
        $msg = $waId !== ''
            ? $conv->messages()->updateOrCreate(['wa_id' => $waId], $data)
            : $conv->messages()->create($data);

        $conv->update([
            'preview' => mb_substr($text, 0, 80),
            'time' => date('H:i', $ts),
            'last_message_at' => now(),
        ]);
        // Carimba último contato nosso e, se for o caso, a data da proposta — é o que
        // alimenta o watchdog de negócio órfão e a meta de "proposta em 24h".
        $conv->registrarSaida($text, $data['type'] ?? 'text');

        // Depois do update: o evento carrega a linha da conversa lida do banco —
        // broadcastar antes mandaria preview/hora velhos pro painel.
        Realtime::messageCreated($msg);

        return $msg;
    }

    /** Envia o material escolhido pela IA (PDF etc.) e espelha a bolha no chat. */
    public function material(Conversation $conv, Material $material): bool
    {
        $path = Storage::path($material->path);
        if (! is_file($path)) {
            Evolution::log('ia.material_sumiu', ['material' => $material->id, 'path' => $material->path], 'error');

            return false;
        }

        $waId = Wa::forConversation($conv)->sendMedia(
            (string) $conv->phone,
            base64_encode((string) file_get_contents($path)),
            $material->mime,
            $material->filename,
            '',
            'document',
        );

        if ($waId === null) {
            Evolution::log('ia.material_falhou', ['material' => $material->id, 'conversation_id' => $conv->id], 'error');

            return false;
        }

        $ts = time();
        $data = [
            'type' => 'file',
            'is_out' => true,
            'file_name' => $material->filename,
            'meta' => $material->mime,
            'status' => 'sent',
            'time' => date('H:i', $ts),
            'ts' => $ts,
            'position' => ((int) $conv->messages()->max('position')) + 1,
        ];
        $msg = $waId !== ''
            ? $conv->messages()->updateOrCreate(['wa_id' => $waId], $data)
            : $conv->messages()->create($data);

        // Cache local da mídia enviada: a Meta não deixa baixar de volta o que saiu daqui,
        // então sem esta cópia a bolha ficaria sem o arquivo para abrir.
        $dir = storage_path('app/wa-media');
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        if (@copy($path, "{$dir}/{$msg->id}")) {
            @file_put_contents("{$dir}/{$msg->id}.mime", $material->mime);
        }

        $conv->update(['preview' => '📄 '.$material->filename, 'time' => $data['time'], 'last_message_at' => now()]);
        Realtime::messageCreated($msg);
        Evolution::log('ia.material_enviado', ['material' => $material->id, 'conversation_id' => $conv->id]);

        return true;
    }
}
