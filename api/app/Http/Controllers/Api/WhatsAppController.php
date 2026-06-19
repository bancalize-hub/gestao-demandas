<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WaAccount;
use Illuminate\Http\Request;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class WhatsAppController extends Controller
{
    private function evo(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
            ->withHeaders(['apikey' => (string) config('services.evolution.key')])
            ->timeout(20);
    }

    /** Instância-alvo: a passada, ou a principal (config) por padrão. */
    private function instance(?string $instance = null): string
    {
        return $instance ?: (string) config('services.evolution.instance');
    }

    /** Conta-alvo da requisição (?account=<id> ou body account_id); default = principal. */
    private function account(Request $request): WaAccount
    {
        $id = $request->input('account_id', $request->query('account'));
        if ($id) {
            return WaAccount::findOrFail((int) $id);
        }
        $primary = WaAccount::primary();
        abort_unless($primary, 404, 'Conta principal não configurada.');

        return $primary;
    }

    /** URL do webhook (mesmo endpoint compartilhado; a instância vem no payload). */
    private function webhookUrl(): string
    {
        return rtrim((string) config('app.url'), '/').'/api/wpp/webhook?token='.config('services.evolution.webhook_token');
    }

    /** Eventos do webhook que o app consome (espelha a instância principal). */
    private const WEBHOOK_EVENTS = ['MESSAGES_UPSERT', 'MESSAGES_SET', 'MESSAGES_UPDATE', 'LABELS_ASSOCIATION', 'LABELS_EDIT'];

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }

    /** Estado da conexão (open | connecting | close) + número conectado. Aceita ?account=<id>. */
    public function status(Request $request)
    {
        $this->ensureAdmin($request);

        $account = $this->account($request);
        $inst = $account->instance;

        $state = $this->evo()->get("/instance/connectionState/{$inst}")
            ->json('instance.state') ?? 'close';

        $number = null;
        if ($state === 'open') {
            $list = $this->evo()->get('/instance/fetchInstances', ['instanceName' => $inst])->json();
            $jid = $list[0]['ownerJid'] ?? null;
            $number = $jid ? explode('@', $jid)[0] : null;
        }

        // Mantém o cache da conta atualizado (usado na lista de números).
        if ($account->state !== $state || ($number && $account->phone !== $number)) {
            $account->update(['state' => $state, 'phone' => $number ?: $account->phone]);
        }

        return response()->json(['state' => $state, 'number' => $number]);
    }

    /** QR code (base64) para parear. Aceita ?account=<id>. */
    public function qr(Request $request)
    {
        $this->ensureAdmin($request);

        $res = $this->evo()->get('/instance/connect/'.$this->account($request)->instance);

        return response()->json([
            'base64' => $res->json('base64'),
            'pairingCode' => $res->json('pairingCode'),
        ]);
    }

    /** Código de pareamento (alternativa ao QR): WhatsApp > conectar com número. Aceita ?account=<id>. */
    public function pair(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate(['number' => 'required|string']);
        $number = preg_replace('/\D/', '', $data['number']);

        $res = $this->evo()->get('/instance/connect/'.$this->account($request)->instance, ['number' => $number]);

        return response()->json(['pairingCode' => $res->json('pairingCode')]);
    }

    /** Desconecta o WhatsApp (logout do aparelho). Aceita ?account=<id>. */
    public function logout(Request $request)
    {
        $this->ensureAdmin($request);

        $account = $this->account($request);
        $this->evo()->delete('/instance/logout/'.$account->instance);
        $account->update(['state' => 'close']);

        return response()->json(['message' => 'ok']);
    }

    /** Lista todos os números (contas) com estado de conexão ao vivo. Principal primeiro. */
    public function accounts(Request $request)
    {
        $this->ensureAdmin($request);

        $accounts = WaAccount::orderByRaw("role = 'primary' DESC")->orderBy('id')->get();

        foreach ($accounts as $a) {
            $state = $this->evo()->get("/instance/connectionState/{$a->instance}")
                ->json('instance.state') ?? 'close';
            $patch = [];
            if ($state !== $a->state) {
                $patch['state'] = $state;
            }
            if ($state === 'open' && ! $a->phone) {
                $list = $this->evo()->get('/instance/fetchInstances', ['instanceName' => $a->instance])->json();
                $jid = $list[0]['ownerJid'] ?? null;
                if ($jid) {
                    $patch['phone'] = explode('@', $jid)[0];
                }
            }
            if ($patch) {
                $a->update($patch);
            }
            $a->remaining_today = $a->remainingToday();
        }

        return response()->json(['accounts' => $accounts]);
    }

    /** Cria um novo número de prospecção (instância na Evolution + webhook). */
    public function createAccount(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => 'required|string|max:80',
            'daily_cap' => 'nullable|integer|min:1|max:1000',
        ]);

        // Nome de instância único e seguro (slug + sufixo aleatório).
        $instance = (Str::slug($data['name']) ?: 'numero').'-'.Str::lower(Str::random(5));

        $res = $this->evo()->timeout(40)->post('/instance/create', [
            'instanceName' => $instance,
            'integration' => 'WHATSAPP-BAILEYS',
            'qrcode' => true,
        ]);
        abort_unless($res->successful(), 502, 'Falha ao criar a instância na Evolution.');

        // Registra o webhook (mesmos eventos da principal) apontando pro endpoint compartilhado.
        $this->evo()->post("/webhook/set/{$instance}", [
            'webhook' => [
                'enabled' => true,
                'url' => $this->webhookUrl(),
                'webhookByEvents' => false,
                'webhookBase64' => false,
                'events' => self::WEBHOOK_EVENTS,
            ],
        ]);

        $account = WaAccount::create([
            'name' => $data['name'],
            'instance' => $instance,
            'role' => 'outreach',
            'is_active' => true,
            'daily_cap' => $data['daily_cap'] ?? 40,
            'state' => 'connecting',
        ]);

        return response()->json($account, 201);
    }

    /** Remove um número de prospecção (logout + delete na Evolution). O principal é protegido. */
    public function destroyAccount(Request $request, WaAccount $account)
    {
        $this->ensureAdmin($request);
        abort_if($account->isPrimary(), 422, 'Não é possível remover o número principal.');

        // Best-effort na Evolution: desconecta e apaga a instância.
        try {
            $this->evo()->delete("/instance/logout/{$account->instance}");
        } catch (\Throwable $e) {
        }
        try {
            $this->evo()->delete("/instance/delete/{$account->instance}");
        } catch (\Throwable $e) {
        }

        // Conversas dessa origem permanecem no histórico, mas sem vínculo de conta.
        $account->conversations()->update(['wa_account_id' => null]);
        $account->delete();

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

        $this->fillAvatars();

        return response()->json(['imported' => $imported, 'demo_removed' => $removeDemo]);
    }

    /**
     * Importa uma conversa exportada do WhatsApp (.txt) — Android ou iPhone.
     * Body: text (conteúdo), number, name?, client_sender? (nome de quem é o cliente).
     */
    public function importTxt(Request $request)
    {
        $data = $request->validate([
            'text' => 'required|string',
            'number' => 'required|string',
            'name' => 'nullable|string|max:255',
            'client_sender' => 'nullable|string|max:255',
        ]);

        $digits = preg_replace('/\D/', '', $data['number']);
        if ($digits === '') {
            return response()->json(['message' => 'Número inválido.'], 422);
        }
        if (strlen($digits) <= 11) {
            $digits = '55'.$digits;
        }
        $slug = 'wa-'.$digits;

        $parsed = $this->parseWhatsAppTxt($data['text']);
        if (empty($parsed)) {
            return response()->json(['message' => 'Não consegui ler mensagens nesse arquivo. Confira se é o .txt exportado do WhatsApp.'], 422);
        }

        $clientSender = trim((string) ($data['client_sender'] ?? '')) ?: null;

        $conv = Conversation::firstOrNew(['slug' => $slug]);
        $name = trim((string) ($data['name'] ?? ''));
        if (! $conv->exists) {
            $conv->name = $name !== '' ? $name : '+'.$digits;
            $conv->initials = $this->initialsOf($conv->name);
            $conv->color = '#6b7cff';
            $conv->position = (int) (Conversation::max('position') ?? 0) + 1;
        } elseif ($name !== '') {
            $conv->name = $name;
            $conv->initials = $this->initialsOf($name);
        }
        $conv->phone = '+'.$digits;
        $conv->wa_jid = $digits.'@s.whatsapp.net';
        $conv->origin = 'WhatsApp';
        $conv->save();

        // Dedup por ASSINATURA (is_out + minuto + texto) contra TODAS as mensagens
        // já existentes (vindas do sync/webhook OU de import anterior) → importar
        // numa conversa que já tem conteúdo só ADICIONA o que falta, sem duplicar.
        $sig = fn ($isOut, $ts, $txt) => ($isOut ? '1' : '0').'|'.date('YmdHi', (int) $ts).'|'.trim(mb_substr((string) $txt, 0, 200));
        $existing = $conv->messages()->get(['is_out', 'ts', 'text'])
            ->mapWithKeys(fn ($mm) => [$sig((bool) $mm->is_out, (int) $mm->ts, (string) $mm->text) => true]);

        $added = 0;
        foreach ($parsed as $p) {
            $isOut = $clientSender ? ($p['sender'] !== $clientSender) : false;
            $s = $sig($isOut, $p['ts'], $p['text']);
            if ($existing->has($s)) {
                continue; // já existe (mesma mensagem) → não duplica
            }
            $conv->messages()->create([
                'wa_id' => 'txt-'.md5($s),
                'type' => 'text',
                'is_out' => $isOut,
                'text' => mb_substr($p['text'], 0, 4000),
                'time' => date('H:i', $p['ts']),
                'ts' => $p['ts'],
                'position' => 0,
            ]);
            $existing[$s] = true;
            $added++;
        }

        // Reordena por ts e atualiza prévia/última.
        $ordered = $conv->messages()->orderByRaw('ts IS NULL, ts')->orderBy('id')->get();
        foreach ($ordered as $i => $msg) {
            if ((int) $msg->position !== $i) {
                $msg->update(['position' => $i]);
            }
        }
        $last = $ordered->last();
        if ($last) {
            $conv->preview = mb_substr((string) ($last->text ?: $conv->preview), 0, 80);
            if ($last->ts) {
                $conv->last_message_at = date('Y-m-d H:i:s', $last->ts);
                $conv->time = date('H:i', $last->ts);
            }
        }
        $conv->save();

        return response()->json([
            'conversation' => $conv->load('messages'),
            'added' => $added,
            'parsed' => count($parsed),
        ], 201);
    }

    /**
     * Parser do .txt exportado do WhatsApp (Android e iPhone).
     *
     * @return array<int, array{ts:int, sender:string, text:string}>
     */
    private function parseWhatsAppTxt(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $text = preg_replace('/[\x{200E}\x{200F}\x{202A}-\x{202E}\x{00A0}]/u', '', $text);
        $lines = explode("\n", $text);

        // iPhone: [12/06/2026, 14:30:45] Fulano: msg   |  Android: 12/06/2026 14:30 - Fulano: msg
        $re = '/^\[?(\d{1,2})\/(\d{1,2})\/(\d{2,4}),?\s+(\d{1,2}):(\d{2})(?::(\d{2}))?\s*([AaPp][Mm])?\]?\s*(?:-\s*)?(.*)$/u';

        $out = [];
        $cur = null;
        foreach ($lines as $line) {
            if (preg_match($re, $line, $m)) {
                $y = strlen($m[3]) === 2 ? ('20'.$m[3]) : $m[3];
                $hh = (int) $m[4];
                if (! empty($m[7])) {
                    $ap = strtolower($m[7]);
                    if ($ap === 'pm' && $hh < 12) {
                        $hh += 12;
                    }
                    if ($ap === 'am' && $hh === 12) {
                        $hh = 0;
                    }
                }
                $ts = mktime($hh, (int) $m[5], (int) ($m[6] ?? 0), (int) $m[2], (int) $m[1], (int) $y);
                $rest = $m[8] ?? '';
                if (preg_match('/^([^:]{1,60}):\s(.*)$/su', $rest, $mm)) {
                    if ($cur) {
                        $out[] = $cur;
                    }
                    $cur = ['ts' => $ts, 'sender' => trim($mm[1]), 'text' => $mm[2]];
                } else {
                    // Mensagem de sistema (sem "Nome: ") → ignora.
                    if ($cur) {
                        $out[] = $cur;
                        $cur = null;
                    }
                }
            } else {
                if ($cur) {
                    $cur['text'] .= "\n".$line; // continuação da mensagem anterior
                }
            }
        }
        if ($cur) {
            $out[] = $cur;
        }

        return $out;
    }

    /** Webhook do Evolution (público; protegido por token). Mensagens em tempo real. */
    /** Etiquetas do WhatsApp Business (para vincular às etapas do funil). */
    public function labels(Request $request)
    {
        $this->ensureAdmin($request);

        return response()->json(['labels' => \App\Support\Evolution::findLabels()]);
    }

    /**
     * Inicia (ou abre) uma conversa por número — útil para contatos que o
     * Evolution não sincronizou (sem histórico). Valida que o número existe no WhatsApp.
     */
    public function start(Request $request)
    {
        $data = $request->validate([
            'number' => 'required|string',
            'name' => 'nullable|string|max:255',
        ]);

        $digits = preg_replace('/\D/', '', $data['number']);
        if ($digits === '') {
            return response()->json(['message' => 'Número inválido.'], 422);
        }
        if (strlen($digits) <= 11) {
            $digits = '55'.$digits; // assume Brasil quando vem sem código do país
        }

        // Confirma que o número está no WhatsApp e pega o jid canônico.
        $check = collect($this->evo()->post("/chat/whatsappNumbers/{$this->instance()}", [
            'numbers' => [$digits],
        ])->json());
        $first = $check->first();
        if (! is_array($first) || empty($first['exists'])) {
            return response()->json(['message' => 'Esse número não está no WhatsApp.'], 422);
        }

        $jid = (string) ($first['jid'] ?? ($digits.'@s.whatsapp.net'));
        $local = explode('@', $jid)[0];
        $slug = 'wa-'.preg_replace('/[^a-z0-9]/i', '', $local);

        $conv = Conversation::firstOrNew(['slug' => $slug]);
        $name = trim((string) ($data['name'] ?? ''));
        if (! $conv->exists) {
            $conv->name = $name !== '' ? $name : '+'.$local;
            $conv->initials = $this->initialsOf($conv->name);
            $conv->color = '#6b7cff';
            $conv->position = (int) (Conversation::max('position') ?? 0) + 1;
        } elseif ($name !== '') {
            $conv->name = $name;
            $conv->initials = $this->initialsOf($name);
        }
        $conv->phone = '+'.$local;
        $conv->wa_jid = $jid;
        $conv->origin = 'WhatsApp';
        $conv->save(); // hook define a 1ª etapa do funil

        return response()->json($conv->load('messages'), 201);
    }

    public function webhook(Request $request)
    {
        abort_unless($request->query('token') === config('services.evolution.webhook_token'), 401);

        $event = $request->input('event');

        // De qual número (instância) veio o evento. A principal (vertice) resolve para a conta
        // primary; números de prospecção resolvem para a conta outreach correspondente.
        $account = WaAccount::byInstance($request->input('instance')) ?? WaAccount::primary();

        // messages.upsert = mensagem nova; messages.set = lote de histórico (sync full history).
        if ($event === 'messages.upsert' || $event === 'messages.set') {
            $data = $request->input('data', []);
            $messages = isset($data['key']) ? [$data] : ($data['messages'] ?? []);
            foreach ($messages as $m) {
                if (is_array($m)) {
                    $this->ingestMessage($m, $account);
                }
            }
        } elseif ($event === 'messages.update' || $event === 'messages.edit') {
            // Recibo (ack): entregue/lido. Pode vir como objeto único ou lista.
            $data = $request->input('data', []);
            $items = (isset($data['keyId']) || isset($data['key']) || isset($data['status'])) ? [$data] : (array_is_list($data) ? $data : [$data]);
            foreach ($items as $u) {
                if (is_array($u)) {
                    $this->ingestStatus($u);
                }
            }
        } elseif ($event === 'labels.association') {
            $this->ingestLabelAssociation($request->input('data', []));
        }

        return response()->json(['ok' => true]);
    }

    /** Atualiza o recibo (status) de uma mensagem enviada, a partir do ack do WhatsApp. */
    private function ingestStatus(array $u): void
    {
        $waId = (string) ($u['keyId'] ?? ($u['key']['id'] ?? ($u['message']['key']['id'] ?? '')));
        $raw = strtoupper((string) ($u['status'] ?? ($u['update']['status'] ?? '')));
        if ($waId === '' || $raw === '') {
            return;
        }
        $map = [
            'PENDING' => 'pending', 'ERROR' => 'error',
            'SERVER_ACK' => 'sent', 'SENT' => 'sent',
            'DELIVERY_ACK' => 'delivered', 'DELIVERED' => 'delivered',
            'READ' => 'read', 'PLAYED' => 'read',
        ];
        $status = $map[$raw] ?? null;
        if (! $status) {
            return;
        }

        $msg = \App\Models\Message::where('wa_id', $waId)->first();
        if (! $msg || ! $msg->is_out) {
            return;
        }
        // Nunca regride o recibo (read > delivered > sent > pending).
        $rank = ['pending' => 0, 'error' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        if (($rank[$status] ?? 0) < ($rank[(string) $msg->status] ?? 0)) {
            return;
        }
        $msg->update(['status' => $status]);

        try {
            \App\Events\CrmUpdated::dispatch('message');
        } catch (\Throwable $e) {
        }
    }

    /**
     * Etiqueta mudou no WhatsApp → atualiza a etapa do funil da conversa.
     * Só reage a 'add' de uma etiqueta vinculada a alguma etapa (evita ambiguidade/loops).
     */
    private function ingestLabelAssociation(array $data): void
    {
        \Illuminate\Support\Facades\Log::info('wpp: labels.association', $data);

        $assoc = $data['association'] ?? $data;
        $type = (string) ($data['type'] ?? $assoc['type'] ?? '');
        $labelId = (string) ($assoc['labelId'] ?? $data['labelId'] ?? '');
        $chatId = (string) ($assoc['chatId'] ?? $data['chatId'] ?? $assoc['number'] ?? '');

        if ($type !== 'add' || $labelId === '' || $chatId === '') {
            return;
        }

        $stage = \App\Models\Stage::where('wa_label_id', $labelId)->first();
        if (! $stage) {
            return; // etiqueta não vinculada a nenhuma etapa
        }

        $local = explode('@', $chatId)[0];
        $slug = 'wa-'.preg_replace('/[^a-z0-9]/i', '', $local);
        $conv = Conversation::where('slug', $slug)->first();
        if (! $conv || $conv->stage === $stage->key) {
            return; // sem conversa ou já está nessa etapa (corta o loop)
        }

        // Atualização direta (não passa pelo update HTTP → não re-empurra pro WhatsApp).
        $conv->stage = $stage->key;
        $conv->stage_color = $stage->color;
        $conv->tags = [['label' => $stage->name, 'color' => $stage->color]];
        $conv->save();
    }

    public function ingestMessage(array $m, ?WaAccount $account = null): void
    {
        $account ??= WaAccount::primary();
        $key = $m['key'] ?? [];
        $remoteJid = (string) ($key['remoteJid'] ?? '');
        if ($remoteJid === '' || str_ends_with($remoteJid, '@g.us') || str_contains($remoteJid, 'broadcast')) {
            return;
        }

        // Reação (emoji) a uma mensagem — não é mensagem nova; atualiza a mensagem alvo e sai.
        $rawMsg = $this->unwrap($m['message'] ?? []);
        if (isset($rawMsg['reactionMessage'])) {
            $this->ingestReaction($rawMsg['reactionMessage']);

            return;
        }

        $waId = (string) ($key['id'] ?? '');
        if ($waId !== '' && Message::where('wa_id', $waId)->exists()) {
            return;
        }

        $p = $this->parseMessage($m);
        if (! $p) {
            // Diagnóstico: registra tipos de mensagem ainda não tratados (sem dados sensíveis).
            \Illuminate\Support\Facades\Log::warning('wpp: mensagem descartada no parse', [
                'messageType' => $m['messageType'] ?? null,
                'msgKeys' => array_keys($m['message'] ?? []),
                'fromMe' => $key['fromMe'] ?? null,
            ]);

            return;
        }

        [$local, $domain] = array_pad(explode('@', $remoteJid, 2), 2, '');
        $isPhone = $domain === 's.whatsapp.net';

        $realNumber = $isPhone ? $local : null;
        if (! $isPhone) {
            $alt = (string) ($key['remoteJidAlt'] ?? '');
            if (str_ends_with($alt, '@s.whatsapp.net')) {
                $realNumber = explode('@', $alt)[0];
            }
        }

        // Chaveia SEMPRE pelo telefone real quando conhecido. O WhatsApp entrega a mesma pessoa
        // ora pelo @lid (id de privacidade), ora pelo @s.whatsapp.net (telefone) — usar o @lid como
        // chave criava uma conversa duplicada. Com o telefone do remoteJidAlt, ambas caem na mesma.
        if ($realNumber) {
            $local = $realNumber;
            $remoteJid = $realNumber.'@s.whatsapp.net';
            $isPhone = true;
        }
        $slug = 'wa-'.preg_replace('/[^a-z0-9]/i', '', $local);

        $isOut = (bool) ($key['fromMe'] ?? false);
        $ts = (int) ($m['messageTimestamp'] ?? time());

        $conv = Conversation::firstOrNew(['slug' => $slug]);
        $push = trim((string) ($m['pushName'] ?? ''));
        // Nome de verdade tem letras; pushName só-dígitos é id @lid, não serve.
        // E pushName de mensagem ENVIADA é o próprio dono ("Você") — não nomeia o contato.
        $validPush = ! $isOut && $push !== '' && ! preg_match('/^\d+$/', $push)
            && ! in_array(mb_strtolower($push), ['você', 'voce', 'you'], true);
        if (! $conv->exists) {
            $name = $validPush ? $push : ($realNumber ? '+'.$realNumber : 'Contato WhatsApp');
            $conv->name = $name;
            $conv->initials = $this->initialsOf($name);
            $conv->color = '#6b7cff';
            $conv->position = (int) (Conversation::max('position') ?? 0) + 1;
            // Carimba a origem (qual número recebeu) já na criação.
            $conv->wa_account_id = $account?->id;
        } elseif (! $isOut && $validPush && preg_match('/^\+?\d+$/', (string) $conv->name)) {
            // Tinha só o número como nome — assim que o WhatsApp mandar o nome real, usa.
            $conv->name = $push;
            $conv->initials = $this->initialsOf($push);
        }
        $conv->origin = 'WhatsApp';
        $conv->phone = $conv->phone ?: ($realNumber ? '+'.$realNumber : null);
        $conv->wa_jid = $conv->wa_jid ?: $remoteJid;
        // Conversa antiga sem origem definida → carimba com a conta que recebeu agora.
        $conv->wa_account_id = $conv->wa_account_id ?: $account?->id;
        $conv->preview = mb_substr($p['preview'], 0, 80);
        $conv->time = $this->humanDate($ts);
        $conv->last_message_at = date('Y-m-d H:i:s', $ts);
        if (! $isOut) {
            $conv->unread = (int) $conv->unread + 1;
            // Atendimento automático ligado: agenda uma resposta da IA. Cada nova mensagem do
            // lead empurra o prazo (debounce) para não responder no meio de uma rajada.
            // SÓ no número principal (anúncios): prospecção é atendida por humano.
            if ($conv->auto_reply && (! $account || $account->isPrimary())) {
                $conv->auto_reply_due_at = now()->addSeconds(10);
            }
        } else {
            // Nós (humano) respondemos → cancela qualquer resposta automática pendente.
            $conv->auto_reply_due_at = null;
        }
        $conv->save();

        // Prospect respondeu a um disparo? Marca o contato da campanha como respondido (para o relatório).
        if (! $isOut && $account && ! $account->isPrimary() && $realNumber) {
            $contact = \App\Models\CampaignContact::where('phone', $realNumber)
                ->where('status', 'sent')->orderByDesc('id')->first();
            if ($contact) {
                $contact->update(['status' => 'replied', 'conversation_id' => $conv->id]);
                $contact->campaign?->refreshCounts();
            }
        }

        $text = $p['text'] !== null ? mb_substr($p['text'], 0, 4000) : null;

        // Eco do Evolution de uma mensagem que NÓS enviamos pelo CRM (auto-reply ou manual):
        // a mensagem local foi criada sem wa_id, então o eco fromMe não casava e duplicava.
        // Adota o wa_id na mensagem local recente igual em vez de criar uma cópia.
        if ($isOut && $waId !== '' && $text !== null) {
            $localMsg = $conv->messages()
                ->where('is_out', true)->whereNull('wa_id')->where('text', $text)
                ->whereBetween('ts', [$ts - 120, $ts + 120])
                ->reorder()->orderByDesc('id')->first();
            if ($localMsg) {
                $localMsg->update(['wa_id' => $waId]);

                return;
            }
        }

        $conv->messages()->create([
            'wa_id' => $waId ?: null,
            'type' => $p['type'],
            'is_out' => $isOut,
            'text' => $text,
            'reply_to' => $p['reply_to'] ?? null,
            'reply_excerpt' => $p['reply_excerpt'] ?? null,
            'time' => date('H:i', $ts),
            'ts' => $ts ?: null,
            'position' => ((int) $conv->messages()->max('position')) + 1,
        ]);
    }

    /** Busca mensagens de um JID no Evolution e grava como conversa+thread. */
    private function importConversation(string $remoteJid, int $maxPages, ?int $keepLast = null, ?string $name = null, ?string $avatar = null, ?WaAccount $account = null): ?Conversation
    {
        $account ??= WaAccount::primary();
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
        // pushName que é só dígitos num chat @lid é o id interno do WhatsApp, não um nome.
        if ($name !== null && preg_match('/^\d+$/', (string) $name) && ! $realNumber) {
            $name = null;
        }
        $name = $name ?: ($realNumber ? ('+'.$realNumber) : 'Contato WhatsApp');

        $conv = Conversation::firstOrNew(['slug' => $slug]);
        // NÃO sobrescreve nome posto à mão. Só (re)nomeia se a conversa é nova ou o nome atual
        // é automático (número/placeholder). Antes, todo re-import resetava o nome manual p/ o número.
        $cur = trim((string) $conv->name);
        $nameIsAuto = ! $conv->exists || $cur === ''
            || preg_match('/^\+?\d+$/', $cur)
            || str_contains($cur, '@')
            || in_array(mb_strtolower($cur), ['contato whatsapp', 'você', 'voce', 'you'], true);
        if ($nameIsAuto) {
            $conv->name = $name;
            $conv->initials = $this->initialsOf($name);
        }
        $conv->color = $conv->color ?: '#6b7cff';
        if ($avatar) {
            $conv->avatar = $avatar;
        }
        $conv->phone = $realNumber ? ('+'.$realNumber) : null;
        $conv->wa_jid = $remoteJid;
        $conv->wa_account_id = $conv->wa_account_id ?: $account?->id;
        $conv->origin = 'WhatsApp';
        $conv->status_text = 'via WhatsApp';
        $conv->online = false;
        $conv->unread = 0;
        $conv->position = $conv->position ?: ((int) (Conversation::max('position') ?? 0) + 1);
        $conv->save();

        // Merge NÃO-destrutivo: nunca apaga o que já existe (preserva mensagens
        // capturadas pelo webhook, inclusive temporárias que somem do histórico).
        $existing = $conv->messages()->whereNotNull('wa_id')->pluck('wa_id')->flip();

        foreach ($records as $r) {
            $p = $this->parseMessage($r);
            if (! $p) {
                continue;
            }
            $waId = $p['wa_id'];
            if ($waId && $existing->has($waId)) {
                continue; // já temos esta mensagem (webhook ou import anterior)
            }

            $ts = (int) ($r['messageTimestamp'] ?? 0);
            $conv->messages()->create([
                'wa_id' => $waId,
                'type' => $p['type'],
                'is_out' => (bool) ($r['key']['fromMe'] ?? false),
                'text' => $p['text'] !== null ? mb_substr($p['text'], 0, 4000) : null,
                'time' => $ts ? date('H:i', $ts) : null,
                'ts' => $ts ?: null,
                'position' => 0, // recalculado abaixo
            ]);
            if ($waId) {
                $existing[$waId] = true;
            }
        }

        // Conversa sem nenhuma mensagem → descarta (chat de sistema/mídia pura).
        if ($conv->messages()->count() === 0) {
            $conv->delete();

            return null;
        }

        // Reordena cronologicamente (ts; cai para id quando ausente).
        $ordered = $conv->messages()->orderByRaw('ts IS NULL, ts')->orderBy('id')->get();
        foreach ($ordered as $i => $msg) {
            if ((int) $msg->position !== $i) {
                $msg->update(['position' => $i]);
            }
        }

        $lastMsg = $ordered->last();
        if ($lastMsg) {
            $conv->preview = mb_substr((string) ($lastMsg->text ?: $conv->preview), 0, 80);
            if ($lastMsg->ts) {
                $conv->time = $this->humanDate($lastMsg->ts);
                $conv->last_message_at = date('Y-m-d H:i:s', $lastMsg->ts);
            }
        }
        $conv->save();

        return $conv;
    }

    /** Busca a foto de perfil (concorrente) das conversas que ainda não têm. */
    public function fillAvatars(): void
    {
        $convs = Conversation::where('slug', 'like', 'wa-%')
            ->whereNotNull('phone')
            ->where(function ($q) {
                $q->whereNull('avatar')->orWhere('avatar', '');
            })
            ->get(['id', 'phone']);

        foreach ($convs->chunk(20) as $chunk) {
            $byNum = $chunk->keyBy(fn ($c) => preg_replace('/\D/', '', (string) $c->phone));

            $responses = Http::pool(fn ($pool) => $byNum->keys()->map(fn ($num) => $pool->as((string) $num)
                ->baseUrl(rtrim((string) config('services.evolution.url'), '/'))
                ->withHeaders(['apikey' => (string) config('services.evolution.key')])
                ->timeout(15)
                ->post('/chat/fetchProfilePictureUrl/'.config('services.evolution.instance'), ['number' => (string) $num])
            )->all());

            foreach ($byNum as $num => $conv) {
                $r = $responses[(string) $num] ?? null;
                if ($r instanceof \Illuminate\Http\Client\Response && $r->json('profilePictureUrl')) {
                    $conv->avatar = $r->json('profilePictureUrl');
                    $conv->save();
                }
            }
        }
    }

    /** Extrai [type, text(legenda/corpo), preview, wa_id] de uma mensagem do Evolution. */
    private function parseMessage(array $m): ?array
    {
        $key = $m['key'] ?? [];
        $waId = ((string) ($key['id'] ?? '')) ?: null;
        $msg = $this->unwrap($m['message'] ?? []);

        $reply = $this->parseQuoted($msg);

        // Texto (cobre mensagem simples e com formatação/citação).
        $body = $msg['conversation']
            ?? $msg['extendedTextMessage']['text']
            ?? null;
        if (is_string($body) && $body !== '') {
            return ['type' => 'text', 'text' => $body, 'preview' => $body, 'wa_id' => $waId] + $reply;
        }

        // Mídia: detecta pelo campo presente no conteúdo (não pelo messageType,
        // que em mensagens temporárias vem como "ephemeralMessage").
        $map = [
            'imageMessage' => ['image', '📷 Imagem'],
            'audioMessage' => ['voice', '🎵 Áudio'],
            'videoMessage' => ['video', '🎬 Vídeo'],
            'documentMessage' => ['file', '📄 Documento'],
            'stickerMessage' => ['image', 'Figurinha'],
        ];
        foreach ($map as $field => [$type, $label]) {
            if (isset($msg[$field]) && is_array($msg[$field])) {
                $caption = $msg[$field]['caption'] ?? null;

                return ['type' => $type, 'text' => $caption, 'preview' => $caption ?: $label, 'wa_id' => $waId] + $reply;
            }
        }

        return null;
    }

    /** Reação recebida (cliente reagiu): atualiza a reação da mensagem alvo. Emoji vazio remove. */
    private function ingestReaction(array $rm): void
    {
        $targetId = (string) ($rm['key']['id'] ?? '');
        $emoji = (string) ($rm['text'] ?? '');
        if ($targetId === '') {
            return;
        }
        $msg = Message::where('wa_id', $targetId)->first();
        if (! $msg) {
            return;
        }
        $msg->update(['reaction' => $emoji !== '' ? $emoji : null]);

        try {
            \App\Events\CrmUpdated::dispatch('message');
        } catch (\Throwable $e) {
        }
    }

    /** Extrai a citação (quoted) de uma mensagem recebida: ['reply_to'=>?, 'reply_excerpt'=>?]. */
    private function parseQuoted(array $msg): array
    {
        $ctx = $msg['extendedTextMessage']['contextInfo'] ?? null;
        foreach (['imageMessage', 'audioMessage', 'videoMessage', 'documentMessage'] as $f) {
            if (! $ctx && isset($msg[$f]['contextInfo'])) {
                $ctx = $msg[$f]['contextInfo'];
            }
        }
        if (! is_array($ctx) || empty($ctx['stanzaId'])) {
            return ['reply_to' => null, 'reply_excerpt' => null];
        }
        $qm = $ctx['quotedMessage'] ?? [];
        $qtext = $qm['conversation']
            ?? ($qm['extendedTextMessage']['text'] ?? null)
            ?? ($qm['imageMessage']['caption'] ?? null)
            ?? ($qm['videoMessage']['caption'] ?? null)
            ?? (isset($qm['audioMessage']) ? '🎵 Áudio' : (isset($qm['imageMessage']) ? '📷 Imagem' : (isset($qm['documentMessage']) ? '📄 Documento' : '')));

        return ['reply_to' => (string) $ctx['stanzaId'], 'reply_excerpt' => mb_substr((string) $qtext, 0, 180) ?: null];
    }

    /**
     * Desembrulha os "envelopes" do WhatsApp (mensagens temporárias/disappearing,
     * ver-uma-vez, editadas, documento-com-legenda) até chegar no conteúdo real.
     */
    private function unwrap(array $msg): array
    {
        $wrappers = [
            'ephemeralMessage', 'viewOnceMessage', 'viewOnceMessageV2',
            'viewOnceMessageV2Extension', 'documentWithCaptionMessage', 'editedMessage',
        ];
        $guard = 0;
        do {
            $unwrapped = false;
            foreach ($wrappers as $w) {
                if (isset($msg[$w]['message']) && is_array($msg[$w]['message'])) {
                    $msg = $msg[$w]['message'];
                    $unwrapped = true;
                    break;
                }
            }
        } while ($unwrapped && ++$guard < 5);

        return $msg;
    }

    /** Carrega o histórico COMPLETO da conversa (re-importa do Evolution). */
    public function loadFull(Conversation $conversation)
    {
        if ($conversation->wa_jid) {
            $this->importConversation($conversation->wa_jid, maxPages: 30);
        }

        return response()->json($conversation->fresh()->load('messages'));
    }

    /** Mídia descriptografada (data URI base64) de uma mensagem — sob demanda. */
    public function media(\App\Models\Message $message)
    {
        abort_unless((bool) $message->wa_id, 404);

        $res = $this->evo()->timeout(40)->post("/chat/getBase64FromMediaMessage/{$this->instance()}", [
            'message' => ['key' => ['id' => $message->wa_id]],
            'convertToMp4' => false,
        ]);

        $base64 = $res->json('base64');
        abort_unless((bool) $base64, 404);

        $mime = $res->json('mimetype') ?: 'application/octet-stream';

        return response()->json(['data' => "data:{$mime};base64,{$base64}"]);
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
