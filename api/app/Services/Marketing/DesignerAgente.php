<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCreative;
use App\Models\MarketingCreativeRequest;
use App\Models\MarketingGestorSession;
use App\Support\HtmlRender;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

/**
 * O designer: recebe o pedido do gestor de tráfego e entrega criativos na biblioteca.
 *
 * Também é agente: olha as imagens de referência (vencedores e perdedores que o gestor
 * apontou), escreve o prompt da peça, gera pelo provedor de imagem do painel, OLHA o
 * resultado e refaz até ficar bom — ou desiste com o motivo. Só o que ele entrega vira
 * criativo; rascunho reprovado por ele morre na pasta de rascunhos.
 *
 * Motor padrão: a peça escrita em HTML com as fontes da marca e fotografada por um navegador
 * ({@see HtmlRender}) — é o registro tipográfico dos vencedores da conta e o
 * texto sai exato. O gerador de imagem do painel fica para o que pede foto ou ilustração.
 *
 * Pedido do DONO (feito no WhatsApp) volta para ele: as peças entregues vão por lá, e ele
 * responde com ajuste ou com "pode subir".
 */
class DesignerAgente
{
    /** Tentativas de geração por peça pedida: imagem custa, e a 4ª tentativa raramente salva a 3ª. */
    public const TENTATIVAS_POR_PECA = 3;

    /** HTML não custa por tentativa, mas cada volta é um turno do agente. */
    public const TENTATIVAS_HTML_POR_PECA = 6;

    /**
     * Atende a fila (pedidos do dono primeiro — ele está esperando no WhatsApp). Um designer
     * por empresa por vez: a conversa e o agendador podem chamar isto ao mesmo tempo.
     *
     * @return list<MarketingGestorSession>
     */
    public static function atenderFila(MarketingAutopilot $cfg, int $max = 1, bool $soDoDono = false): array
    {
        $lock = Cache::lock("designer-turno:{$cfg->company_id}", 3600);
        if (! $lock->get()) {
            return [];
        }
        $feitas = [];
        try {
            for ($i = 0; $i < $max; $i++) {
                $pedido = MarketingCreativeRequest::where('status', MarketingCreativeRequest::PENDENTE)
                    ->when($soDoDono, fn ($q) => $q->where('para_dono', true))
                    ->orderByDesc('para_dono')->orderBy('id')->first();
                if (! $pedido) {
                    break;
                }
                $feitas[] = (new self($cfg))->atender($pedido);
            }
        } finally {
            $lock->release();
        }

        return $feitas;
    }

    public function __construct(private MarketingAutopilot $cfg) {}

    public function atender(MarketingCreativeRequest $pedido): MarketingGestorSession
    {
        $pedido->update(['status' => MarketingCreativeRequest::PRODUZINDO]);

        $s = GestorAgente::turno('designer', $this->cfg, false, $this->sistema(),
            "Pedido #{$pedido->id} do gestor de tráfego. Comece por `php artisan designer pedido`.",
            ['Bash(php artisan designer *)', 'Bash(php artisan designer:*)', GestorAgente::leituraDeImagens(), self::escritaDeHtml()],
            null, ['GESTOR_PEDIDO' => (string) $pedido->id]);

        $pedido->refresh();
        if ($pedido->status === MarketingCreativeRequest::PRODUZINDO) {
            $pedido->update(['status' => MarketingCreativeRequest::FALHOU,
                'resultado' => 'O designer terminou sem entregar: '.($s->resumo ?: $s->erro ?: 'sem resposta')]);
        }
        if ($pedido->para_dono) {
            $this->mostrarAoDono($pedido);
        }

        return $s;
    }

    /** O designer só escreve HTML na própria pasta de trabalho. */
    public static function escritaDeHtml(): string
    {
        Storage::makeDirectory('marketing/html');

        return 'Write(/'.rtrim(Storage::path('marketing/html'), '/').'/**)';
    }

    /**
     * Manda as peças do pedido do dono para o WhatsApp dele. Cada imagem enviada fica
     * registrada com o id da mensagem: quando ele RESPONDE à imagem, o gestor sabe de qual
     * criativo ele está falando.
     */
    private function mostrarAoDono(MarketingCreativeRequest $p): void
    {
        $avisos = new Avisos($this->cfg);
        if ($p->status !== MarketingCreativeRequest::PRONTO) {
            $avisos->enviar('designer-falhou-'.$p->id, "🎨 Não consegui fazer o criativo que você pediu: {$p->resultado}", 0);

            return;
        }

        foreach (MarketingCreative::whereIn('number', $p->creative_ids ?? [])->orderBy('number')->get() as $c) {
            $b = $c->brief ?? [];
            $legenda = "🎨 *Criativo {$c->number}*".($p->ajustar_criativo ? " (ajuste do {$p->ajustar_criativo})" : '')
                .(filled($b['manchete'] ?? null) ? ' — '.$b['manchete'] : '')
                .(filled($b['nota'] ?? null) ? "\n\n".$b['nota'] : '')
                .(filled($b['mensagem'] ?? null) ? "\n\n*Texto do anúncio:*\n".$b['mensagem'] : '')
                ."\n\n_Responda esta imagem com o que mudar — ou *pode subir* para colocar no ar._";
            $waId = $avisos->enviarImagem(Storage::path($c->path), $legenda, "criativo-{$c->number}.png");
            MarketingAutopilotEvent::create(['kind' => 'enviado_dono', 'message' => "Criativo {$c->number} enviado ao dono no WhatsApp",
                'data' => ['wa_id' => $waId, 'criativo' => $c->number, 'pedido' => $p->id, 'enviado' => $waId !== null]]);
        }
    }

    private function sistema(): string
    {
        $instrucoes = trim((string) $this->cfg->instructions) ?: '(nenhuma)';
        $max = self::TENTATIVAS_POR_PECA;
        $maxHtml = self::TENTATIVAS_HTML_POR_PECA;

        return <<<TXT
Você é o DESIGNER sênior de anúncios desta empresa. O gestor de tráfego faz pedidos com
briefing e referências; você entrega peças prontas para o feed do Instagram (4:5, 1080×1350).
Fale português do Brasil.

## Como você trabalha
1. `php artisan designer pedido` — o briefing, o motivo, as referências (com caminho da imagem),
   os exemplos que o DONO mandou pelo WhatsApp, o criativo a AJUSTAR (se for ajuste), a marca
   e os motores disponíveis.
2. OLHE com a ferramenta Read todas as imagens: referências a seguir, as a evitar, os exemplos
   do dono (eles mostram o que ele quer — siga o estilo deles com prioridade). Entenda o que faz
   a vencedora funcionar (registro visual, hierarquia, manchete, cores, quanto texto) — não
   copie, traduza para a peça nova.
3. MONTE A PEÇA EM HTML (motor padrão — os vencedores da conta são peças tipográficas, e em
   HTML o texto sai exato):
   - Escreva com a ferramenta Write um arquivo em `<pasta de HTML do pedido>/{pedido}-<nome>.html`.
   - Página de exatamente 1080×1350 px: `html,body{margin:0;width:1080px;height:1350px;overflow:hidden}`
     e um fundo explícito. CSS inline no <style>. Nada externo (sem internet, sem Google Fonts,
     sem CDN): use só as fontes instaladas que o pedido lista, pelo nome, em font-family.
   - Logo: `<img src="file://<logo_png do pedido>">` (é o ícone; escreva o nome da marca ao
     lado em texto). Imagem de exemplo/criativo também entra por file:// se precisar.
   - Pense como diretor de arte: grade, margens generosas, hierarquia forte, manchete que lê na
     miniatura do feed, contraste. Tamanhos de fonte pensando em celular (corpo ≥ 26px).
   - `php artisan designer render --html=<caminho do arquivo> --manchete="…" --texto="…" --registro=… --gatilho=…`
     fotografa a página em 2160×2700 e devolve o caminho do rascunho.
   - AJUSTE de criativo existente: leia o `html_original` com Read, escreva uma cópia nova
     com SÓ as mudanças pedidas (mantenha o que não foi pedido para mudar) e renderize.
   O gerador de imagem (`php artisan designer gerar --prompt="…" --manchete="…"`) só se estiver
   configurado e a peça pedir foto/ilustração; descreva a peça inteira e todo texto entre aspas.
4. OLHE o rascunho com Read. Reprove você mesmo se: texto com erro de português, cortado,
   sobreposto ou vazando da página; ilegível no celular; espaço vazio sem intenção; parece
   anúncio de banco/crédito/conta pessoal; fugiu do briefing ou do que o dono pediu. Corrija
   e renderize de novo (até {$maxHtml} tentativas por peça no HTML, {$max} no gerador).
5. `php artisan designer entregar <rascunho1,rascunho2> --mensagem="…" --titulo="…" --nota="…"`
   — sobe para a biblioteca. --mensagem é o texto do anúncio sugerido para o gestor (2–5
   linhas, chama para o WhatsApp); --nota explica a peça e o que ela testa.
   Se não der para entregar (gerador falhando, nada ficou bom), `php artisan designer falhar --motivo="…"`.

Rode as ferramentas exatamente como `php artisan designer …`, um comando por vez, sem cd,
&& ou pipes. Se o pedido é do dono, ele vai receber as peças no WhatsApp com a --nota e a
--mensagem: escreva a --nota para ele (1–2 linhas: o que a peça é e por que filtra bem).

## Instruções da empresa (posicionamento, o que pode e o que não pode dizer — obrigatórias)
{$instrucoes}

## Resposta final
Em 2–4 linhas: o que entregou (ou por que não), e o que cada peça testa.
TXT;
    }
}
