import { defineStore } from 'pinia'

// ----- Tipos -----
export type Screen =
  | 'chat' | 'pipeline' | 'tasks' | 'agenda'
  | 'meeting' | 'contact' | 'contacts' | 'form' | 'mobile'

export interface Contact { id: number, name: string, phone: string | null, email: string | null, avatar: string | null, resource_name?: string | null }

export interface Msg {
  id?: number
  type: 'divider' | 'text' | 'voice' | 'file' | 'image' | 'video'
  label?: string | null
  isOut?: boolean
  text?: string | null
  time?: string | null
  ts?: number | null
  dur?: string | null
  fileName?: string | null
  meta?: string | null
}

export interface Tag { label: string, color: string }
export interface Interaction { title: string, meta: string, color: string }
export interface QuickReply { id: number, label: string, text: string }
export interface Stage { id?: number, key: string, name: string, color: string, goal?: string | null, wa_label_id?: string | null, position?: number }
export interface ChatTab { id: number, name: string, stages: string[], position?: number }

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
  dealValue: string
  dealUnit: string
  stage: string
  stageColor: string
  prob: number
  hot: boolean
  preview: string
  time: string
  unread: number
  archived: boolean
  autoReply: boolean
  lastOut: boolean
  inMemory: boolean
  tags: Tag[]
  phone: string
  email: string
  company: string
  origin: string
  responsible: string
  interactions: Interaction[]
  thread: Msg[]
}

export interface Deal {
  id: number, name: string, sub: string, value: string, tag: string
  stage: string, hot: boolean, won: boolean, tagStrong: boolean, position: number
}
export interface Task {
  id: number, title: string, client: string, priority: 'baixa' | 'media' | 'alta'
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
  deal_id?: number | null
  task_id?: number | null
}
export interface Column<T> { id: string, title: string, dot: string, money?: boolean, cards: T[] }

type Board = 'pipeline' | 'tasks'
// kind distingue card de conversa (id = slug string) de card de negócio (id numérico).
interface DragRef { board: Board, from: string, id: number | string, kind?: 'conv' | 'deal' }

// ----- Metadados fixos das colunas -----
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

// Iniciais a partir do nome (1-2 letras).
function initialsOf(name: string): string {
  const parts = (name || '').trim().split(/\s+/).filter(Boolean)
  if (!parts.length) return '#'
  return ((parts[0][0] || '') + (parts.length > 1 ? parts[parts.length - 1][0] : '')).toUpperCase()
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
    id: c.slug, name: display, nameSaved, initials: nameSaved ? initialsOf(display) : (c.initials || '#'), color: colorForId(c.slug || c.name || String(c.id)), avatar: c.avatar ?? '', online: !!c.online,
    statusText: c.status_text ?? '', role: c.role ?? '', dealValue: c.deal_value ?? '', dealUnit: c.deal_unit ?? '',
    stage: c.stage ?? '', stageColor: c.stage_color ?? '#8696a0', prob: c.prob ?? 0, hot: !!c.hot,
    preview: c.preview ?? '', time: c.time ?? '', unread: c.unread ?? 0, archived: !!c.archived, autoReply: !!c.auto_reply, lastOut: c.last_message ? !!c.last_message.is_out : lastIsOut(c.messages), inMemory: !!c.in_memory, tags: c.tags ?? [],
    phone: c.phone ?? '', email: c.email ?? '', company: c.company ?? '', origin: c.origin ?? '', responsible: c.responsible ?? '',
    interactions: c.interactions ?? [],
    thread: (c.messages ?? []).map(mapMsg),
  }
}
function mapMsg(m: any): Msg {
  return { id: m.id, type: m.type, isOut: !!m.is_out, text: m.text, time: m.time, ts: m.ts ?? null, dur: m.dur, fileName: m.file_name, meta: m.meta, label: m.label }
}
function mapDeal(d: any): Deal {
  return { id: d.id, name: d.name, sub: d.sub ?? '', value: d.value ?? '', tag: d.tag ?? '', stage: d.stage, hot: !!d.hot, won: !!d.won, tagStrong: !!d.tag_strong, position: d.position ?? 0 }
}
function mapTask(t: any): Task {
  return { id: t.id, title: t.title, client: t.client ?? '', priority: t.priority, due: t.due ?? '', type: t.type ?? '', column: t.column, position: t.position ?? 0 }
}

let dragRef: DragRef | null = null
const fullLoaded = new Set<string>()

// Mapa de "tela" -> rota real (navegação por vue-router).
export const SCREEN_ROUTES: Record<Screen, string> = {
  chat: '/',
  pipeline: '/funil',
  tasks: '/tarefas',
  agenda: '/agenda',
  meeting: '/reuniao',
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
    // ----- Google Agenda -----
    googleConnected: false,
    googleEmail: null as string | null,
    events: [] as CalEvent[],
    eventsLoading: false,
  }),

  getters: {
    activeConv(s): Conversation | undefined {
      return s.conversations.find(c => c.id === s.activeId) || s.conversations[0]
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
    listReady: s => !s.loading,
    threadReady: s => !s.loading && !s.threadError,
    isOffline: s => s.connection === 'offline',
  },

  actions: {
    async init() {
      this.loading = true
      try {
        const client = api()
        const [convs, deals, tasks, qr, stages, tabs, contacts] = await Promise.all([
          client<any[]>(`/api/conversations`),
          client<any[]>(`/api/deals`),
          client<any[]>(`/api/tasks`),
          client<QuickReply[]>(`/api/quick-replies`),
          client<Stage[]>(`/api/stages`),
          client<ChatTab[]>(`/api/chat-tabs`),
          client<Contact[]>(`/api/contacts`),
        ])
        this.conversations = convs.map(mapConv)
        this.dealList = deals.map(mapDeal)
        this.taskList = tasks.map(mapTask)
        this.quickReplies = qr
        if (stages.length) this.stages = stages
        this.chatTabs = (tabs || []).map(t => ({ ...t, stages: t.stages || [] }))
        this.contacts = contacts || []
        this.linkContactNames()
        this.connection = 'online'
        if (!this.conversations.find(c => c.id === this.activeId))
          this.activeId = this.conversations[0]?.id ?? ''
      }
      catch {
        this.connection = 'offline'
      }
      finally {
        setTimeout(() => { this.loading = false }, 300)
      }
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
      this.conversations = raw.map((r) => {
        const mapped = mapConv(r)
        const existing = byId.get(mapped.id)
        if (!existing) return mapped
        // A lista não traz mais a thread (só a última msg); preserva a que já está carregada.
        Object.assign(existing, mapped, { thread: existing.thread })
        // Conversa aberta recebeu mensagem nova? (última do servidor != última carregada)
        if (existing.id === this.activeId && r.last_message) {
          const lastLoaded = [...existing.thread].reverse().find(o => o.id)
          if (!lastLoaded || lastLoaded.id !== r.last_message.id) activeNewMsg = true
        }
        return existing
      })
      this.linkContactNames()
      // Mantém a conversa aberta em tempo real recarregando só a thread dela (barata).
      if (activeNewMsg && this.activeId) this.loadFullThread(this.activeId, true)
    },

    // Atualiza a lista/threads (polling — mensagens novas em tempo real).
    async refresh() {
      try {
        const convs = await api()<any[]>('/api/conversations')
        this.applyConversations(convs)
      }
      catch { /* silencioso */ }
    },

    // Polling global: conversas + negócios (mantém chat, etiquetas e funil em tempo real).
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

    // Carrega o histórico completo da conversa (o sync guarda só as recentes).
    async loadFullThread(id: string, force = false) {
      if (!force && fullLoaded.has(id)) return
      fullLoaded.add(id)
      try {
        const c = await api()<any>(`/api/conversations/${id}/full`)
        const conv = this.conversations.find(x => x.id === id)
        if (conv) conv.thread = (c.messages ?? []).map(mapMsg)
      }
      catch { if (!force) fullLoaded.delete(id) }
    },

    selectConv(id: string) {
      this.activeId = id
      this.threadError = false
      this.aiSuggestion = ''
      this.loadFullThread(id)
      const c = this.conversations.find(x => x.id === id)
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
    // Liga/desliga o atendimento automático (a IA responde o lead sozinha).
    toggleAutoReply(id: string) {
      const c = this.conversations.find(x => x.id === id)
      if (!c) return
      c.autoReply = !c.autoReply
      api()(`/api/conversations/${id}`, { method: 'PATCH', body: { auto_reply: c.autoReply } }).catch(() => { c.autoReply = !c.autoReply })
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

    send(text: string) {
      const t = text.trim()
      const conv = this.activeConv
      if (!t || !conv) return
      const time = agora()
      conv.thread.push({ type: 'text', isOut: true, text: t, time })
      conv.preview = t
      conv.time = time
      conv.unread = 0
      api()(`/api/conversations/${conv.id}/messages`, { method: 'POST', body: { type: 'text', is_out: true, text: t, time } }).catch(() => {})
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
    // Inicia/abre uma conversa por número (contatos sem histórico no Evolution).
    async startConversation(payload: { number: string, name?: string }) {
      const created = await api()<any>('/api/wpp/start', { method: 'POST', body: payload })
      const mapped = mapConv(created)
      const idx = this.conversations.findIndex(c => c.id === mapped.id)
      if (idx >= 0) this.conversations[idx] = mapped
      else this.conversations.unshift(mapped)
      this.activeId = mapped.id
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
    async loadContacts() {
      try {
        this.contacts = await api()<Contact[]>('/api/contacts')
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
      return r
    },

    // ----- Tabs da lista de conversas (filtram por etiqueta/etapa) -----
    async createChatTab(payload: { name: string, stages: string[] }) {
      const t = await api()<ChatTab>('/api/chat-tabs', { method: 'POST', body: payload })
      this.chatTabs.push({ ...t, stages: t.stages || [] })
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
    async submitForm(payload: { name: string, company: string, desc: string, due: string }) {
      const name = payload.name.trim()
      const desc = payload.desc.trim()
      if (!name || !desc) { this.formError = true; return }
      const company = payload.company.trim()
      const due = payload.due.trim()
      const body = {
        title: desc.length > 70 ? `${desc.slice(0, 70)}…` : desc,
        client: name + (company ? ` · ${company}` : ''),
        priority: this.formPriority,
        due: due || 'Sem prazo',
        type: this.formType,
        column: 'todo',
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
        const s = await api()<{ connected: boolean, email: string | null }>('/api/google/status')
        this.googleConnected = s.connected
        this.googleEmail = s.email
      }
      catch { this.googleConnected = false }
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
    },
    async fetchEvents(fromISO: string, toISO: string) {
      if (!this.googleConnected) return
      this.eventsLoading = true
      try {
        const r = await api()<{ events: CalEvent[] }>(`/api/google/events?from=${encodeURIComponent(fromISO)}&to=${encodeURIComponent(toISO)}`)
        this.events = r.events
      }
      catch { /* silencioso */ }
      finally { this.eventsLoading = false }
    },
    async createEvent(payload: Partial<CalEvent>) {
      const created = await api()<CalEvent>('/api/google/events', { method: 'POST', body: payload })
      this.events.push(created)
      return created
    },
    async updateEvent(id: string, patch: Partial<CalEvent>) {
      const updated = await api()<CalEvent>(`/api/google/events/${id}`, { method: 'PATCH', body: patch })
      const i = this.events.findIndex(e => e.id === id)
      if (i >= 0) this.events[i] = updated
      return updated
    },
    async deleteEvent(id: string) {
      this.events = this.events.filter(e => e.id !== id)
      await api()(`/api/google/events/${id}`, { method: 'DELETE' }).catch(() => {})
    },
  },
})
