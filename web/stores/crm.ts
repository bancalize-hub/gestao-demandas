import { defineStore } from 'pinia'

// ----- Tipos -----
export type Screen =
  | 'chat' | 'pipeline' | 'tasks' | 'agenda'
  | 'meeting' | 'contact' | 'form' | 'mobile'

export interface Msg {
  type: 'divider' | 'text' | 'voice' | 'file'
  label?: string | null
  isOut?: boolean
  text?: string | null
  time?: string | null
  dur?: string | null
  fileName?: string | null
  meta?: string | null
}

export interface Tag { label: string, color: string }
export interface Interaction { title: string, meta: string, color: string }
export interface QuickReply { id: number, label: string, text: string }

export interface Conversation {
  id: string // slug
  name: string
  initials: string
  color: string
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
export interface Column<T> { id: string, title: string, dot: string, money?: boolean, cards: T[] }

type Board = 'pipeline' | 'tasks'
interface DragRef { board: Board, from: string, id: number }

// ----- Metadados fixos das colunas -----
const PIPE_COLS = [
  { id: 'novo', title: 'Novo lead', dot: '#53bdeb' },
  { id: 'contato', title: 'Contato feito', dot: '#7c6cf5' },
  { id: 'proposta', title: 'Proposta enviada', dot: '#3aa6ff' },
  { id: 'negociacao', title: 'Negociação', dot: '#ffb443' },
  { id: 'fechado', title: 'Fechado', dot: '#25D366' },
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

// Mapeia o snake_case da API para o domínio camelCase do frontend.
function mapConv(c: any): Conversation {
  return {
    id: c.slug, name: c.name, initials: c.initials, color: c.color, online: !!c.online,
    statusText: c.status_text ?? '', role: c.role ?? '', dealValue: c.deal_value ?? '', dealUnit: c.deal_unit ?? '',
    stage: c.stage ?? '', stageColor: c.stage_color ?? '#8696a0', prob: c.prob ?? 0, hot: !!c.hot,
    preview: c.preview ?? '', time: c.time ?? '', unread: c.unread ?? 0, tags: c.tags ?? [],
    phone: c.phone ?? '', email: c.email ?? '', company: c.company ?? '', origin: c.origin ?? '', responsible: c.responsible ?? '',
    interactions: c.interactions ?? [],
    thread: (c.messages ?? []).map(mapMsg),
  }
}
function mapMsg(m: any): Msg {
  return { type: m.type, isOut: !!m.is_out, text: m.text, time: m.time, dur: m.dur, fileName: m.file_name, meta: m.meta, label: m.label }
}
function mapDeal(d: any): Deal {
  return { id: d.id, name: d.name, sub: d.sub ?? '', value: d.value ?? '', tag: d.tag ?? '', stage: d.stage, hot: !!d.hot, won: !!d.won, tagStrong: !!d.tag_strong, position: d.position ?? 0 }
}
function mapTask(t: any): Task {
  return { id: t.id, title: t.title, client: t.client ?? '', priority: t.priority, due: t.due ?? '', type: t.type ?? '', column: t.column, position: t.position ?? 0 }
}

let dragRef: DragRef | null = null

// Mapa de "tela" -> rota real (navegação por vue-router).
export const SCREEN_ROUTES: Record<Screen, string> = {
  chat: '/',
  pipeline: '/funil',
  tasks: '/tarefas',
  agenda: '/agenda',
  meeting: '/reuniao',
  contact: '/contato',
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
    dragOverCol: null as string | null,
    formType: 'Novo recurso',
    formPriority: 'media' as 'baixa' | 'media' | 'alta',
    formSubmitted: false,
    formError: false,
    aiSuggestion: '',
    aiLoading: false,
  }),

  getters: {
    activeConv(s): Conversation | undefined {
      return s.conversations.find(c => c.id === s.activeId) || s.conversations[0]
    },
    pipeline(s): Column<Deal>[] {
      return PIPE_COLS.map(col => ({
        ...col, money: true,
        cards: s.dealList.filter(d => d.stage === col.id).slice().sort(sortByPos),
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
        const [convs, deals, tasks, qr] = await Promise.all([
          client<any[]>(`/api/conversations`),
          client<any[]>(`/api/deals`),
          client<any[]>(`/api/tasks`),
          client<QuickReply[]>(`/api/quick-replies`),
        ])
        this.conversations = convs.map(mapConv)
        this.dealList = deals.map(mapDeal)
        this.taskList = tasks.map(mapTask)
        this.quickReplies = qr
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

    selectConv(id: string) {
      this.activeId = id
      this.threadError = false
      this.aiSuggestion = ''
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

    // Sugestão de resposta gerada pelo Claude (assinatura) com base na conversa ativa.
    async suggestReply() {
      const conv = this.activeConv
      if (!conv || this.aiLoading) return
      this.aiLoading = true
      this.aiSuggestion = ''
      try {
        const r = await api()<{ suggestion: string }>(`/api/conversations/${conv.id}/suggest-reply`, { method: 'POST' })
        this.aiSuggestion = r.suggestion || ''
      }
      catch {
        this.aiSuggestion = ''
      }
      finally {
        this.aiLoading = false
      }
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

    // ----- Drag & drop (persistido) -----
    setDrag(board: Board, from: string, id: number) { dragRef = { board, from, id } },
    setDragOver(key: string | null) { this.dragOverCol = key },
    dropTo(board: Board, targetCol: string) {
      const d = dragRef
      this.dragOverCol = null
      dragRef = null
      if (!d || d.board !== board) return
      if (board === 'pipeline') {
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

    // ----- Respostas rápidas -----
    async addQuickReply(payload: { label: string, text: string }) {
      const created = await api()<QuickReply>('/api/quick-replies', { method: 'POST', body: payload })
      this.quickReplies.push(created)
    },
    removeQuickReply(id: number) {
      this.quickReplies = this.quickReplies.filter(q => q.id !== id)
      api()(`/api/quick-replies/${id}`, { method: 'DELETE' }).catch(() => {})
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
  },
})
