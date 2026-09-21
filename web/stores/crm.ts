import { defineStore } from 'pinia'

// ----- Tipos -----
export type Screen =
  | 'chat' | 'pipeline' | 'tasks' | 'agenda'
  | 'contact' | 'contacts' | 'form' | 'mobile'

export interface Contact { id: number, name: string, phone: string | null, email: string | null, avatar: string | null, resource_name?: string | null }

export interface Msg {
  id?: number
  type: 'divider' | 'text' | 'voice' | 'file' | 'image' | 'video'
  label?: string | null
  isOut?: boolean
  status?: string | null // pending|sent|delivered|read|error (recibo da mensagem enviada)
  waId?: string | null // id da mensagem no WhatsApp (para citar/responder)
  replyTo?: string | null // wa_id da mensagem citada
  replyExcerpt?: string | null // trecho da mensagem citada (balão de citação)
  reaction?: string | null // emoji de reação na mensagem
  text?: string | null
  transcript?: string | null
  time?: string | null
  ts?: number | null
  dur?: string | null
  fileName?: string | null
  meta?: string | null
}

export interface Tag { label: string, color: string }
export interface Interaction { title: string, meta: string, color: string }
export interface Activity { id: number, type: string, title: string, body: string | null, occurred_at: string, user?: { id: number, name: string } | null }
export interface FollowUp { id: number, title: string, starts_at: string | null, column: string, ai_draft: string | null, ai_draft_at: string | null }
export interface QuickReply { id: number, label: string, text: string }

/** Template aprovado pela Meta — o único jeito de falar fora da janela de 24h. */
export interface WaTemplate { name: string, language: string, category: string, body: string, params: number }
export interface Stage { id?: number, key: string, name: string, color: string, goal?: string | null, wa_label_id?: string | null, position?: number }
// qualified: '1' qualificado, '0' desqualificado, 'sem' ainda não triado. Vazio = todos.
export type TabQual = '1' | '0' | 'sem'
// show_hidden: a tab é a LIXEIRA — mostra só as conversas excluídas, em vez das ativas.
export interface ChatTab { id: number, name: string, stages: string[], qualified?: TabQual[], show_hidden?: boolean, position?: number, chat?: number | null }

export interface Conversation {
  id: string // slug
  name: string
  nameSaved: boolean
  initials: string
  color: string
  avatar: string
  online: boolean
  statusText: string
  role: string
  /** Dono do negócio (users.id). O campo `responsible` é texto livre e legado. */
  ownerUserId: number | null
  dealValue: string
  dealUnit: string
  stage: string
  stageColor: string
  prob: number
  hot: boolean
  preview: string
  time: string
  lastMessageAt: string | null
  startedAt: number | null // ts da 1ª mensagem (data do 1º contato) — filtro de data do Funil
  unread: number
  archived: boolean
  autoReply: boolean
  /** Triagem: true = qualificado, false = desqualificado, null = ainda não triado. */
  qualified: boolean | null
  qualifiedReason: string
  /** A marca veio da IA e ainda não foi confirmada por gente. */
  qualifiedAuto: boolean
  /** Reprovado na triagem E sem nada que segure na tela (reunião / etapa avançada). Quem manda é o servidor. */
  triagemOculta: boolean
  /** "Excluída" pelo menu da lista: sai da tela sem apagar nada, e só a tab de lixeira a mostra. */
  excluida: boolean
  lastOut: boolean
  inMemory: boolean
  tags: Tag[]
  phone: string
  email: string
  company: string
  origin: string
  responsible: string
  segmento: string
  notes: string
  interactions: Interaction[]
  thread: Msg[]
  threadHasMore: boolean // há mensagens mais antigas no servidor (paginação da thread)
  waCloud: boolean // sai pela API oficial da Meta → vale a janela de 24h / templates
  // A próxima reunião de pé, ou null. Só vem no /full — a lista de conversas não carrega.
  meeting: { id: number, starts_at: string | null, title: string | null, meet_link: string | null } | null
}

export interface Deal {
  id: number, name: string, sub: string, value: string, tag: string
  stage: string, hot: boolean, won: boolean, tagStrong: boolean, position: number
}
export interface Task {
  id: number, title: string, description: string, client: string, priority: 'baixa' | 'media' | 'alta'
  due: string, type: string, column: string, position: number
}
export interface CalEvent {
  id: string
  title: string
  description?: string | null
  location?: string | null
  starts_at: string | null
  ends_at: string | null
  all_day?: boolean
  html_link?: string | null
  hangout_link?: string | null
  attendees?: { email: string, name?: string | null, response?: string, organizer?: boolean }[]
  add_meet?: boolean
  attended?: boolean // cliente comprovadamente compareceu (Meet API) → destaque na agenda
  no_show?: boolean // presença apurada e o cliente NÃO compareceu → vermelho na agenda
  checked?: boolean // presença já apurada pelo servidor (senão: aguardando apuração)
  summary?: string | null // resumo da reunião (read.ai), se houver
  reminder_sent?: boolean // lembrete de WhatsApp já enviado ao cliente
  conversation_slug?: string | null // lead vinculado à reunião (abre a ficha ao clicar no card)
  conversation_name?: string | null
  deal_id?: number | null
  task_id?: number | null
  // De qual agenda da empresa o evento veio — define a cor do card e em qual conta Google
  // ele é editado/excluído (mandar o owner_id errado dá 404 no Google).
  owner_id?: number
  owner_name?: string
  owner_email?: string | null
  owner_color?: string
}
/** Uma agenda da empresa = o Google Agenda de um usuário. */
export interface CalendarLayer {
  user_id: number
  name: string
  email: string | null
  color: string
  is_me: boolean
  // Recebe reunião NOVA? (liga/desliga do dia). Diferente de estar visível na grade:
  // a agenda de quem está de folga continua aparecendo, só não entra no rodízio.
  agenda_ativa: boolean
  is_host: boolean
}
export interface Column<T> { id: string, title: string, dot: string, money?: boolean, cards: T[] }

type Board = 'pipeline' | 'tasks'
// kind distingue card de conversa (id = slug string) de card de negócio (id numérico).
interface DragRef { board: Board, from: string, id: number | string, kind?: 'conv' | 'deal' }

// ----- Metadados fixos das colunas -----
// Cores de STATUS/etapa/prioridade são DADO (usadas como `${cor}22` p/ tint e como
// borda) e legíveis nos dois temas — ficam em hex, não em token de tema.
const DEFAULT_STAGES: Stage[] = [
  { key: 'novo', name: 'Novo lead', color: '#53bdeb' },
  { key: 'contato', name: 'Contato feito', color: '#7c6cf5' },
  { key: 'proposta', name: 'Proposta enviada', color: '#3aa6ff' },
  { key: 'negociacao', name: 'Negociação', color: '#ffb443' },
  { key: 'fechado', name: 'Fechado', color: '#25D366' },
]
const TASK_COLS = [
  { id: 'todo', title: 'A fazer', dot: '#8696a0' },
  { id: 'doing', title: 'Em andamento', dot: '#53bdeb' },
  { id: 'review', title: 'Em revisão', dot: '#ffb443' },
  { id: 'done', title: 'Concluído', dot: '#25D366' },
]

// ----- Helpers -----
export function prioMeta(k: string) {
  return ({
    alta: { label: 'Alta', color: '#ff6b6b' },
    media: { label: 'Média', color: '#ffb443' },
    baixa: { label: 'Baixa', color: '#53bdeb' },
  } as Record<string, { label: string, color: string }>)[k] || { label: 'Média', color: '#ffb443' }
}
function fmtK(n: number) {
  return n >= 1000 ? `R$ ${(n / 1000).toFixed(1).replace('.', ',')}k` : `R$ ${n}`
}
export function sumCol(col: Column<Deal>) {
  const n = col.cards.reduce((a, c) => a + (parseInt(String(c.value || '').replace(/[^\d]/g, ''), 10) || 0), 0)
  return fmtK(n)
}
function agora() {
  return new Date().toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}
function sortByPos<T extends { position: number, id: number }>(a: T, b: T) {
  return a.position - b.position || a.id - b.id
}

// Cor única por conversa (HSL derivado do identificador) — avatares variados na lista.
function colorForId(id: string): string {
  let h = 0
  for (let i = 0; i < id.length; i++) h = (h * 31 + id.charCodeAt(i)) % 360
  return `hsl(${h}, 60%, 50%)`
}

// Telefone com máscara (ex.: +55 41 99657-8036). Vazio se não for um número reconhecível.
export function maskPhone(raw: string): string {
  const d = (raw || '').replace(/\D/g, '')
  if (!d) return ''
  if (d.startsWith('55') && (d.length === 12 || d.length === 13)) {
    const ddd = d.slice(2, 4)
    const n = d.slice(4)
    const num = n.length === 9 ? `${n.slice(0, 5)}-${n.slice(5)}` : n.length === 8 ? `${n.slice(0, 4)}-${n.slice(4)}` : n
    return `+55 ${ddd} ${num}`
  }
  if (d.length >= 10 && d.length <= 11) { // DDD + número, sem código do país
    const ddd = d.slice(0, 2)
    const n = d.slice(2)
    const num = n.length === 9 ? `${n.slice(0, 5)}-${n.slice(5)}` : `${n.slice(0, 4)}-${n.slice(4)}`
    return `+55 ${ddd} ${num}`
  }
  return `+${d}` // outro país/formato — ao menos não mostra o id cru
}

// Carimbo de data/hora da lista de conversas, estilo WhatsApp:
// hoje → HH:mm | ontem → "ontem" | últimos 6 dias → dia da semana | mais antigo → DD/MM/AAAA.
// Recebe o ISO de last_message_at; cai no horário cru (timeStr) se não houver data.
const WEEKDAYS_PT = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado']
export function fmtListTime(lastMessageAt: string | null | undefined, timeStr: string): string {
  if (!lastMessageAt) return timeStr
  // Sem sufixo de fuso, o servidor já manda no horário local (America/Sao_Paulo).
  const d = new Date(String(lastMessageAt).replace(' ', 'T'))
  if (Number.isNaN(d.getTime())) return timeStr

  const now = new Date()
  const startOf = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime()
  const diffDays = Math.round((startOf(now) - startOf(d)) / 86400000)

  if (diffDays <= 0) return timeStr || d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
  if (diffDays === 1) return 'ontem'
  if (diffDays <= 6) return WEEKDAYS_PT[d.getDay()]
  return d.toLocaleDateString('pt-BR') // DD/MM/AAAA
}

// Temperatura do lead: automática pelo tempo sem interação (última mensagem da conversa),
// com o 🔥 manual fixando como Quente. "awaiting" = última msg foi do lead (devemos resposta).
// Limites: Quente ≤2d · Morno 3-5d · Frio >5d.
export interface LeadTemp { key: 'quente' | 'morno' | 'frio', label: string, color: string, days: number, awaiting: boolean }
export function leadTemperature(c: { lastMessageAt?: string | null, lastOut?: boolean, hot?: boolean }): LeadTemp {
  const awaiting = c.lastOut === false // última mensagem foi do lead → aguardando nossa resposta
  let days = 999
  if (c.lastMessageAt) {
    const d = new Date(String(c.lastMessageAt).replace(' ', 'T'))
    if (!Number.isNaN(d.getTime())) days = Math.max(0, Math.floor((Date.now() - d.getTime()) / 86400000))
  }
  const key: 'quente' | 'morno' | 'frio' = c.hot || days <= 2 ? 'quente' : days <= 5 ? 'morno' : 'frio'
  const meta = {
    quente: { label: 'Quente', color: '#ff5a3c' },
    morno: { label: 'Morno', color: '#ffb443' },
    frio: { label: 'Frio', color: '#53bdeb' },
  }[key]
  return { key, label: meta.label, color: meta.color, days, awaiting }
}

// Rótulo curto de tempo desde a última interação (ex.: "hoje", "há 3d").
export function sinceLabel(days: number): string {
  if (days >= 999) return 'sem msg'
  if (days <= 0) return 'hoje'
  if (days === 1) return 'ontem'
  return `há ${days}d`
}

// Iniciais a partir do nome (1-2 letras).
function initialsOf(name: string): string {
  const parts = (name || '').trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '#'
  return ((parts[0][0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
}

/**
 * A foto do contato sai pela NOSSA API, nunca pelo link do WhatsApp.
 *
 * O `pps.whatsapp.net` que vem no campo `avatar` tem prazo de validade (`oe=`) e passa a
 * responder 403 depois de alguns dias — apontar o `<img>` direto para ele fazia a foto de
 * todo mundo sumir com o tempo. O endpoint serve o binário que guardamos em disco; o campo
 * do banco vira só o sinal de que existe foto. Quando não existe, fica vazio e a tela cai
 * no avatar de iniciais, como já fazia.
 */
function avatarUrl(c: any): string {
  if (!c?.avatar || !c?.slug) return ''
  const base = useRuntimeConfig().public.apiOrigin as string
  return `${base}/api/conversations/${encodeURIComponent(c.slug)}/avatar`
}

// Última mensagem foi enviada por nós? (mensagens vêm ordenadas por ts ascendente).
function lastIsOut(messages: any[] | undefined): boolean {
  if (!messages || !messages.length) return false
  return !!messages[messages.length - 1].is_out
}

// Mapeia o snake_case da API para o domínio camelCase do frontend.
function mapConv(c: any): Conversation {
  const raw = (c.name ?? '').trim()
  // Nome "automático" = só dígitos/@ (número cru ou id @lid). Nome real tem letras.
  const isAuto = raw === '' || /^\+?[\d]+$/.test(raw) || raw.includes('@') || raw === 'Contato WhatsApp'
  const nameSaved = !isAuto
  let display = raw
  if (isAuto) {
    const masked = c.phone ? maskPhone(c.phone) : ''
    display = masked || 'Contato WhatsApp'
  }
  return {
    id: c.slug, name: display, nameSaved, initials: nameSaved ? initialsOf(display) : (c.initials || '#'), color: colorForId(c.slug || c.name || String(c.id)), avatar: avatarUrl(c), online: !!c.online,
    statusText: c.status_text ?? '', role: c.role ?? '', ownerUserId: c.owner_user_id ?? null, dealValue: c.deal_value ?? '', dealUnit: c.deal_unit ?? '',
    stage: c.stage ?? '', stageColor: c.stage_color ?? '#8696a0', prob: c.prob ?? 0, hot: !!c.hot,
    preview: c.preview ?? '', time: c.time ?? '', lastMessageAt: c.last_message_at ?? null, startedAt: c.started_ts ?? null, unread: c.unread ?? 0, archived: !!c.archived, autoReply: !!c.auto_reply, lastOut: c.last_message ? !!c.last_message.is_out : lastIsOut(c.messages), inMemory: !!c.in_memory, tags: c.tags ?? [],
    // `?? null` e não `!!`: "sem triagem" é um estado de verdade, diferente de desqualificado.
    qualified: c.qualified === null || c.qualified === undefined ? null : !!c.qualified,
    qualifiedReason: c.qualified_reason ?? '', qualifiedAuto: !!c.qualified_auto, triagemOculta: !!c.triagem_oculta, excluida: !!c.hidden_at,
    phone: c.phone ?? '', email: c.email ?? '', company: c.company ?? '', origin: c.origin ?? '', responsible: c.responsible ?? '',
    segmento: c.segmento ?? '', notes: c.notes ?? '',
    interactions: c.interactions ?? [],
    thread: (c.messages ?? []).map(mapMsg),
    threadHasMore: !!c.messages_has_more,
    waCloud: !!c.wa_cloud, // só vem no /full e no /wpp/start (a lista não carrega a conta)
    meeting: c.meeting ?? null, // idem: só no /full
  }
}
function mapMsg(m: any): Msg {
  return { id: m.id, type: m.type, isOut: !!m.is_out, status: m.status ?? null, waId: m.wa_id ?? null, replyTo: m.reply_to ?? null, replyExcerpt: m.reply_excerpt ?? null, reaction: m.reaction ?? null, text: m.text, transcript: m.transcript ?? null, time: m.time, ts: m.ts ?? null, dur: m.dur, fileName: m.file_name, meta: m.meta, label: m.label }
}
function mapDeal(d: any): Deal {
  return { id: d.id, name: d.name, sub: d.sub ?? '', value: d.value ?? '', tag: d.tag ?? '', stage: d.stage, hot: !!d.hot, won: !!d.won, tagStrong: !!d.tag_strong, position: d.position ?? 0 }
}
function mapTask(t: any): Task {
  return { id: t.id, title: t.title, description: t.description ?? '', client: t.client ?? '', priority: t.priority, due: t.due ?? '', type: t.type ?? '', column: t.column, position: t.position ?? 0 }
}

let dragRef: DragRef | null = null
const fullLoaded = new Set<string>()
const loadingOlder = new Set<string>() // paginação de histórico em voo, por conversa
const patchingConv = new Set<string>() // patch de linha única em voo, por conversa

// Mescla mensagens novas na thread sem duplicar: dedup por id/waId e "adoção" da
// bolha otimista (enviada por nós, ainda sem id) quando o eco do servidor chega.
// Insere na posição cronológica (ts) — push cego bagunçava a ordem quando um delta
// chegava atrasado. Otimista sem ts conta como "agora" (fim da lista).
function mergeIncoming(conv: Conversation, msgs: Msg[]) {
  for (const m of msgs) {
    if (m.id && conv.thread.some(x => x.id === m.id)) continue
    if (m.waId && conv.thread.some(x => x.waId === m.waId)) continue
    const opt = m.isOut
      ? conv.thread.find(x => !x.id && x.isOut && x.type === m.type && (x.text ?? '') === (m.text ?? ''))
      : undefined
    if (opt) {
      Object.assign(opt, m)
      continue
    }
    let i = conv.thread.length
    const mts = m.ts ?? Number.POSITIVE_INFINITY
    while (i > 0 && (conv.thread[i - 1].ts ?? Number.POSITIVE_INFINITY) > mts) i--
    conv.thread.splice(i, 0, m)
  }
}

// Mapa de "tela" -> rota real (navegação por vue-router).
export const SCREEN_ROUTES: Record<Screen, string> = {
  chat: '/',
  pipeline: '/funil',
  tasks: '/tarefas',
  agenda: '/agenda',
  contact: '/contato',
  contacts: '/contatos',
  form: '/solicitar',
  mobile: '/mobile',
}

// Cliente HTTP autenticado (Sanctum cookie + CSRF). Caminhos relativos /api/...
function api() {
  return useApi()
}

export const useCrmStore = defineStore('crm', {
  state: () => ({
    screen: 'chat' as Screen,
    activeId: '',
    // No celular a lista e o chat ocupam a tela toda alternadamente. Este flag diz se o
    // usuário ABRIU uma conversa (vê o chat) ou está na lista. No desktop é ignorado.
    chatOpen: false,
    typing: false,
    loading: true,
    threadError: false,
    connection: 'online' as 'online' | 'offline',
    conversations: [] as Conversation[],
    dealList: [] as Deal[],
    taskList: [] as Task[],
    quickReplies: [] as QuickReply[],
    labels: [] as { id: number, name: string, color: string }[],
    stages: [...DEFAULT_STAGES] as Stage[],
    chatTabs: [] as ChatTab[],
    contacts: [] as Contact[],
    dragOverCol: null as string | null,
    formType: 'Novo recurso',
    formPriority: 'media' as 'baixa' | 'media' | 'alta',
    formSubmitted: false,
    formError: false,
    aiSuggestion: '',
    aiLoading: false,
    // Janela de 24h da API oficial: quando fecha, o WhatsApp só aceita template aprovado.
    // Números não-oficiais (Evolution) nunca entram nesse estado.
    templatePanel: false,
    templates: [] as WaTemplate[],
    templatesLoading: false,
    templatesFetched: false, // a lista do menu lateral já foi buscada nesta sessão?
    templateError: '',
    // ----- Google Agenda -----
    googleConnected: false, // a conta Google DO USUÁRIO logado (só controla o botão "Conectar")
    googleEmail: null as string | null,
    // Agendas da empresa: uma camada por usuário com Google conectado (estilo Google Agenda).
    calendars: [] as CalendarLayer[],
    hiddenCalendars: [] as number[], // agendas desmarcadas no seletor (só na tela)
    events: [] as CalEvent[],
    eventsLoading: false,
    lastEventsRange: null as { from: string, to: string } | null, // p/ recarregar a agenda em tempo real
    // ----- Linha do tempo do lead aberto na ficha -----
    activities: [] as Activity[],
    activitiesConvId: null as string | null,
    activitiesLoading: false,
    // ----- Indicadores do dia (painel do funil) -----
    newLeadsToday: 0,
    newLeadsList: [] as { slug: string, name: string, time: string }[],
    // ----- Follow-ups do lead aberto na ficha -----
    followups: [] as FollowUp[],
    followupsConvId: null as string | null,
    // Leads reprovados na triagem já foram buscados? Eles não vêm na carga normal.
    desqualificadosCarregados: false,
    desqualificadosCarregando: false,
    // Idem para as conversas excluídas (lixeira).
    excluidasCarregadas: false,
    excluidasCarregando: false,
  }),

  getters: {
    // O fallback tem de ser o primeiro VISÍVEL: sem conversa escolhida, abrir um lead que
    // a triagem tirou da tela seria abrir justamente o que ninguém pediu para ver.
    activeConv(s): Conversation | undefined {
      return s.conversations.find(c => c.id === s.activeId) || s.conversations.find(c => !c.triagemOculta && !c.excluida)
    },
    /**
     * Conversas que a operação enxerga. Lead DESQUALIFICADO some da tela igual a conversa
     * excluída — só que sem apagar nem congelar nada: a IA continua atendendo, triando e
     * retomando, e no dia em que o veredito virar qualificado (ou voltar a "sem triagem")
     * ele reaparece sozinho, porque a régua é o campo, não uma cópia da lista.
     *
     * Filtra por `triagemOculta`, não por `qualified`: quem já andou no funil ou tem reunião
     * marcada continua na tela mesmo reprovado (a conta é do servidor, e a tela não tem como
     * refazê-la — reunião não viaja na linha da lista).
     *
     * Quem precisa vê-los mesmo assim (tab "Desqualificados", conversa aberta no chat) lê
     * `conversations` direto — este getter é o padrão de tudo que é "a lista".
     */
    visiveis(s): Conversation[] {
      return s.conversations.filter(c => !c.triagemOculta && !c.excluida)
    },
    pipeline(s): Column<Deal>[] {
      return s.stages.map(col => ({
        id: col.key, title: col.name, dot: col.color, money: true,
        cards: s.dealList.filter(d => d.stage === col.key).slice().sort(sortByPos),
      }))
    },
    tasks(s): Column<Task>[] {
      return TASK_COLS.map(col => ({
        ...col,
        cards: s.taskList.filter(t => t.column === col.id).slice().sort(sortByPos),
      }))
    },
    // A Agenda funciona quando a EMPRESA tem pelo menos uma conta Google conectada —
    // não é preciso cada usuário conectar a sua para ver as reuniões marcadas.
    hasCalendars: s => s.calendars.length > 0,
    // Eventos das agendas marcadas no seletor. Filtra na tela (sem ida ao servidor) para
    // ligar/desligar uma agenda ser instantâneo.
    visibleEvents(s): CalEvent[] {
      if (!s.hiddenCalendars.length) return s.events
      return s.events.filter(e => e.owner_id == null || !s.hiddenCalendars.includes(e.owner_id))
    },
    listReady: s => !s.loading,
    threadReady: s => !s.loading && !s.threadError,
    isOffline: s => s.connection === 'offline',
  },

  actions: {
    async init() {
      this.loading = true
      const client = api()
      // CRÍTICO p/ a lista de chat aparecer: conversas + estágios + abas.
      // O resto (deals/tasks/quick-replies/contatos) carrega em background depois,
      // sem travar a tela — antes as 7 chamadas bloqueavam o render juntas.
      try {
        const [convs, stages, tabs] = await Promise.all([
          client<any[]>(`/api/conversations`),
          client<Stage[]>(`/api/stages`),
          client<ChatTab[]>(`/api/chat-tabs`),
        ])
        this.conversations = convs.map(mapConv)
        if (stages.length) this.stages = stages
        this.chatTabs = (tabs || []).map(t => ({ ...t, stages: t.stages || [], qualified: t.qualified || [] }))
        this.connection = 'online'
        if (!this.conversations.find(c => c.id === this.activeId))
          this.activeId = this.conversations[0]?.id ?? ''
      }
      catch {
        this.connection = 'offline'
      }
      finally {
        this.loading = false
      }

      // SECUNDÁRIO: em background, não bloqueia a lista.
      client<any[]>(`/api/deals`).then(d => { this.dealList = d.map(mapDeal) }).catch(() => {})
      client<any[]>(`/api/tasks`).then(t => { this.taskList = t.map(mapTask) }).catch(() => {})
      client<QuickReply[]>(`/api/quick-replies`).then((q) => { this.quickReplies = q }).catch(() => {})
      // Contatos nomeiam conversas: ao chegar, re-linka os nomes (número → nome real).
      client<Contact[]>(`/api/contacts`).then((c) => { this.contacts = c || []; this.linkContactNames() }).catch(() => {})
    },

    go(screen: Screen) {
      this.screen = screen
      return navigateTo(SCREEN_ROUTES[screen] ?? '/')
    },

    // Aplica conversas do servidor SEM "piscar" a thread aberta: reaproveita os
    // objetos existentes e preserva mensagens otimistas (sem id) que o servidor
    // ainda não confirmou — evita a mensagem enviada sumir e voltar.
    applyConversations(raw: any[]) {
      const byId = new Map(this.conversations.map(c => [c.id, c]))
      let activeNewMsg = false
      // O payload do servidor NÃO traz os escondidos pela triagem nem os excluídos. Quem já
      // os tinha em memória (abriu a tab de reprovados ou a lixeira, está com um deles aberto
      // no chat) não pode perdê-los no primeiro refresh — sumiriam da tab e a conversa aberta
      // ficaria órfã. Sai da lista quem o servidor deixou de mandar por OUTRO motivo
      // (ex.: excluída em outra aba, que chega aqui sem a marca local).
      const vindos = new Set(raw.map((r: any) => r.slug))
      const foraDoPayload = this.conversations.filter(c => (c.triagemOculta || c.excluida) && !vindos.has(c.id))
      this.conversations = raw.map((r) => {
        const mapped = mapConv(r)
        const existing = byId.get(mapped.id)
        if (!existing) return mapped
        // A lista não traz mais a thread (só a última msg) nem o canal; preserva o que já
        // está carregado — senão a conversa aberta "esquece" que é da API oficial.
        Object.assign(existing, mapped, { thread: existing.thread, threadHasMore: existing.threadHasMore, waCloud: existing.waCloud, meeting: existing.meeting })
        // Conversa aberta recebeu mensagem nova? (última do servidor != última carregada)
        if (existing.id === this.activeId && r.last_message) {
          const lastLoaded = [...existing.thread].reverse().find(o => o.id)
          if (!lastLoaded || lastLoaded.id !== r.last_message.id) activeNewMsg = true
        }
        return existing
      })
      this.conversations.push(...foraDoPayload)
      this.linkContactNames()
      // Mantém a conversa aberta em dia buscando só o DELTA (mensagens após a última carregada).
      if (activeNewMsg && this.activeId) this.syncThread(this.activeId)
    },

    // Atualiza a lista/threads (polling — mensagens novas em tempo real).
    async refresh() {
      try {
        const convs = await api()<any[]>('/api/conversations')
        this.applyConversations(convs)
      }
      catch { /* silencioso */ }
    },

    // Fallback global (polling lento + eventos sem rota incremental): conversas + negócios.
    // Recibos/mensagens da conversa aberta chegam pelos eventos message.new/message.patch,
    // então aqui NÃO se re-baixa a thread.
    async refreshBoards() {
      try {
        const [convs, deals] = await Promise.all([
          api()<any[]>('/api/conversations'),
          api()<any[]>('/api/deals'),
        ])
        this.applyConversations(convs)
        this.dealList = deals.map(mapDeal)
        this.connection = 'online'
      }
      catch { this.connection = 'offline' }
    },

    async refreshDeals() {
      try {
        const deals = await api()<any[]>('/api/deals')
        this.dealList = deals.map(mapDeal)
      }
      catch { /* silencioso */ }
    },

    // Indicadores do dia (leads novos que mandaram msg hoje) — para o painel do funil.
    async loadTodayStats() {
      try {
        const r = await api()<{ new_leads: number, leads: { slug: string, name: string, time: string }[] }>('/api/stats/today')
        this.newLeadsToday = r.new_leads
        this.newLeadsList = r.leads || []
      }
      catch { /* mantém o último valor */ }
    },

    // Carrega a ÚLTIMA página da thread (o histórico anterior pagina sob demanda).
    async loadFullThread(id: string, force = false) {
      if (!force && fullLoaded.has(id)) return
      fullLoaded.add(id)
      try {
        const c = await api()<any>(`/api/conversations/${id}/full`)
        const conv = this.conversations.find(x => x.id === id)
        if (conv) {
          // Preserva o que chegou DURANTE o voo do /full: otimistas (sem id) e
          // mensagens empurradas pelo websocket que a resposta ainda não trazia.
          const prev = conv.thread
          conv.thread = (c.messages ?? []).map(mapMsg)
          conv.threadHasMore = !!c.messages_has_more
          conv.waCloud = !!c.wa_cloud
          conv.meeting = c.meeting ?? null
          const newest = conv.thread.length ? (conv.thread[conv.thread.length - 1].ts ?? 0) : 0
          mergeIncoming(conv, prev.filter(m => !m.id || (m.ts ?? 0) >= newest))
          // Conversa nova no canal oficial: não existe janela de 24h para abrir texto
          // livre, então já traz a lista de templates em vez de deixar o envio falhar.
          if (conv.waCloud && !conv.thread.length && this.activeId === id) this.openTemplates()
        }
      }
      catch { if (!force) fullLoaded.delete(id) }
    },

    // "Carregar anteriores": busca a página mais antiga que a 1ª mensagem carregada.
    async loadOlderMessages(id: string): Promise<boolean> {
      const conv = this.conversations.find(x => x.id === id)
      const first = conv?.thread.find(m => m.id)
      if (!conv || !first || loadingOlder.has(id)) return false
      loadingOlder.add(id)
      try {
        const qs = `before_id=${first.id}${first.ts != null ? `&before_ts=${first.ts}` : ''}&limit=150`
        const r = await api()<{ messages: any[], has_more: boolean }>(`/api/conversations/${id}/messages?${qs}`)
        conv.thread = [...r.messages.map(mapMsg), ...conv.thread]
        conv.threadHasMore = !!r.has_more
        return r.messages.length > 0
      }
      catch { return false }
      finally { loadingOlder.delete(id) }
    },

    // Delta da thread: só as mensagens DEPOIS da última carregada (troca de chat instantânea).
    async syncThread(id: string) {
      const conv = this.conversations.find(x => x.id === id)
      if (!conv) return
      const last = [...conv.thread].reverse().find(m => m.id && m.ts != null)
      if (!last) return this.loadFullThread(id, true)
      try {
        const r = await api()<{ messages: any[] }>(`/api/conversations/${id}/messages?after_ts=${last.ts}&after_id=${last.id}&limit=500`)
        mergeIncoming(conv, r.messages.map(mapMsg))
      }
      catch { /* silencioso */ }
    },

    // ----- Tempo real com payload (WebSocket) -----
    // message.new: a mensagem + a linha da conversa chegam NO evento — patch direto, zero refetch.
    applyRealtimeMessage(e: any) {
      const row = e?.conversation
      if (!row?.slug) return
      let conv = this.conversations.find(c => c.id === row.slug)
      if (!conv) {
        conv = mapConv(row)
        this.conversations.unshift(conv)
      }
      else {
        Object.assign(conv, mapConv(row), { thread: conv.thread, threadHasMore: conv.threadHasMore, waCloud: conv.waCloud, meeting: conv.meeting })
        // Conversa com mensagem nova sobe pro topo (mesma ordem do servidor).
        const idx = this.conversations.indexOf(conv)
        if (idx > 0) {
          this.conversations.splice(idx, 1)
          this.conversations.unshift(conv)
        }
      }
      if (e?.message && (conv.thread.length || conv.id === this.activeId))
        mergeIncoming(conv, [mapMsg(e.message)])
      // Chat aberto NESSA conversa e aba realmente à vista: marca lida. Aba parada
      // em segundo plano NÃO pode zerar o não-lido (o time inteiro perderia o aviso).
      if (conv.id === this.activeId && this.screen === 'chat' && this.chatOpen && conv.unread > 0
        && typeof document !== 'undefined' && document.visibilityState === 'visible' && document.hasFocus()) {
        this.markRead(conv.id)
      }
      this.linkContactNames()
    },

    // Zera o não-lido (ação explícita do usuário ou aba visível com o chat aberto).
    markRead(id: string) {
      const c = this.conversations.find(x => x.id === id)
      if (!c || c.unread === 0) return
      c.unread = 0
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { unread: 0 } }).catch(() => {})
    },

    // message.patch: recibo/reação/transcrição/remoção aplicados na bolha certa.
    applyMessagePatch(e: any) {
      const conv = this.conversations.find(c => c.id === e?.slug)
      if (!conv) return
      const m = conv.thread.find(x => (e.id && x.id === e.id) || (e.wa_id && x.waId === e.wa_id))
      if (!m) return
      if (e.removed) {
        conv.thread.splice(conv.thread.indexOf(m), 1)
        return
      }
      if ('status' in e && e.status != null) m.status = e.status
      if ('reaction' in e) m.reaction = e.reaction ?? null
      if ('transcript' in e && e.transcript != null) m.transcript = e.transcript
    },

    // Evento genérico de conversa (etapa/nome/ficha mudou em outra aba): busca SÓ essa linha.
    async patchConversationFromServer(slug: string) {
      if (patchingConv.has(slug)) return
      patchingConv.add(slug)
      try {
        const row = await api()<any>(`/api/conversations/${slug}`)
        const conv = this.conversations.find(c => c.id === slug)
        // waCloud não vem na linha da lista: preserva o que o /full já descobriu.
        if (conv) Object.assign(conv, mapConv(row), { thread: conv.thread, threadHasMore: conv.threadHasMore, waCloud: conv.waCloud, meeting: conv.meeting })
        else this.conversations.unshift(mapConv(row))
        this.linkContactNames()
      }
      catch (e: any) {
        // 404 = a conversa foi apagada em outra aba — remove da lista local.
        if (e?.status === 404 || e?.statusCode === 404)
          this.conversations = this.conversations.filter(c => c.id !== slug)
      }
      finally { patchingConv.delete(slug) }
    },

    selectConv(id: string) {
      this.activeId = id
      this.chatOpen = true
      this.threadError = false
      this.aiSuggestion = ''
      this.templatePanel = false // o painel é da conversa anterior
      const c = this.conversations.find(x => x.id === id)
      // Thread em cache aparece NA HORA; só o delta vem da rede. Sem cache, carrega a última página.
      if (c && c.thread.some(m => m.id)) this.syncThread(id)
      else this.loadFullThread(id)
      if (c && c.unread > 0) {
        c.unread = 0
        api()(`/api/conversations/${id}`, { method: 'PATCH', body: { unread: 0 } }).catch(() => {})
      }
    },
    retryThread() {
      this.threadError = false
      this.loading = true
      setTimeout(() => { this.loading = false }, 600)
    },

    markUnread(id: string) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      c.unread = Math.max(1, c.unread)
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { unread: c.unread } }).catch(() => {})
    },
    toggleArchive(id: string) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      c.archived = !c.archived
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { archived: c.archived } }).catch(() => {})
    },

    /**
     * "Exclui" a conversa — some da lista, mas o histórico fica no banco e ela volta
     * sozinha se o contato mandar mensagem nova. Devolve o que é preciso para o Desfazer.
     * Some na hora (otimista) e a marca é desfeita se o servidor recusar.
     *
     * MARCA em vez de tirar do array: a tab de lixeira lê da mesma lista, então quem acabou
     * de excluir vê a conversa aparecer lá na hora — tirar daqui obrigaria a rebuscar tudo.
     */
    async deleteConversation(id: string): Promise<{ ok: boolean, name: string }> {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return { ok: false, name: '' }
      c.excluida = true
      if (this.activeId === id) this.activeId = this.visiveis[0]?.id ?? ''
      try {
        await api()(`/api/conversations/${id}`, { method: 'DELETE' })
        return { ok: true, name: c.name }
      }
      catch {
        c.excluida = false
        return { ok: false, name: c.name }
      }
    },

    /** Desfaz o excluir. A linha volta do servidor porque a exclusão pode ser de outra aba. */
    async restoreConversation(id: string): Promise<boolean> {
      try {
        const r = await api()<any>(`/api/conversations/${id}/restore`, { method: 'POST' })
        const c = this.conversations.find(x => x.id === r.slug)
        if (c) Object.assign(c, mapConv(r), { thread: c.thread, threadHasMore: c.threadHasMore, waCloud: c.waCloud, meeting: c.meeting })
        else this.conversations.push(mapConv(r))
        return true
      }
      catch {
        return false
      }
    },
    /**
     * Busca os leads reprovados na triagem — eles ficam fora da carga normal (somem da
     * tela como conversa excluída) e só descem quando alguém pede para revê-los.
     * Uma vez só por sessão: dali em diante o tempo real cuida deles como das outras.
     */
    async carregarDesqualificados() {
      if (this.desqualificadosCarregados || this.desqualificadosCarregando) return
      this.desqualificadosCarregando = true
      try {
        const raw = await api()<any[]>('/api/conversations?desqualificados=1')
        const existentes = new Set(this.conversations.map(c => c.id))
        this.conversations.push(...raw.filter(r => !existentes.has(r.slug)).map(mapConv))
        this.desqualificadosCarregados = true
        this.linkContactNames()
      }
      catch { /* silencioso: a tab só fica vazia */ }
      finally { this.desqualificadosCarregando = false }
    },
    /**
     * Busca as conversas EXCLUÍDAS (a lixeira). Nada foi apagado — elas só ganharam
     * `hidden_at` e saíram da tela; a tab de lixeira é o caminho de volta.
     */
    async carregarExcluidas() {
      if (this.excluidasCarregadas || this.excluidasCarregando) return
      this.excluidasCarregando = true
      try {
        const raw = await api()<any[]>('/api/conversations?excluidas=1')
        const existentes = new Set(this.conversations.map(c => c.id))
        this.conversations.push(...raw.filter(r => !existentes.has(r.slug)).map(mapConv))
        this.excluidasCarregadas = true
        this.linkContactNames()
      }
      catch { /* silencioso: a tab só fica vazia */ }
      finally { this.excluidasCarregando = false }
    },
    // Liga/desliga o atendimento automático (a IA responde o lead sozinha).
    toggleAutoReply(id: string) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      c.autoReply = !c.autoReply
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { auto_reply: c.autoReply } }).catch(() => { c.autoReply = !c.autoReply })
    },
    /**
     * Triagem do lead. Cicla qualificado → desqualificado → sem triagem.
     *
     * Marcar à mão apaga o "auto": a partir daí a IA não mexe mais nesta conversa, então
     * o chip para de mudar sozinho debaixo de quem acabou de decidir.
     */
    async setQualified(id: string, value: boolean | null) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      const antes = { q: c.qualified, motivo: c.qualifiedReason, auto: c.qualifiedAuto }
      c.qualified = value
      c.qualifiedAuto = false
      if (value === null) c.qualifiedReason = ''
      try {
        await api()(`/api/conversations/${id}`, { method: 'PATCH', body: { qualified: value } })
        // Só o servidor sabe se o lead sai da tela: a conta olha reunião e etapa, que a
        // linha da lista não carrega. O broadcast do PATCH é `toOthers`, então esta aba
        // ficaria com o chip novo e a visibilidade velha se não buscasse a linha aqui.
        await this.patchConversationFromServer(id)
      }
      catch { c.qualified = antes.q; c.qualifiedReason = antes.motivo; c.qualifiedAuto = antes.auto }
    },
    /** Próximo estado do ciclo do chip de triagem. */
    cycleQualified(id: string) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      return this.setQualified(id, c.qualified === null ? true : (c.qualified ? false : null))
    },
    async memorize(id: string) {
      const c = this.conversations.find(x => x.id === id)
      await api()(`/api/conversations/${id}/memorize`, { method: 'POST' })
      if (c) c.inMemory = true
    },

    // Sugestão de resposta gerada pelo Claude (assinatura) com base na conversa ativa.
    async suggestReply(instruction?: string) {
      const conv = this.activeConv
      if (!conv || this.aiLoading) return
      this.aiLoading = true
      const previous = this.aiSuggestion
      if (!instruction) this.aiSuggestion = ''
      try {
        const r = await api()<{ suggestion: string }>(`/api/conversations/${conv.id}/suggest-reply`, {
          method: 'POST',
          body: instruction ? { instruction, previous } : {},
        })
        this.aiSuggestion = r.suggestion || ''
      }
      catch {
        if (!instruction) this.aiSuggestion = ''
      }
      finally {
        this.aiLoading = false
      }
    },
    async saveRule(instruction: string) {
      await api()('/api/memory/rules', { method: 'POST', body: { instruction } })
    },
    // Agendar reunião: a IA lê a conversa, vê a agenda e marca (ou sugere horário).
    async scheduleMeeting(id: string) {
      return await api()<{ scheduled: boolean, message: string, slot_label?: string, note?: string, event?: any }>(
        `/api/conversations/${id}/schedule-meeting`,
        { method: 'POST' },
      )
    },

    send(text: string, reply?: { waId?: string | null, excerpt?: string | null }) {
      const t = text.trim()
      const conv = this.activeConv
      if (!t || !conv) return
      const time = agora()
      const optimistic: Msg = { type: 'text', isOut: true, status: 'pending', text: t, time, replyTo: reply?.waId ?? null, replyExcerpt: reply?.excerpt ?? null }
      conv.thread.push(optimistic)
      conv.preview = t
      conv.time = time
      conv.unread = 0
      const body: any = { type: 'text', is_out: true, text: t, time }
      if (reply?.waId) { body.reply_to = reply.waId; body.reply_excerpt = reply.excerpt ?? '' }
      api()<any>(`/api/conversations/${conv.id}/messages`, { method: 'POST', body })
        .then((created) => { optimistic.id = created?.id; optimistic.status = created?.status ?? 'sent' })
        .catch((e: any) => {
          optimistic.status = 'error'
          // Janela de 24h da API oficial fechada: em vez de deixar a bolha vermelha sem
          // explicação, tira a mensagem da thread e abre os templates aprovados.
          if (e?.response?._data?.code === 'window_closed') {
            const i = conv.thread.indexOf(optimistic)
            if (i >= 0) conv.thread.splice(i, 1)
            this.openTemplates()
          }
        })
    },

    /**
     * Carrega a lista de templates UMA VEZ por sessão, para o menu do painel lateral.
     *
     * A rota é por conversa, mas o que ela devolve é da CONTA — os mesmos templates
     * aprovados valem para todo mundo. Buscar a cada conversa aberta seria uma chamada
     * à Graph API por clique na lista, e o chat já apanhou de payload gordo antes.
     *
     * Silencioso de propósito: é enfeite de menu. Se falhar, o caminho de sempre
     * (openTemplates, no rodapé) continua mostrando erro de verdade.
     */
    async ensureTemplates() {
      if (this.templatesFetched || this.templatesLoading) return
      this.templatesFetched = true
      const conv = this.activeConv
      if (!conv) return
      try {
        const r = await api()<{ templates: WaTemplate[] }>(`/api/conversations/${conv.id}/templates`)
        this.templates = r.templates || []
      }
      catch { this.templatesFetched = false } // deixa tentar de novo na próxima conversa
    },

    /** Abre o painel de templates (e carrega a lista aprovada da Meta). */
    async openTemplates() {
      const conv = this.activeConv
      if (!conv) return
      this.templatePanel = true
      this.templateError = ''
      this.templatesLoading = true
      try {
        const r = await api()<{ templates: WaTemplate[] }>(`/api/conversations/${conv.id}/templates`)
        this.templates = r.templates || []
        if (!this.templates.length) this.templateError = 'Nenhum template aprovado nesta conta. Crie e aprove um no Gerenciador da Meta.'
      }
      catch { this.templateError = 'Não consegui carregar os templates.' }
      finally { this.templatesLoading = false }
    },

    /** Envia um template aprovado com as variáveis preenchidas. */
    async sendTemplate(t: WaTemplate, params: string[]): Promise<string | null> {
      const conv = this.activeConv
      if (!conv) return 'Conversa não encontrada.'
      try {
        const m = await api()<any>(`/api/conversations/${conv.id}/template`, {
          method: 'POST',
          body: { name: t.name, language: t.language, params, body: t.body },
        })
        conv.thread.push(mapMsg(m))
        conv.preview = m.text || t.name
        conv.time = m.time
        this.templatePanel = false
        return null
      }
      catch (e: any) { return e?.response?._data?.message || 'Falha ao enviar o template.' }
    },

    // Encaminha uma mensagem para outra conversa.
    /** NULL quando encaminhou; a frase do erro quando não (mesma regra do sendMedia). */
    async forwardMessage(targetConvId: string, messageId: number): Promise<string | null> {
      try {
        const m = await api()<any>(`/api/conversations/${targetConvId}/forward`, { method: 'POST', body: { message_id: messageId } })
        const target = this.conversations.find(c => c.id === targetConvId)
        if (target) {
          target.preview = m.type === 'text' ? (m.text || '') : (m.type === 'image' ? '📷 Foto' : m.type === 'video' ? '🎬 Vídeo' : m.type === 'voice' ? '🎵 Áudio' : `📄 ${m.file_name || 'arquivo'}`)
          target.time = m.time
          if (targetConvId === this.activeId) target.thread.push(mapMsg(m))
        }
        return null
      }
      catch (e: any) {
        // Aqui NÃO se abre o painel de templates: o destino do encaminhamento costuma ser
        // outra conversa, e o painel é sempre o da conversa aberta — abriria o template
        // do contato errado. A frase do servidor já diz o que fazer.
        return e?.response?._data?.message || 'Falha ao encaminhar.'
      }
    },

    // Reage a uma mensagem (emoji). Toggle: reagir com o mesmo emoji remove.
    react(messageId: number, emoji: string) {
      const conv = this.activeConv
      if (!conv) return
      const m = conv.thread.find(x => x.id === messageId)
      if (!m) return
      const next = m.reaction === emoji ? '' : emoji
      m.reaction = next || null
      api()(`/api/conversations/${conv.id}/messages/${messageId}/react`, { method: 'POST', body: { emoji: next } }).catch(() => {})
    },

    // Reenvia uma mensagem que o WhatsApp recusou (bolha com ⚠). Otimista: mostra
    // "enviando" na hora e volta pra 'error' se o servidor recusar de novo.
    async resendMessage(messageId: number): Promise<string | null> {
      const conv = this.activeConv
      if (!conv) return 'Conversa não encontrada.'
      const m = conv.thread.find(x => x.id === messageId)
      if (!m) return 'Mensagem não encontrada.'
      const before = m.status
      m.status = 'pending'
      try {
        const updated = await api()<any>(`/api/conversations/${conv.id}/messages/${messageId}/resend`, { method: 'POST' })
        m.status = updated?.status ?? 'sent'
        return null
      }
      catch (e: any) {
        m.status = before
        return e?.data?.message || 'Não consegui reenviar. Tente de novo em alguns minutos.'
      }
    },

    // Envia mídia (imagem/vídeo/documento) pelo WhatsApp.
    /**
     * Devolve NULL quando enviou e a FRASE DO ERRO quando não.
     *
     * Devolvia só um booleano, e o `catch` vazio jogava fora a explicação que o servidor
     * já mandava — inclusive "a janela de 24h fechou", que tem saída (template). Quem
     * usava via só "Falha ao enviar" e ficava tentando de novo o que nunca ia passar.
     */
    async sendMedia(file: File, caption = '', dur = ''): Promise<string | null> {
      const conv = this.activeConv
      if (!conv) return 'Conversa não encontrada.'
      const fd = new FormData()
      fd.append('file', file)
      if (caption.trim()) fd.append('caption', caption.trim())
      if (dur) fd.append('dur', dur)
      try {
        const m = await api()<any>(`/api/conversations/${conv.id}/media`, { method: 'POST', body: fd })
        conv.thread.push({ id: m.id, type: m.type, isOut: true, status: m.status ?? 'sent', text: m.text, time: m.time, ts: m.ts, dur: m.dur, fileName: m.file_name, meta: m.meta })
        conv.preview = m.type === 'image' ? '📷 Foto' : m.type === 'video' ? '🎬 Vídeo' : `📄 ${m.file_name || 'arquivo'}`
        conv.time = m.time
        conv.unread = 0
        return null
      }
      catch (e: any) {
        // Fora da janela, mídia livre não existe: o único caminho é template aprovado.
        // Abre o painel já, como o envio de texto faz — a mesma saída, no mesmo lugar.
        if (e?.response?._data?.code === 'window_closed') this.openTemplates()
        return e?.response?._data?.message || 'Falha ao enviar a mídia — tente de novo.'
      }
    },

    // Apaga uma mensagem do CRM (otimista; reverte se o servidor recusar).
    async removeMessage(messageId: number) {
      const conv = this.activeConv
      if (!conv) return
      const idx = conv.thread.findIndex(m => m.id === messageId)
      if (idx < 0) return
      const [removed] = conv.thread.splice(idx, 1)
      try {
        await api()(`/api/conversations/${conv.id}/messages/${messageId}`, { method: 'DELETE' })
        const last = [...conv.thread].reverse().find(m => m.type === 'text' && m.text)
        conv.preview = last?.text ?? ''
        if (last?.time) conv.time = last.time
      }
      catch {
        conv.thread.splice(idx, 0, removed) // falhou no servidor → desfaz
      }
    },

    // ----- Drag & drop (persistido) -----
    setDrag(board: Board, from: string, id: number | string, kind?: 'conv' | 'deal') { dragRef = { board, from, id, kind } },
    setDragOver(key: string | null) { this.dragOverCol = key },
    dropTo(board: Board, targetCol: string) {
      const d = dragRef
      this.dragOverCol = null
      dragRef = null
      if (!d || d.board !== board) return
      if (board === 'pipeline') {
        // Card de conversa: arrastar muda a etapa/etiqueta da conversa.
        if (d.kind === 'conv') {
          this.setConvStage(String(d.id), targetCol)
          return
        }
        const item = this.dealList.find(x => x.id === d.id)
        if (!item || item.stage === targetCol) return
        item.stage = targetCol
        item.position = Math.max(0, ...this.dealList.filter(x => x.stage === targetCol).map(x => x.position)) + 1
        api()(`/api/deals/${item.id}`, { method: 'PATCH', body: { stage: targetCol } }).catch(() => {})
      }
      else {
        const item = this.taskList.find(x => x.id === d.id)
        if (!item || item.column === targetCol) return
        item.column = targetCol
        item.position = Math.max(0, ...this.taskList.filter(x => x.column === targetCol).map(x => x.position)) + 1
        api()(`/api/tasks/${item.id}`, { method: 'PATCH', body: { column: targetCol } }).catch(() => {})
      }
    },

    // ----- Negócios (funil) -----
    async createDeal(payload: { name: string, sub: string, value: string, stage: string, hot: boolean }) {
      const created = await api()<any>('/api/deals', { method: 'POST', body: payload })
      this.dealList.push(mapDeal(created))
    },
    updateDeal(id: number, patch: Partial<Deal>) {
      const d = this.dealList.find(x => x.id === id)
      if (d) Object.assign(d, patch)
      api()(`/api/deals/${id}`, { method: 'PATCH', body: patch }).catch(() => {})
    },
    removeDeal(id: number) {
      this.dealList = this.dealList.filter(d => d.id !== id)
      api()(`/api/deals/${id}`, { method: 'DELETE' }).catch(() => {})
    },
    removeTask(id: number) {
      this.taskList = this.taskList.filter(t => t.id !== id)
      api()(`/api/tasks/${id}`, { method: 'DELETE' }).catch(() => {})
    },
    async updateTask(id: number, patch: Partial<Task>) {
      const t = this.taskList.find(x => x.id === id)
      if (t) Object.assign(t, patch)
      const updated = await api()<any>(`/api/tasks/${id}`, { method: 'PATCH', body: patch })
      if (updated && t) Object.assign(t, mapTask(updated))
    },

    // ----- Respostas rápidas -----
    async addQuickReply(payload: { label: string, text: string }) {
      const created = await api()<QuickReply>('/api/quick-replies', { method: 'POST', body: payload })
      this.quickReplies.push(created)
    },
    removeQuickReply(id: number) {
      this.quickReplies = this.quickReplies.filter(q => q.id !== id)
      api()(`/api/quick-replies/${id}`, { method: 'DELETE' }).catch(() => {})
    },

    // ----- Etiquetas -----
    async createLabel(payload: { name: string, color: string }) {
      const created = await api()<{ id: number, name: string, color: string }>('/api/labels', { method: 'POST', body: payload })
      this.labels.push(created)
      return created
    },
    removeLabel(id: number) {
      this.labels = this.labels.filter(l => l.id !== id)
      api()(`/api/labels/${id}`, { method: 'DELETE' }).catch(() => {})
    },
    // Define a etapa da conversa = etiqueta única (marca uma, desmarca a anterior).
    // Move no funil e atualiza a etiqueta no chat.
    setConvStage(id: string, stageKey: string) {
      const c = this.conversations.find(x => x.id === id)
      const st = this.stages.find(s => s.key === stageKey)
      if (!c || !st) return
      c.stage = st.key
      c.stageColor = st.color
      c.tags = [{ label: st.name, color: st.color }] // sempre uma única etiqueta ativa
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { stage: st.key, stage_color: st.color, tags: c.tags } }).catch(() => {})
    },
    // Edita o valor do negócio direto pela ficha da conversa.
    setConvValue(id: string, value: string) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      c.dealValue = value
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { deal_value: value } }).catch(() => {})
    },
    // Edição genérica da ficha do lead (CRM). Recebe os campos do FRONT (camelCase),
    // aplica otimista no store e converte p/ as colunas do back (snake_case) no PATCH.
    patchConvFields(id: string, patch: Partial<Conversation>) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      Object.assign(c, patch)
      const map: Record<string, string> = {
        email: 'email', company: 'company', origin: 'origin', responsible: 'responsible',
        role: 'role', segmento: 'segmento', notes: 'notes', prob: 'prob', dealValue: 'deal_value', name: 'name',
        ownerUserId: 'owner_user_id',
      }
      const body: Record<string, any> = {}
      for (const [k, v] of Object.entries(patch)) {
        if (map[k]) body[map[k]] = v
      }
      if (Object.keys(body).length) api()(`/api/conversations/${id}`, { method: 'PATCH', body }).catch(() => {})
    },
    // ----- Linha do tempo do lead -----
    async loadActivities(id: string) {
      this.activitiesConvId = id
      this.activitiesLoading = true
      try {
        const rows = await api()<Activity[]>(`/api/conversations/${id}/activities`)
        if (this.activitiesConvId === id) this.activities = rows
      }
      catch { if (this.activitiesConvId === id) this.activities = [] }
      finally { this.activitiesLoading = false }
    },
    async addActivity(id: string, payload: { title: string, body?: string, type?: string }) {
      const created = await api()<Activity>(`/api/conversations/${id}/activities`, { method: 'POST', body: payload })
      if (this.activitiesConvId === id) this.activities.unshift(created)
    },
    removeActivity(id: string, activityId: number) {
      this.activities = this.activities.filter(a => a.id !== activityId)
      api()(`/api/conversations/${id}/activities/${activityId}`, { method: 'DELETE' }).catch(() => {})
    },
    // ----- Follow-ups (acompanhamento) -----
    async loadFollowups(id: string) {
      this.followupsConvId = id
      try {
        const rows = await api()<FollowUp[]>(`/api/conversations/${id}/followups`)
        if (this.followupsConvId === id) this.followups = rows
      }
      catch { if (this.followupsConvId === id) this.followups = [] }
    },
    async createFollowup(id: string, payload: { title: string, starts_at: string }) {
      const created = await api()<FollowUp>(`/api/conversations/${id}/followups`, { method: 'POST', body: payload })
      if (this.followupsConvId === id) this.followups.push(created)
      // a criação gera uma atividade no back; recarrega a timeline se for o lead aberto
      if (this.activitiesConvId === id) this.loadActivities(id)
    },
    async completeFollowup(id: string, taskId: number) {
      const f = this.followups.find(x => x.id === taskId)
      if (f) f.column = 'done'
      await api()(`/api/followups/${taskId}/complete`, { method: 'PATCH' }).catch(() => {})
      if (this.activitiesConvId === id) this.loadActivities(id)
    },
    removeFollowup(taskId: number) {
      this.followups = this.followups.filter(x => x.id !== taskId)
      api()(`/api/tasks/${taskId}`, { method: 'DELETE' }).catch(() => {})
    },
    // Leva o rascunho da IA para o composer do chat (o vendedor revisa e envia).
    useFollowupDraft(id: string, draft: string) {
      this.selectConv(id) // carrega a thread; sem isso o chat abre vazio
      this.aiSuggestion = draft // depois do selectConv, que zera a sugestão
      this.go('chat')
    },
    // Inicia/abre uma conversa por número (contatos sem histórico no Evolution).
    // account_id escolhe o número remetente; sem ele, sai pelo principal.
    async startConversation(payload: { number: string, name?: string, account_id?: number }) {
      const created = await api()<any>('/api/wpp/start', { method: 'POST', body: payload })
      const mapped = mapConv(created)
      const idx = this.conversations.findIndex(c => c.id === mapped.id)
      if (idx >= 0) this.conversations[idx] = mapped
      else this.conversations.unshift(mapped)
      this.activeId = mapped.id
      this.chatOpen = true
      // Sem histórico e no canal oficial → a conversa só começa por template.
      if (mapped.waCloud && !mapped.thread.length) this.openTemplates()
      return mapped
    },

    // Exibe o nome do CONTATO salvo nas conversas cujo telefone bate (últimos 8 dígitos),
    // quando ainda não há nome salvo na conversa. Roda no cliente, instantâneo.
    linkContactNames() {
      if (!this.contacts.length || !this.conversations.length) return
      const idx: Record<string, string> = {}
      for (const ct of this.contacts) {
        const d = (ct.phone || '').replace(/\D/g, '')
        if (d.length >= 8 && ct.name) idx[d.slice(-8)] = ct.name
      }
      for (const c of this.conversations) {
        if (c.nameSaved || !c.phone) continue
        const d = c.phone.replace(/\D/g, '')
        const name = d.length >= 8 ? idx[d.slice(-8)] : undefined
        if (name) {
          c.name = name
          c.nameSaved = true
          c.initials = initialsOf(name)
        }
      }
    },

    // ----- Contatos (sincronizados com o Google) -----
    async loadContacts(listId?: number) {
      try {
        // ?list= filtra por lista de contatos (a tela de Contatos é organizada por listas).
        this.contacts = await api()<Contact[]>(`/api/contacts${listId ? `?list=${listId}` : ''}`)
        this.linkContactNames()
      }
      catch { /* silencioso */ }
    },
    async createContact(payload: { name: string, phone?: string, email?: string }) {
      const c = await api()<Contact>('/api/contacts', { method: 'POST', body: payload })
      this.contacts.unshift(c)
      return c
    },
    // Abre o chat do contato: acha a conversa pelo telefone; se não existir, inicia uma.
    async openContactChat(contact: Contact) {
      const target = (contact.phone || '').replace(/\D/g, '').slice(-8)
      const conv = target ? this.conversations.find(c => c.phone && c.phone.replace(/\D/g, '').slice(-8) === target) : null
      if (conv) {
        this.activeId = conv.id
        this.chatOpen = true
        this.loadFullThread(conv.id)
      }
      else if (contact.phone) {
        await this.startConversation({ number: contact.phone, name: contact.name })
      }
      else {
        return
      }
      this.go('chat')
    },
    async updateContact(id: number, patch: Partial<Contact>) {
      const updated = await api()<Contact>(`/api/contacts/${id}`, { method: 'PATCH', body: patch })
      const i = this.contacts.findIndex(c => c.id === id)
      if (i >= 0) this.contacts[i] = updated
      return updated
    },
    removeContact(id: number) {
      this.contacts = this.contacts.filter(c => c.id !== id)
      api()(`/api/contacts/${id}`, { method: 'DELETE' }).catch(() => {})
    },

    // Importa conversa exportada (.txt) do WhatsApp.
    async importTxt(payload: { text: string, number: string, name?: string, client_sender?: string }) {
      const r = await api()<any>('/api/wpp/import-txt', { method: 'POST', body: payload })
      const mapped = mapConv(r.conversation)
      const idx = this.conversations.findIndex(c => c.id === mapped.id)
      if (idx >= 0) this.conversations[idx] = mapped
      else this.conversations.unshift(mapped)
      this.activeId = mapped.id
      this.chatOpen = true
      return r
    },

    // ----- Tabs da lista de conversas (filtram por etiqueta/etapa) -----
    async createChatTab(payload: { name: string, stages: string[], qualified?: TabQual[], show_hidden?: boolean, chat?: number }) {
      const t = await api()<ChatTab>('/api/chat-tabs', { method: 'POST', body: payload })
      this.chatTabs.push({ ...t, stages: t.stages || [], qualified: t.qualified || [] })
      return t
    },
    updateChatTab(id: number, patch: Partial<ChatTab>) {
      const t = this.chatTabs.find(x => x.id === id)
      if (t) Object.assign(t, patch)
      api()(`/api/chat-tabs/${id}`, { method: 'PATCH', body: patch }).catch(() => {})
    },
    removeChatTab(id: number) {
      this.chatTabs = this.chatTabs.filter(t => t.id !== id)
      api()(`/api/chat-tabs/${id}`, { method: 'DELETE' }).catch(() => {})
    },
    // Salva o nome do cliente.
    setConvName(id: string, name: string) {
      const c = this.conversations.find(x => x.id === id)
      const n = name.trim()
      if (!c || !n) return
      c.name = n
      c.nameSaved = true
      c.initials = initialsOf(n)
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { name: n } }).catch(() => {})
    },

    // ----- Etapas do funil (também são as etiquetas) -----
    async createStage(payload: { name: string, color: string }) {
      const s = await api()<Stage>('/api/stages', { method: 'POST', body: payload })
      this.stages.push(s)
      return s
    },
    updateStage(id: number, patch: Partial<Stage>) {
      const s = this.stages.find(x => x.id === id)
      if (s) Object.assign(s, patch)
      api()(`/api/stages/${id}`, { method: 'PATCH', body: patch }).catch(() => {})
    },
    removeStage(id: number) {
      this.stages = this.stages.filter(x => x.id !== id)
      api()(`/api/stages/${id}`, { method: 'DELETE' }).catch(() => {})
    },
    reorderStages() {
      api()('/api/stages/reorder', { method: 'POST', body: { ids: this.stages.map(s => s.id) } }).catch(() => {})
    },

    // ----- Portal de solicitações -----
    async submitForm(payload: { name: string, company: string, desc: string, due: string, slug?: string }) {
      const name = payload.name.trim()
      const desc = payload.desc.trim()
      if (!name || !desc) { this.formError = true; return }
      const company = payload.company.trim()
      const due = payload.due.trim()
      const body = {
        title: desc.length > 70 ? `${desc.slice(0, 70)}…` : desc,
        description: desc, // texto completo da solicitação — vai pro detalhe da tarefa
        client: name + (company ? ` · ${company}` : ''),
        priority: this.formPriority,
        due: due || 'Sem prazo',
        type: this.formType,
        column: 'todo',
        // Empresa (tenant) destino — pelo slug na URL do portal (?e=slug).
        company_slug: payload.slug || undefined,
      }
      try {
        const created = await api()<any>(`/api/solicitacoes`, { method: 'POST', body })
        this.taskList.unshift(mapTask(created))
        this.formSubmitted = true
        this.formError = false
      }
      catch {
        this.formError = true
      }
    },
    resetForm() {
      this.formSubmitted = false
      this.formType = 'Novo recurso'
      this.formPriority = 'media'
      this.formError = false
    },

    // ----- Google Agenda -----
    async loadGoogleStatus() {
      try {
        const s = await api()<{ connected: boolean, email: string | null, calendars?: CalendarLayer[] }>('/api/google/status')
        this.googleConnected = s.connected
        this.googleEmail = s.email
        this.calendars = s.calendars ?? []
        // Agenda que deixou de existir (usuário saiu / desconectou) não fica escondida p/ sempre.
        this.hiddenCalendars = this.hiddenCalendars.filter(id => this.calendars.some(c => c.user_id === id))
      }
      catch { this.googleConnected = false; this.calendars = [] }
    },
    // Liga/desliga o AGENDAMENTO de alguém (só admin). Atualiza a lista na hora e
    // desfaz se o servidor recusar — a tela nunca fica dizendo algo que o banco não tem.
    async setAgendaAtiva(userId: number, ativa: boolean) {
      const cal = this.calendars.find(c => c.user_id === userId)
      const antes = cal?.agenda_ativa
      if (cal) cal.agenda_ativa = ativa
      try {
        await api()(`/api/google/calendars/${userId}/agenda`, { method: 'PATCH', body: { ativa } })
      }
      catch (e) {
        if (cal && antes !== undefined) cal.agenda_ativa = antes
        throw e
      }
    },
    toggleCalendar(userId: number) {
      this.hiddenCalendars = this.hiddenCalendars.includes(userId)
        ? this.hiddenCalendars.filter(id => id !== userId)
        : [...this.hiddenCalendars, userId]
    },
    // Pega a URL de consentimento e leva o navegador ao Google.
    async connectGoogle() {
      const { url } = await api()<{ url: string }>('/api/google/connect')
      window.location.href = url
    },
    async disconnectGoogle() {
      await api()('/api/google/disconnect', { method: 'DELETE' })
      this.googleConnected = false
      this.googleEmail = null
      this.events = []
      // A empresa pode ter OUTRAS agendas conectadas: relê o status em vez de zerar tudo.
      await this.loadGoogleStatus()
    },
    async fetchEvents(fromISO: string, toISO: string) {
      if (!this.hasCalendars) return
      this.lastEventsRange = { from: fromISO, to: toISO }
      this.eventsLoading = true
      try {
        const r = await api()<{ events: CalEvent[] }>(`/api/google/events?from=${encodeURIComponent(fromISO)}&to=${encodeURIComponent(toISO)}`)
        this.events = r.events
      }
      catch { /* silencioso */ }
      finally { this.eventsLoading = false }
    },
    // Recarrega a agenda no intervalo atual (tempo real: presença/resumo mudaram no servidor).
    async refreshEvents() {
      if (!this.hasCalendars || !this.lastEventsRange) return
      const r = await api()<{ events: CalEvent[] }>(`/api/google/events?from=${encodeURIComponent(this.lastEventsRange.from)}&to=${encodeURIComponent(this.lastEventsRange.to)}`).catch(() => null)
      if (r) this.events = r.events
    },
    async createEvent(payload: Partial<CalEvent>) {
      const created = await api()<CalEvent>('/api/google/events', { method: 'POST', body: payload })
      this.events.push(created)
      return created
    },
    // owner_id: em qual agenda da empresa o evento vive — sem ele o Google devolve 404.
    async updateEvent(id: string, patch: Partial<CalEvent>) {
      const dono = patch.owner_id ?? this.events.find(e => e.id === id)?.owner_id
      const updated = await api()<CalEvent>(`/api/google/events/${id}`, { method: 'PATCH', body: { ...patch, owner_id: dono } })
      const i = this.events.findIndex(e => e.id === id)
      if (i >= 0) this.events[i] = updated
      return updated
    },
    /**
     * Marca à mão se a reunião aconteceu. A apuração automática só enxerga quem entrou na
     * sala do Meet — reunião por telefone, presencial ou em outro link precisa disto.
     */
    async markAttendance(id: string, attended: boolean) {
      const ev = this.events.find(e => e.id === id)
      const r = await api()<{ attended: boolean, no_show: boolean }>(`/api/google/events/${id}/attendance`, {
        method: 'POST',
        // owner_id/slug: o evento pode nem existir em `meetings` ainda (compromisso criado no
        // Google sem Meet) — o servidor cria a linha usando a agenda certa e já vincula o lead.
        body: { attended, owner_id: ev?.owner_id, conversation_slug: ev?.conversation_slug },
      })
      const i = this.events.findIndex(e => e.id === id)
      if (i >= 0) this.events[i] = { ...this.events[i], attended: r.attended, no_show: r.no_show }
      return r
    },
    async deleteEvent(id: string) {
      const dono = this.events.find(e => e.id === id)?.owner_id
      this.events = this.events.filter(e => e.id !== id)
      await api()(`/api/google/events/${id}${dono ? `?owner_id=${dono}` : ''}`, { method: 'DELETE' }).catch(() => {})
    },
  },
})
