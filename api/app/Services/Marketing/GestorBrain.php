<?php

namespace App\Services\Marketing;

use App\Models\MarketingAutopilot;
use App\Models\MarketingMemory;
use App\Support\Claude;
use RuntimeException;

/**
 * A cabeça do gestor de tráfego: lê a fotografia da conta e devolve decisões.
 *
 * Só decide — quem executa é o {@see Gestor}, que confere cada decisão contra as travas
 * (teto, trava de 24h, passo máximo de verba) antes de tocar na Meta. Por isso o prompt
 * pode ser ambicioso: a IA propõe, o código é que não deixa passar do combinado.
 */
class GestorBrain
{
    /**
     * @param  array<string, mixed>  $foto
     * @param  list<string>  $historico
     * @return array{analise: string, teto: ?float, acoes: list<array<string, mixed>>, memorias: list<array<string, mixed>>, alerta: ?string, diagnosticos: list<array<string, mixed>>}
     */
    public function decidir(array $foto, MarketingAutopilot $cfg, array $historico): array
    {
        $saida = Claude::run($this->prompt($foto, $cfg, $historico), 480);
        $json = Claude::json($saida);
        if (! is_array($json)) {
            throw new RuntimeException('A IA não devolveu decisões legíveis'.($saida === null ? ' (IA indisponível — confira o token em Admin → Memória).' : '.'));
        }

        return [
            'analise' => trim((string) ($json['analise'] ?? '')),
            'teto' => is_numeric($json['teto'] ?? null) ? (float) $json['teto'] : null,
            'acoes' => array_values(array_filter((array) ($json['acoes'] ?? []), 'is_array')),
            'memorias' => array_values(array_filter((array) ($json['memorias'] ?? []), 'is_array')),
            'alerta' => filled($json['alerta'] ?? null) ? (string) $json['alerta'] : null,
            'diagnosticos' => array_values(array_filter((array) ($json['diagnosticos'] ?? []), 'is_array')),
        ];
    }

    /**
     * @param  array<string, mixed>  $foto
     * @param  list<string>  $historico
     */
    private function prompt(array $foto, MarketingAutopilot $cfg, array $historico): string
    {
        $dados = json_encode($foto, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        $memoria = MarketingMemory::contexto() ?: '(sem memória registrada)';
        $instrucoes = trim((string) $cfg->instructions) ?: '(nenhuma)';
        $passado = $historico ? '- '.implode("\n- ", $historico) : '(nada nas últimas 72h)';
        $agora = now()->format('d/m/Y H:i (l)');
        $alcance = $cfg->manage_whole_account
            ? 'Você gerencia a CONTA INTEIRA, inclusive campanhas criadas à mão.'
            : 'Você só mexe nos anúncios com "do_piloto": true. Os outros aparecem só para você saber quanto do teto já está tomado.';

        return <<<PROMPT
Você é o gestor de tráfego desta empresa, trabalhando sozinho, revisando a conta de hora em
hora. Agora: {$agora}. {$alcance} Responda SOMENTE com JSON, sem texto antes ou depois.

## O que já foi medido nesta conta (não é opinião — respeite)
- A métrica que manda é CUSTO POR LEAD QUALIFICADO (cpq, triagem do CRM). Lead barato e CTR
  alto costumam ser o lead ERRADO (quem quer empréstimo/conta pessoal). Nunca escale por CTR,
  custo por conversa ou volume de leads.
- Cada criativo entrega ~2–3 qualificados/dia e NÃO responde a orçamento: acima de ~R$ 25/dia
  por criativo o real extra compra lixo. Escalar = MAIS criativos diferentes no ar (subir o teto
  abre vaga e o piloto preenche), não o mesmo criativo com mais verba. Subir verba de um
  vencedor é teste, e com cautela.
- Todo criativo de engajamento degenera em 5–7 dias para pedido de empréstimo. Criativo velho
  com cpq subindo está morrendo: pause antes de queimar.
- Duplicar um conjunto não recupera criativo gasto (testado: deu 17% de qualificação).
- Público que qualifica: homem, 18–54, só Instagram (feed/stories/reels). Facebook e mulheres
  qualificaram pior. Testar outro público é permitido, sempre pequeno e com hipótese clara.
- Lead só é triado horas depois da conversa: anúncio com leads e poucos triados ainda não tem
  veredito. Com menos de ~8 triados, não reprove pela taxa — só por gasto alto sem nenhum
  qualificado.
- Toda edição de verba em conjunto no ar reinicia parte do aprendizado da Meta: mexa pouco.
  Ficar parado quando está tudo dentro da meta é a decisão certa; responder "acoes": [] é normal.
- Conta pré-paga: com saldo zerado tudo fica ACTIVE e não entrega. Você não recarrega — avise.

## Travas que o sistema aplica (pedido fora delas é recusado e registrado)
- Teto normal R$ {$cfg->daily_cap}/dia, teto máximo DURO R$ {$cfg->max_cap}/dia, meta de cpq R$ {$cfg->target_cpq}.
  Você escolhe o teto em vigor ("teto"). Acima do normal, só se o cpq da conta nos últimos 3 dias
  estiver dentro da meta com pelo menos 3 qualificados. Baixar é sempre permitido.
- Verba: no máximo +50%/−50% por mexida, mínimo R$ 6, nunca passando do teto em vigor.
- Religar ou mudar verba de algo que já foi mexido nas últimas 24h é recusado.
- No máximo 10 ações por revisão. Teste de público: 1 por dia, com a verba padrão por criativo.
- Os anúncios "do_piloto" já têm regra fixa (pausa por qualificação, gasto sem qualificado e
  vida útil de {$cfg->lifetime_days} dias) e o piloto cria criativos novos sozinho quando há folga no
  teto. Você não precisa repetir isso — foque no que a regra fixa não vê.

## Como analisar (faça TODOS os passos antes de decidir)
1. Conta: saldo, comprometido × teto, cpq 3d × 7d × meta. A conta está melhorando ou piorando?
   Para onde está indo o dinheiro que não vira qualificado?
2. Cada anúncio que gastou na semana: leia o "dia_a_dia". Qualificação caindo com gasto estável =
   criativo morrendo. Dias sem lead com gasto = entrega ruim. Compare 3d com 7d e 30d.
   Leia "motivos_reprovacao_7d": se os reprovados pedem empréstimo/conta pessoal, o criativo atrai
   o público errado (pausar); se é "sem CNPJ"/"curioso" esparso, é ruído normal.
   Reunião marcada e comparecimento (crm.reunioes / compareceram) valem mais que qualificado.
3. Orçamentos: cada real comprometido está no melhor lugar? Dinheiro parado em conjunto que não
   qualifica deveria estar livre para criativo novo (o piloto preenche a folga sozinho).
4. Recortes (idade, gênero, posicionamento): onde o qualificado pela CAPI custa menos? Há um
   recorte claramente melhor ou pior que justifique um teste de público ou um corte?
5. Criativos (histórico 30d): quais registros × gatilhos qualificam? Existe criativo VALIDADO
   pausado que vale religar (descansado, cpq bom no passado)?
6. Só então decida. Cada ação com motivo que cite os números. Se faltar dado para decidir algo,
   diga o que falta em vez de chutar.

## Instruções da empresa (têm prioridade sobre o resto)
{$instrucoes}

## Memória de marketing
{$memoria}

## Suas decisões e as do piloto nas últimas 72h
{$passado}

## Fotografia da conta agora
Gasto "3d"/"7d" = dias fechados, sem hoje. "orcamentos" = onde a verba diária mora (campanha
CBO ou conjunto ABO); "comprometido" = soma dos orçamentos com anúncio entregando.
{$dados}

## Resposta
{
  "analise": "5 a 10 linhas, português, para o dono ler: diagnóstico da conta com números, o que você fez e por quê, e o que vai observar nas próximas horas",
  "diagnosticos": [{"anuncio": "<ad id>", "nome": "...", "veredito": "escalar|manter|observar|pausar|religar", "porque": "uma frase com os números"}],
  "teto": 120,
  "acoes": [
    {"acao": "pausar", "anuncio": "<ad id>", "motivo": "..."},
    {"acao": "religar", "anuncio": "<ad id>", "motivo": "..."},
    {"acao": "verba", "orcamento": "<id do orçamento>", "valor": 30, "motivo": "..."},
    {"acao": "testar_publico", "criativo": 97, "publico": {"age_min": 25, "age_max": 44,
      "genders": [1], "publisher_platforms": ["instagram"], "instagram_positions": ["reels"]},
      "mensagem": "texto do anúncio", "titulo": "headline curta", "motivo": "hipótese testada"}
  ],
  "memorias": [{"categoria": "criativo|publico|orcamento|metrica|aprendizado", "titulo": "...",
    "conteudo": "o que você aprendeu, com os números", "confianca": "medido|hipotese"}],
  "alerta": null
}
"teto": null mantém o atual. "memorias" só para aprendizado novo e relevante (raramente).
"alerta": texto curto só se o dono PRECISA agir (algo que você não pode resolver); senão null.
Públicos aceitos em testar_publico: age_min, age_max, genders ([1]=homem, [2]=mulher),
publisher_platforms (instagram, facebook), instagram_positions, facebook_positions.
PROMPT;
    }
}
