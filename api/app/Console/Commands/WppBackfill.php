<?php

namespace App\Console\Commands;

use App\Http\Controllers\Api\WhatsAppController;
use App\Models\Conversation;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class WppBackfill extends Command
{
    protected $signature = 'wpp:backfill {--offset=200}';

    protected $description = 'Importa TODO o histórico do WhatsApp (Evolution) varrendo todas as mensagens.';

    public function handle(): int
    {
        $url = rtrim((string) config('services.evolution.url'), '/');
        $key = (string) config('services.evolution.key');
        $inst = (string) config('services.evolution.instance');
        $http = Http::baseUrl($url)->withHeaders(['apikey' => $key])->timeout(60);
        $ctrl = app(WhatsAppController::class);

        Conversation::$muteBroadcast = true; // sem flood de tempo real durante o bulk

        $offset = (int) $this->option('offset');
        $page = 1;
        $done = 0;
        $total = null;
        $recs = [];
        $pushNames = []; // slug => nome do contato (pelo pushName de mensagens de entrada)

        do {
            $resp = $http->post("/chat/findMessages/$inst", ['page' => $page, 'offset' => $offset])->json();
            $recs = $resp['messages']['records'] ?? [];
            $total = $resp['messages']['total'] ?? 0;
            $pages = $resp['messages']['pages'] ?? 1;
            foreach ($recs as $m) {
                if (is_array($m)) {
                    // Captura o nome do contato pelo pushName das mensagens de ENTRADA
                    // (funciona até p/ @lid, que não casa no findContacts).
                    $jid = (string) ($m['key']['remoteJid'] ?? '');
                    if ($jid !== '' && empty($m['key']['fromMe'] ?? false)) {
                        $pn = trim((string) ($m['pushName'] ?? ''));
                        if ($pn !== '' && ! preg_match('/^\d+$/', $pn)
                            && ! in_array(mb_strtolower($pn), ['você', 'voce', 'you'], true)) {
                            $slug = 'wa-'.preg_replace('/[^a-z0-9]/i', '', explode('@', $jid)[0]);
                            if (! isset($pushNames[$slug])) {
                                $pushNames[$slug] = $pn;
                            }
                        }
                    }
                    try {
                        $ctrl->ingestMessage($m);
                    } catch (\Throwable $e) {
                    }
                    $done++;
                }
            }
            $this->info("pag {$page}/{$pages}: +".count($recs)." (acum {$done}/{$total})");
            $page++;
        } while (count($recs) > 0 && $page <= $pages);

        // Normaliza cada conversa: ordena por ts, define preview/ultima e zera unread (historico = lido).
        $this->info('Normalizando conversas...');
        // Mapa de contatos (nome + foto) p/ enriquecer — 1 chamada.
        $contacts = [];
        foreach ((array) $http->post("/chat/findContacts/$inst", [])->json() as $ct) {
            if (is_array($ct) && ! empty($ct['remoteJid'])) {
                $contacts[$ct['remoteJid']] = $ct;
                $d = preg_replace('/\D/', '', explode('@', $ct['remoteJid'])[0]);
                if ($d !== '' && ! isset($contacts['#'.$d])) {
                    $contacts['#'.$d] = $ct;
                }
            }
        }
        // Nome salvo por telefone, a partir da tabela local de contatos (sincronizada
        // do Google pelo comando contacts:sync). Últimos 8 dígitos como chave.
        $googleMap = [];
        foreach (\App\Models\Contact::whereNotNull('phone')->get(['name', 'phone']) as $ct) {
            $d = preg_replace('/\D/', '', (string) $ct->phone);
            if (strlen($d) >= 8) {
                $googleMap[substr($d, -8)] = $ct->name;
            }
        }
        $this->info('Contatos locais p/ nomear: '.count($googleMap).'.');

        $isAuto = fn ($n) => $n === null || trim($n) === '' || preg_match('/^\+?\d+$/', trim($n))
            || str_contains($n, '@') || $n === 'Contato WhatsApp'
            || in_array(mb_strtolower(trim($n)), ['você', 'voce', 'you'], true);
        $initials = function ($name) {
            $p = preg_split('/\s+/', trim((string) $name), -1, PREG_SPLIT_NO_EMPTY);

            return mb_strtoupper(mb_substr($p[0] ?? '', 0, 1).(count($p) > 1 ? mb_substr(end($p), 0, 1) : '')) ?: '#';
        };

        $tz = config('app.timezone', 'America/Sao_Paulo');
        Conversation::where('slug', 'like', 'wa-%')->cursor()->each(function (Conversation $c) use ($tz, $contacts, $isAuto, $initials, $pushNames, $googleMap) {
            $msgs = $c->messages()->orderByRaw('ts IS NULL, ts')->orderBy('id')->get();
            if ($msgs->isEmpty()) {
                return;
            }
            foreach ($msgs as $i => $msg) {
                if ((int) $msg->position !== $i) {
                    $msg->update(['position' => $i]);
                }
            }
            $last = $msgs->last();
            $c->preview = mb_substr((string) ($last->text ?: $c->preview), 0, 80);
            if ($last->ts) {
                $c->last_message_at = Carbon::createFromTimestamp($last->ts, $tz)->format('Y-m-d H:i:s');
                $c->time = Carbon::createFromTimestamp($last->ts, $tz)->format('H:i');
            }
            $c->unread = 0;

            // Enriquece nome + foto (sem sobrescrever nome posto à mão).
            $d = preg_replace('/\D/', '', explode('@', (string) $c->wa_jid)[0]);
            $ct = $contacts[$c->wa_jid] ?? ($d ? ($contacts['#'.$d] ?? null) : null);

            // Nome: 1º contato do Google (nome salvo por você, por telefone);
            //        2º pushName das mensagens de entrada; 3º findContacts.
            if ($isAuto($c->name)) {
                $name = null;
                $d2 = preg_replace('/\D/', '', (string) $c->phone);
                if ($d2 !== '' && strlen($d2) >= 8) {
                    $name = $googleMap[substr($d2, -8)] ?? null;
                }
                if (! $name) {
                    $name = $pushNames[$c->slug] ?? null;
                }
                if (! $name && $ct) {
                    $p = trim((string) ($ct['pushName'] ?? ''));
                    if ($p !== '' && ! preg_match('/^\d+$/', $p)
                        && ! in_array(mb_strtolower($p), ['você', 'voce', 'you'], true)) {
                        $name = $p;
                    }
                }
                if ($name) {
                    $c->name = $name;
                    $c->initials = $initials($name);
                } elseif (in_array(mb_strtolower((string) $c->name), ['você', 'voce', 'you'], true)) {
                    // @lid sem nome real → pelo menos não deixa "Você" (engana).
                    $c->name = 'Contato WhatsApp';
                    $c->initials = '#';
                }
            }
            if ($ct && ! empty($ct['profilePicUrl']) && empty($c->avatar)) {
                $c->avatar = $ct['profilePicUrl'];
            }
            $c->saveQuietly();
        });

        // Fotos: busca ao vivo (fetchProfilePictureUrl) p/ quem tem telefone e ainda sem foto.
        $this->info('Buscando fotos de perfil...');
        try {
            $ctrl->fillAvatars();
        } catch (\Throwable $e) {
        }

        Conversation::$muteBroadcast = false;
        $this->info("Backfill concluido. Mensagens processadas: {$done}.");

        return self::SUCCESS;
    }
}
