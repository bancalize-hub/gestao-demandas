<script setup lang="ts">
// Onde estão os leads que QUALIFICAM, por recorte.
//
// A tela toda é construída para não repetir o erro que já custou decisão nesta conta:
// olhar uma tabela ordenada por taxa e tratar o topo como descoberta. Toda linha carrega
// tamanho de amostra e intervalo, e o veredito do recorte vem ANTES da tabela — se ele
// diz "ainda é ruído", a ordem das linhas não significa nada.
const api = useApi()

interface Linha {
  valor: string; leads: number; triados: number; qualificados: number
  sem_triagem: number; taxa: number | null; ic: [number, number]; manski: [number, number]
  reunioes: number; realizadas: number; vendas: number
}
interface Corte {
  titulo: string; nota: string; sem_chave: number; linhas: Linha[]
  conclusivo: boolean; explicacao: string; faltam: number | null
}
interface LinhaFb {
  valor: string; gasto: number; impressoes: number; cliques: number
  ctr: number; cpm: number; conversas: number; custo_conversa: number | null
}
interface Resposta {
  base: {
    leads: number; de_anuncio: number; triados: number; sem_triagem: number
    qualificados: number; reunioes: number; realizadas: number; vendas: number; com_ddd: number
  }
  cortes: Corte[]
  facebook: { chave: string; titulo: string; linhas: LinhaFb[] }[]
  erro_facebook: string | null
  facebook_de: string | null
  periodo: { de: string; ate: string }
}

const dados = ref<Resposta | null>(null)
const carregando = ref(true)
const erro = ref('')
const periodo = ref('last_30d')

const periodos = [
  { label: '7 dias', value: 'last_7d' },
  { label: '30 dias', value: 'last_30d' },
  { label: 'Este mês', value: 'this_month' },
]

async function carregar() {
  carregando.value = true
  erro.value = ''
  try { dados.value = await api<Resposta>(`/api/marketing/otimizacao?periodo=${periodo.value}`) }
  catch (e: any) { erro.value = e?.response?._data?.message || e?.message || 'Não consegui carregar.' }
  finally { carregando.value = false }
}
onMounted(carregar)
watch(periodo, carregar)

const pct = (v: number | null) => v === null ? '—' : `${Math.round(v * 100)}%`
const dinheiro = (v: number | null) => v === null ? '—' : v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })

/** A fatia da base sem triagem — é ela que decide se esta página tem o que dizer. */
const semTriagem = computed(() => {
  const b = dados.value?.base
  return b && b.leads > 0 ? b.sem_triagem / b.leads : 0
})

const cartao = 'background:var(--c-bg-deepest);border:1px solid var(--c-surface-1);border-radius:12px;padding:14px 16px;'
const th = 'text-align:left;font-size:10.5px;font-weight:800;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.03em;padding:6px 10px;white-space:nowrap;'
const td = 'font-size:12px;padding:7px 10px;border-top:1px solid var(--c-surface-1);white-space:nowrap;'
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <!-- Sem quebra de linha, o cabeçalho tinha ~400px de largura mínima (ícone + seletor
         de período + "← Gerenciador", nenhum deles encolhível) e em 360px estourava para
         fora: como a raiz tem overflow-y:auto, o estouro virava rolagem horizontal da tela
         inteira. O r-pad ainda alinha o respiro lateral com o corpo abaixo. -->
    <div class="r-wrap r-pad" style="padding-block:16px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px;">🎯</div>
      <div style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Otimização</div>
        <div style="font-size:12px;color:var(--c-text-muted);">De onde vêm os leads que qualificam</div>
      </div>
      <!-- o espaçador vira a quebra de linha no celular: o período e o link caem juntos
           na fileira de baixo, em vez de cada um ocupar uma fileira própria -->
      <div class="r-break-line" style="flex:1;" />
      <div style="display:flex;align-items:center;gap:4px;background:var(--c-bg-deepest);border:1px solid var(--c-surface-1);border-radius:10px;padding:4px;">
        <button
          v-for="p in periodos" :key="p.value" class="r-tap"
          :style="{ background: periodo === p.value ? 'var(--c-surface-2)' : 'transparent', border: 'none', color: periodo === p.value ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 10px', borderRadius: '7px', cursor: 'pointer' }"
          @click="periodo = p.value"
        >{{ p.label }}</button>
      </div>
      <NuxtLink to="/marketing" class="r-tap" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">← Gerenciador</NuxtLink>
    </div>

    <!--
      A coluna tinha teto de 1100px mas nenhuma centralização: em 1600px+ as tabelas e os
      KPIs ficavam colados na borda esquerda enquanto o cabeçalho ia até o fim da tela. Em
      360px os 48px de padding lateral saíam da largura de tabelas que já estão apertadas.
    -->
    <div class="r-page r-pad" style="--r-page:1100px;padding-block:20px;">
      <div v-if="carregando" style="font-size:12.5px;color:var(--c-text-faint);">Carregando…</div>
      <div v-else-if="erro" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-danger-soft);">{{ erro }}</div>

      <template v-else-if="dados">
        <!-- A base primeiro: sem saber de quantos leads se está falando, nenhuma
             porcentagem abaixo quer dizer coisa alguma. -->
        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(120px,100%),1fr));gap:10px;margin-bottom:16px;">
          <div v-for="m in [
            { r: 'Leads', v: dados.base.leads, s: `${dados.base.de_anuncio} de anúncio` },
            { r: 'Triados', v: dados.base.triados, s: `${dados.base.sem_triagem} sem triagem` },
            { r: 'Qualificados', v: dados.base.qualificados, s: '' },
            { r: 'Reuniões', v: dados.base.reunioes, s: `${dados.base.realizadas} realizadas` },
            { r: 'Vendas', v: dados.base.vendas, s: '' },
          ]" :key="m.r" :style="cartao">
            <div style="font-size:10.5px;color:var(--c-text-faint);font-weight:800;text-transform:uppercase;letter-spacing:.03em;">{{ m.r }}</div>
            <div style="font-size:22px;font-weight:800;line-height:1.3;">{{ m.v }}</div>
            <div v-if="m.s" style="font-size:10.5px;color:var(--c-text-faint);">{{ m.s }}</div>
          </div>
        </div>

        <!-- O gargalo real. Enquanto ele existir, é a resposta mais útil da página. -->
        <div v-if="semTriagem > 0.2" style="background:var(--c-warn-bg);border:1px solid rgba(var(--c-warn-rgb),.4);border-radius:12px;padding:13px 15px;font-size:12.5px;color:var(--c-warn-hi);margin-bottom:18px;line-height:1.6;">
          <strong>{{ pct(semTriagem) }} dos leads não foram triados</strong> ({{ dados.base.sem_triagem }} de {{ dados.base.leads }}).
          É isto, e não falta de verba ou de tempo de campanha, que trava as conclusões abaixo: a taxa de cada linha é a de quem
          <em>foi</em> triado, e o resto pode estar em qualquer lugar entre o melhor e o pior caso (a faixa "no pior/melhor caso" de cada linha).
          Triar os leads que já entraram é o que mais rápido faz esta página começar a responder.
        </div>

        <div style="font-size:12px;color:var(--c-text-muted);margin-bottom:18px;line-height:1.6;">
          Cada recorte diz primeiro <strong>se dá para concluir alguma coisa</strong> e só depois mostra a tabela.
          Quando o veredito é "ainda é ruído", a ordem das linhas é ordem de sorteio — não mova verba por causa dela.
        </div>

        <!-- Recortes do CRM: os únicos que conhecem qualificação de verdade. -->
        <div v-for="c in dados.cortes" :key="c.titulo" style="margin-bottom:22px;">
          <div :style="cartao + 'padding:0;overflow:hidden;'">
            <div style="padding:13px 16px;border-bottom:1px solid var(--c-surface-1);">
              <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;">
                <strong style="font-size:13.5px;">{{ c.titulo }}</strong>
                <span :style="{ fontSize: '10.5px', fontWeight: 800, padding: '3px 8px', borderRadius: '6px', background: c.conclusivo ? 'rgba(47,122,102,.15)' : 'var(--c-surface-1)', color: c.conclusivo ? 'var(--c-teal)' : 'var(--c-text-faint)' }">
                  {{ c.conclusivo ? '✓ DIFERENÇA REAL' : '◌ AINDA É RUÍDO' }}
                </span>
              </div>
              <div style="font-size:11.5px;color:var(--c-text-secondary);margin-top:6px;line-height:1.55;">{{ c.explicacao }}</div>
              <div style="font-size:11px;color:var(--c-text-faint);margin-top:5px;line-height:1.5;">{{ c.nota }}</div>
            </div>

            <!--
              São 9 colunas com nowrap (~900px): no celular a tabela rola, e sem prender a
              coluna do rótulo o usuário ficava lendo "Intervalo (95%)" sem saber de qual
              recorte. O r-table-wrap acrescenta o overscroll-contain que faltava (o arraste
              lateral encadeava no gesto de voltar do iOS) e o r-scroll-hint avisa, com
              sombra na borda, que ainda há coluna fora da tela. O border-collapse vira
              separate porque o Safari ignora position:sticky em tabela colapsada.
            -->
            <div class="r-table-wrap r-scroll-hint" style="--r-hint-bg:var(--c-bg-deepest);">
              <table class="r-table r-table-sticky-1" style="width:100%;border-collapse:separate;border-spacing:0;--r-sticky-bg:var(--c-bg-deepest);">
                <thead>
                  <tr>
                    <th :style="th">&nbsp;</th>
                    <th :style="th">Leads</th>
                    <th :style="th" title="Mandou 2+ mensagens — a primeira é automática do clique no anúncio">Respondeu</th>
                    <th :style="th" title="Sobre os leads TRIADOS, não sobre o total">Qualificou</th>
                    <th :style="th">Intervalo (95%)</th>
                    <th :style="th" title="Onde a taxa pode estar conforme o que os leads sem triagem viessem a ser">Pior / melhor caso</th>
                    <th :style="th" title="Leads que marcaram reunião, sobre os qualificados">Marcou reunião</th>
                    <th :style="th" title="Leads que compareceram, sobre os que marcaram">Compareceu</th>
                    <th :style="th" title="Vendas sobre quem compareceu">Fechou</th>
                  </tr>
                </thead>
                <tbody>
                  <tr v-for="l in c.linhas" :key="l.valor" :style="l.triados < 12 ? 'opacity:.55;' : ''">
                    <td :style="td + 'font-weight:700;'">
                      {{ l.valor }}
                      <span v-if="l.triados < 12" title="Amostra pequena demais para entrar em comparação" style="font-size:10px;color:var(--c-text-faint);"> · amostra curta</span>
                    </td>
                    <td :style="td">{{ l.leads }}</td>
                    <td :style="td">{{ l.responderam }} <span style="color:var(--c-text-faint);">{{ pct(l.funil.respondeu) }}</span></td>
                    <td :style="td + 'font-weight:800;'">
                      {{ l.qualificados }}<span style="font-weight:400;color:var(--c-text-faint);">/{{ l.triados }}</span>
                      <span style="color:var(--c-text-secondary);">{{ pct(l.funil.qualificou) }}</span>
                    </td>
                    <td :style="td + 'color:var(--c-text-secondary);'">{{ pct(l.ic[0]) }} – {{ pct(l.ic[1]) }}</td>
                    <td :style="td + 'color:var(--c-text-faint);'">{{ pct(l.manski[0]) }} – {{ pct(l.manski[1]) }}</td>
                    <td :style="td">{{ l.marcaram }} <span style="color:var(--c-text-faint);">{{ pct(l.funil.marcou) }}</span></td>
                    <td :style="td">{{ l.compareceram }} <span style="color:var(--c-text-faint);">{{ pct(l.funil.compareceu) }}</span></td>
                    <td :style="td">{{ l.vendas }} <span style="color:var(--c-text-faint);">{{ pct(l.funil.fechou) }}</span></td>
                  </tr>
                </tbody>
              </table>
            </div>

            <div v-if="c.sem_chave" style="padding:8px 16px;font-size:10.5px;color:var(--c-text-faint);border-top:1px solid var(--c-surface-1);">
              {{ c.sem_chave }} lead(s) fora deste recorte (sem telefone brasileiro ou sem data) — ficam de fora da tabela em vez de virar uma linha "outros" que não quer dizer nada.
            </div>
          </div>
        </div>

        <!-- Bloco do Facebook, separado de propósito. -->
        <div style="border-top:1px solid var(--c-surface-1);padding-top:18px;margin-top:26px;">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:5px;">Recortes do Facebook — o funil PARA aqui</div>
          <div style="font-size:12px;color:var(--c-text-muted);line-height:1.6;margin-bottom:14px;">
            Idade, gênero e localização só existem do lado da Meta, e o WhatsApp não entrega nenhum dos três junto do lead.
            Por isso <strong>não dá para dizer quem qualifica, quem marca reunião ou quem fecha por faixa etária ou por gênero</strong>:
            não existe chave ligando a pessoa que conversou no WhatsApp à célula demográfica que a Meta contou — o dado dela é agregado por anúncio, não por pessoa.
            O último passo do funil que estes recortes alcançam é <strong>conversa iniciada</strong>.
            <br><br>
            O funil completo — respondeu, qualificou, marcou, compareceu, fechou — está nas tabelas acima, que são as que cruzam com o CRM.
            E cuidado ao ler custo por conversa como qualidade de público: nesta conta já se mediu que lead barato e lead bom andam em direções <strong>opostas</strong>.
          </div>

          <div v-if="dados.erro_facebook" style="background:var(--c-warn-bg);border:1px solid rgba(var(--c-warn-rgb),.4);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-warn-hi);margin-bottom:14px;">
            {{ dados.erro_facebook }}<template v-if="dados.facebook_de"> Mostrando o último resultado bom.</template>
          </div>

          <div v-for="f in dados.facebook" :key="f.chave" style="margin-bottom:18px;">
            <div :style="cartao + 'padding:0;overflow:hidden;'">
              <!-- r-wrap: sem quebra, o selo de 38 caracteres era espremido ao lado do
                   título e quebrava em três linhas dentro da própria pílula, virando um
                   bloco de texto com fundo em vez de um selo -->
              <div class="r-wrap" style="padding:11px 16px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:9px;">
                <strong style="font-size:13px;">{{ f.titulo }}</strong>
                <span style="font-size:10px;font-weight:800;padding:3px 8px;border-radius:6px;background:var(--c-surface-1);color:var(--c-text-faint);">CONVERSA INICIADA — NÃO É QUALIFICAÇÃO</span>
              </div>
              <!-- mesmo caso da tabela do CRM: 7 colunas nowrap, e sem a primeira presa
                   Gasto/CPM/Custo-por-conversa ficam sem dizer de que recorte são -->
              <div v-if="!f.linhas.length" style="padding:12px 16px;font-size:12px;color:var(--c-text-faint);">Sem dados no período.</div>
              <div v-else class="r-table-wrap r-scroll-hint" style="--r-hint-bg:var(--c-bg-deepest);">
                <table class="r-table r-table-sticky-1" style="width:100%;border-collapse:separate;border-spacing:0;--r-sticky-bg:var(--c-bg-deepest);">
                  <thead>
                    <tr>
                      <th :style="th">&nbsp;</th>
                      <th :style="th">Gasto</th>
                      <th :style="th">Impressões</th>
                      <th :style="th">CTR</th>
                      <th :style="th">CPM</th>
                      <th :style="th">Conversas</th>
                      <th :style="th">Custo/conversa</th>
                    </tr>
                  </thead>
                  <tbody>
                    <tr v-for="l in f.linhas.slice(0, 12)" :key="l.valor">
                      <td :style="td + 'font-weight:700;'">{{ l.valor }}</td>
                      <td :style="td">{{ dinheiro(l.gasto) }}</td>
                      <td :style="td">{{ l.impressoes.toLocaleString('pt-BR') }}</td>
                      <td :style="td">{{ l.ctr }}%</td>
                      <td :style="td">{{ dinheiro(l.cpm) }}</td>
                      <td :style="td">{{ l.conversas }}</td>
                      <td :style="td">{{ dinheiro(l.custo_conversa) }}</td>
                    </tr>
                  </tbody>
                </table>
              </div>
              <div v-if="f.linhas.length > 12" style="padding:8px 16px;font-size:10.5px;color:var(--c-text-faint);border-top:1px solid var(--c-surface-1);">
                Mostrando as 12 linhas de maior gasto, de {{ f.linhas.length }}.
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
/*
  A coluna presa precisa de uma marca de onde o conteúdo passa por baixo — sem ela, com a
  tabela rolada, o número da coluna vizinha encosta no rótulo e parece pertencer a ele.
  É box-shadow, e não border-right, para não entrar na conta de largura das colunas.
*/
.r-table-sticky-1 th:first-child,
.r-table-sticky-1 td:first-child {
  box-shadow: 1px 0 0 0 var(--c-surface-1);
}
</style>
