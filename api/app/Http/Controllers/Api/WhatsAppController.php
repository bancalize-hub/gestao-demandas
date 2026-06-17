<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    private function evo(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
            ->withHeaders(['apikey' => (string) config('services.evolution.key')])
            ->timeout(20);
    }

    private function instance(): string
    {
        return (string) config('services.evolution.instance');
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }

    /** Estado da conexão (open | connecting | close) + número conectado. */
    public function status(Request $request)
    {
        $this->ensureAdmin($request);

        $state = $this->evo()->get("/instance/connectionState/{$this->instance()}")
            ->json('instance.state') ?? 'close';

        $number = null;
        if ($state === 'open') {
            $list = $this->evo()->get('/instance/fetchInstances', ['instanceName' => $this->instance()])->json();
            $jid = $list[0]['ownerJid'] ?? null;
            $number = $jid ? explode('@', $jid)[0] : null;
        }

        return response()->json(['state' => $state, 'number' => $number]);
    }

    /** QR code (base64) para parear. */
    public function qr(Request $request)
    {
        $this->ensureAdmin($request);

        $res = $this->evo()->get("/instance/connect/{$this->instance()}");

        return response()->json([
            'base64' => $res->json('base64'),
            'pairingCode' => $res->json('pairingCode'),
        ]);
    }

    /** Código de pareamento (alternativa ao QR): WhatsApp > conectar com número. */
    public function pair(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate(['number' => 'required|string']);
        $number = preg_replace('/\D/', '', $data['number']);

        $res = $this->evo()->get("/instance/connect/{$this->instance()}", ['number' => $number]);

        return response()->json(['pairingCode' => $res->json('pairingCode')]);
    }

    /** Desconecta o WhatsApp (logout do aparelho). */
    public function logout(Request $request)
    {
        $this->ensureAdmin($request);

        $this->evo()->delete("/instance/logout/{$this->instance()}");

        return response()->json(['message' => 'ok']);
    }

    /** Importa a conversa COMPLETA de um número (paginado) para o chat do CRM. */
    public function import(Request $request)
    {
        $this->ensureAdmin($request);

        $number = preg_replace('/\D/', '', $request->validate(['number' => 'required|string'])['number']);

        $pic = $this->evo()->post("/chat/fetchProfilePictureUrl/{$this->instance()}", ['number' => $number])
            ->json('profilePictureUrl');

        $conv = $this->importConversation("{$number}@s.whatsapp.net", maxPages: 30, avatar: $pic);

        if (! $conv) {
            return response()->json(['message' => 'Nenhuma mensagem encontrada para esse número.'], 404);
        }

        return response()->json(['conversation' => $conv->load('messages')]);
    }

    /**
     * Sincroniza o chat com o WhatsApp: remove as conversas de exemplo e
     * importa as conversas reais mais recentes (versão leve, 1 página cada).
     */
    public function sync(Request $request)
    {
        $this->ensureAdmin($request);

        $removeDemo = $request->boolean('remove_demo', true);
        if ($removeDemo) {
            $demoIds = Conversation::where('slug', 'not like', 'wa-%')->pluck('id');
            Message::whereIn('conversation_id', $demoIds)->delete();
            Conversation::whereIn('id', $demoIds)->delete();
        }

        // Sem limit → importa TODAS as conversas individuais.
        $limit = $request->input('limit');

        // Mapa de contatos (nome + foto) — 1 chamada.
        $contacts = collect($this->evo()->post("/chat/findContacts/{$this->instance()}", [])->json())
            ->filter(fn ($c) => is_array($c) && ! empty($c['remoteJid']))
            ->keyBy('remoteJid');

        // Conversas individuais: exclui grupos (@g.us) e broadcast; inclui número e @lid.
        $chats = collect($this->evo()->post("/chat/findChats/{$this->instance()}", [])->json())
            ->filter(function ($c) {
                $jid = is_array($c) ? (string) ($c['remoteJid'] ?? '') : '';

                return (str_ends_with($jid, '@s.whatsapp.net') || str_ends_with($jid, '@lid'))
                    && empty($c['isGroup']);
            })
            ->sortByDesc(fn ($c) => $c['updatedAt'] ?? '');

        if ($limit !== null) {
            $chats = $chats->take((int) $limit);
        }

        $imported = 0;
        $seen = [];
        foreach ($chats as $c) {
            $jid = (string) $c['remoteJid'];
            if (isset($seen[$jid])) {
                continue; // não importa a mesma conversa 2x
            }
            $seen[$jid] = true;

            $ct = $contacts[$jid] ?? null;
            $name = $ct['pushName'] ?? ($c['pushName'] ?? null);
            $avatar = $ct['profilePicUrl'] ?? ($c['profilePicUrl'] ?? null);

            // No bulk: só as 30 mensagens mais recentes por conversa (peso do chat).
            if ($this->importConversation($jid, maxPages: 1, keepLast: 30, name: $name, avatar: $avatar)) {
                $imported++;
            }
        }

        return response()->json(['imported' => $imported, 'demo_removed' => $removeDemo]);
    }

    /** Busca mensagens de um JID no Evolution e grava como conversa+thread. */
    private function importConversation(string $remoteJid, int $maxPages, ?int $keepLast = null, ?string $name = null, ?string $avatar = null): ?Conversation
    {
        [$local, $domain] = array_pad(explode('@', $remoteJid, 2), 2, '');
        $isPhone = $domain === 's.whatsapp.net';
        $slug = 'wa-'.preg_replace('/[^a-z0-9]/i', '', $local);

        $records = [];
        $page = 1;
        do {
            $body = $this->evo()->post("/chat/findMessages/{$this->instance()}", [
                'where' => ['key' => ['remoteJid' => $remoteJid]],
                'page' => $page,
                'offset' => 100,
            ])->json('messages') ?? [];

            $batch = $body['records'] ?? [];
            $records = array_merge($records, $batch);
            $pages = (int) ($body['pages'] ?? 1);
            $page++;
        } while ($page <= $pages && $page <= $maxPages && count($batch) > 0);

        if (count($records) === 0) {
            return null;
        }

        usort($records, fn ($a, $b) => ((int) ($a['messageTimestamp'] ?? 0)) <=> ((int) ($b['messageTimestamp'] ?? 0)));

        if ($keepLast !== null && count($records) > $keepLast) {
            $records = array_slice($records, -$keepLast);
        }

        // Número real — inclusive p/ @lid (via key.remoteJidAlt).
        $realNumber = $isPhone ? $local : null;
        if (! $isPhone) {
            foreach ($records as $r) {
                $alt = (string) ($r['key']['remoteJidAlt'] ?? '');
                if (str_ends_with($alt, '@s.whatsapp.net')) {
                    $realNumber = explode('@', $alt)[0];
                    break;
                }
            }
        }

        if ($name === null) {
            foreach ($records as $r) {
                if (! ($r['key']['fromMe'] ?? false) && ! empty($r['pushName'])) {
                    $name = $r['pushName'];
                    break;
                }
            }
        }
        $name = $name ?: ($realNumber ? ('+'.$realNumber) : 'Contato WhatsApp');

        $conv = Conversation::firstOrNew(['slug' => $slug]);
        $conv->name = $name;
        $conv->initials = $this->initialsOf($name);
        $conv->color = $conv->color ?: '#6b7cff';
        if ($avatar) {
            $conv->avatar = $avatar;
        }
        $conv->phone = $realNumber ? ('+'.$realNumber) : null;
        $conv->origin = 'WhatsApp';
        $conv->status_text = 'via WhatsApp';
        $conv->online = false;
        $conv->unread = 0;
        $conv->position = $conv->position ?: ((int) (Conversation::max('position') ?? 0) + 1);
        $conv->save();

        $conv->messages()->delete();

        $pos = 0;
        $last = null;
        $lastTs = 0;
        foreach ($records as $r) {
            $msg = $r['message'] ?? [];
            $text = $msg['conversation'] ?? $msg['extendedTextMessage']['text'] ?? null;

            if ($text === null) {
                $mt = (string) ($r['messageType'] ?? '');
                $text = match (true) {
                    str_contains($mt, 'image') => '📷 Imagem',
                    str_contains($mt, 'audio') => '🎵 Áudio',
                    str_contains($mt, 'video') => '🎬 Vídeo',
                    str_contains($mt, 'document') => '📄 Documento',
                    str_contains($mt, 'sticker') => 'Figurinha',
                    default => null,
                };
                if ($text === null) {
                    continue;
                }
            }

            $ts = (int) ($r['messageTimestamp'] ?? 0);
            $conv->messages()->create([
                'type' => 'text',
                'is_out' => (bool) ($r['key']['fromMe'] ?? false),
                'text' => mb_substr($text, 0, 4000),
                'time' => $ts ? date('H:i', $ts) : null,
                'position' => $pos++,
            ]);
            $last = $text;
            $lastTs = $ts;
        }

        // Sem mensagens de texto úteis → descarta (chats de sistema/mídia pura).
        if ($pos === 0) {
            $conv->messages()->delete();
            $conv->delete();

            return null;
        }

        $conv->preview = mb_substr((string) $last, 0, 80);
        $conv->time = $lastTs ? $this->humanDate($lastTs) : null;
        $conv->save();

        return $conv;
    }

    /** Prévia: hoje → HH:MM, ontem → "Ontem", senão → DD/MM/AAAA. */
    private function humanDate(int $ts): string
    {
        $day = date('Y-m-d', $ts);
        if ($day === date('Y-m-d')) {
            return date('H:i', $ts);
        }
        if ($day === date('Y-m-d', strtotime('-1 day'))) {
            return 'Ontem';
        }

        return date('d/m/Y', $ts);
    }

    private function initialsOf(string $name): string
    {
        $p = preg_split('/\s+/', trim($name)) ?: [];
        $ini = mb_strtoupper(mb_substr($p[0] ?? '', 0, 1).mb_substr($p[1] ?? '', 0, 1));

        return $ini !== '' ? $ini : 'WA';
    }
}
