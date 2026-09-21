<script setup lang="ts">
// Gerenciador de anúncios do Facebook Ads. UMA coisa só: a tabela de campanhas.
// A biblioteca de criativos virou /marketing/criativos e a credencial virou
// /admin/meta-ads (mini menu do avatar) — esta tela não tem mais abas.
const api = useApi()

interface Campanha {
  id: string; name: string; objective: string | null
  status: string; effective_status: string
  daily_budget: string | null; lifetime_budget: string | null; created_time: string
  /** Arte do criativo (thumbnail_url da Graph API). Null quando o anúncio não tem imagem. */
  miniatura: string | null
  /**
   * Orçamento diário EFETIVO em centavos, venha da campanha (CBO) ou da soma dos
   * conjuntos (ABO). `daily_budget` sozinho não serve: nesta conta ele é sempre nulo.
   */
  orcamento_diario: number | null
  orcamento_nivel: 'campanha' | 'conjunto' | null
  orcamento_conjuntos: number
}
interface Metrica {
  campaign_id?: string; impressions?: string; clicks?: string
  ctr?: string; spend?: string; cpc?: string; cpm?: string
  actions?: { action_type: string; value: string }[]
}
interface AdStat {
  criativo: string; leads: number; responderam: number; qualificados: number; reunioes: number; realizadas: number; vendas: number
}
interface AdStatCamp {
  campaign_id: string; leads: number; responderam: number; qualificados: number; reunioes: number; realizadas: number; vendas: number
}
type CrmCampo = 'leads' | 'responderam' | 'qualificados' | 'reunioes' | 'realizadas' | 'vendas'

// ---------------------------------------------------------------- período ---
const periodos = [
  { label: 'Hoje', value: 'today' },
  // 'yesterday' é date_preset da Graph API e tem par no janela() do MarketingController.
  // Os dois lados precisam conhecer a chave: o gasto vem do Facebook pelo preset, os
  // leads vêm do banco pela janela, e um período que só um dos dois entende divide o
  // gasto de um intervalo pelos leads de outro.
  { label: 'Ontem', value: 'yesterday' },
  { label: '7 dias', value: 'last_7d' },
  { label: '30 dias', value: 'last_30d' },
  { label: 'Este mês', value: 'this_month' },
]
const periodo = ref('last_7d')
/** Intervalo do calendário. As duas pontas entram — o dia final inteiro conta. */
const dataDe = ref('')
const dataAte = ref('')
const custom = computed(() => periodo.value === 'custom')
const intervaloPronto = computed(() => !!(dataDe.value && dataAte.value))

/** Query de período: intervalo escolhido no calendário ou preset do Facebook. */
function qsPeriodo(): string {
  return (custom.value && intervaloPronto.value)
    ? `de=${dataDe.value}&ate=${dataAte.value}`
    : `periodo=${periodo.value}`
}

const campanhas = ref<Campanha[]>([])

/**
 * Mostrar só o que está no ar.
 *
 * Campanha pausada acumula: a conta chegou a 14 com 3 rodando, e as 11 mortas empurravam
 * as vivas para fora da tela justamente na hora de decidir verba. Fica DESLIGADO por
 * padrão: o painel abre mostrando a conta inteira, e esconder é escolha de quem olha.
 */
const soAtivas = ref(false)
const campanhasVisiveis = computed(() => soAtivas.value ? campanhas.value.filter(isAtiva) : campanhas.value)
const metricasMap = ref<Record<string, Metrica>>({})
const adStatsMap = ref<Record<string, AdStat>>({})
const adStatsCampMap = ref<Record<string, AdStatCamp>>({})
const semAtribuicao = ref<AdStatCamp | null>(null)
const loadingPainel = ref(false)
const erroPainel = ref('')
const erroStats = ref('')
const editandoOrc = ref<Record<string, string>>({})
const salvandoOrc = ref<Record<string, boolean>>({})
const salvandoSts = ref<Record<string, boolean>>({})
const duplicando = ref<Record<string, boolean>>({})

/** "há 12 min" — idade do dado servido do cache quando o Facebook falhou. */
function desde(iso?: string | null): string {
  if (!iso) return ''
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (min < 1) return 'agora há pouco'
  if (min < 60) return `há ${min} min`
  return `há ${Math.floor(min / 60)}h${String(min % 60).padStart(2, '0')}`
}

/**
 * Carga do painel. Uma falha NÃO pode zerar a tabela: um tropeço da Graph API caía no
 * `catch`, `campanhas` virava `[]` e a tela inteira ficava em "—" sem uma palavra de
 * explicação. O erro aparece na faixa de aviso e o que já estava na tela fica.
 */
async function carregarPainel() {
  if (custom.value && !intervaloPronto.value) return
  loadingPainel.value = true
  erroPainel.value = ''
  try {
    const [c, m, s] = await Promise.all([
      api<{ campanhas: Campanha[], erro?: string, de?: string | null }>('/api/marketing/campanhas'),
      api<{ metricas: Metrica[], erro?: string, de?: string | null }>(`/api/marketing/metricas?nivel=campaign&${qsPeriodo()}`),
      // mesmo período do gasto: CPL só faz sentido dividindo a mesma janela de datas
      api<{ stats: AdStat[], stats_campanha?: AdStatCamp[], sem_atribuicao?: AdStatCamp, erro?: string | null }>(`/api/marketing/ad-stats?${qsPeriodo()}`)
        .catch((e: Error) => ({ stats: [], erro: `Não consegui ler os números do CRM (${e?.message || 'erro de rede'}).` })),
    ])
    if (c.campanhas?.length || !campanhas.value.length) campanhas.value = c.campanhas || []
    const falha = c.erro || m.erro
    if (falha) {
      const idade = desde(c.erro ? c.de : m.de)
      erroPainel.value = idade ? `${falha} — mostrando os números de ${idade}.` : falha
    }
    const map: Record<string, Metrica> = {}
    for (const r of m.metricas || []) { if (r.campaign_id) map[r.campaign_id] = r }
    if (Object.keys(map).length || !Object.keys(metricasMap.value).length) metricasMap.value = map
    const smap: Record<string, AdStat> = {}
    for (const r of s.stats || []) { if (r.criativo) smap[r.criativo] = r }
    adStatsMap.value = smap
    const cmap: Record<string, AdStatCamp> = {}
    for (const r of (s as { stats_campanha?: AdStatCamp[] }).stats_campanha || []) { if (r.campaign_id) cmap[r.campaign_id] = r }
    adStatsCampMap.value = cmap
    // Sem o cruzamento anúncio→campanha não existe número de CRM nenhum: as colunas
    // ficam em "—", porque um "0" ali afirmaria que o anúncio não deu resultado.
    erroStats.value = s.erro || ''
    semAtribuicao.value = (s as { sem_atribuicao?: AdStatCamp }).sem_atribuicao ?? null
  }
  catch (e) {
    erroPainel.value = `Não consegui falar com o servidor (${(e as Error)?.message || 'erro de rede'}). Os números na tela podem estar velhos.`
  }
  finally { loadingPainel.value = false }
}
watch([periodo, dataDe, dataAte], carregarPainel)
onMounted(carregarPainel)

// ------------------------------------------------------------- formatação ---
function mFmt(c: Campanha): Metrica { return metricasMap.value[c.id] || {} }
function brl(v: string | number | null | undefined) { return parseFloat(String(v || 0)).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }) }
function mNum(v: string | null | undefined) { return v ? parseInt(v, 10).toLocaleString('pt-BR') : '—' }

/**
 * O Facebook devolve métrica de TODA campanha que gastou no período, inclusive as
 * arquivadas/excluídas — que não aparecem na lista. Somar o mapa inteiro dava um total
 * sem dono. O total é das campanhas LISTADAS; o resto vira o aviso `gastoForaDaLista()`.
 *
 * "Listada" é o que está NA TELA, então o filtro "só ativas" entra aqui também: rodapé
 * que soma linha escondida é a reclamação clássica de número que não fecha. E o gasto
 * das pausadas não some da conta — cai no `gastoForaDaLista()`, que já existe para isso
 * e o mostra no aviso.
 */
function somaListadas(campo: 'spend' | 'impressions' | 'clicks') {
  return campanhasVisiveis.value.reduce((s, c) => s + parseFloat(metricasMap.value[c.id]?.[campo] || '0'), 0)
}
function gastoTotal() { return somaListadas('spend') }
function gastoForaDaLista() {
  const listadas = new Set(campanhasVisiveis.value.map(c => c.id))
  return Object.entries(metricasMap.value)
    .filter(([id]) => !listadas.has(id))
    .reduce((s, [, m]) => s + parseFloat(m.spend || '0'), 0)
}
function totalCrm(campo: CrmCampo) {
  return campanhasVisiveis.value.reduce((s, c) => s + (adStat(c)?.[campo] ?? 0), 0)
}
function totalConversas() {
  return campanhasVisiveis.value.reduce((s, c) => s + (conversas(c) ?? 0), 0)
}
/**
 * Custos do rodapé: gasto total ÷ evento total, não a média dos custos das linhas —
 * uma campanha que gastou R$ 200 pesa mais que uma de R$ 10.
 */
function custoTotalPor(qtd: number): number | null {
  return qtd > 0 && gastoTotal() > 0 ? gastoTotal() / qtd : null
}
function ctrTotal() {
  const imp = somaListadas('impressions')
  return imp > 0 ? (somaListadas('clicks') / imp) * 100 : null
}
function cpmTotal() {
  const imp = somaListadas('impressions')
  return imp > 0 ? (gastoTotal() / imp) * 1000 : null
}
/**
 * Taxa de conversão entre duas etapas. É o que o custo sozinho não conta: um anúncio
 * pode ter o melhor CPL da tabela e ser o pior, se nenhum daqueles leads marca reunião.
 */
function taxa(de: number | null | undefined, para: number | null | undefined): string | null {
  if (!de || para === null || para === undefined) return null
  return `${Math.round((para / de) * 100)}%`
}

function isAtiva(c: Campanha) { return c.effective_status === 'ACTIVE' }
function statusCor(c: Campanha) { return c.effective_status === 'ACTIVE' ? 'var(--c-ai)' : c.effective_status === 'PAUSED' ? 'var(--c-warn)' : 'var(--c-text-muted)' }
function statusTxt(c: Campanha) {
  const m: Record<string, string> = { ACTIVE: 'Ativa', PAUSED: 'Pausada', DELETED: 'Excluída', ARCHIVED: 'Arquivada', IN_PROCESS: 'Processando', WITH_ISSUES: 'Com problemas' }
  return m[c.effective_status] || c.effective_status
}
function objTxt(o: string | null) {
  const m: Record<string, string> = { OUTCOME_TRAFFIC: 'Tráfego', OUTCOME_LEADS: 'Leads', OUTCOME_SALES: 'Vendas', OUTCOME_ENGAGEMENT: 'Engajamento', MESSAGES: 'Mensagens', LINK_CLICKS: 'Cliques', CONVERSIONS: 'Conversões' }
  return o ? (m[o] || o) : ''
}
function orcDiario(c: Campanha) { return c.orcamento_diario ? c.orcamento_diario / 100 : null }
/**
 * Dá para editar daqui? Só quando existe UM lugar óbvio para gravar. Campanha com dois
 * conjuntos tem dois orçamentos, e o painel escolher um por conta própria seria decidir
 * verba no lugar de quem cuida da conta — nesse caso a célula vira texto e manda pro FB.
 */
function orcEditavel(c: Campanha) {
  return c.orcamento_nivel === 'campanha' || (c.orcamento_nivel === 'conjunto' && c.orcamento_conjuntos === 1)
}
function conversas(c: Campanha) {
  const ac = mFmt(c).actions
  if (!ac) return null
  for (const tipo of ['onsite_conversion.messaging_conversation_started_7d', 'onsite_conversion.messaging_first_reply', 'messaging_conversation_started']) {
    const a = ac.find(x => x.action_type === tipo)
    if (a) return parseInt(a.value, 10)
  }
  return null
}
function custoMsg(c: Campanha) { const conv = conversas(c); const m = mFmt(c); return (conv && m.spend) ? parseFloat(m.spend) / conv : null }

// Extrai "Criativo N" do nome da campanha para cruzar com adStatsMap (legado).
function criativoKey(c: Campanha): string | null {
  const m = c.name.match(/Criativo\s+(\d+)/i)
  return m ? `Criativo ${m[1]}` : null
}
function adStat(c: Campanha): AdStat | AdStatCamp | null {
  if (adStatsCampMap.value[c.id]) return adStatsCampMap.value[c.id]
  const k = criativoKey(c)
  return k ? (adStatsMap.value[k] ?? null) : null
}
/** Custo por evento do CRM: sem evento não há custo, e "R$ 0" seria mentira. */
function custoPor(c: Campanha, campo: CrmCampo): number | null {
  const s = adStat(c); const m = mFmt(c)
  return (s && s[campo] > 0 && m.spend) ? parseFloat(m.spend) / s[campo] : null
}
/** Quantidade de um evento do CRM. Com o cruzamento quebrado devolve null → "—". */
function qtd(c: Campanha, campo: CrmCampo): number | null {
  const s = adStat(c)
  if (s) return s[campo] ?? 0
  if (erroStats.value) return null
  return mFmt(c).spend ? 0 : null
}

// ---------------------------------------------------------------- colunas ---
type Grupo = 'campanha' | 'facebook' | 'crm'
interface Coluna {
  key: string
  label: string
  grupo: Grupo
  dica?: string
  /** Colunas fixas não podem ser escondidas — sem elas a linha perde identidade. */
  fixa?: boolean
  /** Célula com layout próprio (nome, status, orçamento). */
  especial?: boolean
  cor?: string
}

const COLUNAS: Coluna[] = [
  { key: 'campanha', label: 'Campanha', grupo: 'campanha', fixa: true, especial: true },
  { key: 'status', label: 'Status', grupo: 'campanha', especial: true },
  { key: 'orcamento', label: 'Orçamento/dia', grupo: 'campanha', especial: true },
  { key: 'gasto', label: 'Gasto', grupo: 'facebook' },
  { key: 'impressoes', label: 'Impressões', grupo: 'facebook' },
  { key: 'cliques', label: 'Cliques', grupo: 'facebook' },
  { key: 'ctr', label: 'CTR', grupo: 'facebook', dica: 'Cliques ÷ impressões' },
  { key: 'cpc', label: 'CPC', grupo: 'facebook', dica: 'Custo por clique' },
  { key: 'cpm', label: 'CPM', grupo: 'facebook', dica: 'Custo por mil impressões' },
  { key: 'leads', label: 'Leads CRM', grupo: 'crm', cor: '#25D366', dica: 'Leads que chegaram no CRM pelo referral do anúncio, no período' },
  { key: 'cpl', label: 'CPL real', grupo: 'crm', cor: '#25D366', dica: 'Gasto ÷ leads do CRM, no mesmo período' },
  { key: 'qualificados', label: 'Qualificados', grupo: 'crm', cor: '#4CAF90', dica: 'Leads marcados como qualificados na triagem. Quem ainda não foi triado não conta aqui.' },
  { key: 'custo_qualificado', label: 'Custo/qualif.', grupo: 'crm', cor: '#4CAF90', dica: 'Gasto ÷ leads qualificados — o custo que diz para onde levar a verba' },
  { key: 'reunioes', label: 'Reuniões', grupo: 'crm', cor: '#4CAF90', dica: 'Reuniões marcadas na agenda pelos leads que chegaram no período' },
  { key: 'custo_reuniao', label: 'Custo/reun.', grupo: 'crm', cor: '#4CAF90', dica: 'Gasto ÷ reuniões' },
  { key: 'realizadas', label: 'Realizadas', grupo: 'crm', cor: '#25D366', dica: 'Dessas reuniões, as que o cliente compareceu (presença apurada no Meet)' },
  { key: 'custo_realizada', label: 'Custo/realiz.', grupo: 'crm', cor: '#25D366', dica: 'Gasto ÷ reuniões realizadas' },
  { key: 'vendas', label: 'Vendas', grupo: 'crm', cor: '#25D366', dica: 'Leads do período que estão na etapa Fechado' },
  { key: 'custo_venda', label: 'Custo/venda', grupo: 'crm', cor: '#25D366', dica: 'Gasto ÷ vendas' },
  { key: 'conversas', label: 'Conversas', grupo: 'facebook', dica: 'Conversas iniciadas, medidas pelo Facebook' },
  { key: 'custo_msg', label: 'Custo/msg', grupo: 'facebook', dica: 'Gasto ÷ conversas' },
]

const CHAVE_PREF = 'marketing.colunas.v1'
const escondidas = ref<string[]>([])
onMounted(() => {
  try {
    const salvo = localStorage.getItem(CHAVE_PREF)
    if (salvo) escondidas.value = JSON.parse(salvo)
  }
  catch { /* preferência corrompida não pode derrubar a tela */ }
})
function alternarColuna(key: string) {
  const col = COLUNAS.find(c => c.key === key)
  if (col?.fixa) return
  escondidas.value = escondidas.value.includes(key)
    ? escondidas.value.filter(k => k !== key)
    : [...escondidas.value, key]
  try { localStorage.setItem(CHAVE_PREF, JSON.stringify(escondidas.value)) }
  catch { /* modo privado sem storage: a escolha vale só nesta sessão */ }
}
function mostrarTodas() {
  escondidas.value = []
  try { localStorage.setItem(CHAVE_PREF, '[]') } catch { /* idem */ }
}
const colunas = computed(() => COLUNAS.filter(c => !escondidas.value.includes(c.key)))
const editorAberto = ref(false)

/*
  A explicação de cada métrica morava só no `title=`, que no dedo NÃO existe: nem toque
  longo, nem hover. Num aparelho é onde mais falta — a coluna se chama "CPL real" e não há
  como descobrir o que é. Tocar no cabeçalho abre a explicação numa faixa no rodapé.
*/
const dica = ref('')
let dicaTimer: ReturnType<typeof setTimeout> | undefined
function mostrarDica(texto?: string) {
  if (! texto) { return }
  dica.value = texto
  clearTimeout(dicaTimer)
  dicaTimer = setTimeout(() => { dica.value = '' }, 6000)
}
onBeforeUnmount(() => clearTimeout(dicaTimer))

// ------------------------------------------------------------- as células ---
interface Celula { txt: string; sub?: string | null; cor?: string; forte?: boolean }

/** O conteúdo de uma célula da linha da campanha. */
function celula(c: Campanha, key: string): Celula {
  const m = mFmt(c)
  const s = adStat(c)
  const apagado = 'var(--c-text-faint)'
  const num = (v: number | null, sub?: string | null, cor?: string): Celula =>
    v === null ? { txt: '—', cor: apagado } : { txt: String(v), sub, cor: v ? cor : apagado, forte: !!v }
  const dinheiro = (v: number | null, cor?: string): Celula =>
    v === null ? { txt: '—', cor: apagado } : { txt: brl(v), cor, forte: true }

  switch (key) {
    case 'gasto': return m.spend ? { txt: brl(m.spend), forte: true } : { txt: '—', cor: apagado }
    case 'impressoes': return { txt: mNum(m.impressions), cor: 'var(--c-text-secondary)' }
    case 'cliques': return { txt: mNum(m.clicks), cor: 'var(--c-text-secondary)' }
    case 'ctr': return { txt: m.ctr ? `${parseFloat(m.ctr).toFixed(2).replace('.', ',')}%` : '—', cor: 'var(--c-text-secondary)' }
    case 'cpc': return { txt: m.cpc ? brl(m.cpc) : '—', cor: 'var(--c-text-secondary)' }
    case 'cpm': return { txt: m.cpm ? brl(m.cpm) : '—', cor: 'var(--c-text-secondary)' }
    case 'leads': return num(qtd(c, 'leads'), s?.leads ? `${s.responderam} respond.` : null, '#25D366')
    case 'cpl': return dinheiro(custoPor(c, 'leads'), '#25D366')
    case 'qualificados': return num(qtd(c, 'qualificados'), s ? taxa(s.leads, s.qualificados) && `${taxa(s.leads, s.qualificados)} dos leads` : null, '#4CAF90')
    case 'custo_qualificado': return dinheiro(custoPor(c, 'qualificados'), '#4CAF90')
    case 'reunioes': return num(qtd(c, 'reunioes'), s ? taxa(s.leads, s.reunioes) && `${taxa(s.leads, s.reunioes)} dos leads` : null, '#4CAF90')
    case 'custo_reuniao': return dinheiro(custoPor(c, 'reunioes'), '#4CAF90')
    case 'realizadas': return num(qtd(c, 'realizadas'), s ? taxa(s.reunioes, s.realizadas) && `${taxa(s.reunioes, s.realizadas)} compareceu` : null, '#25D366')
    case 'custo_realizada': return dinheiro(custoPor(c, 'realizadas'), '#25D366')
    case 'vendas': return num(qtd(c, 'vendas'), s ? taxa(s.reunioes, s.vendas) && `${taxa(s.reunioes, s.vendas)} das reun.` : null, '#25D366')
    case 'custo_venda': return dinheiro(custoPor(c, 'vendas'), '#25D366')
    case 'conversas': return num(conversas(c), null, 'var(--c-text-secondary)')
    case 'custo_msg': return dinheiro(custoMsg(c), 'var(--c-ai)')
    default: return { txt: '' }
  }
}

/** O conteúdo da mesma coluna na linha de TOTAL. */
function total(key: string): Celula {
  const apagado = 'var(--c-text-faint)'
  const dinheiro = (v: number | null, cor?: string): Celula => v === null ? { txt: '—', cor: apagado } : { txt: brl(v), cor, forte: true }
  const contagem = (campo: CrmCampo, sub?: string | null, cor?: string): Celula =>
    ({ txt: String(totalCrm(campo)), sub, cor, forte: true })

  switch (key) {
    case 'campanha': return { txt: 'TOTAL', cor: apagado, forte: true }
    case 'gasto': return { txt: brl(gastoTotal()), forte: true }
    case 'impressoes': return { txt: mNum(String(somaListadas('impressions'))), cor: 'var(--c-text-secondary)', forte: true }
    case 'cliques': return { txt: mNum(String(somaListadas('clicks'))), cor: 'var(--c-text-secondary)', forte: true }
    case 'ctr': return { txt: ctrTotal() !== null ? `${ctrTotal()!.toFixed(2).replace('.', ',')}%` : '—', cor: 'var(--c-text-secondary)', forte: true }
    case 'cpc': return dinheiro(custoTotalPor(somaListadas('clicks')), 'var(--c-text-secondary)')
    case 'cpm': return dinheiro(cpmTotal(), 'var(--c-text-secondary)')
    case 'leads': return contagem('leads', totalCrm('leads') ? `${totalCrm('responderam')} respond.` : null, '#25D366')
    case 'cpl': return dinheiro(custoTotalPor(totalCrm('leads')), '#25D366')
    case 'qualificados': return contagem('qualificados', taxa(totalCrm('leads'), totalCrm('qualificados')) && `${taxa(totalCrm('leads'), totalCrm('qualificados'))} dos leads`, '#4CAF90')
    case 'custo_qualificado': return dinheiro(custoTotalPor(totalCrm('qualificados')), '#4CAF90')
    case 'reunioes': return contagem('reunioes', taxa(totalCrm('leads'), totalCrm('reunioes')) && `${taxa(totalCrm('leads'), totalCrm('reunioes'))} dos leads`, '#4CAF90')
    case 'custo_reuniao': return dinheiro(custoTotalPor(totalCrm('reunioes')), '#4CAF90')
    case 'realizadas': return contagem('realizadas', taxa(totalCrm('reunioes'), totalCrm('realizadas')) && `${taxa(totalCrm('reunioes'), totalCrm('realizadas'))} compareceu`, '#25D366')
    case 'custo_realizada': return dinheiro(custoTotalPor(totalCrm('realizadas')), '#25D366')
    case 'vendas': return contagem('vendas', taxa(totalCrm('reunioes'), totalCrm('vendas')) && `${taxa(totalCrm('reunioes'), totalCrm('vendas'))} das reun.`, '#25D366')
    case 'custo_venda': return dinheiro(custoTotalPor(totalCrm('vendas')), '#25D366')
    case 'conversas': return { txt: String(totalConversas() || '—'), cor: 'var(--c-text-secondary)', forte: true }
    case 'custo_msg': return dinheiro(custoTotalPor(totalConversas()), 'var(--c-ai)')
    default: return { txt: '' }
  }
}

/**
 * Linha dos leads cujo anúncio não bate com nenhuma campanha da lista (campanha
 * arquivada, ou fora das 50 que a conta devolve). Ficam aqui, e não no lixo: sem esta
 * linha o painel some com lead de verdade e a soma nunca fecha com o CRM.
 */
function semAtrib(key: string): Celula {
  const s = semAtribuicao.value
  const apagado = 'var(--c-text-faint)'
  if (!s) return { txt: '' }
  if (key === 'campanha') return { txt: 'SEM ATRIBUIÇÃO', sub: 'anúncio fora das campanhas listadas', cor: apagado, forte: true }
  const campos: Record<string, CrmCampo> = { leads: 'leads', qualificados: 'qualificados', reunioes: 'reunioes', realizadas: 'realizadas', vendas: 'vendas' }
  const campo = campos[key]
  if (!campo) return { txt: '—', cor: apagado }
  return {
    txt: String(s[campo]),
    sub: key === 'leads' ? `${s.responderam} respond.` : null,
    cor: 'var(--c-text-secondary)',
    forte: true,
  }
}

// ------------------------------------------------------------------ ações ---
async function toggleSts(c: Campanha) {
  if (salvandoSts.value[c.id]) return
  salvandoSts.value[c.id] = true
  try {
    const r = await api<{ ok: boolean, erro?: string }>(`/api/marketing/campanhas/${c.id}`, { method: 'PATCH', body: { status: isAtiva(c) ? 'PAUSED' : 'ACTIVE' } })
    if (!r.ok) throw new Error(r.erro)
    await carregarPainel()
  }
  catch (e: any) { alert(e?.response?._data?.erro || e?.message || 'Erro ao atualizar.') }
  finally { salvandoSts.value[c.id] = false }
}
function iniciarEdicaoOrc(c: Campanha) { editandoOrc.value[c.id] = String(orcDiario(c) ? (orcDiario(c) as number).toFixed(2).replace('.', ',') : '') }
async function salvarOrc(c: Campanha) {
  const valor = parseFloat((editandoOrc.value[c.id] || '').replace(',', '.'))
  if (!valor || valor < 1) { alert('Orçamento mínimo: R$ 1,00'); return }
  salvandoOrc.value[c.id] = true
  try {
    const r = await api<{ ok: boolean, erro?: string }>(`/api/marketing/campanhas/${c.id}`, { method: 'PATCH', body: { orcamento_diario_reais: valor } })
    if (!r.ok) throw new Error(r.erro)
    delete editandoOrc.value[c.id]
    await carregarPainel()
  }
  catch (e: any) { alert(e?.response?._data?.erro || e?.message || 'Erro ao salvar.') }
  finally { salvandoOrc.value[c.id] = false }
}

/**
 * Duplica a campanha no Facebook (conjuntos e anúncios juntos).
 *
 * Confirma antes porque cria objeto de verdade na conta de anúncios — e uma cópia
 * acidental que ninguém percebe vira campanha órfã na lista.
 */
async function duplicar(c: Campanha) {
  if (!confirm(`Duplicar "${c.name}"?\n\nA cópia vem com os mesmos conjuntos e anúncios, e nasce PAUSADA.`)) return
  duplicando.value[c.id] = true
  try {
    const r = await api<{ ok: boolean, erro?: string }>(`/api/marketing/campanhas/${c.id}/duplicar`, { method: 'POST', body: {} })
    if (!r.ok) throw new Error(r.erro)
    await carregarPainel()
  }
  catch (e: any) { alert(e?.response?._data?.erro || e?.message || 'Erro ao duplicar.') }
  finally { duplicando.value[c.id] = false }
}

const ativas = computed(() => campanhas.value.filter(isAtiva).length)

/**
 * A tabela pronta: cada célula calculada UMA vez.
 *
 * No template, `celula(c, col.key).txt` seria uma chamada por propriedade lida — quatro
 * por célula, ~700 por render numa tabela de 9 campanhas. Aqui a conta é feita uma vez
 * por célula e o template só lê.
 */
const grade = computed(() => campanhasVisiveis.value.map(c => ({
  campanha: c,
  cels: Object.fromEntries(colunas.value.map(col => [col.key, celula(c, col.key)])) as Record<string, Celula>,
})))
const rodape = computed(() => Object.fromEntries(colunas.value.map(col => [col.key, total(col.key)])) as Record<string, Celula>)
const rodapeSemAtrib = computed(() => semAtribuicao.value
  ? Object.fromEntries(colunas.value.map(col => [col.key, semAtrib(col.key)])) as Record<string, Celula>
  : null)
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow:hidden;">
    <!--
      cabeçalho

      A margem lateral vira `max(...)` nas quatro faixas desta tela (cabeçalho, controles,
      avisos e tabela): a partir de ~1900px ela cresce sozinha e segura o conteúdo em
      1800px centralizados. Sem isso, em 2560px o nome da campanha ficava na borda
      esquerda e "Custo/venda" na direita, e o olho perdia a linha entre os dois. 1800px é
      a largura natural das 21 colunas — um teto menor faria a tabela rolar no desktop.
    -->
    <div class="r-wrap" style="padding:16px max(clamp(12px,4vw,50px), calc((100% - 1800px) / 2));border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-ink)" stroke-width="1.9"><path d="M3 11v2a1 1 0 0 0 1 1h2v4h2v-4l10 4.5v-15L8 8H4a1 1 0 0 0-1 1Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </div>
      <div style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Gerenciador de anúncios</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Campanhas do Facebook Ads cruzadas com os leads reais do CRM</div>
      </div>
      <!-- o espaçador vira a quebra de linha no celular: solto na barra que quebra, ele abria um buraco e desalinhava o resto -->
      <div class="r-break-line" style="flex:1;" />
      <!--
        Os três atalhos somam ~330px e não cabiam nos 331px úteis de um 360px: o cabeçalho
        quebrava em três fileiras e empurrava a tabela para fora da tela. Viram uma tira que
        o dedo arrasta, e cada um passa a ter alvo de 44px.
      -->
      <div class="r-swipe r-scroll-hint r-min0" style="--r-hint-bg:var(--c-bg-deep);display:flex;align-items:center;gap:8px;">
        <NuxtLink to="/marketing/memoria" class="r-tap" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">🧠 Memória</NuxtLink>
        <NuxtLink to="/marketing/criativos" class="r-tap" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">🖼️ Criativos</NuxtLink>
        <NuxtLink to="/marketing/otimizacao" class="r-tap" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">🎯 Otimização</NuxtLink>
      </div>
    </div>

    <!-- controles: período, calendário e editor de colunas -->
    <div style="padding:10px max(clamp(12px,4vw,50px), calc((100% - 1800px) / 2));border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap;">
      <!--
        Os seis botões de período eram alvos de ~25px empilhando em três fileiras no
        celular. Agrupados, viram uma tira que rola com o dedo, e o r-tap dá os 44px
        mínimos de toque sem mexer no desktop.
      -->
      <!-- `r-scroll-hint` não é enfeite: o `r-swipe` esconde a barra de rolagem, e sem a
           sombra de "tem mais para o lado" o botão 📅 Datas — único caminho para o período
           personalizado — some da tela em 360px sem deixar pista de que existe. -->
      <div class="r-swipe r-scroll-hint r-min0" style="--r-hint-bg:var(--c-bg-deep);display:flex;align-items:center;gap:6px;">
        <button
          v-for="p in periodos" :key="p.value"
          class="r-tap"
          :style="{ background: periodo === p.value ? 'var(--c-surface-0)' : 'transparent', border: '1px solid ' + (periodo === p.value ? 'var(--c-surface-3)' : 'transparent'), color: periodo === p.value ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
          @click="periodo = p.value"
        >{{ p.label }}</button>
        <button
          class="r-tap"
          :style="{ background: custom ? 'var(--c-surface-0)' : 'transparent', border: '1px solid ' + (custom ? 'var(--c-surface-3)' : 'transparent'), color: custom ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
          title="Escolher as datas no calendário"
          @click="periodo = 'custom'"
        >📅 Datas</button>
      </div>

      <!-- r-hide: numa barra que quebra linha, o divisor caía sozinho no começo de uma fileira e virava um risco solto -->
      <div class="r-hide" style="width:1px;height:18px;background:var(--c-surface-2);margin:0 4px;" />
      <button
        class="r-tap"
        :style="{ background: soAtivas ? 'var(--c-surface-0)' : 'transparent', border: '1px solid ' + (soAtivas ? 'var(--c-surface-3)' : 'transparent'), color: soAtivas ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
        :title="soAtivas ? 'Mostrando só as campanhas no ar — clique para ver todas' : `Esconder as pausadas (${ativas} de ${campanhas.length} no ar)`"
        @click="soAtivas = !soAtivas"
      >🟢 Só ativas</button>

      <!-- calendário: as duas pontas entram no cálculo -->
      <!-- no celular a fonte dos dois campos vira 16px (regra global anti-zoom) e o par não cabia mais lado a lado: com o r-wrap ele empilha em vez de vazar -->
      <div v-if="custom" class="r-wrap r-min0" style="display:flex;align-items:center;gap:6px;background:var(--c-surface-0);border:1px solid var(--c-surface-3);border-radius:8px;padding:3px 8px;">
        <input v-model="dataDe" type="date" class="r-tap-h" style="background:transparent;border:none;color:var(--c-text);font-family:inherit;font-size:11.5px;outline:none;color-scheme:dark light;">
        <span style="font-size:11px;color:var(--c-text-faint);">até</span>
        <input v-model="dataAte" type="date" class="r-tap-h" style="background:transparent;border:none;color:var(--c-text);font-family:inherit;font-size:11.5px;outline:none;color-scheme:dark light;">
      </div>
      <span v-if="custom && !intervaloPronto" style="font-size:11px;color:var(--c-warn-soft);">escolha as duas datas</span>

      <!-- o espaçador vira a quebra de linha: ao quebrar, ele engolia o resto da fileira e jogava "⚙ Colunas" e "↻ Atualizar" para uma linha própria -->
      <div class="r-break-line" style="flex:1;" />

      <!-- os três pedaços do resumo eram itens soltos da barra: quebravam separados e deixavam um "·" órfão no fim de uma fileira -->
      <div v-if="Object.keys(metricasMap).length" style="display:flex;align-items:center;gap:6px;min-width:0;">
        <span style="font-size:11px;color:var(--c-text-faint);">Gasto: <b style="color:var(--c-text);">{{ brl(gastoTotal()) }}</b></span>
        <span style="font-size:11px;color:var(--c-text-faint);">·</span>
        <span style="font-size:11px;color:var(--c-text-faint);">{{ ativas }} ativas de {{ campanhas.length }}</span>
        <span class="r-hide" style="font-size:11px;color:var(--c-text-faint);">·</span>
      </div>

      <!-- editor de colunas -->
      <div style="position:relative;">
        <button
          class="r-tap"
          :style="{ background: escondidas.length ? 'var(--c-surface-0)' : 'transparent', border: '1px solid var(--c-surface-3)', color: escondidas.length ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
          title="Escolher quais colunas aparecem na tabela"
          @click="editorAberto = !editorAberto"
        ><!-- espaço RÍGIDO: o `.r-tap` torna o botão inline-flex no celular, cada filho vira
             item de flex e o espaço comum no começo do <span> é descartado — o rótulo lia
             "⚙ Colunas(17/21)" justo no estado em que ele precisa ser entendido -->⚙ Colunas<span v-if="escondidas.length">&nbsp;({{ colunas.length }}/{{ COLUNAS.length }})</span></button>

        <template v-if="editorAberto">
          <div style="position:fixed;inset:0;z-index:60;" @click="editorAberto = false" />
          <div class="mk-col-pop" style="position:absolute;right:0;top:calc(100% + 6px);z-index:61;width:260px;max-height:70dvh;overflow-y:auto;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:12px;box-shadow:0 16px 44px rgba(0,0,0,.35);padding:10px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
              <div style="font-size:12px;font-weight:800;">Colunas da tabela</div>
              <div style="flex:1;" />
              <button class="r-tap" style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:11px;font-weight:700;cursor:pointer;padding:0;" @click="mostrarTodas">Mostrar todas</button>
            </div>
            <template v-for="g in (['campanha', 'facebook', 'crm'] as const)" :key="g">
              <div style="font-size:10px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.4px;margin:9px 0 4px;">
                {{ g === 'campanha' ? 'A campanha' : g === 'facebook' ? 'Facebook' : 'CRM (leads reais)' }}
              </div>
              <!-- r-tap-h e não r-tap: a linha precisa continuar sendo uma linha de lista (o r-tap a tornaria inline-flex centralizada); os 22px de altura eram loteria no dedo -->
              <label
                v-for="col in COLUNAS.filter(c => c.grupo === g)" :key="col.key"
                class="r-tap-h"
                :style="{ display: 'flex', alignItems: 'center', gap: '8px', padding: '5px 6px', borderRadius: '7px', fontSize: '12px', cursor: col.fixa ? 'default' : 'pointer', opacity: col.fixa ? .5 : 1 }"
                :title="col.fixa ? 'Esta coluna não pode ser escondida' : col.dica"
              >
                <input type="checkbox" :checked="!escondidas.includes(col.key)" :disabled="col.fixa" style="accent-color:var(--accent);cursor:inherit;" @change="alternarColuna(col.key)">
                <span :style="{ color: col.cor || 'var(--c-text)' }">{{ col.label }}</span>
              </label>
            </template>
          </div>
        </template>
      </div>

      <button
        class="r-tap"
        :style="{ background: 'transparent', border: '1px solid var(--c-surface-3)', color: 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 10px', borderRadius: '7px', cursor: loadingPainel ? 'default' : 'pointer', opacity: loadingPainel ? .5 : 1 }"
        :disabled="loadingPainel"
        @click="carregarPainel"
      >{{ loadingPainel ? '…' : '↻ Atualizar' }}</button>
    </div>

    <div v-if="erroPainel" style="padding:11px max(clamp(12px,4vw,50px), calc((100% - 1800px) / 2));background:rgba(255,170,0,.12);border-bottom:1px solid rgba(255,170,0,.35);font-size:12.5px;font-weight:600;color:var(--c-warn-soft);flex-shrink:0;">
      ⚠ O Facebook não respondeu: {{ erroPainel }}
    </div>
    <div v-if="erroStats" style="padding:11px max(clamp(12px,4vw,50px), calc((100% - 1800px) / 2));background:rgba(255,170,0,.12);border-bottom:1px solid rgba(255,170,0,.35);font-size:12.5px;font-weight:600;color:var(--c-warn-soft);flex-shrink:0;">
      ⚠ Leads, reuniões e vendas fora do ar: {{ erroStats }}
    </div>

    <!-- estados vazios -->
    <div v-if="loadingPainel && !campanhas.length" style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--c-text-faint);font-size:13px;">Carregando campanhas…</div>
    <div v-else-if="custom && !intervaloPronto" style="flex:1;display:flex;align-items:center;justify-content:center;color:var(--c-text-faint);font-size:13px;">Escolha a data inicial e a final.</div>
    <div v-else-if="!campanhas.length" style="flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;color:var(--c-text-faint);font-size:13px;">
      <div>Nenhuma campanha na conta de anúncios.</div>
      <NuxtLink to="/admin/meta-ads" style="color:var(--accent);font-size:12px;">Conferir a conexão com o Facebook →</NuxtLink>
    </div>

    <!-- tabela -->
    <div v-else class="r-table-wrap" style="flex:1;overflow:auto;min-height:0;">
      <!--
        A margem lateral vai neste invólucro, não no `padding` do container que rola:
        com a tabela mais larga que a tela, o padding-direito de um elemento de scroll é
        descartado por vários navegadores e a última coluna volta a encostar na borda.
        Sendo `inline-block`, ele encolhe até o tamanho da tabela e leva as duas margens
        junto; o `min-width:100%` mantém o recuo mesmo quando a tabela é estreita.
      -->
      <div style="display:inline-block;min-width:100%;box-sizing:border-box;padding:0 max(clamp(12px,4vw,50px), calc((100% - 1800px) / 2));">
        <!--
          São 21 colunas (~1800px): a tabela rola no eixo X em qualquer tela menor que isso,
          e a coluna "Campanha" rolava junto — da terceira coluna em diante não dava mais
          para saber de qual campanha era o número. O r-table-sticky-1 prende a primeira
          coluna; o r-table-sticky-head prende o cabeçalho.

          Os dois exigem `border-collapse:separate`: com `collapse` o Safari ignora o
          `position:sticky` de th/td e não pinta o fundo — era por isso que as linhas
          passavam por baixo do cabeçalho e do TOTAL no iPhone. Em modelo separado a borda
          declarada no <tr> não é desenhada, então ela mora agora em cada célula.
        -->
        <table class="mk-table r-table-sticky-head r-table-sticky-1" style="width:100%;border-collapse:separate;border-spacing:0;font-size:12.5px;white-space:nowrap;--r-sticky-bg:var(--c-bg-deep);">
        <thead>
          <tr style="background:var(--c-bg-deepest);--r-sticky-bg:var(--c-bg-deepest);">
            <th
              v-for="col in colunas" :key="col.key"
              :style="{ padding: '10px 12px', textAlign: col.key === 'campanha' ? 'left' : col.key === 'status' ? 'center' : 'right', fontSize: '10.5px', fontWeight: 700, whiteSpace: 'nowrap', color: col.cor || 'var(--c-text-faint)', borderBottom: '1px solid var(--c-surface-1)', cursor: col.dica ? 'help' : 'default' }"
              :title="col.dica"
              @click="mostrarDica(col.dica)"
            >{{ col.label }}<span v-if="col.dica" class="r-only-mobile" style="opacity:.55;margin-left:3px;">ⓘ</span></th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="{ campanha: c, cels } in grade" :key="c.id"
            class="mk-row"
            style="transition:background .1s;"
            @mouseenter="e => (e.currentTarget as HTMLElement).style.background = 'var(--c-surface-0)'"
            @mouseleave="e => (e.currentTarget as HTMLElement).style.background = ''"
          >
            <template v-for="col in colunas" :key="col.key">
              <!-- nome + objetivo -->
              <td v-if="col.key === 'campanha'" style="padding:11px 12px;vertical-align:middle;border-bottom:1px solid var(--c-surface-1);">
                <div style="display:flex;align-items:center;gap:10px;">
                  <img
                    v-if="c.miniatura" :src="c.miniatura" alt="" loading="lazy"
                    style="width:38px;height:38px;flex:0 0 38px;border-radius:6px;object-fit:cover;background:var(--c-surface-1);"
                    @error="e => ((e.target as HTMLImageElement).style.display = 'none')"
                  >
                  <div style="min-width:0;">
                    <!-- 280px fixos + miniatura + paddings pediam ~350px: em 360px a primeira coluna tomava a tela toda. O teto agora encolhe junto (52vw) e as reticências continuam. -->
                    <div class="r-clamp-w mk-nome" style="--r-w:280px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.name }}</div>
                    <div style="display:flex;align-items:center;gap:7px;margin-top:2px;">
                      <span v-if="objTxt(c.objective)" style="font-size:10.5px;color:var(--c-text-faint);">{{ objTxt(c.objective) }}</span>
                      <!-- r-tap-inline: duplicar cria campanha de verdade na conta e o alvo tinha ~14px; a área clicável cresce por ::after, sem esticar a altura da linha -->
                      <button
                        :disabled="duplicando[c.id]"
                        class="r-tap-inline"
                        title="Duplicar a campanha com os conjuntos e anúncios. A cópia nasce pausada."
                        style="background:none;border:none;color:var(--c-text-faint);font-family:inherit;font-size:10.5px;font-weight:700;cursor:pointer;padding:0;text-decoration:underline;text-underline-offset:2px;"
                        @click="duplicar(c)"
                      >{{ duplicando[c.id] ? 'duplicando…' : '⧉ duplicar' }}</button>
                    </div>
                  </div>
                </div>
              </td>

              <!-- status (clicável: liga/desliga) -->
              <td v-else-if="col.key === 'status'" style="padding:11px 12px;text-align:center;vertical-align:middle;border-bottom:1px solid var(--c-surface-1);">
                <button
                  :disabled="salvandoSts[c.id]"
                  class="r-tap"
                  :title="isAtiva(c) ? 'Clique para pausar' : 'Clique para ativar'"
                  :style="{ background: 'transparent', border: '1px solid ' + statusCor(c), color: statusCor(c), fontFamily: 'inherit', fontSize: '10.5px', fontWeight: 700, padding: '3px 9px', borderRadius: '6px', cursor: salvandoSts[c.id] ? 'default' : 'pointer', opacity: salvandoSts[c.id] ? .5 : 1 }"
                  @click="toggleSts(c)"
                >{{ salvandoSts[c.id] ? '…' : statusTxt(c) }}</button>
              </td>

              <!-- orçamento (clicável: edita) -->
              <td v-else-if="col.key === 'orcamento'" style="padding:11px 12px;text-align:right;vertical-align:middle;white-space:nowrap;border-bottom:1px solid var(--c-surface-1);">
                <div v-if="c.id in editandoOrc" style="display:flex;align-items:center;gap:4px;justify-content:flex-end;">
                  <span style="font-size:11px;color:var(--c-text-faint);">R$</span>
                  <input
                    v-model="editandoOrc[c.id]" type="text" inputmode="decimal"
                    class="mk-orc-input"
                    style="width:72px;background:var(--c-surface-0);border:1px solid var(--c-ai);border-radius:5px;padding:3px 6px;color:var(--c-text);font-family:inherit;font-size:12px;outline:none;text-align:right;"
                    @keydown.enter="salvarOrc(c)" @keydown.escape="delete editandoOrc[c.id]"
                  >
                  <button :disabled="salvandoOrc[c.id]" class="r-tap" style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:11px;font-weight:700;padding:3px 7px;border-radius:5px;cursor:pointer;" @click="salvarOrc(c)">{{ salvandoOrc[c.id] ? '…' : 'OK' }}</button>
                  <!-- OK e ✕ ficam a 4px um do outro: com alvo de ~19px, confirmar e cancelar a verba eram o mesmo toque -->
                  <button class="r-tap" style="background:none;border:none;color:var(--c-text-faint);font-family:inherit;font-size:12px;cursor:pointer;padding:2px 4px;" @click="delete editandoOrc[c.id]">✕</button>
                </div>
                <div
                  v-else-if="orcDiario(c) && orcEditavel(c)"
                  class="r-tap"
                  :title="c.orcamento_nivel === 'conjunto' ? 'Orçamento do conjunto — clique para editar' : 'Clique para editar'"
                  style="cursor:pointer;display:inline-flex;align-items:center;gap:5px;padding:3px 7px;border-radius:5px;border:1px solid transparent;"
                  @mouseenter="e => (e.currentTarget as HTMLElement).style.borderColor = 'var(--c-surface-3)'"
                  @mouseleave="e => (e.currentTarget as HTMLElement).style.borderColor = 'transparent'"
                  @click="iniciarEdicaoOrc(c)"
                >
                  {{ brl(orcDiario(c)) }}<span style="font-size:10px;color:var(--c-text-faint);">/dia</span>
                  <span style="font-size:10px;color:var(--c-text-faint);opacity:.6;">✏</span>
                </div>
                <div v-else-if="orcDiario(c)" :title="`${c.orcamento_conjuntos} conjuntos com orçamento próprio — ajuste pelo Facebook`" style="display:inline-flex;align-items:center;gap:4px;">
                  {{ brl(orcDiario(c)) }}<span style="font-size:10px;color:var(--c-text-faint);">/dia · {{ c.orcamento_conjuntos }} conj.</span>
                </div>
                <span v-else-if="c.lifetime_budget" style="color:var(--c-text-faint);font-size:11px;">{{ brl(parseFloat(c.lifetime_budget) / 100) }} total</span>
                <span v-else style="color:var(--c-text-faint);">—</span>
              </td>

              <!-- todo o resto: número, com a taxa embaixo quando houver -->
              <td v-else style="padding:11px 12px;text-align:right;vertical-align:middle;font-variant-numeric:tabular-nums;border-bottom:1px solid var(--c-surface-1);">
                <span :style="{ color: cels[col.key].cor, fontWeight: cels[col.key].forte ? 700 : 400 }">{{ cels[col.key].txt }}</span>
                <div v-if="cels[col.key].sub" style="font-size:10px;color:var(--c-text-faint);margin-top:1px;">{{ cels[col.key].sub }}</div>
              </td>
            </template>
          </tr>

          <!-- totais -->
          <tr class="mk-total" style="background:var(--c-bg-deepest);--r-sticky-bg:var(--c-bg-deepest);">
            <td
              v-for="col in colunas" :key="col.key"
              :style="{ padding: '10px 12px', textAlign: col.key === 'campanha' ? 'left' : 'right', fontVariantNumeric: 'tabular-nums', fontSize: col.key === 'campanha' ? '11px' : '12.5px', fontWeight: 800, color: rodape[col.key].cor || 'var(--c-text)', borderTop: '2px solid var(--c-surface-1)' }"
            >
              <template v-if="!['status', 'orcamento'].includes(col.key)">
                {{ rodape[col.key].txt }}
                <div v-if="rodape[col.key].sub" style="font-size:10px;font-weight:600;color:var(--c-text-faint);">{{ rodape[col.key].sub }}</div>
              </template>
            </td>
          </tr>

          <!-- leads sem campanha correspondente -->
          <tr v-if="rodapeSemAtrib && semAtribuicao?.leads" style="background:var(--c-bg-deepest);--r-sticky-bg:var(--c-bg-deepest);">
            <td
              v-for="col in colunas" :key="col.key"
              :style="{ padding: '10px 12px', textAlign: col.key === 'campanha' ? 'left' : 'right', fontVariantNumeric: 'tabular-nums', fontSize: col.key === 'campanha' ? '11px' : '12.5px', fontWeight: 700, color: rodapeSemAtrib[col.key].cor || 'var(--c-text)', borderTop: '1px solid var(--c-surface-1)' }"
              :title="col.key === 'campanha' ? 'Leads com anúncio que não corresponde a nenhuma campanha listada — campanha arquivada ou fora da lista' : undefined"
            >
              <template v-if="!['status', 'orcamento'].includes(col.key)">
                {{ rodapeSemAtrib[col.key].txt }}
                <div v-if="rodapeSemAtrib[col.key].sub" style="font-size:10px;font-weight:400;color:var(--c-text-faint);">{{ rodapeSemAtrib[col.key].sub }}</div>
              </template>
            </td>
          </tr>
          </tbody>
        </table>

        <!-- o invólucro tem a largura da TABELA (~1800px): sem teto, este aviso se esticava até lá e só era legível rolando a tela para o lado -->
        <div v-if="gastoForaDaLista() > 0.005" style="padding:10px 0;font-size:11px;color:var(--c-text-faint);white-space:normal;max-width:min(640px,90vw);">
          Mais {{ brl(gastoForaDaLista()) }} gastos no período por campanhas que não estão na lista (arquivadas ou excluídas).
        </div>
      </div>
    </div>

    <!-- Explicação da métrica no dedo: `r-above-nav` para não cair atrás da barra de
         navegação do celular, `r-toast` para não passar da largura da tela. -->
    <div
      v-if="dica"
      class="r-toast r-above-nav"
      style="position:fixed;left:50%;transform:translateX(-50%);bottom:14px;z-index:70;max-width:min(520px,calc(100vw - 24px));background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:12px;box-shadow:0 14px 40px rgba(0,0,0,.4);padding:11px 14px;font-size:12.5px;line-height:1.45;color:var(--c-text-secondary);white-space:normal;cursor:pointer;"
      @click="dica = ''"
    >{{ dica }}</div>
  </div>
</template>

<style scoped>
/*
  O painel "⚙ Colunas" é `absolute` dentro do próprio botão. No celular a barra de
  filtros quebra linha e joga esse botão para o começo de uma fileira nova: o `right:0`
  abria os 260px para a esquerda e metade do painel ficava fora da tela, inalcançável
  (a raiz da tela tem `overflow:hidden`, então não há rolagem de resgate).

  Preso à JANELA ele volta a caber inteiro. O `.r-pop` sozinho não resolve aqui porque
  os 10px dele seriam medidos a partir do botão, não da borda da tela.
*/
@media (max-width: 820px) {
  .mk-col-pop {
    position: fixed !important;
    left: 10px !important;
    right: 10px !important;
    top: auto !important;
    /* A barra de navegação do CRM vira barra INFERIOR no celular (58px + safe area) e não
       tem z-index: com `bottom:12px` as últimas caixas de seleção do painel ficavam
       desenhadas por cima dos botões do menu. `.r-above-nav` já calcula essa folga. */
    bottom: calc(var(--r-bottom, 12px) + 58px + env(safe-area-inset-bottom, 0px)) !important;
    width: auto !important;
    max-width: calc(100vw - 20px) !important;
    max-height: 70dvh !important;
  }

  /*
    Os 72px do campo cabem "999,99" a 12px. No celular a regra global anti-zoom sobe a
    fonte para 16px e "R$ 1.234,56" passava a ser cortado pela esquerda — quem edita a
    verba deixava de ver o que estava digitando.
  */
  .mk-orc-input {
    width: 112px !important;
  }
}

/*
  A coluna "Campanha" agora fica presa enquanto a tabela rola, ou seja, ocupa a tela o
  tempo todo: com os 52vw do r-clamp-w ela levava 3/4 de um 360px e sobravam ~100px para
  os vinte números. Aqui ele encurta mais, com as mesmas reticências.
*/
@media (max-width: 480px) {
  .mk-nome {
    max-width: 38vw !important;
  }
}

/*
  A linha TOTAL fica presa no rodapé da tabela. O `position:sticky` vivia no <tr>, que o
  Safari ignora: as linhas de campanha passavam por cima do total. Aqui ele vai nas
  células, que é onde funciona nos dois navegadores.
*/
.mk-total td {
  position: sticky;
  bottom: 0;
  z-index: 2;
  background: var(--c-bg-deepest);
}

/*
  No desktop a linha inteira acende no hover, e a primeira coluna ganhou fundo opaco
  próprio (é o que a mantém legível quando a tabela rola no eixo X) — sem isto ela
  ficaria como o único pedaço apagado da linha sob o ponteiro.
*/
@media (min-width: 821px) {
  .mk-table tr.mk-row:hover td:first-child {
    background: var(--c-surface-0) !important;
  }
}
</style>
