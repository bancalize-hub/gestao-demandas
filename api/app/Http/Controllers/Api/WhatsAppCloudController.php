<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CampaignContact;
use App\Models\Company;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\WaAccount;
use App\Services\LeadsDeAnuncio;
use App\Support\Channels\CloudChannel;
use App\Support\Evolution;
use App\Support\OptOut;
use App\Support\Realtime;
use App\Support\Tenancy;
use App\Support\Wa;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * API OFICIAL do WhatsApp (Cloud API da Meta).
 *
 * Webhook (recebimento) + administração dos números que falam pelo canal oficial.
 * O canal não-oficial (Evolution/Baileys) segue no {@see WhatsAppController} — os dois
 * convivem, um número por vez escolhe seu provider.
 *
 * Coexistência (número que continua no app WhatsApp Business): além de `messages`,
 * a Meta manda `message_echoes` (o que a equipe respondeu pelo celular), `history`
 * (até ~6 meses de conversas antigas, logo após o onboarding) e `state_sync`
 * (contatos). Tudo isso é ingerido aqui para o CRM ficar espelhado.
 */
class WhatsAppCloudController extends Controller
{
    /**
     * Handshake do webhook (GET). A Meta chama uma vez, ao salvar a URL no painel,
     * e espera o hub.challenge de volta em texto puro.
     *
     * O token é comparado contra TODAS as contas cloud (cada empresa tem o seu) e
     * contra o global do .env — sem revelar qual bateu.
     */
    public function verify(Request $request)
    {
        $token = (string) $request->query('hub_verify_token', '');
        $challenge = (string) $request->query('hub_challenge', '');

        if ($request->query('hub_mode') !== 'subscribe' || $token === '') {
            abort(400);
        }

        $global = (string) config('services.wa_cloud.verify_token', '');
        $ok = ($global !== '' && hash_equals($global, $token))
            || WaAccount::withoutGlobalScopes()->where('provider', 'cloud')->where('verify_token', $token)->exists();

        abort_unless($ok, 403);

        return response($challenge, 200)->header('Content-Type', 'text/plain');
    }

    /**
     * Webhook (POST). Cada entrega pode trazer vários "changes" de números diferentes,
     * então a empresa é resolvida change a change pelo phone_number_id.
     *
     * Responde 200 sempre que o payload é legítimo: erro de processamento não pode
     * virar retry infinito da Meta (ela reenvia por dias em cima de não-2xx).
     */
    public function webhook(Request $request)
    {
        $payload = $request->json()->all();

        // Trilha de entrada: sem ela, "a mensagem não chegou" vira adivinhação.
        Evolution::log('cloud.webhook.entrada', [
            'entries' => count((array) ($payload['entry'] ?? [])),
            'campos' => collect((array) ($payload['entry'] ?? []))
                ->flatMap(fn ($e) => array_column((array) ($e['changes'] ?? []), 'field'))->unique()->values()->all(),
            'assinado' => $request->hasHeader('X-Hub-Signature-256'),
        ]);

        foreach ((array) ($payload['entry'] ?? []) as $entry) {
            foreach ((array) ($entry['changes'] ?? []) as $change) {
                $field = (string) ($change['field'] ?? '');
                $value = (array) ($change['value'] ?? []);

                $account = WaAccount::byPhoneNumberId(
                    (string) ($value['metadata']['phone_number_id'] ?? '')
                );

                // Número desconhecido: não dá para atribuir a nenhuma empresa. Ignorar é o
                // seguro — cair na conta de outra empresa vazaria mensagens entre tenants.
                if (! $account || ! $account->company_id) {
                    Evolution::log('cloud.webhook.numero_desconhecido', [
                        'field' => $field,
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                    ], 'warning');
                    Log::warning('wa-cloud: webhook de número desconhecido', [
                        'field' => $field,
                        'phone_number_id' => $value['metadata']['phone_number_id'] ?? null,
                    ]);

                    continue;
                }

                // Autenticidade: HMAC do corpo CRU com o app secret da empresa dona do número.
                if (! $this->signatureOk($request, $account)) {
                    Evolution::log('cloud.webhook.assinatura_invalida', ['account' => $account->id], 'error');
                    Log::warning('wa-cloud: assinatura inválida', ['account' => $account->id]);
                    abort(403);
                }

                try {
                    app(Tenancy::class)->run($account->company_id, function () use ($field, $value, $account) {
                        $this->handleChange($field, $value, $account);
                    });
                } catch (\Throwable $e) {
                    Evolution::log('cloud.webhook.excecao', [
                        'field' => $field,
                        'account' => $account->id,
                        'error' => $e->getMessage(),
                        'file' => $e->getFile().':'.$e->getLine(),
                    ], 'error');
                    report($e);
                }
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Confere o X-Hub-Signature-256 (HMAC-SHA256 do corpo cru com o app secret).
     * Conta sem app_secret configurado aceita sem conferir — permite plugar o webhook
     * antes de ter o segredo em mãos, e a tela de admin avisa que está sem assinatura.
     */
    private function signatureOk(Request $request, WaAccount $account): bool
    {
        $secret = (string) ($account->app_secret ?? '');
        if ($secret === '') {
            return true;
        }

        $header = (string) $request->header('X-Hub-Signature-256', '');
        if (! str_starts_with($header, 'sha256=')) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        return hash_equals($expected, $header);
    }

    /** Roteia cada tipo de evento do webhook. */
    private function handleChange(string $field, array $value, WaAccount $account): void
    {
        // Nome do contato vem separado das mensagens, num array paralelo.
        $names = [];
        foreach ((array) ($value['contacts'] ?? []) as $c) {
            $wa = (string) ($c['wa_id'] ?? '');
            if ($wa !== '') {
                $names[$wa] = trim((string) ($c['profile']['name'] ?? ''));
            }
        }

        // Mensagens recebidas do cliente.
        foreach ((array) ($value['messages'] ?? []) as $m) {
            $this->ingestMessage((array) $m, $account, isOut: false, pushName: $names[(string) ($m['from'] ?? '')] ?? null);
        }

        // Recibos (enviada/entregue/lida/falhou).
        foreach ((array) ($value['statuses'] ?? []) as $s) {
            $this->ingestStatus((array) $s);
        }

        // COEXISTÊNCIA: o que a equipe respondeu pelo app WhatsApp Business no celular.
        foreach ((array) ($value['message_echoes'] ?? []) as $m) {
            $this->ingestMessage((array) $m, $account, isOut: true);
        }

        // COEXISTÊNCIA: histórico (até ~6 meses) liberado no onboarding, em lotes.
        if ($field === 'history' || isset($value['history'])) {
            $this->ingestHistory((array) ($value['history'] ?? []), $value, $account);
        }

        // COEXISTÊNCIA: agenda de contatos do celular — só melhora o nome de quem já existe.
        foreach ((array) ($value['state_sync'] ?? []) as $s) {
            $this->ingestStateSync((array) $s);
        }
    }

    /**
     * Converte uma mensagem no formato da Cloud API para o formato interno.
     * Devolve null para tipos que o CRM não representa (system, unsupported…).
     */
    private function parseMessage(array $m): ?array
    {
        $type = (string) ($m['type'] ?? '');

        $media = [
            'image' => ['image', '📷 Imagem'],
            'audio' => ['voice', '🎵 Áudio'],
            'video' => ['video', '🎬 Vídeo'],
            'document' => ['file', '📄 Documento'],
            'sticker' => ['image', 'Figurinha'],
        ];

        if ($type === 'text') {
            $body = (string) ($m['text']['body'] ?? '');

            return $body === '' ? null : ['type' => 'text', 'text' => $body, 'preview' => $body];
        }

        if (isset($media[$type])) {
            [$internal, $label] = $media[$type];
            $node = (array) ($m[$type] ?? []);
            $caption = trim((string) ($node['caption'] ?? '')) ?: null;

            return [
                'type' => $internal,
                'text' => $caption,
                'preview' => $caption ?: ($node['filename'] ?? $label),
                'wa_media_id' => (string) ($node['id'] ?? '') ?: null,
                // mime da Meta vem com parâmetros ("audio/ogg; codecs=opus"); guarda só o tipo.
                'meta' => trim(explode(';', (string) ($node['mime_type'] ?? ''))[0]) ?: null,
                'file_name' => (string) ($node['filename'] ?? '') ?: null,
            ];
        }

        // Botão / lista / fluxo: o CRM guarda o texto escolhido, que é o que importa na conversa.
        if ($type === 'button') {
            $text = (string) ($m['button']['text'] ?? '');

            return $text === '' ? null : ['type' => 'text', 'text' => $text, 'preview' => $text];
        }
        if ($type === 'interactive') {
            $i = (array) ($m['interactive'] ?? []);
            $text = (string) ($i['button_reply']['title'] ?? $i['list_reply']['title'] ?? '');

            return $text === '' ? null : ['type' => 'text', 'text' => $text, 'preview' => $text];
        }

        if ($type === 'location') {
            $loc = (array) ($m['location'] ?? []);
            $desc = trim((string) ($loc['name'] ?? '').' '.(string) ($loc['address'] ?? ''));
            $text = '📍 Localização'.($desc !== '' ? ": {$desc}" : '')
                .' (https://maps.google.com/?q='.($loc['latitude'] ?? '').','.($loc['longitude'] ?? '').')';

            return ['type' => 'text', 'text' => $text, 'preview' => '📍 Localização'];
        }

        if ($type === 'contacts') {
            $nomes = array_filter(array_map(
                fn ($c) => (string) ($c['name']['formatted_name'] ?? ''),
                (array) ($m['contacts'] ?? [])
            ));
            $text = '👤 Contato: '.implode(', ', $nomes);

            return ['type' => 'text', 'text' => $text, 'preview' => $text];
        }

        return null;
    }

    /**
     * Grava (idempotente) uma mensagem recebida, um eco do app Business ou uma do histórico.
     *
     * `$isOut` distingue quem falou: na Cloud API mensagem recebida vem em `messages`
     * (sempre do cliente) e mensagem nossa vem em `message_echoes` ou no histórico.
     */
    private function ingestMessage(array $m, WaAccount $account, bool $isOut, ?string $pushName = null, bool $broadcast = true, ?string $statusHint = null): void
    {
        $waId = (string) ($m['id'] ?? '');
        if ($waId !== '' && Message::where('wa_id', $waId)->exists()) {
            return; // a Meta reentrega o mesmo webhook em caso de dúvida
        }

        // O telefone do CLIENTE: em mensagem recebida é o `from`; no que sai, é o `to`.
        $phone = preg_replace('/\D/', '', (string) ($isOut ? ($m['to'] ?? '') : ($m['from'] ?? '')));
        if (! $phone) {
            return;
        }

        // Reação: não é mensagem nova — atualiza o alvo e sai.
        if (($m['type'] ?? '') === 'reaction') {
            $this->ingestReaction((array) ($m['reaction'] ?? []));

            return;
        }

        $p = $this->parseMessage($m);
        if (! $p) {
            Log::info('wa-cloud: mensagem ignorada no parse', ['type' => $m['type'] ?? null, 'id' => $waId]);

            return;
        }

        $ts = (int) ($m['timestamp'] ?? time());
        $conv = $this->upsertConversation($phone, $pushName, $account, $isOut, $ts, (string) $p['preview'], $m);

        // Eco de uma mensagem que o próprio CRM enviou (já gravada, ainda sem wa_id):
        // adota o id em vez de duplicar a bolha no chat.
        if ($isOut && $waId !== '' && ! empty($p['text'])) {
            $local = $conv->messages()
                ->where('is_out', true)->whereNull('wa_id')->where('text', $p['text'])
                ->whereBetween('ts', [$ts - 120, $ts + 120])
                ->reorder()->orderByDesc('id')->first();
            if ($local) {
                $local->update(['wa_id' => $waId]);

                return;
            }
        }

        $quoted = (string) ($m['context']['id'] ?? '');
        $msg = $conv->messages()->create([
            'wa_id' => $waId ?: null,
            'wa_media_id' => $p['wa_media_id'] ?? null,
            'type' => $p['type'],
            'is_out' => $isOut,
            'text' => $p['text'] !== null ? mb_substr((string) $p['text'], 0, 4000) : null,
            'reply_to' => $quoted ?: null,
            'reply_excerpt' => $quoted ? $this->excerptOf($quoted) : null,
            'meta' => $p['meta'] ?? null,
            'file_name' => $p['file_name'] ?? null,
            'status' => $isOut ? ($statusHint ?: 'sent') : null,
            'time' => date('H:i', $ts),
            'ts' => $ts,
            'position' => ((int) $conv->messages()->max('position')) + 1,
        ]);

        if ($broadcast) {
            Realtime::messageCreated($msg);
        }
    }

    /**
     * Acha/cria a conversa do cliente e atualiza o resumo da lista.
     * Espelha as regras já usadas na ingestão da Evolution (slug wa-<dígitos>,
     * IA automática para lead novo, contagem de não lidas, campanha respondida).
     */
    private function upsertConversation(string $phone, ?string $pushName, WaAccount $account, bool $isOut, int $ts, string $preview, array $raw = []): Conversation
    {
        $slug = 'wa-'.$phone;
        $conv = Conversation::firstOrNew(['slug' => $slug]);

        $push = trim((string) $pushName);
        $validPush = ! $isOut && $push !== '' && ! preg_match('/^\+?\d+$/', $push);

        if (! $conv->exists) {
            $name = $validPush ? $push : '+'.$phone;
            $conv->name = $name;
            $conv->initials = $this->initialsOf($name);
            $conv->color = '#6b7cff';
            $conv->position = (int) (Conversation::max('position') ?? 0) + 1;
            $conv->wa_account_id = $account->id;

            // Lead novo que chegou sozinho no número principal: a empresa pode querer
            // que a IA já assuma o atendimento.
            if (! $isOut && $account->isPrimary()) {
                $conv->auto_reply = (bool) Company::find($account->company_id)?->auto_reply_new_leads;
            }
        } elseif ($validPush && preg_match('/^\+?\d+$/', (string) $conv->name)) {
            $conv->name = $push;
            $conv->initials = $this->initialsOf($push);
        }

        $conv->origin = 'WhatsApp';
        $conv->phone = $conv->phone ?: '+'.$phone;
        $conv->wa_jid = $conv->wa_jid ?: $phone.'@s.whatsapp.net';

        // Conversa "excluída" que recebe mensagem NOVA do contato volta para a lista —
        // esconder não bloqueia ninguém, e lead que voltou a falar sozinho é o que menos
        // se pode perder. Só na entrada: mensagem nossa não desfaz a decisão de esconder.
        if (! $isOut) {
            $conv->hidden_at = null;
        }

        // O número que RECEBEU passa a ser o dono da conversa. Sem isto, conversa
        // antiga da Evolution que volta a falar no número oficial continuava carimbada
        // no número velho — e a resposta saía (ou nem saía) pelo canal errado: foi
        // exatamente o "respondi e não chegou" depois da migração.
        if ($conv->wa_account_id !== $account->id) {
            Evolution::log('cloud.conversa_recarimbada', [
                'conversation_id' => $conv->id,
                'slug' => $slug,
                'de' => $conv->wa_account_id,
                'para' => $account->id,
            ]);
            $conv->wa_account_id = $account->id;
        }

        $temReferral = ! $isOut && isset($raw['referral']) && is_array($raw['referral']);
        $conversaNova = ! $conv->exists;

        // Lead de anúncio clicável (Click-to-WhatsApp): guarda de onde veio.
        //
        // `ctwa_clid` é o identificador do CLIQUE no anúncio, e é a única chave que liga
        // este lead de volta ao Facebook depois. Sem ele não existe evento de conversão
        // atribuível (ver MetaConversions) — então guarda-se sempre, mesmo que hoje só
        // o painel use `id` e `titulo`.
        if ($temReferral) {
            $custom = (array) ($conv->custom_fields ?? []);
            $custom['anuncio'] = array_filter([
                'titulo' => $raw['referral']['headline'] ?? null,
                'origem' => $raw['referral']['source_type'] ?? null,
                'id' => $raw['referral']['source_id'] ?? null,
                'ctwa_clid' => $raw['referral']['ctwa_clid'] ?? null,
                'url' => $raw['referral']['source_url'] ?? null,
            ]);
            $conv->custom_fields = $custom;
        }

        // Clique de anúncio que chegou SEM referral — o lead vira "orgânico" no /marketing
        // e o gasto do anúncio fica órfão. Em 04–06/08/2026 foram 6 de 81 (7%).
        //
        // Não dá para consertar aqui: se a Meta não mandou o `ctwa_clid`, não existe chave
        // para ligar este lead ao anúncio, e chutar o anúncio "mais provável" sujaria a
        // única métrica que decide verba. O que dá é MEDIR — sem este log não havia como
        // saber se a perda é da Meta ou nossa, porque webhook de entrada não era logado.
        //
        // O sinal é o texto do botão: quem clicou no anúncio chega com a frase pré-digitada
        // pelo Facebook. Se veio essa frase e não veio referral, faltou atribuição.
        if ($conversaNova && ! $isOut && ! $temReferral && $this->pareceCliqueDeAnuncio($preview)) {
            Evolution::log('cloud.referral_ausente', [
                'conversation_id' => $conv->id,
                'slug' => $slug,
                'primeira_msg' => mb_substr($preview, 0, 120),
                'campos_recebidos' => array_keys($raw),
            ], 'warning');
        }

        // Só avança o resumo se esta mensagem for mais recente que a última conhecida
        // (o histórico da coexistência chega fora de ordem).
        if (! $conv->last_message_at || strtotime((string) $conv->last_message_at) <= $ts) {
            $conv->preview = mb_substr($preview, 0, 80);
            $conv->time = date('H:i', $ts);
            $conv->last_message_at = date('Y-m-d H:i:s', $ts);
        }

        if (! $isOut) {
            $conv->unread = (int) $conv->unread + 1;
            // "para de mandar", "não quero mais": cala o robô nesta conversa antes de
            // agendar qualquer resposta. Insistir depois disso é o que trouxe a
            // restrição por spam.
            OptOut::aplicar($conv, $preview);
            if ($conv->auto_reply && $account->isPrimary()) {
                $conv->auto_reply_due_at = now()->addSeconds(10);
            }
            // O lead voltou a falar: encerra a rodada de retomada ativa (recomeça do zero
            // se ele sumir de novo).
            $conv->nudge_count = 0;
            $conv->nudge_last_at = null;
        } else {
            $conv->auto_reply_due_at = null;
        }

        $conv->save();

        // Lead de anúncio entra na lista de contatos na hora — é o que mantém a lista
        // "Leads de anúncio" viva sem ninguém importar nada à mão.
        if (! $isOut) {
            LeadsDeAnuncio::registrarSeAnuncio($conv, $preview, $temReferral, $conversaNova);
            LeadsDeAnuncio::detectarCriativo($conv, $preview, $conversaNova);
            // NÃO se manda evento de "chegou lead" (removido em 08/08/2026).
            //
            // Ele existiu aqui e era o pior uso possível de um dos quatro nomes de evento
            // que o business_messaging aceita: a Meta JÁ conta o mesmo fato sozinha, como
            // conversa iniciada, e como conversão personalizada não lê evento de mensagem
            // (ver MetaConversions), lead comum e lead qualificado chegavam no Gerenciador
            // como a MESMA linha — 132 "leads" indistinguíveis, e nada para otimizar.
            //
            // Agora `MetaConversions::LEAD` é gasto com o lead que a triagem APROVA, e sai
            // de Conversation::booted(). O evento do funil que nasce aqui é nenhum.
        }

        // Prospect respondeu a um disparo → marca o contato da campanha.
        if (! $isOut && ! $account->isPrimary()) {
            $contact = CampaignContact::where('phone', $phone)
                ->where('status', 'sent')->orderByDesc('id')->first();
            if ($contact) {
                $contact->update(['status' => 'replied', 'conversation_id' => $conv->id]);
                $contact->campaign?->refreshCounts();
            }
        }

        return $conv;
    }

    /** Recibo de uma mensagem enviada. Mesma escada da Evolution: nunca regride. */
    private function ingestStatus(array $s): void
    {
        $waId = (string) ($s['id'] ?? '');
        $raw = (string) ($s['status'] ?? '');
        $map = ['sent' => 'sent', 'delivered' => 'delivered', 'read' => 'read', 'failed' => 'error'];
        $status = $map[$raw] ?? null;
        if ($waId === '' || ! $status) {
            return;
        }

        $msg = Message::where('wa_id', $waId)->first();
        if (! $msg || ! $msg->is_out) {
            return;
        }

        if ($status === 'error') {
            // Vai para o canal 'whatsapp' (nível info): o laravel.log roda com LOG_LEVEL=error
            // e engolia este aviso, deixando "não chegou" sem causa no log.
            Evolution::log('cloud.recibo.falha', [
                'wa_id' => $waId,
                'message_id' => $msg->id,
                'conversation_id' => $msg->conversation_id,
                'code' => $s['errors'][0]['code'] ?? null,
                'title' => $s['errors'][0]['title'] ?? null,
                'details' => $s['errors'][0]['error_data']['details'] ?? null,
                'recibo' => $s,
            ], 'error');
        }

        $rank = ['pending' => 0, 'sent' => 1, 'delivered' => 2, 'read' => 3];
        if ($status === 'error') {
            // Falha terminal: só não sobrescreve entrega já comprovada.
            if (($rank[(string) $msg->status] ?? 0) >= $rank['delivered']) {
                return;
            }
        } elseif (($rank[$status] ?? 0) < ($rank[(string) $msg->status] ?? 0)) {
            return;
        }

        $msg->update(['status' => $status]);
        Realtime::messagePatched($msg, ['status' => $status]);
    }

    /** Reação do cliente a uma mensagem nossa (emoji vazio = removeu). */
    private function ingestReaction(array $r): void
    {
        $target = (string) ($r['message_id'] ?? '');
        if ($target === '') {
            return;
        }
        $msg = Message::where('wa_id', $target)->first();
        if (! $msg) {
            return;
        }
        $emoji = (string) ($r['emoji'] ?? '');
        $msg->update(['reaction' => $emoji !== '' ? $emoji : null]);
        Realtime::messagePatched($msg, ['reaction' => $msg->reaction]);
    }

    /**
     * Histórico da coexistência: lotes de conversas antigas do celular.
     *
     * Chega em vários "chunks" logo após o onboarding. Não broadcasta (seria um flood
     * de milhares de eventos no Reverb) — o CRM mostra tudo na próxima abertura do chat.
     */
    private function ingestHistory(array $history, array $value, WaAccount $account): void
    {
        $business = preg_replace('/\D/', '', (string) ($value['metadata']['display_phone_number'] ?? ''));

        foreach ($history as $chunk) {
            foreach ((array) ($chunk['threads'] ?? []) as $thread) {
                foreach ((array) ($thread['messages'] ?? []) as $m) {
                    $m = (array) $m;
                    // No histórico não há `to`: quem mandou é o `from`. Se for o número
                    // da empresa, a mensagem é nossa e o cliente é o dono da thread.
                    $from = preg_replace('/\D/', '', (string) ($m['from'] ?? ''));
                    $isOut = $business !== '' && $from === $business;
                    if ($isOut) {
                        $m['to'] = (string) ($m['to'] ?? $thread['id'] ?? '');
                    }

                    $this->ingestMessage(
                        $m,
                        $account,
                        isOut: $isOut,
                        broadcast: false,
                        statusHint: (string) ($m['history_context']['status'] ?? '') ?: null,
                    );
                }
            }

            $meta = (array) ($chunk['metadata'] ?? []);
            Log::info('wa-cloud: histórico importado', [
                'account' => $account->id,
                'phase' => $meta['phase'] ?? null,
                'chunk' => $meta['chunk_order'] ?? null,
                'progress' => $meta['progress'] ?? null,
            ]);
        }
    }

    /** Contato da agenda do celular: só dá nome a quem está salvo como número. */
    private function ingestStateSync(array $s): void
    {
        if (($s['type'] ?? '') !== 'contact') {
            return;
        }
        $phone = preg_replace('/\D/', '', (string) ($s['contact']['phone_number'] ?? ''));
        $name = trim((string) ($s['contact']['full_name'] ?? $s['contact']['first_name'] ?? ''));
        if (! $phone || $name === '') {
            return;
        }

        $conv = Conversation::where('slug', 'wa-'.$phone)->first();
        if ($conv && preg_match('/^\+?\d+$/', (string) $conv->name)) {
            $conv->update(['name' => $name, 'initials' => $this->initialsOf($name)]);
        }
    }

    /**
     * A frase é o texto pré-digitado que o Facebook põe no botão do anúncio/página?
     *
     * Descoberto por dados, não cadastrado: uma primeira-mensagem repetida em dezenas de
     * conversas diferentes só pode ser template — ninguém escreve a mesma frase 88 vezes.
     * Mesma lógica do textosDeBotao() da triagem (QualificarTick), aqui para saber se um
     * lead sem referral veio mesmo de anúncio.
     *
     * Cache de 1h porque isto roda no webhook, no caminho de toda mensagem que entra.
     */
    private function pareceCliqueDeAnuncio(string $texto): bool
    {
        $texto = mb_strtolower(trim($texto));
        if ($texto === '') {
            return false;
        }

        $empresa = app(Tenancy::class)->id();
        if ($empresa === null) {
            return false;
        }

        $modelos = Cache::remember("botoes_anuncio:{$empresa}", 3600, function () use ($empresa) {
            return DB::table('messages as m')
                ->join(DB::raw('(SELECT conversation_id, MIN(ts) mts FROM messages WHERE is_out = 0 GROUP BY conversation_id) f'),
                    fn ($j) => $j->on('f.conversation_id', '=', 'm.conversation_id')->on('f.mts', '=', 'm.ts'))
                ->where('m.is_out', false)
                ->where('m.company_id', $empresa)
                ->where('m.text', '<>', '')
                // 20, e não 3 como na triagem: lá um falso positivo só enfraquece evidência
                // fraca; aqui ele viraria alarme falso de atribuição perdida em toda
                // conversa que começa com "bom dia".
                ->havingRaw('COUNT(*) >= 20')
                ->groupBy('m.text')
                ->pluck('m.text')
                ->map(fn ($t) => mb_strtolower(trim((string) $t)))
                ->all();
        });

        return in_array($texto, $modelos, true);
    }

    /** Trecho da mensagem citada, para o balão de resposta. */
    private function excerptOf(string $waId): ?string
    {
        $q = Message::where('wa_id', $waId)->first();

        return $q ? mb_substr((string) ($q->text ?? ''), 0, 180) ?: null : null;
    }

    private function initialsOf(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = array_slice(array_filter(array_map(fn ($p) => mb_substr($p, 0, 1), $parts)), 0, 2);

        return mb_strtoupper(implode('', $letters)) ?: '#';
    }

    // ───────────────────────────── administração ─────────────────────────────

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403);
    }

    /** Cria/atualiza um número que fala pela API oficial. */
    public function saveAccount(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'id' => 'nullable|integer',
            'name' => 'required|string|max:80',
            'phone' => 'nullable|string|max:32',
            'role' => 'nullable|in:primary,outreach',
            'phone_number_id' => 'required|string|max:64',
            'waba_id' => 'nullable|string|max:64',
            'access_token' => 'nullable|string',
            'app_secret' => 'nullable|string|max:191',
            'coexistence' => 'boolean',
            'graph_version' => 'nullable|string|max:8',
        ]);

        // O mesmo número não pode estar em duas empresas: o webhook resolve o tenant
        // pelo phone_number_id e a duplicata mandaria conversas para a empresa errada.
        $clash = WaAccount::withoutGlobalScopes()
            ->where('phone_number_id', $data['phone_number_id'])
            ->when(! empty($data['id']), fn ($q) => $q->where('id', '!=', $data['id']))
            ->exists();
        if ($clash) {
            return response()->json(['message' => 'Este número (phone_number_id) já está conectado.'], 422);
        }

        $account = ! empty($data['id'])
            ? WaAccount::findOrFail($data['id'])
            : new WaAccount(['role' => $data['role'] ?? 'outreach', 'is_active' => true, 'daily_cap' => 0, 'warmup_day' => 1]);

        $account->fill([
            'provider' => 'cloud',
            'name' => $data['name'],
            'phone' => $data['phone'] ?? $account->phone,
            'role' => $data['role'] ?? $account->role ?? 'outreach',
            'phone_number_id' => $data['phone_number_id'],
            'waba_id' => $data['waba_id'] ?? $account->waba_id,
            'coexistence' => (bool) ($data['coexistence'] ?? $account->coexistence),
            'graph_version' => $data['graph_version'] ?: null,
        ]);

        // Token/segredo em branco na edição = "não mexer" (o formulário nunca os recebe de volta).
        if (! empty($data['access_token'])) {
            $account->access_token = trim($data['access_token']);
        }
        if (! empty($data['app_secret'])) {
            $account->app_secret = trim($data['app_secret']);
        }
        // Token do handshake: gerado uma vez e mostrado na tela para colar no painel da Meta.
        $account->verify_token = $account->verify_token ?: bin2hex(random_bytes(16));
        $account->state = 'close';
        $account->save();

        $state = Wa::for($account)->connectionState();
        $account->update(['state' => $state]);

        return response()->json([
            'account' => $account->only(['id', 'name', 'provider', 'phone', 'role', 'phone_number_id', 'waba_id', 'coexistence', 'state']),
            'verify_token' => $account->verify_token,
            'webhook_url' => url('/api/wpp/cloud/webhook'),
            'info' => (new CloudChannel($account))->numberInfo(),
        ]);
    }

    /** Diagnóstico do número oficial: conexão, dados da Meta e se o segredo está configurado. */
    public function status(Request $request, WaAccount $account)
    {
        $this->ensureAdmin($request);
        abort_unless($account->isCloud(), 404);

        $channel = new CloudChannel($account);

        return response()->json([
            'state' => $channel->connectionState(),
            'info' => $channel->numberInfo(),
            'has_token' => (bool) $account->access_token,
            'has_app_secret' => (bool) $account->app_secret,
            'verify_token' => $account->verify_token,
            'webhook_url' => url('/api/wpp/cloud/webhook'),
        ]);
    }

    /** Templates aprovados da WABA — o que dá para enviar fora da janela de 24h. */
    public function templates(Request $request, WaAccount $account)
    {
        abort_unless($account->isCloud(), 404);

        $templates = collect((new CloudChannel($account))->templates())
            ->map(function ($t) {
                $body = collect($t['components'] ?? [])->firstWhere('type', 'BODY')['text'] ?? '';
                // Quantas variáveis o corpo pede — a tela precisa disso para pedir um
                // valor por {{n}}. Sem o campo, a campanha nunca oferecia os campos.
                preg_match_all('/\{\{\s*(\d+)\s*\}\}/', $body, $vars);

                return [
                    'name' => $t['name'] ?? '',
                    'status' => $t['status'] ?? '',
                    'category' => $t['category'] ?? '',
                    'language' => $t['language'] ?? '',
                    'body' => $body,
                    'header' => collect($t['components'] ?? [])->firstWhere('type', 'HEADER')['text'] ?? '',
                    'params' => $vars[1] ? max(array_map('intval', $vars[1])) : 0,
                ];
            })
            ->values();

        return response()->json(['templates' => $templates]);
    }
}
