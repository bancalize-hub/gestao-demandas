<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Meeting;
use App\Models\Stage;
use App\Services\AiReplyService;
use App\Services\ChatSender;
use App\Support\Claude;
use App\Support\Evolution;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Retomada ativa: a IA vai atrás do lead que sumiu no meio da conversa.
 *
 * O atendimento automático só responde quando o cliente escreve — lead que ficou
 * empolgado e parou de responder morria em silêncio. Aqui, a cada rodada de silêncio,
 * a IA sobe uma escada de retomadas com espaçamento crescente e ângulos diferentes
 * (30 min ainda na mesma conversa → lembrete no dia seguinte → novo valor →
 * encerramento educado), e para.
 *
 * O degrau de 30 min só vale para conversa VIVA (o lead falou pouco antes da nossa
 * última mensagem); quem já estava frio começa direto no degrau seguinte.
 *
 * A rodada zera sozinha quando o lead volta a responder.
 */
class NudgeTick extends Command
{
    protected $signature = 'nudge:tick';

    protected $description = 'A IA retoma sozinha o contato com os leads que pararam de responder';

    public function handle(AiReplyService $ai, ChatSender $sender): int
    {
        $delays = config('services.nudge.delays_minutes');
        if (! $delays) {
            return self::SUCCESS;
        }

        // Fora da janela de horário (ou domingo) ninguém é incomodado: a pendência
        // simplesmente espera o próximo tick dentro do expediente.
        if (! $this->dentroDaJanela()) {
            return self::SUCCESS;
        }

        // IA fora do ar (token revogado etc.): retomada não é urgente, espera a trégua.
        if (Claude::indisponivel()) {
            return self::SUCCESS;
        }

        $max = count($delays);
        $tenancy = app(Tenancy::class);

        // Consulta global (sem tenant vinculado): candidatos de TODAS as empresas.
        // O filtro fino (última mensagem é nossa, silêncio suficiente, etc.) roda por
        // conversa — aqui só o corte barato que elimina a maioria.
        //
        // Do mais RECENTE para o mais antigo: o degrau de 30 min só faz sentido quente,
        // e com a fila ordenada ao contrário uma pilha de conversas velhas consumiria o
        // teto do tick e a conversa que acabou de esfriar nunca seria alcançada.
        $candidatos = Conversation::where('auto_reply', true)
            ->where('archived', false)
            ->whereNotNull('phone')
            ->whereNull('auto_reply_due_at')
            ->where('nudge_count', '<', $max)
            ->where('last_message_at', '<=', now()->subMinutes(min($delays)))
            ->orderByDesc('last_message_at')
            ->limit((int) config('services.nudge.per_tick', 15))
            ->get();

        foreach ($candidatos as $conv) {
            $tenancy->set($conv->company_id);

            try {
                $this->processar($conv, $delays, $ai, $sender);
            } catch (\Throwable $e) {
                // Uma conversa problemática nunca derruba o tick (nem as demais).
                Log::error('nudge: falha na conversa', ['conv' => $conv->id, 'e' => $e->getMessage()]);
            } finally {
                $tenancy->forget();
            }
        }

        return self::SUCCESS;
    }

    private function processar(Conversation $conv, array $delays, AiReplyService $ai, ChatSender $sender): void
    {
        if (! Company::find($conv->company_id)?->nudge_enabled) {
            return;
        }

        // Negócio na última etapa do funil (fechado/perdido) não se persegue.
        if ($this->etapaFinal($conv)) {
            return;
        }

        $ultima = $conv->messages()->reorder()->orderByDesc('ts')->orderByDesc('id')->first();
        if (! $ultima) {
            return;
        }

        // A bola está com a gente? Se a última mensagem é do lead, quem responde é o
        // atendimento automático (ou o humano) — retomar aqui seria atropelar.
        if (! $ultima->is_out) {
            return;
        }

        // Lead que NUNCA respondeu nada não é retomada, é prospecção fria — isso é
        // trabalho das campanhas (com anti-ban próprio), não da IA de atendimento.
        $entrada = $conv->lastInboundTs();
        if (! $entrada) {
            return;
        }

        // Rodada nova: o lead voltou a falar depois da última retomada → zera o contador.
        $count = (int) $conv->nudge_count;
        if ($count > 0 && $conv->nudge_last_at && $entrada > $conv->nudge_last_at->timestamp) {
            $conv->update(['nudge_count' => 0, 'nudge_last_at' => null]);
            $count = 0;
        }
        if ($count >= count($delays)) {
            return;
        }

        // Reunião marcada no futuro: o lead não sumiu, ele já tem hora com a gente.
        // Quem fala com ele é o lembrete de reunião.
        if (Meeting::where('conversation_id', $conv->id)->active()->exists()) {
            return;
        }

        $silencio = time() - (int) ($ultima->ts ?: $ultima->created_at?->timestamp);

        // Degrau da escada. O primeiro (30 min) existe só para o caso "sumiu no meio da
        // conversa": o lead falou pouco antes da nossa mensagem E o silêncio ainda é curto.
        // Sem essas duas condições ele é pulado — cutucar em 30 min quem já estava frio há
        // dias é atropelo, e usar esse degrau numa conversa parada desde ontem só gastaria
        // uma mensagem quase idêntica à do degrau seguinte.
        $tier = $count;
        if ($tier === 0 && ! $this->mesmaConversa($ultima, $entrada, $silencio, $delays[0])) {
            $tier = 1;
            if ($tier >= count($delays)) {
                return;
            }
        }

        // Espalhamento fixo por conversa: sem ele, todo lead vencido recebe no mesmo minuto
        // em que a janela abre — cheira a robô e concentra envios no mesmo número. Proporcional
        // ao degrau (teto de 3h), senão o empurrão de 30 min chegaria horas depois.
        $jitter = min((int) ($delays[$tier] * 60 / 8), 3 * 3600);
        $exigido = $delays[$tier] * 60 + ($jitter > 0 ? crc32("nudge:{$conv->id}") % $jitter : 0);
        if ($silencio < $exigido) {
            return;
        }

        // API oficial: fora das 24h desde a última mensagem DO CLIENTE a Meta recusa texto
        // livre — e uma retomada é, por definição, fora dessa janela. Encerra a rodada com
        // registro (em vez de tentar de novo a cada tick) e deixa para o humano/template.
        if (! $conv->canSendFreeform()) {
            $conv->update(['nudge_count' => count($delays), 'nudge_last_at' => now()]);
            Evolution::log('nudge.janela_fechada', [
                'conversation_id' => $conv->id,
                'wa_account_id' => $conv->wa_account_id,
            ], 'warning');

            return;
        }

        $reply = $ai->generate($conv, $this->instrucao($tier, $silencio));
        if ($reply === null || trim($reply) === '') {
            Evolution::log('nudge.ia_nao_gerou', ['conversation_id' => $conv->id], 'error');

            return;
        }

        [$reply, $material] = AiReplyService::extrairMaterial($reply);
        if (trim($reply) === '') {
            $reply = 'Segue o material.';
        }

        $msg = $sender->text($conv, $reply);
        if (! $msg) {
            Evolution::log('nudge.envio_falhou', [
                'conversation_id' => $conv->id,
                'wa_account_id' => $conv->wa_account_id,
            ], 'error');

            return;
        }

        // Guarda o degrau alcançado (não $count + 1): quando o degrau rápido é pulado, a
        // próxima retomada tem que ser a de 3 dias, não a de 20h de novo.
        $conv->update(['nudge_count' => $tier + 1, 'nudge_last_at' => now()]);

        if ($material) {
            $sender->material($conv, $material);
        }

        LeadActivity::log(
            $conv->id,
            'nudge',
            'IA retomou o contato ('.($tier + 1).'ª tentativa, após '.$this->humano($silencio).' de silêncio)',
            $reply,
        );

        $this->info("nudge: retomou conversa {$conv->id} ({$conv->name}) — tentativa ".($tier + 1));
    }

    /**
     * O que esta retomada tem que ser. Cada degrau muda de ângulo — três vezes
     * "passando para saber se você viu" é o que faz o lead bloquear o número.
     */
    private function instrucao(int $tier, int $silencio): string
    {
        $quanto = $this->humano($silencio);
        $base = "O lead parou de responder há {$quanto} — a última mensagem da conversa é SUA, ele não respondeu. "
            .'Escreva uma mensagem para retomar o contato. '
            .'Ela precisa parecer escrita na hora por uma pessoa, não um lembrete automático. '
            .'Retome pelo ASSUNTO CONCRETO que vocês estavam tratando (o que ele perguntou/demonstrou interesse). '
            .'Nunca cobre resposta, nunca diga "vi que você não respondeu", "passando para saber se viu", '
            .'"ainda tem interesse?" nem nada que soe a robô de follow-up. ';

        if ($tier === 0) {
            return $base.'Vocês estão na MESMA conversa, ele sumiu no meio dela há poucos minutos. '
                .'NÃO cumprimente (nada de "bom dia/boa tarde"): emende no assunto como quem lembrou de um '
                .'detalhe útil ou se ofereceu para explicar melhor. UMA frase curta, leve, sem cobrança.';
        }

        return $base.match ($tier) {
            1 => 'Tom leve e curto: uma frase que continue de onde parou e uma pergunta simples e fácil de '
                .'responder sobre o ponto que ele estava avaliando.',
            2 => 'As retomadas anteriores não tiveram resposta: NÃO repita o que você já disse. '
                .'Traga algo de valor novo — um ponto prático, um exemplo de caso parecido ou uma informação útil '
                .'sobre a dúvida que ele levantou — e feche com uma pergunta aberta e sem pressão.',
            default => 'Esta é a ÚLTIMA retomada (as anteriores não tiveram resposta): faça um encerramento educado, '
                .'sem culpa e sem drama. Diga que não vai insistir, deixe a porta aberta para quando fizer sentido '
                .'e pergunte se prefere que você retome mais para a frente. Duas frases, no máximo.',
        };
    }

    /**
     * Ainda é a MESMA conversa? Duas condições: o lead falou pouco antes da nossa última
     * mensagem (estava em fluxo, não é alguém frio que recebeu um contato agora) e o
     * silêncio ainda é curto — o teto é 3x o próprio degrau (30 min → até 1h30).
     */
    private function mesmaConversa($ultima, int $entrada, int $silencio, int $degrauMinutos): bool
    {
        $nossa = (int) ($ultima->ts ?: $ultima->created_at?->timestamp);
        $emFluxo = ($nossa - $entrada) <= (int) config('services.nudge.flow_minutes', 120) * 60;

        return $emFluxo && $silencio <= $degrauMinutos * 3 * 60;
    }

    /** Silêncio em português: "35 minutos", "3 horas", "6 dias". */
    private function humano(int $segundos): string
    {
        if ($segundos < 5400) {
            return max(1, (int) round($segundos / 60)).' minutos';
        }
        if ($segundos < 86400) {
            return max(1, (int) round($segundos / 3600)).' horas';
        }

        return max(1, (int) round($segundos / 86400)).' dias';
    }

    /** Dentro do horário comercial configurado (e não é domingo)? */
    private function dentroDaJanela(): bool
    {
        $agora = now();
        if ($agora->isSunday()) {
            return false;
        }

        $hora = (int) $agora->format('G');

        return $hora >= (int) config('services.nudge.start_hour', 9)
            && $hora < (int) config('services.nudge.end_hour', 19);
    }

    /** A conversa está na última etapa do funil (negócio fechado/encerrado)? */
    private function etapaFinal(Conversation $conv): bool
    {
        $ultima = Stage::orderByDesc('position')->orderByDesc('id')->value('key');

        return $ultima !== null && $conv->stage === $ultima;
    }
}
