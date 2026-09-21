<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\Conversation;
use App\Models\LeadActivity;
use App\Models\Meeting;
use App\Models\Stage;
use App\Services\AiReplyService;
use App\Services\ChatSender;
use App\Support\Attendance;
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

        // IA fora do ar (token revogado etc.): retomada não é urgente, espera a trégua.
        if (Claude::indisponivel()) {
            return self::SUCCESS;
        }

        // Fora do expediente (ou domingo) só o PRIMEIRO degrau vale. Ele é a continuação
        // de uma conversa que estava viva minutos atrás — e o atendimento automático já
        // responde a qualquer hora, então segurar esse empurrão até as 9h transformaria
        // "emendar no assunto" em "sumiu e voltou no dia seguinte". Os degraus longos
        // (20h, 3 e 7 dias) continuam presos ao horário comercial: aí sim é abordagem
        // nova, e ninguém quer ser abordado às 4 da manhã.
        // A janela agora é POR EMPRESA (Admin → Horário de atendimento). O mapa é
        // calculado uma vez por tick: são poucas empresas e o relógio não muda no meio.
        $dentroPorEmpresa = Company::query()->get()
            ->mapWithKeys(fn ($c) => [$c->id => Attendance::dentro($c)])
            ->all();
        $dentroIds = array_keys(array_filter($dentroPorEmpresa));

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
            // Humano conduzindo a conversa agora: a retomada automática espera a vez dela.
            ->where(fn ($q) => $q->whereNull('ai_paused_until')->orWhere('ai_paused_until', '<=', now()))
            ->where('nudge_count', '<', $max)
            ->where('last_message_at', '<=', now()->subMinutes(min($delays)))
            // Empresa DENTRO da janela entra inteira; empresa FORA só contribui com o
            // candidato QUENTE (últimos 3x o primeiro degrau — é o único que escapa da
            // janela). Sem esta separação, as conversas de uma empresa fechada enchiam o
            // limit() e eram todas descartadas em processar() sem mudar de estado — a
            // empresa aberta ficava faminta olhando a mesma fila parada, tick após tick.
            ->where(fn ($q) => $q->whereIn('company_id', $dentroIds)
                ->orWhere('last_message_at', '>=', now()->subMinutes($delays[0] * 3)))
            ->orderByDesc('last_message_at')
            ->limit((int) config('services.nudge.per_tick', 15))
            ->get();

        foreach ($candidatos as $conv) {
            $tenancy->set($conv->company_id);

            try {
                $this->processar($conv, $delays, $ai, $sender, $dentroPorEmpresa[$conv->company_id] ?? false);
            } catch (\Throwable $e) {
                // Uma conversa problemática nunca derruba o tick (nem as demais).
                Log::error('nudge: falha na conversa', ['conv' => $conv->id, 'e' => $e->getMessage()]);
            } finally {
                $tenancy->forget();
            }
        }

        return self::SUCCESS;
    }

    private function processar(Conversation $conv, array $delays, AiReplyService $ai, ChatSender $sender, bool $janela): void
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

        // now() (e não time()): mesmo instante em produção, mas é o relógio que o Carbon
        // controla — sem isso não dá para exercitar os degraus em teste.
        $silencio = now()->timestamp - (int) ($ultima->ts ?: $ultima->created_at?->timestamp);

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

        // Só o degrau quente escapa do horário comercial (ver o comentário em handle()).
        if (! $janela && $tier !== 0) {
            return;
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
        // livre — e todo degrau a partir do de 20h cai fora dessa janela, então sem este
        // caminho a retomada simplesmente não existiria no número oficial.
        if (! $conv->canSendFreeform()) {
            $this->porTemplate($conv, $delays, $tier, $silencio, $ai, $sender);

            return;
        }

        $reply = $ai->generate($conv, $this->instrucao($tier, $silencio));
        if ($reply === null || trim($reply) === '') {
            Evolution::log('nudge.ia_nao_gerou', ['conversation_id' => $conv->id], 'error');

            return;
        }

        // A IA recusou escrever a retomada — quase sempre porque o cliente já pediu para
        // parar. Mandar o texto da recusa seria pior que não mandar nada, e insistir de
        // novo depois é exatamente o que a Meta chamou de spam: encerra a retomada aqui.
        if (AiReplyService::pareceRecusa($reply)) {
            $this->encerrarRodada($conv, $delays, 'nudge.ia_recusou');

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
     * Retomada pelo caminho que sobra na Cloud API fora das 24h: TEMPLATE aprovado.
     *
     * O template é uma frase fixa aprovada pela Meta — a IA não escreve a mensagem, só
     * preenche as duas variáveis ({{1}} primeiro nome, {{2}} assunto que estava em jogo).
     * Sem template configurado não há o que enviar: encerra a rodada com registro, em vez
     * de tentar de novo a cada tick e queimar uma chamada de IA por tentativa.
     */
    private function porTemplate(Conversation $conv, array $delays, int $tier, int $silencio, AiReplyService $ai, ChatSender $sender): void
    {
        $nome = (string) config('services.nudge.template');
        if (trim($nome) === '') {
            $this->encerrarRodada($conv, $delays, 'nudge.janela_fechada');

            return;
        }

        // Quais variáveis o template tem sai do PRÓPRIO `template_text` — ele é a cópia da
        // frase aprovada, então :nome/:assunto ali são exatamente {{1}}/{{2}} na Meta. Mandar
        // parâmetro a mais (ou a menos) do que o template declara é recusa na hora (132000),
        // e assim trocar de template é mexer só no .env, sem voltar aqui.
        $frase = (string) config('services.nudge.template_text');

        $vars = [];
        if (str_contains($frase, ':nome')) {
            $vars[':nome'] = $this->primeiroNome($conv);
        }
        if (str_contains($frase, ':assunto')) {
            $vars[':assunto'] = $this->assunto($conv, $ai);
        }

        // A bolha do chat tem que ser a MESMA frase que o cliente recebeu, senão o
        // atendente responde sem saber o que foi enviado no lugar dele.
        $espelho = str_replace(array_keys($vars), array_values($vars), $frase);

        $msg = $sender->template(
            $conv,
            $nome,
            (string) config('services.nudge.template_language', 'pt_BR'),
            array_values($vars),
            $espelho,
        );

        if (! $msg) {
            // Quase sempre é template não aprovado / nome ou idioma errado — nada que se
            // resolva em 5 minutos. Encerra a rodada (o motivo fica no log da Meta).
            $this->encerrarRodada($conv, $delays, 'nudge.template_falhou');

            return;
        }

        $conv->update(['nudge_count' => $tier + 1, 'nudge_last_at' => now()]);

        LeadActivity::log(
            $conv->id,
            'nudge',
            'IA retomou o contato por template ('.($tier + 1).'ª tentativa, após '.$this->humano($silencio).' de silêncio)',
            $espelho,
        );

        $this->info("nudge: retomou conversa {$conv->id} ({$conv->name}) por template — tentativa ".($tier + 1));
    }

    /**
     * O assunto que estava em jogo, em poucas palavras — é a variável {{2}} do template.
     * Vai para dentro de uma frase pronta ("nossa conversa sobre ___"), então precisa ser
     * um pedaço de frase, não uma mensagem.
     */
    private function assunto(Conversation $conv, AiReplyService $ai): string
    {
        $texto = $ai->generate(
            $conv,
            'NÃO escreva uma mensagem para o cliente. Responda APENAS com o assunto concreto que vocês '
            .'estavam tratando nesta conversa, em no máximo 6 palavras, para encaixar na frase '
            .'"nossa conversa sobre ___". O trecho é lido PELO cliente, então fale com ele: use '
            .'"a sua operação", nunca "a operação dele/dela". Sem aspas, sem ponto final, sem explicação. '
            .'Exemplos de resposta válida: "as taxas do gateway", "a integração com o seu ERP".',
        );

        $texto = trim((string) $texto, " \t\n\r\0\x0B\"'.");

        // Uma variável de template NÃO pode ir vazia (a Meta recusa) e não pode ter quebra
        // de linha. Se a IA devolveu qualquer coisa fora do esperado, cai no genérico.
        $texto = trim(preg_replace('/\s+/u', ' ', $texto) ?? '');

        return ($texto !== '' && mb_strlen($texto) <= 60) ? $texto : 'o que conversamos por aqui';
    }

    /** Primeiro nome do lead — variável {{1}}. Conversa sem nome (só telefone) vira saudação neutra. */
    private function primeiroNome(Conversation $conv): string
    {
        $nome = trim((string) $conv->name);
        $primeiro = $nome !== '' ? (preg_split('/\s+/', $nome)[0] ?? '') : '';

        return ($primeiro !== '' && ! preg_match('/^\+?\d+$/', $primeiro)) ? $primeiro : 'tudo bem';
    }

    /** Fecha a rodada de retomadas desta conversa (nada mais será tentado até o lead voltar). */
    private function encerrarRodada(Conversation $conv, array $delays, string $evento): void
    {
        $conv->update(['nudge_count' => count($delays), 'nudge_last_at' => now()]);

        Evolution::log($evento, [
            'conversation_id' => $conv->id,
            'wa_account_id' => $conv->wa_account_id,
        ], 'warning');
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

    /** A conversa está na última etapa do funil (negócio fechado/encerrado)? */
    private function etapaFinal(Conversation $conv): bool
    {
        $ultima = Stage::orderByDesc('position')->orderByDesc('id')->value('key');

        return $ultima !== null && $conv->stage === $ultima;
    }
}
