<?php

namespace App\Services;

use App\Models\MarketingAutopilot;
use App\Models\WaAccount;
use App\Support\Evolution;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * Alarme de reunião pelo WhatsApp da equipe: lead esperando, ninguém nosso na sala,
 * anfitrião sem Google.
 *
 * Existe porque, de set a nov/2026, a reunião perdida por "ninguém nosso na sala" subiu de
 * 5 % para 38 %, e pelo menos 12 leads escreveram "entrei e ninguém aceitou". O aviso que
 * existia virava uma tarefa `followup`, que fica fora do quadro (Task::scopeManual): ninguém
 * via a tempo. Aqui o aviso sai por WhatsApp, que é onde a equipe olha.
 *
 * Sai por um número da EVOLUTION, como o relatório do gestor (Marketing\Avisos): pela API
 * oficial, mensagem fora da janela de 24h exige template e o alarme não chegaria.
 *
 * Quem recebe: o "WhatsApp de aviso" da empresa (marketing_autopilots.alert_phone, o mesmo
 * do relatório do gestor) mais os números de CRM_ALERTA_SALA_FONES ("empresa:fone,...").
 * Empresa sem nenhum destino não recebe nada (só fica no log).
 */
class AlarmeDaSala
{
    private bool $simular = false;

    /** @var array<int, array{chave: string, destinos: array<string>, texto: string, enviado: bool}> */
    public array $disparos = [];

    public function simular(bool $simular = true): self
    {
        $this->simular = $simular;

        return $this;
    }

    /**
     * Mensagem do lead que diz "estou esperando para entrar" (ou parecido).
     *
     * Só faz sentido perto da reunião: quem chama só passa mensagens da janela da call.
     * Por isso a régua é larga ("estou aguardando" sozinho conta), e foi tirada das
     * mensagens reais de quem esperou e ninguém atendeu (set a nov/2026).
     */
    public static function pareceEsperando(?string $texto): bool
    {
        $t = Str::of((string) $texto)->ascii()->lower()->replaceMatches('/\s+/', ' ')->trim()->value();
        if ($t === '') {
            return false;
        }

        $padroes = [
            '~\bpedi(ndo)? (pra|para|p/|pa) (entrar|participar)~',
            '~\b(ja )?solicitei\b~',
            '~^solicitad[oa]\b~',
            '~\b(aguardando|esperando) (me |ser |eles |voces |vcs )?(aceit|admit|liber|adicion|autoriz|entrar|voces|vcs|voce|vc|na sala|no link|na reuniao|no meet)~',
            '~\b(to|tou|tô|estou|esto|ja estou|ja to|fiquei|fico) (aguardando|esperando)\b~',
            '~^(aguardando|esperando)\b~',
            '~\bningu?em (me )?(aceit|entrou|apareceu|atend|adicion|admit|liber|veio|esta na|ta na|na sala)~',
            '~\b(estou|to|tou|ja estou|ja to|entrei|ja entrei) (na|no) (sala|reuniao|call|link|meet|chamada)\b~',
            '~\bja entrei\b~',
            '~\bentrei\b.*\b(ningu|nada|sozinh)~',
            '~\b(cade|kd|cad)\b.{0,12}\b(voces|vcs|vc|voce|o pessoal|alguem|a equipe)\b~',
            '~\bsala vazia\b~',
            '~\bnao (me )?(aceitaram|aceitou|admitiram|admitiu|deixa|deixaram|liberaram|liberou|consigo entrar)\b~',
            '~\bme (aceita|aceitem|admite|admitam|libera|liberem|adiciona)\b~',
            '~\bpode(m)? (me )?(aceitar|admitir|liberar)\b~',
            '~\b(faz|ha) .{0,20}que (peco|to pedindo|estou pedindo)\b~',
            '~\bpode entrar\b~',
        ];

        foreach ($padroes as $p) {
            if (preg_match($p, $t)) {
                return true;
            }
        }

        return false;
    }

    /** Números que recebem o alarme desta empresa (só dígitos, sem repetir). */
    public static function destinos(int $companyId): array
    {
        $fones = [];

        $alerta = MarketingAutopilot::withoutGlobalScopes()->where('company_id', $companyId)->value('alert_phone');
        if ($alerta) {
            $fones[] = $alerta;
        }

        foreach (explode(',', (string) config('services.crm.alerta_sala_fones', '')) as $par) {
            [$empresa, $fone] = array_pad(explode(':', trim($par), 2), 2, '');
            if ((int) $empresa === $companyId && trim($fone) !== '') {
                $fones[] = $fone;
            }
        }

        return array_values(array_unique(array_filter(array_map(
            fn ($f) => preg_replace('/\D/', '', (string) $f),
            $fones,
        ))));
    }

    /**
     * Manda o alarme uma vez por chave dentro de `$minutos`.
     *
     * A trava vem antes do envio de propósito: o tick roda a cada minuto, e o mesmo
     * "ninguém na sala" chegando 30 vezes é o jeito mais rápido de alguém silenciar o número.
     *
     * @return bool se foi enviado para pelo menos um número (em simulação: se teria sido)
     */
    public function enviar(int $companyId, string $chave, string $texto, int $minutos): bool
    {
        $destinos = self::destinos($companyId);
        $conta = $this->conta($companyId);

        if (! $destinos || ! $conta) {
            // Uma linha por empresa por dia: o tick roda a cada minuto e empresa que nunca
            // configurou aviso não pode encher o log.
            if (Cache::add("alarme-sala:{$companyId}:sem-destino:".now()->format('Y-m-d'), 1, now()->addDay())) {
                Evolution::log('meeting.alarme_sem_destino', [
                'company_id' => $companyId, 'chave' => $chave,
                    'motivo' => ! $destinos ? 'sem WhatsApp de aviso' : 'sem número da Evolution',
                ], 'warning');
            }

            return false;
        }

        $cacheKey = "alarme-sala:{$companyId}:{$chave}";
        if ($this->simular ? Cache::has($cacheKey) : ! Cache::add($cacheKey, now()->timestamp, now()->addMinutes($minutos))) {
            return false;
        }

        $enviado = false;
        foreach ($destinos as $fone) {
            if ($this->simular) {
                $enviado = true;

                continue;
            }
            $enviado = (Evolution::sendText($fone, $texto, instance: $conta->instance) !== null) || $enviado;
        }

        $this->disparos[] = ['chave' => $chave, 'destinos' => $destinos, 'texto' => $texto, 'enviado' => $enviado];

        Evolution::log('meeting.alarme_sala', [
            'company_id' => $companyId, 'chave' => $chave, 'destinos' => count($destinos),
            'enviado' => $enviado, 'simulado' => $this->simular,
        ], $enviado ? 'info' : 'error');

        // Envio que falhou em todos os números solta a trava: o próximo tick tenta de novo.
        if (! $enviado && ! $this->simular) {
            Cache::forget($cacheKey);
        }

        return $enviado;
    }

    /** O número da Evolution da empresa: o escolhido no gestor, ou o primeiro ativo. */
    private function conta(int $companyId): ?WaAccount
    {
        $q = WaAccount::withoutGlobalScopes()
            ->where('company_id', $companyId)
            ->where(fn ($w) => $w->whereNull('provider')->orWhere('provider', '!=', 'cloud'))
            ->whereNotNull('instance')->where('instance', '!=', '');

        $escolhida = MarketingAutopilot::withoutGlobalScopes()->where('company_id', $companyId)->value('alert_wa_account_id');
        if ($escolhida && ($conta = (clone $q)->whereKey($escolhida)->first())) {
            return $conta;
        }

        return $q->where('is_active', true)->orderByRaw('COALESCE(avatars_only, 0)')->orderBy('id')->first();
    }
}
