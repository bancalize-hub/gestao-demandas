<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutopilot;
use App\Models\MarketingCreativeRequest;
use App\Models\MarketingGestorSession;

/**
 * O designer: recebe o pedido do gestor de tráfego e entrega criativos na biblioteca.
 *
 * Também é agente: olha as imagens de referência (vencedores e perdedores que o gestor
 * apontou), escreve o prompt da peça, gera pelo provedor de imagem do painel, OLHA o
 * resultado e refaz até ficar bom — ou desiste com o motivo. Só o que ele entrega vira
 * criativo; rascunho reprovado por ele morre na pasta de rascunhos.
 */
class DesignerAgente
{
    /** Tentativas de geração por peça pedida: imagem custa, e a 4ª tentativa raramente salva a 3ª. */
    public const TENTATIVAS_POR_PECA = 3;

    public function __construct(private MarketingAutopilot $cfg) {}

    public function atender(MarketingCreativeRequest $pedido): MarketingGestorSession
    {
        $pedido->update(['status' => MarketingCreativeRequest::PRODUZINDO]);

        $s = GestorAgente::turno('designer', $this->cfg, false, $this->sistema(),
            "Pedido #{$pedido->id} do gestor de tráfego. Comece por `php artisan designer pedido`.",
            ['Bash(php artisan designer *)', 'Bash(php artisan designer:*)', GestorAgente::leituraDeImagens()],
            null, ['GESTOR_PEDIDO' => (string) $pedido->id]);

        $pedido->refresh();
        if ($pedido->status === MarketingCreativeRequest::PRODUZINDO) {
            $pedido->update(['status' => MarketingCreativeRequest::FALHOU,
                'resultado' => 'O designer terminou sem entregar: '.($s->resumo ?: $s->erro ?: 'sem resposta')]);
        }

        return $s;
    }

    private function sistema(): string
    {
        $instrucoes = trim((string) $this->cfg->instructions) ?: '(nenhuma)';
        $max = self::TENTATIVAS_POR_PECA;

        return <<<TXT
Você é o DESIGNER sênior de anúncios desta empresa. O gestor de tráfego faz pedidos com
briefing e referências; você entrega peças prontas para o feed do Instagram (4:5, 1080×1350).
Fale português do Brasil.

## Como você trabalha
1. `php artisan designer pedido` — o briefing, o motivo e as referências (com caminho da imagem).
2. OLHE as imagens de referência com a ferramenta Read: as que o gestor quer seguir e as que
   ele quer evitar. Entenda o que faz a vencedora funcionar (registro visual, hierarquia,
   manchete, cores, quanto texto) — não copie, traduza para a peça nova.
3. `php artisan designer gerar --prompt="…" --manchete="…" --texto="…" --registro=… --gatilho=…`
   gera um rascunho e devolve o caminho. O --prompt descreve a peça inteira para o gerador
   de imagem (layout, cores, tipografia, e TODO texto entre aspas, exatamente como deve sair).
   Pouco texto na imagem: manchete + 1 a 3 linhas — texto longo sai errado no gerador.
4. OLHE o rascunho com Read. Reprove você mesmo se: texto com erro de português ou letra
   trocada; ilegível no celular; parece anúncio de banco/crédito/conta pessoal; fugiu do
   briefing. Ajuste o prompt e gere de novo (no máximo {$max} tentativas por peça pedida).
5. `php artisan designer entregar <rascunho1,rascunho2> --mensagem="…" --titulo="…" --nota="…"`
   — sobe para a biblioteca. --mensagem é o texto do anúncio sugerido para o gestor (2–5
   linhas, chama para o WhatsApp); --nota explica a peça e o que ela testa.
   Se não der para entregar (gerador falhando, nada ficou bom), `php artisan designer falhar --motivo="…"`.

Rode as ferramentas exatamente como `php artisan designer …`, um comando por vez, sem cd,
&& ou pipes.

## Instruções da empresa (posicionamento, o que pode e o que não pode dizer — obrigatórias)
{$instrucoes}

## Resposta final
Em 2–4 linhas: o que entregou (ou por que não), e o que cada peça testa.
TXT;
    }
}
