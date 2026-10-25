<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutopilot;
use App\Models\MarketingCreative;
use App\Models\MarketingMemory;
use App\Support\Claude;
use RuntimeException;

/**
 * A parte que pensa do piloto automático: decide o que a próxima peça vai dizer e
 * confere se a imagem gerada saiu do jeito pedido.
 *
 * Roda no Claude da ASSINATURA (o mesmo motor do atendimento e do /agente) — nada de
 * token pago por chamada. Lê o desempenho por lead qualificado, a memória de marketing
 * da empresa e as instruções do painel.
 */
class AutopilotBrain
{
    /**
     * @param  list<MarketingCreative>  $estoque  criativos prontos que só precisam de copy
     * @param  list<array<string, mixed>>  $desempenho
     * @return array{copys: array<int, array{mensagem: string, titulo: ?string}>, pecas: list<array<string, mixed>>}
     */
    public function planejar(array $estoque, int $novas, array $desempenho, MarketingAutopilot $cfg): array
    {
        $vazio = ['copys' => [], 'pecas' => []];
        if ($novas <= 0 && ! $estoque) {
            return $vazio;
        }

        $saida = Claude::run($this->promptPlano($estoque, max(0, $novas), $desempenho, $cfg), 300);
        $json = Claude::json($saida);
        if (! is_array($json)) {
            throw new RuntimeException('A IA não devolveu um plano legível'.($saida === null ? ' (IA indisponível — confira o token em Admin → Memória).' : '.'));
        }

        $copys = [];
        foreach ((array) ($json['copys'] ?? []) as $c) {
            if (isset($c['criativo'], $c['mensagem']) && trim((string) $c['mensagem']) !== '') {
                $copys[(int) $c['criativo']] = ['mensagem' => (string) $c['mensagem'], 'titulo' => $c['titulo'] ?? null];
            }
        }

        $pecas = array_values(array_filter((array) ($json['pecas'] ?? []), fn ($p) => is_array($p)
            && filled($p['prompt_imagem'] ?? null) && filled($p['mensagem'] ?? null)));

        return ['copys' => $copys, 'pecas' => array_slice($pecas, 0, max(0, $novas))];
    }

    /** O prompt que vai para o gerador de imagem — o da IA mais as regras fixas de formato. */
    public function promptImagem(array $peca): string
    {
        return trim((string) $peca['prompt_imagem'])."\n\n"
            .'Formato vertical 4:5 (1080x1350), peça de anúncio para feed do Instagram. '
            .'Todo texto da imagem em português do Brasil, escrito EXATAMENTE como está entre aspas, '
            .'com acentuação correta e sem palavras inventadas. Tipografia nítida e legível no celular. '
            .'Sem fotos de pessoas, sem cédulas de dinheiro, sem cartões de crédito, sem celular com app de banco.';
    }

    /**
     * A IA lê a imagem gerada e diz se o texto saiu certo e se a peça não parece anúncio de
     * crédito/conta pessoal (o que atrai exatamente o lead que a conta precisa afastar).
     *
     * @return array{ok: bool, motivo: string}
     */
    public function conferirImagem(string $caminho, array $peca): array
    {
        $esperado = trim(($peca['manchete'] ?? '')."\n".($peca['texto_imagem'] ?? ''));
        $saida = Claude::run(
            "Leia a imagem em {$caminho}. É um anúncio gerado por IA que vai para o Instagram.\n\n"
            ."Texto que DEVERIA estar na imagem:\n\"\"\"\n{$esperado}\n\"\"\"\n\n"
            ."Confira:\n"
            .'1. O texto principal está legível e sem erro de português, letra trocada ou palavra inventada? '
            ."Pequenas diferenças de quebra de linha não importam; palavra errada importa.\n"
            ."2. A peça poderia ser confundida com anúncio de empréstimo, crédito, conta digital ou cartão para pessoa física?\n\n"
            .'Responda SOMENTE com JSON: {"texto_ok": true|false, "parece_credito_pessoal": true|false, "problemas": "<curto, em português>"}',
            120,
        );

        $json = Claude::json($saida);
        if (! is_array($json)) {
            return ['ok' => false, 'motivo' => 'não consegui conferir a imagem (resposta da IA ilegível)'];
        }

        $ok = ($json['texto_ok'] ?? false) === true && ($json['parece_credito_pessoal'] ?? true) === false;

        return ['ok' => $ok, 'motivo' => (string) ($json['problemas'] ?? ($ok ? '' : 'reprovada'))];
    }

    /**
     * @param  list<MarketingCreative>  $estoque
     * @param  list<array<string, mixed>>  $desempenho
     */
    private function promptPlano(array $estoque, int $novas, array $desempenho, MarketingAutopilot $cfg): string
    {
        $memoria = MarketingMemory::contexto() ?: '(sem memória registrada)';
        $instrucoes = trim((string) $cfg->instructions) ?: '(nenhuma)';
        $tabela = json_encode($desempenho, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $listaEstoque = $estoque
            ? collect($estoque)->map(fn (MarketingCreative $c) => "- criativo {$c->number}: arquivo \"{$c->original_name}\"".($c->notes ? " — {$c->notes}" : ''))->implode("\n")
            : '(nenhum)';

        return <<<PROMPT
Você é o gestor de tráfego automático desta empresa. Seu trabalho agora é planejar anúncios de
clique-para-WhatsApp no Instagram. Responda SOMENTE com JSON, sem texto antes ou depois.

## Como esta conta funciona (medido, não opinião)
- A métrica que manda é CUSTO POR LEAD QUALIFICADO (triagem do CRM). Conversa barata, CTR alto e
  custo por lead baixo costumam trazer o lead ERRADO (quem quer empréstimo/conta pessoal).
- O registro visual filtra mais que a copy: peça que parece material de negócios (capa de jornal,
  classificado, comunicado, demonstrativo) afasta curioso antes do clique. Peça que parece
  propaganda de banco/conta/crédito atrai exatamente quem não compra.
- Cada criativo dura 5–7 dias. Variedade real (registro × gatilho diferentes) vale mais que
  repetir o vencedor com outra cor.
- Status VALIDADO = deu resultado, faça variações dele. REPROVADO = não repita o ângulo.

## Instruções da empresa (têm prioridade sobre tudo abaixo)
{$instrucoes}

## Memória de marketing da empresa
{$memoria}

## Desempenho dos criativos (últimos 30 dias)
{$tabela}

## Tarefa
1. Escreva a copy do anúncio para cada criativo de ESTOQUE abaixo (a imagem já existe; deduza o
   conteúdo pelo nome do arquivo e pelas notas):
{$listaEstoque}

2. Crie {$novas} peça(s) NOVA(S), cada uma com combinação diferente de registro visual × gatilho,
   puxando para o que qualifica na tabela. O prompt_imagem descreve a peça inteira para um gerador
   de imagem: layout, cores, tipografia e TODO o texto que aparece, entre aspas. Pouco texto na
   imagem (manchete + 1 a 3 linhas de apoio): texto longo sai com erro no gerador.

A "mensagem" é o texto principal do anúncio (acima da imagem), 2 a 5 linhas, termina chamando
para o WhatsApp. "titulo" é a headline curta (até 40 caracteres).

Formato da resposta:
{
  "copys": [{"criativo": 12, "mensagem": "...", "titulo": "..."}],
  "pecas": [{
    "registro": "jornal|classificado|comunicado|demonstrativo|dossie|quadro|outro",
    "gatilho": "volume|construir|marca|atende|caminho|preco|outro",
    "manchete": "texto exato da manchete na imagem",
    "texto_imagem": "demais textos exatos da imagem",
    "prompt_imagem": "descrição completa da peça para o gerador",
    "mensagem": "...",
    "titulo": "...",
    "por_que": "uma frase: o que esta peça testa e por que deve qualificar"
  }]
}
PROMPT;
    }
}
