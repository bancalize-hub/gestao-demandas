import { defineStore } from 'pinia'

/**
 * Quadro de tarefas (/tarefas) — listas, cartões, etiquetas, responsáveis, filtros
 * e arquivo. Store PRÓPRIA, separada da `crm`: o quadro não divide estado com o chat
 * e o funil, e assim uma tela não rebusca a outra sem precisar.
 *
 * A ordem de um cartão é a posição dentro da lista. Ao arrastar, a ordem muda ANTES
 * de o servidor responder (o cartão precisa acompanhar o dedo) e a API só é chamada
 * quando o arrasto termina.
 */

export interface BoardList {
  id: number
  name: string
  color: string
  position: number
  archived_at: string | null
}

export interface BoardLabel {
  id: number
  name: string
  color: string
  position: number
}

export interface BoardMember {
  id: number
  name: string
  email?: string
}

export interface BoardCard {
  id: number
  task_list_id: number | null
  title: string
  description: string | null
  client: string | null
  priority: 'baixa' | 'media' | 'alta'
  type: string | null
  due: string | null
  due_at: string | null
  position: number
  archived_at: string | null
  conversation_id: number | null
  label_ids: number[]
  members: { id: number, name: string }[]
  checklist_total: number
  checklist_done: number
  comments_count: number
  attachments_count: number
}

export interface ChecklistItem { id: number, text: string, done: boolean, position: number }
export interface CardComment { id: number, body: string, user_id: number | null, author: string, created_at: string | null }
export interface CardAttachment { id: number, name: string, mime: string, size: number, is_image: boolean, url: string, created_at: string | null }

export interface CardDetail extends BoardCard {
  checklist: ChecklistItem[]
  comments: CardComment[]
  attachments: CardAttachment[]
}

export type DueFilter = '' | 'atrasado' | 'hoje' | 'semana' | 'sem'

function api() {
  return useApi()
}

function byPos<T extends { position: number, id: number }>(a: T, b: T) {
  return a.position - b.position || a.id - b.id
}

/** Meia-noite de hoje — base de toda conta de prazo (dia, não hora). */
function hoje() {
  const d = new Date()
  d.setHours(0, 0, 0, 0)
  return d
}

/** Dias entre hoje e a data (negativo = atrasado). Null quando não há prazo. */
export function diasAte(iso: string | null): number | null {
  if (!iso) return null
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return null
  d.setHours(0, 0, 0, 0)
  return Math.round((d.getTime() - hoje().getTime()) / 86400000)
}

/** Como o prazo aparece no cartão: texto curto + cor do estado. */
export function prazoMeta(card: { due_at: string | null, due?: string | null }) {
  const dias = diasAte(card.due_at)
  if (dias === null) {
    const livre = (card.due || '').trim()
    return livre && livre.toLowerCase() !== 'sem prazo'
      ? { label: livre, color: 'var(--c-text-muted)', bg: 'transparent', atrasado: false }
      : null
  }
  const d = new Date(card.due_at as string)
  const curto = d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short' }).replace('.', '')
  if (dias < 0) return { label: `${curto} · atrasado`, color: '#fff', bg: 'var(--c-danger, #ff6b6b)', atrasado: true }
  if (dias === 0) return { label: 'hoje', color: '#3a2a00', bg: '#ffb443', atrasado: false }
  if (dias === 1) return { label: 'amanhã', color: 'var(--c-text)', bg: 'var(--c-surface-3)', atrasado: false }
  return { label: curto, color: 'var(--c-text-muted)', bg: 'var(--c-surface-2)', atrasado: false }
}

export const usePrioMeta = (k: string) => (
  k === 'alta' ? { label: 'Alta', color: '#ff6b6b' }
    : k === 'baixa' ? { label: 'Baixa', color: '#53bdeb' }
      : { label: 'Média', color: '#ffb443' }
)

export const useBoardStore = defineStore('board', {
  state: () => ({
    lists: [] as BoardList[],
    labels: [] as BoardLabel[],
    members: [] as BoardMember[],
    cards: [] as BoardCard[],
    loading: true,
    loaded: false,
    error: '' as string,

    // Filtros da barra de cima (valem só na tela; nada vai para o servidor).
    filtro: {
      texto: '',
      labelIds: [] as number[],
      memberIds: [] as number[],
      prio: '' as '' | 'baixa' | 'media' | 'alta',
      prazo: '' as DueFilter,
    },

    // Cartão aberto no modal.
    aberto: null as CardDetail | null,
    abertoCarregando: false,

    // Arquivo (cartões e listas arquivados).
    arquivo: { aberto: false, carregando: false, cards: [] as BoardCard[], lists: [] as BoardList[] },

    // Lista em que o compositor de "adicionar cartão" deve abrir (vem do botão do topo).
    compondoEm: null as number | null,

    // Arrasto em curso (HTML5 drag and drop).
    dragCardId: null as number | null,
    dragListId: null as number | null,
    dragOverListId: null as number | null,
  }),

  getters: {
    /** Cartões que passam pelos filtros da barra. */
    filtrados(s): BoardCard[] {
      const f = s.filtro
      const texto = f.texto.trim().toLowerCase()
      return s.cards.filter((c) => {
        if (texto) {
          const alvo = `${c.title} ${c.description ?? ''} ${c.client ?? ''}`.toLowerCase()
          if (!alvo.includes(texto)) return false
        }
        if (f.labelIds.length && !f.labelIds.some(id => c.label_ids.includes(id))) return false
        if (f.memberIds.length && !f.memberIds.some(id => c.members.some(m => m.id === id))) return false
        if (f.prio && c.priority !== f.prio) return false
        if (f.prazo) {
          const dias = diasAte(c.due_at)
          if (f.prazo === 'sem' && dias !== null) return false
          if (f.prazo === 'atrasado' && (dias === null || dias >= 0)) return false
          if (f.prazo === 'hoje' && dias !== 0) return false
          if (f.prazo === 'semana' && (dias === null || dias < 0 || dias > 7)) return false
        }
        return true
      })
    },

    /** As listas com os cartões já filtrados e na ordem. */
    colunas(): (BoardList & { cards: BoardCard[], total: number })[] {
      const visiveis = this.filtrados
      return [...this.lists].sort(byPos).map(l => ({
        ...l,
        cards: visiveis.filter(c => c.task_list_id === l.id).sort(byPos),
        total: this.cards.filter(c => c.task_list_id === l.id).length,
      }))
    },

    filtroAtivo(s): boolean {
      const f = s.filtro
      return !!(f.texto.trim() || f.labelIds.length || f.memberIds.length || f.prio || f.prazo)
    },

    labelById(s) {
      return (id: number) => s.labels.find(l => l.id === id)
    },
  },

  actions: {
    // ----- Carga -----
    async load(silencioso = false) {
      if (!silencioso) this.loading = true
      try {
        const data = await api()<any>('/api/board')
        this.lists = data.lists ?? []
        this.labels = data.labels ?? []
        this.members = data.members ?? []
        this.cards = data.cards ?? []
        this.loaded = true
        this.error = ''
      }
      catch (e: any) {
        this.error = 'Não foi possível carregar o quadro.'
      }
      finally {
        this.loading = false
      }
    },

    /**
     * Re-sincroniza sem piscar a tela (evento do WebSocket, aba voltando ao foco).
     * Só vale para quem já abriu o quadro: o aviso do WebSocket chega em todas as telas,
     * e quem está no chat não precisa baixar cartão nenhum.
     */
    refresh() {
      if (!this.loaded) return
      if (this.dragCardId || this.dragListId) return   // no meio de um arrasto, não mexe
      return this.load(true)
    },

    // ----- Listas -----
    async criarLista(name: string) {
      const nome = name.trim()
      if (!nome) return
      const nova = await api()<BoardList>('/api/board/lists', { method: 'POST', body: { name: nome } })
      this.lists.push(nova)
    },

    async atualizarLista(id: number, patch: Partial<Pick<BoardList, 'name' | 'color'>>) {
      const l = this.lists.find(x => x.id === id)
      if (l) Object.assign(l, patch)
      await api()(`/api/board/lists/${id}`, { method: 'PATCH', body: patch })
    },

    async arquivarLista(id: number) {
      this.lists = this.lists.filter(l => l.id !== id)
      this.cards = this.cards.filter(c => c.task_list_id !== id)
      await api()(`/api/board/lists/${id}`, { method: 'DELETE' })
    },

    async moverLista(id: number, direcao: -1 | 1) {
      const ordenadas = [...this.lists].sort(byPos)
      const i = ordenadas.findIndex(l => l.id === id)
      const j = i + direcao
      if (i < 0 || j < 0 || j >= ordenadas.length) return
      const [l] = ordenadas.splice(i, 1)
      ordenadas.splice(j, 0, l)
      ordenadas.forEach((x, k) => { x.position = k })
      this.lists = ordenadas
      await api()('/api/board/lists/reorder', { method: 'POST', body: { ids: ordenadas.map(l => l.id) } })
    },

    /** Usado pelo arrasto de listas: já recebe a ordem final. */
    async salvarOrdemListas() {
      const ids = [...this.lists].sort(byPos).map(l => l.id)
      await api()('/api/board/lists/reorder', { method: 'POST', body: { ids } })
    },

    // ----- Etiquetas -----
    async criarEtiqueta(color: string, name = '') {
      const nova = await api()<BoardLabel>('/api/board/labels', { method: 'POST', body: { color, name } })
      this.labels.push(nova)
      return nova
    },
    async atualizarEtiqueta(id: number, patch: Partial<Pick<BoardLabel, 'name' | 'color'>>) {
      const l = this.labels.find(x => x.id === id)
      if (l) Object.assign(l, patch)
      await api()(`/api/board/labels/${id}`, { method: 'PATCH', body: patch })
    },
    async apagarEtiqueta(id: number) {
      this.labels = this.labels.filter(l => l.id !== id)
      this.cards.forEach((c) => { c.label_ids = c.label_ids.filter(x => x !== id) })
      if (this.aberto) this.aberto.label_ids = this.aberto.label_ids.filter(x => x !== id)
      this.filtro.labelIds = this.filtro.labelIds.filter(x => x !== id)
      await api()(`/api/board/labels/${id}`, { method: 'DELETE' })
    },

    // ----- Cartões -----
    async criarCartao(listId: number, title: string) {
      const titulo = title.trim()
      if (!titulo) return
      const criado = await api()<any>('/api/tasks', { method: 'POST', body: { title: titulo, task_list_id: listId } })
      this.cards.unshift({
        id: criado.id,
        task_list_id: criado.task_list_id ?? listId,
        title: criado.title,
        description: criado.description ?? null,
        client: criado.client ?? null,
        priority: criado.priority ?? 'media',
        type: criado.type ?? null,
        due: criado.due ?? null,
        due_at: criado.due_at ?? null,
        position: criado.position ?? 0,
        archived_at: null,
        conversation_id: criado.conversation_id ?? null,
        label_ids: [],
        members: [],
        checklist_total: 0,
        checklist_done: 0,
        comments_count: 0,
        attachments_count: 0,
      })
    },

    async atualizarCartao(id: number, patch: Record<string, any>) {
      const c = this.cards.find(x => x.id === id)
      if (c) Object.assign(c, patch)
      if (this.aberto?.id === id) Object.assign(this.aberto, patch)
      await api()(`/api/tasks/${id}`, { method: 'PATCH', body: patch })
    },

    /**
     * Move o cartão para uma lista e uma posição — SÓ na tela. É chamado a cada passada
     * do mouse durante o arrasto, então não pode falar com o servidor; quem grava é o
     * `soltarCartao`, no fim.
     */
    moverCartaoLocal(id: number, listId: number, index: number) {
      const card = this.cards.find(c => c.id === id)
      if (!card) return
      const destino = this.cards.filter(c => c.task_list_id === listId && c.id !== id).sort(byPos)
      const alvo = Math.max(0, Math.min(index, destino.length))
      destino.splice(alvo, 0, card)
      card.task_list_id = listId
      destino.forEach((c, i) => { c.position = i })
    },

    async soltarCartao(id: number) {
      const card = this.cards.find(c => c.id === id)
      if (!card || !card.task_list_id) return
      const index = this.cards
        .filter(c => c.task_list_id === card.task_list_id)
        .sort(byPos)
        .findIndex(c => c.id === id)
      await api()(`/api/tasks/${id}/move`, { method: 'PATCH', body: { task_list_id: card.task_list_id, index } })
    },

    /** Mover pelo modal (e pelo celular, onde arrastar não existe). */
    async moverCartao(id: number, listId: number, index = 0) {
      this.moverCartaoLocal(id, listId, index)
      if (this.aberto?.id === id) this.aberto.task_list_id = listId
      await api()(`/api/tasks/${id}/move`, { method: 'PATCH', body: { task_list_id: listId, index } })
    },

    async arquivarCartao(id: number) {
      this.cards = this.cards.filter(c => c.id !== id)
      if (this.aberto?.id === id) this.aberto = null
      this.arquivo.cards = []
      await api()(`/api/tasks/${id}/archive`, { method: 'POST' })
    },

    async restaurarCartao(id: number) {
      this.arquivo.cards = this.arquivo.cards.filter(c => c.id !== id)
      const card = await api()<BoardCard>(`/api/tasks/${id}/restore`, { method: 'POST' })
      this.cards.push(card)
    },

    async apagarCartao(id: number) {
      this.cards = this.cards.filter(c => c.id !== id)
      this.arquivo.cards = this.arquivo.cards.filter(c => c.id !== id)
      if (this.aberto?.id === id) this.aberto = null
      await api()(`/api/tasks/${id}`, { method: 'DELETE' })
    },

    // ----- Etiquetas e responsáveis do cartão -----
    async alternarEtiqueta(id: number, labelId: number) {
      const card = this.cards.find(c => c.id === id) ?? this.aberto
      if (!card) return
      const ids = card.label_ids.includes(labelId)
        ? card.label_ids.filter(x => x !== labelId)
        : [...card.label_ids, labelId]
      this.aplicarNoCartao(id, { label_ids: ids })
      await api()(`/api/tasks/${id}/labels`, { method: 'PUT', body: { label_ids: ids } })
    },

    async alternarResponsavel(id: number, userId: number) {
      const card = this.cards.find(c => c.id === id) ?? this.aberto
      if (!card) return
      const tem = card.members.some(m => m.id === userId)
      const ids = tem ? card.members.filter(m => m.id !== userId).map(m => m.id) : [...card.members.map(m => m.id), userId]
      const membros = ids.map(i => ({ id: i, name: this.members.find(m => m.id === i)?.name ?? '' }))
      this.aplicarNoCartao(id, { members: membros })
      await api()(`/api/tasks/${id}/members`, { method: 'PUT', body: { user_ids: ids } })
    },

    /** Espelha uma mudança no cartão do quadro e no modal, quando é o mesmo cartão. */
    aplicarNoCartao(id: number, patch: Record<string, any>) {
      const c = this.cards.find(x => x.id === id)
      if (c) Object.assign(c, patch)
      if (this.aberto?.id === id) Object.assign(this.aberto, patch)
    },

    // ----- Modal do cartão -----
    async abrirCartao(id: number) {
      this.abertoCarregando = true
      const base = this.cards.find(c => c.id === id) ?? this.arquivo.cards.find(c => c.id === id)
      if (base) this.aberto = { ...base, checklist: [], comments: [], attachments: [] }
      try {
        this.aberto = await api()<CardDetail>(`/api/tasks/${id}/card`)
      }
      catch { /* fica com o resumo que já estava na tela */ }
      finally { this.abertoCarregando = false }
    },

    fecharCartao() {
      this.aberto = null
    },

    async addItemChecklist(text: string) {
      const card = this.aberto
      if (!card || !text.trim()) return
      const item = await api()<ChecklistItem>(`/api/tasks/${card.id}/checklist`, { method: 'POST', body: { text: text.trim() } })
      card.checklist.push(item)
      this.recontarChecklist()
    },

    async alternarItemChecklist(itemId: number) {
      const card = this.aberto
      const item = card?.checklist.find(i => i.id === itemId)
      if (!card || !item) return
      item.done = !item.done
      this.recontarChecklist()
      await api()(`/api/tasks/${card.id}/checklist/${itemId}`, { method: 'PATCH', body: { done: item.done } })
    },

    async renomearItemChecklist(itemId: number, text: string) {
      const card = this.aberto
      const item = card?.checklist.find(i => i.id === itemId)
      if (!card || !item || !text.trim()) return
      item.text = text.trim()
      await api()(`/api/tasks/${card.id}/checklist/${itemId}`, { method: 'PATCH', body: { text: item.text } })
    },

    async removerItemChecklist(itemId: number) {
      const card = this.aberto
      if (!card) return
      card.checklist = card.checklist.filter(i => i.id !== itemId)
      this.recontarChecklist()
      await api()(`/api/tasks/${card.id}/checklist/${itemId}`, { method: 'DELETE' })
    },

    recontarChecklist() {
      const card = this.aberto
      if (!card) return
      const total = card.checklist.length
      const feitos = card.checklist.filter(i => i.done).length
      this.aplicarNoCartao(card.id, { checklist_total: total, checklist_done: feitos })
      card.checklist_total = total
      card.checklist_done = feitos
    },

    async comentar(body: string) {
      const card = this.aberto
      if (!card || !body.trim()) return
      const c = await api()<CardComment>(`/api/tasks/${card.id}/comments`, { method: 'POST', body: { body: body.trim() } })
      card.comments.unshift(c)
      card.comments_count = card.comments.length
      this.aplicarNoCartao(card.id, { comments_count: card.comments.length })
    },

    async apagarComentario(id: number) {
      const card = this.aberto
      if (!card) return
      card.comments = card.comments.filter(c => c.id !== id)
      card.comments_count = card.comments.length
      this.aplicarNoCartao(card.id, { comments_count: card.comments.length })
      await api()(`/api/tasks/${card.id}/comments/${id}`, { method: 'DELETE' })
    },

    async anexar(file: File) {
      const card = this.aberto
      if (!card) return
      const form = new FormData()
      form.append('file', file)
      const anexo = await api()<CardAttachment>(`/api/tasks/${card.id}/attachments`, { method: 'POST', body: form })
      card.attachments.unshift(anexo)
      card.attachments_count = card.attachments.length
      this.aplicarNoCartao(card.id, { attachments_count: card.attachments.length })
    },

    async removerAnexo(id: number) {
      const card = this.aberto
      if (!card) return
      card.attachments = card.attachments.filter(a => a.id !== id)
      card.attachments_count = card.attachments.length
      this.aplicarNoCartao(card.id, { attachments_count: card.attachments.length })
      await api()(`/api/tasks/${card.id}/attachments/${id}`, { method: 'DELETE' })
    },

    // ----- Arquivo -----
    async abrirArquivo() {
      this.arquivo.aberto = true
      this.arquivo.carregando = true
      try {
        const data = await api()<any>('/api/board/archived')
        this.arquivo.cards = data.cards ?? []
        this.arquivo.lists = data.lists ?? []
      }
      finally { this.arquivo.carregando = false }
    },

    async restaurarLista(id: number) {
      this.arquivo.lists = this.arquivo.lists.filter(l => l.id !== id)
      await api()(`/api/board/lists/${id}/restore`, { method: 'POST' })
      await this.load(true)
    },

    limparFiltros() {
      this.filtro.texto = ''
      this.filtro.labelIds = []
      this.filtro.memberIds = []
      this.filtro.prio = ''
      this.filtro.prazo = ''
    },
  },
})
