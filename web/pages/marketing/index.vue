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
    <!-- cabeçalho -->
    <div style="padding:16px 50px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-ink)" stroke-width="1.9"><path d="M3 11v2a1 1 0 0 0 1 1h2v4h2v-4l10 4.5v-15L8 8H4a1 1 0 0 0-1 1Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </div>
      <div style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Gerenciador de anúncios</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Campanhas do Facebook Ads cruzadas com os leads reais do CRM</div>
      </div>
      <div style="flex:1;" />
      <NuxtLink to="/marketing/memoria" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">🧠 Memória</NuxtLink>
      <NuxtLink to="/marketing/criativos" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">🖼️ Criativos</NuxtLink>
    </div>

    <!-- controles: período, calendário e editor de colunas -->
    <div style="padding:10px 50px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap;">
      <button
        v-for="p in periodos" :key="p.value"
        :style="{ background: periodo === p.value ? 'var(--c-surface-0)' : 'transparent', border: '1px solid ' + (periodo === p.value ? 'var(--c-surface-3)' : 'transparent'), color: periodo === p.value ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
        @click="periodo = p.value"
      >{{ p.label }}</button>
      <button
        :style="{ background: custom ? 'var(--c-surface-0)' : 'transparent', border: '1px solid ' + (custom ? 'var(--c-surface-3)' : 'transparent'), color: custom ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
        title="Escolher as datas no calendário"
        @click="periodo = 'custom'"
      >📅 Datas</button>

      <div style="width:1px;height:18px;background:var(--c-surface-2);margin:0 4px;" />
      <button
        :style="{ background: soAtivas ? 'var(--c-surface-0)' : 'transparent', border: '1px solid ' + (soAtivas ? 'var(--c-surface-3)' : 'transparent'), color: soAtivas ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
        :title="soAtivas ? 'Mostrando só as campanhas no ar — clique para ver todas' : `Esconder as pausadas (${ativas} de ${campanhas.length} no ar)`"
        @click="soAtivas = !soAtivas"
      >🟢 Só ativas</button>

      <!-- calendário: as duas pontas entram no cálculo -->
      <div v-if="custom" style="display:flex;align-items:center;gap:6px;background:var(--c-surface-0);border:1px solid var(--c-surface-3);border-radius:8px;padding:3px 8px;">
        <input v-model="dataDe" type="date" style="background:transparent;border:none;color:var(--c-text);font-family:inherit;font-size:11.5px;outline:none;color-scheme:dark light;">
        <span style="font-size:11px;color:var(--c-text-faint);">até</span>
        <input v-model="dataAte" type="date" style="background:transparent;border:none;color:var(--c-text);font-family:inherit;font-size:11.5px;outline:none;color-scheme:dark light;">
      </div>
      <span v-if="custom && !intervaloPronto" style="font-size:11px;color:var(--c-warn-soft);">escolha as duas datas</span>

      <div style="flex:1;" />

      <template v-if="Object.keys(metricasMap).length">
        <span style="font-size:11px;color:var(--c-text-faint);">Gasto: <b style="color:var(--c-text);">{{ brl(gastoTotal()) }}</b></span>
        <span style="font-size:11px;color:var(--c-text-faint);">·</span>
        <span style="font-size:11px;color:var(--c-text-faint);">{{ ativas }} ativas de {{ campanhas.length }}</span>
        <span style="font-size:11px;color:var(--c-text-faint);">·</span>
      </template>

      <!-- editor de colunas -->
      <div style="position:relative;">
        <button
          :style="{ background: escondidas.length ? 'var(--c-surface-0)' : 'transparent', border: '1px solid var(--c-surface-3)', color: escondidas.length ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '7px', cursor: 'pointer' }"
          title="Escolher quais colunas aparecem na tabela"
          @click="editorAberto = !editorAberto"
        >⚙ Colunas<span v-if="escondidas.length"> ({{ colunas.length }}/{{ COLUNAS.length }})</span></button>

        <template v-if="editorAberto">
          <div style="position:fixed;inset:0;z-index:60;" @click="editorAberto = false" />
          <div style="position:absolute;right:0;top:calc(100% + 6px);z-index:61;width:260px;max-height:70vh;overflow-y:auto;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:12px;box-shadow:0 16px 44px rgba(0,0,0,.35);padding:10px;">
            <div style="display:flex;align-items:center;gap:8px;margin-bottom:8px;">
              <div style="font-size:12px;font-weight:800;">Colunas da tabela</div>
              <div style="flex:1;" />
              <button style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:11px;font-weight:700;cursor:pointer;padding:0;" @click="mostrarTodas">Mostrar todas</button>
            </div>
            <template v-for="g in (['campanha', 'facebook', 'crm'] as const)" :key="g">
              <div style="font-size:10px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.4px;margin:9px 0 4px;">
                {{ g === 'campanha' ? 'A campanha' : g === 'facebook' ? 'Facebook' : 'CRM (leads reais)' }}
              </div>
              <label
                v-for="col in COLUNAS.filter(c => c.grupo === g)" :key="col.key"
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
        :style="{ background: 'transparent', border: '1px solid var(--c-surface-3)', color: 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 10px', borderRadius: '7px', cursor: loadingPainel ? 'default' : 'pointer', opacity: loadingPainel ? .5 : 1 }"
        :disabled="loadingPainel"
        @click="carregarPainel"
      >{{ loadingPainel ? '…' : '↻ Atualizar' }}</button>
    </div>

    <div v-if="erroPainel" style="padding:11px 50px;background:rgba(255,170,0,.12);border-bottom:1px solid rgba(255,170,0,.35);font-size:12.5px;font-weight:600;color:var(--c-warn-soft);flex-shrink:0;">
      ⚠ O Facebook não respondeu: {{ erroPainel }}
    </div>
    <div v-if="erroStats" style="padding:11px 50px;background:rgba(255,170,0,.12);border-bottom:1px solid rgba(255,170,0,.35);font-size:12.5px;font-weight:600;color:var(--c-warn-soft);flex-shrink:0;">
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
    <div v-else style="flex:1;overflow:auto;min-height:0;">
      <!--
        A margem lateral vai neste invólucro, não no `padding` do container que rola:
        com a tabela mais larga que a tela, o padding-direito de um elemento de scroll é
        descartado por vários navegadores e a última coluna volta a encostar na borda.
        Sendo `inline-block`, ele encolhe até o tamanho da tabela e leva as duas margens
        junto; o `min-width:100%` mantém o recuo mesmo quando a tabela é estreita.
      -->
      <div style="display:inline-block;min-width:100%;box-sizing:border-box;padding:0 50px;">
        <table style="width:100%;border-collapse:collapse;font-size:12.5px;white-space:nowrap;">
        <thead>
          <tr style="position:sticky;top:0;z-index:2;background:var(--c-bg-deepest);border-bottom:1px solid var(--c-surface-1);">
            <th
              v-for="col in colunas" :key="col.key"
              :style="{ padding: '10px 12px', textAlign: col.key === 'campanha' ? 'left' : col.key === 'status' ? 'center' : 'right', fontSize: '10.5px', fontWeight: 700, whiteSpace: 'nowrap', color: col.cor || 'var(--c-text-faint)' }"
              :title="col.dica"
            >{{ col.label }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="{ campanha: c, cels } in grade" :key="c.id"
            style="border-bottom:1px solid var(--c-surface-1);transition:background .1s;"
            @mouseenter="e => (e.currentTarget as HTMLElement).style.background = 'var(--c-surface-0)'"
            @mouseleave="e => (e.currentTarget as HTMLElement).style.background = ''"
          >
            <template v-for="col in colunas" :key="col.key">
              <!-- nome + objetivo -->
              <td v-if="col.key === 'campanha'" style="padding:11px 12px;vertical-align:middle;">
                <div style="display:flex;align-items:center;gap:10px;">
                  <img
                    v-if="c.miniatura" :src="c.miniatura" alt="" loading="lazy"
                    style="width:38px;height:38px;flex:0 0 38px;border-radius:6px;object-fit:cover;background:var(--c-surface-1);"
                    @error="e => ((e.target as HTMLImageElement).style.display = 'none')"
                  >
                  <div style="min-width:0;">
                    <div style="font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:280px;">{{ c.name }}</div>
                    <div style="display:flex;align-items:center;gap:7px;margin-top:2px;">
                      <span v-if="objTxt(c.objective)" style="font-size:10.5px;color:var(--c-text-faint);">{{ objTxt(c.objective) }}</span>
                      <button
                        :disabled="duplicando[c.id]"
                        title="Duplicar a campanha com os conjuntos e anúncios. A cópia nasce pausada."
                        style="background:none;border:none;color:var(--c-text-faint);font-family:inherit;font-size:10.5px;font-weight:700;cursor:pointer;padding:0;text-decoration:underline;text-underline-offset:2px;"
                        @click="duplicar(c)"
                      >{{ duplicando[c.id] ? 'duplicando…' : '⧉ duplicar' }}</button>
                    </div>
                  </div>
                </div>
              </td>

              <!-- status (clicável: liga/desliga) -->
              <td v-else-if="col.key === 'status'" style="padding:11px 12px;text-align:center;vertical-align:middle;">
                <button
                  :disabled="salvandoSts[c.id]"
                  :title="isAtiva(c) ? 'Clique para pausar' : 'Clique para ativar'"
                  :style="{ background: 'transparent', border: '1px solid ' + statusCor(c), color: statusCor(c), fontFamily: 'inherit', fontSize: '10.5px', fontWeight: 700, padding: '3px 9px', borderRadius: '6px', cursor: salvandoSts[c.id] ? 'default' : 'pointer', opacity: salvandoSts[c.id] ? .5 : 1 }"
                  @click="toggleSts(c)"
                >{{ salvandoSts[c.id] ? '…' : statusTxt(c) }}</button>
              </td>

              <!-- orçamento (clicável: edita) -->
              <td v-else-if="col.key === 'orcamento'" style="padding:11px 12px;text-align:right;vertical-align:middle;white-space:nowrap;">
                <div v-if="c.id in editandoOrc" style="display:flex;align-items:center;gap:4px;justify-content:flex-end;">
                  <span style="font-size:11px;color:var(--c-text-faint);">R$</span>
                  <input
                    v-model="editandoOrc[c.id]" type="text" inputmode="decimal"
                    style="width:72px;background:var(--c-surface-0);border:1px solid var(--c-ai);border-radius:5px;padding:3px 6px;color:var(--c-text);font-family:inherit;font-size:12px;outline:none;text-align:right;"
                    @keydown.enter="salvarOrc(c)" @keydown.escape="delete editandoOrc[c.id]"
                  >
                  <button :disabled="salvandoOrc[c.id]" style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:11px;font-weight:700;padding:3px 7px;border-radius:5px;cursor:pointer;" @click="salvarOrc(c)">{{ salvandoOrc[c.id] ? '…' : 'OK' }}</button>
                  <button style="background:none;border:none;color:var(--c-text-faint);font-family:inherit;font-size:12px;cursor:pointer;padding:2px 4px;" @click="delete editandoOrc[c.id]">✕</button>
                </div>
                <div
                  v-else-if="orcDiario(c) && orcEditavel(c)"
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
              <td v-else style="padding:11px 12px;text-align:right;vertical-align:middle;font-variant-numeric:tabular-nums;">
                <span :style="{ color: cels[col.key].cor, fontWeight: cels[col.key].forte ? 700 : 400 }">{{ cels[col.key].txt }}</span>
                <div v-if="cels[col.key].sub" style="font-size:10px;color:var(--c-text-faint);margin-top:1px;">{{ cels[col.key].sub }}</div>
              </td>
            </template>
          </tr>

          <!-- totais -->
          <tr style="background:var(--c-bg-deepest);border-top:2px solid var(--c-surface-1);position:sticky;bottom:0;">
            <td
              v-for="col in colunas" :key="col.key"
              :style="{ padding: '10px 12px', textAlign: col.key === 'campanha' ? 'left' : 'right', fontVariantNumeric: 'tabular-nums', fontSize: col.key === 'campanha' ? '11px' : '12.5px', fontWeight: 800, color: rodape[col.key].cor || 'var(--c-text)' }"
            >
              <template v-if="!['status', 'orcamento'].includes(col.key)">
                {{ rodape[col.key].txt }}
                <div v-if="rodape[col.key].sub" style="font-size:10px;font-weight:600;color:var(--c-text-faint);">{{ rodape[col.key].sub }}</div>
              </template>
            </td>
          </tr>

          <!-- leads sem campanha correspondente -->
          <tr v-if="rodapeSemAtrib && semAtribuicao?.leads" style="background:var(--c-bg-deepest);border-top:1px solid var(--c-surface-1);">
            <td
              v-for="col in colunas" :key="col.key"
              :style="{ padding: '10px 12px', textAlign: col.key === 'campanha' ? 'left' : 'right', fontVariantNumeric: 'tabular-nums', fontSize: col.key === 'campanha' ? '11px' : '12.5px', fontWeight: 700, color: rodapeSemAtrib[col.key].cor || 'var(--c-text)' }"
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

        <div v-if="gastoForaDaLista() > 0.005" style="padding:10px 0;font-size:11px;color:var(--c-text-faint);white-space:normal;">
          Mais {{ brl(gastoForaDaLista()) }} gastos no período por campanhas que não estão na lista (arquivadas ou excluídas).
        </div>
      </div>
    </div>
  </div>
</template>
