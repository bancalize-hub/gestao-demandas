<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingGestorSession;
use App\Models\MarketingMemory;
use App\Support\Claude;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Um turno do gestor de tráfego como AGENTE: o Claude recebe as ferramentas da conta
 * (`php artisan gestor …`) e a visão das imagens dos criativos, investiga o que achar
 * necessário, age e marca quando volta. Nada de foto pronta e lista fechada de ações — ele
 * decide o que olhar, como um gestor humano abrindo o Gerenciador.
 *
 * O ritmo é dele (entre {@see MIN_INTERVALO} e {@see MAX_INTERVALO}); a vigia sem IA de 10 em
 * 10 min só o acorda antes quando algo não pode esperar.
 */
class GestorAgente
{
    public const MIN_INTERVALO = 60;

    public const MAX_INTERVALO = 360;

    /** Quando ele não marca a volta: 3h — análise boa custa token, e a conta não muda a cada hora. */
    public const PADRAO_INTERVALO = 180;

    private const TIMEOUT = 1800;

    public function __construct(private MarketingAutopilot $cfg, private bool $simular = false) {}

    public function rodar(?string $urgente = null): MarketingGestorSession
    {
        return self::turno('gestor', $this->cfg, $this->simular, $this->sistema(), $this->pedido($urgente),
            ['Bash(php artisan gestor *)', 'Bash(php artisan gestor:*)', self::leituraDeImagens()],
            function (MarketingGestorSession $s) {
                if ($this->simular) {
                    return;
                }
                $this->cfg->refresh();
                // Marcou a volta neste turno (`gestor voltar-em`)? Senão, o padrão.
                $marcou = MarketingAutopilotEvent::where('session_id', $s->id)->where('kind', 'agenda')->exists();
                $this->cfg->update(['last_review_at' => now(), 'last_run_at' => now(), 'last_analysis' => $s->resumo]
                    + ($marcou ? [] : ['next_review_at' => now()->addMinutes(self::PADRAO_INTERVALO)]));
            });
    }

    /**
     * O motor comum do gestor e do designer: cria a sessão, roda o Claude como agente e
     * grava cada passo (o que pensou, que ferramenta chamou, o que voltou) enquanto acontece.
     *
     * @param  list<string>  $ferramentas
     * @param  callable(MarketingGestorSession): void|null  $aoTerminar
     */
    public static function turno(string $agente, MarketingAutopilot $cfg, bool $simular, string $sistema, string $pedido, array $ferramentas, ?callable $aoTerminar = null, array $envExtra = []): MarketingGestorSession
    {
        $s = MarketingGestorSession::create(['agente' => $agente, 'simulated' => $simular, 'started_at' => now(), 'status' => 'rodando', 'passos' => []]);
        $passos = [];
        $usadas = 0;

        $registrar = function (array $ev) use (&$passos, &$usadas, $s) {
            foreach (($ev['type'] ?? '') === 'assistant' ? ($ev['message']['content'] ?? []) : [] as $b) {
                if (($b['type'] ?? '') === 'text' && trim($b['text'] ?? '') !== '') {
                    $passos[] = ['t' => 'pensou', 'v' => Str::limit(trim($b['text']), 2000)];
                } elseif (($b['type'] ?? '') === 'tool_use') {
                    $usadas++;
                    $in = $b['input'] ?? [];
                    $passos[] = ['t' => 'usou', 'v' => Str::limit((string) ($in['command'] ?? $in['file_path'] ?? json_encode($in, JSON_UNESCAPED_UNICODE)), 600)];
                }
            }
            foreach (($ev['type'] ?? '') === 'user' ? ($ev['message']['content'] ?? []) : [] as $b) {
                if (($b['type'] ?? '') === 'tool_result') {
                    $c = $b['content'] ?? '';
                    $txt = is_array($c) ? collect($c)->map(fn ($x) => $x['text'] ?? '['.($x['type'] ?? '?').']')->implode("\n") : (string) $c;
                    $passos[] = ['t' => 'viu', 'v' => Str::limit($txt, 1500)];
                }
            }
            // Grava aos poucos: o turno leva minutos, e a tela mostra o agente trabalhando.
            if (count($passos) % 4 === 0) {
                $s->update(['passos' => $passos, 'ferramentas_usadas' => $usadas]);
            }
        };

        $r = Claude::agente($pedido, $sistema, $ferramentas, base_path(), [
            'GESTOR_EMPRESA' => (string) $cfg->company_id,
            'GESTOR_SIMULAR' => $simular ? '1' : '0',
            'GESTOR_SESSAO' => (string) $s->id,
        ] + $envExtra, self::TIMEOUT, $registrar);

        $s->update([
            'ended_at' => now(),
            'status' => $r['ok'] ? 'ok' : 'falhou',
            'passos' => $passos,
            'ferramentas_usadas' => $usadas,
            'resumo' => $r['resultado'] ? Str::limit(trim($r['resultado']), 6000) : null,
            'erro' => $r['erro'],
        ]);
        if ($aoTerminar) {
            $aoTerminar($s);
        }

        return $s;
    }

    /** O agente lê (vê) as imagens dos criativos — e só elas: nada de .env, código ou banco. */
    public static function leituraDeImagens(): string
    {
        return 'Read(/'.rtrim(Storage::path('marketing'), '/').'/**)';
    }

    private function pedido(?string $urgente): string
    {
        $ultimo = MarketingGestorSession::where('agente', 'gestor')->where('status', 'ok')->where('simulated', false)
            ->latest('id')->first();
        $antes = $ultimo
            ? "Seu último turno terminou {$ultimo->ended_at?->format('d/m H:i')}. O que você concluiu e deixou combinado:\n\"\"\"\n{$ultimo->resumo}\n\"\"\""
            : 'É o seu PRIMEIRO turno nesta conta: faça um diagnóstico completo antes de mexer em qualquer coisa — anúncios, conversas dos leads, criativos (olhe as imagens) e recortes.';
        $motivo = $urgente ? "\n\nVOCÊ FOI CHAMADO ANTES DA HORA: {$urgente}." : '';
        $sim = $this->simular ? "\n\nESTE TURNO É UMA SIMULAÇÃO: as ações não são executadas (a ferramenta avisa). Decida como se fossem de verdade." : '';

        return 'Turno de '.now()->format('d/m/Y H:i (l)').".{$motivo}{$sim}\n\n{$antes}\n\nComece.";
    }

    private function sistema(): string
    {
        $c = $this->cfg;
        $instrucoes = trim((string) $c->instructions) ?: '(nenhuma)';
        $memoria = MarketingMemory::contexto(60) ?: '(vazia)';
        $alcance = $c->manage_whole_account
            ? 'Você cuida da CONTA INTEIRA, inclusive das campanhas que o dono criou à mão.'
            : 'Você só pode mexer nos anúncios criados por você (os outros aparecem só para você saber quanto do teto está tomado).';

        return <<<TXT
Você é o GESTOR DE TRÁFEGO desta empresa — sênior, trabalhando sozinho, como se fosse
funcionário dedicado à conta. Ninguém vai revisar suas decisões antes de valerem: o dono
confia em você e lê o seu relatório. {$alcance} Fale e escreva em português do Brasil.

## Como você trabalha
Você tem ferramentas para olhar e agir na conta. Use quantas precisar, na ordem que fizer
sentido. Um bom turno, em geral:
1. `php artisan gestor conta` — saldo, compromisso × teto, custo por lead qualificado.
2. `php artisan gestor anuncios` — todo anúncio que está entregando ou gastou na semana.
3. Para cada anúncio que importa: `gestor anuncio <id>` (série dia a dia, motivos de
   reprovação, reuniões, texto e imagem) e `gestor conversas <id>` — LEIA o que os leads
   escreveram. É aí que se descobre se o criativo atrai o dono de negócio ou quem quer
   empréstimo. Olhe a imagem do criativo com a ferramenta Read no caminho que a ferramenta dá.
4. `gestor recortes idade|genero|posicionamento|regiao|hora|dispositivo` — onde o
   qualificado (qualificados_capi = Purchase da CAPI = lead qualificado) custa menos.
5. `gestor criativos` — o histórico de cada criativo; olhe as IMAGENS dos que mais qualificaram
   e dos que mais queimaram dinheiro e entenda o que os diferencia (registro visual, manchete,
   promessa, cores, quanto texto, cara de banco ou de material de negócios).
6. Decida e aja. Toda ação leva --motivo com os números que a justificam.
7. Se faltam criativos bons para rodar, peça ao DESIGNER (`gestor pedir-criativo`) com um
   briefing de verdade: o que a peça comunica, para quem, registro visual, manchete sugerida,
   o que copiar dos vencedores (--referencias=) e o que evitar dos perdedores (--evitar=), e
   por quê. O designer entrega na biblioteca; você publica quando achar certo.
8. Termine com `gestor voltar-em <minutos> --motivo=` (entre 60 e 360). Volte cedo quando algo
   precisa ser acompanhado (criativo novo, verba mexida, saldo acabando); volte tarde quando a
   conta está estável — cada turno custa, e dado de 1 hora é ruído.

Rode as ferramentas EXATAMENTE como `php artisan gestor …` (o diretório já é o certo), um
comando por vez, sem `cd`, sem `&&`, sem pipes — qualquer outra coisa é bloqueada.
Ajuda completa: `php artisan gestor`.

## O que já foi medido nesta conta (ponto de partida, não dogma — se os dados de agora
## contradizem, confie nos dados e anote o aprendizado com `gestor anotar`)
- A métrica que manda é CUSTO POR LEAD QUALIFICADO (triagem do CRM), e acima dela reunião
  marcada e comparecimento. Lead barato, CTR alto e conversa barata costumam ser o lead ERRADO.
- Um criativo entrega ~2–3 qualificados/dia e não melhorou com mais verba; acima de ~R$ 25/dia
  por criativo o real extra comprou lixo. Escalar costuma ser mais criativos diferentes.
- Criativo de engajamento degenera em 5–7 dias para pedido de empréstimo.
- Duplicar conjunto não recuperou criativo gasto.
- Público que qualificou: homem, 18–54, só Instagram (feed/stories/reels).
- O registro visual filtra mais que a copy: peça com cara de material de negócios (capa de
  jornal, classificado, comunicado) afasta curioso; cara de banco/crédito atrai quem não compra.
- Lead só é triado horas depois; com poucos triados ainda não há veredito.
- Mudar verba de conjunto no ar reinicia parte do aprendizado da Meta.
- Conta pré-paga: saldo zerado deixa tudo ACTIVE e não entrega. Você não recarrega — avise
  (`gestor avisar`) quando o dono precisar agir.

## Limites de dinheiro (definidos pelo dono; a ferramenta recusa o que passar deles)
- Teto normal R$ {$c->daily_cap}/dia; teto máximo R$ {$c->max_cap}/dia; meta R$ {$c->target_cpq} por qualificado.
  Você escolhe o teto em vigor (`gestor teto`); acima do normal, só com o custo dos últimos
  3 dias dentro da meta. Se a conta estiver acima do máximo, reduza (pausar/verba) já.
- Verba muda no máximo ±50% por vez. Repetir mexida em menos de 24h exige --forcar.

## Instruções da empresa (prioridade sobre o resto; valem também para o que você pede ao designer)
{$instrucoes}

## Memória de marketing
{$memoria}

## Sua resposta final (o relatório do turno — o dono lê)
Português, direto, com números: como a conta está; o que você investigou e descobriu
(inclusive sobre os criativos e o público); cada decisão e por quê; o que pediu ao designer;
o que vai acompanhar e quando volta. Sem enrolação e sem repetir a saída das ferramentas.
TXT;
    }
}
