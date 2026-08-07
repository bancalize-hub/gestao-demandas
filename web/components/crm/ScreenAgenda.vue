<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { type CalEvent, useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
const route = useRoute()
const router = useRouter()

// Cores em rotação para diferenciar visualmente os eventos.
const PALETTE = [
  { bar: 'var(--c-info)', bg: 'rgba(83,189,235,.16)', fg: 'var(--c-info-soft)' },
  { bar: 'var(--c-ai)', bg: 'rgba(124,108,245,.16)', fg: 'var(--c-ai-faint)' },
  { bar: 'var(--c-warn)', bg: 'rgba(255,180,67,.16)', fg: 'var(--c-warn-soft)' },
  { bar: 'var(--accent)', bg: 'rgba(var(--accent-rgb),.16)', fg: 'var(--accent-soft)' },
]
const DAY_NAMES = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB']
const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro']
const START_HOUR = 7
const END_HOUR = 21
const HOUR_H = 58 // px por hora

const HOURS = Array.from({ length: END_HOUR - START_HOUR }, (_, i) => START_HOUR + i)

// ----- Semana atual (segunda como início) -----
function startOfWeek(d: Date): Date {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  const dow = x.getDay() // 0=dom
  const diff = (dow === 0 ? -6 : 1) - dow // volta até segunda
  x.setDate(x.getDate() + diff)
  return x
}
const weekStart = ref(startOfWeek(new Date()))

const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => {
  const d = new Date(weekStart.value)
  d.setDate(d.getDate() + i)
  return d
}))

const rangeLabel = computed(() => {
  const a = weekDays.value[0]
  const b = weekDays.value[6]
  const mesA = MONTHS[a.getMonth()]
  const mesB = MONTHS[b.getMonth()]
  if (a.getMonth() === b.getMonth())
    return `${a.getDate()} – ${b.getDate()} de ${mesA}, ${b.getFullYear()}`
  return `${a.getDate()} ${mesA} – ${b.getDate()} ${mesB}, ${b.getFullYear()}`
})

function isToday(d: Date) {
  const t = new Date()
  return d.getFullYear() === t.getFullYear() && d.getMonth() === t.getMonth() && d.getDate() === t.getDate()
}
function sameDay(a: Date, b: Date) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

// ----- Carga -----
async function loadWeek() {
  const from = new Date(weekDays.value[0]); from.setHours(0, 0, 0, 0)
  const to = new Date(weekDays.value[6]); to.setHours(23, 59, 59, 999)
  await crm.fetchEvents(from.toISOString(), to.toISOString())
}
function shiftWeek(delta: number) {
  const d = new Date(weekStart.value)
  d.setDate(d.getDate() + delta * 7)
  weekStart.value = d
  loadWeek()
}
function goToday() {
  weekStart.value = startOfWeek(new Date())
  loadWeek()
}

const banner = ref<{ type: 'ok' | 'erro', text: string } | null>(null)

onMounted(async () => {
  await crm.loadGoogleStatus()
  // Feedback do retorno do OAuth (?google=conectado|erro).
  const g = route.query.google
  if (g === 'conectado') {
    banner.value = { type: 'ok', text: 'Google Agenda conectado!' }
    await crm.loadGoogleStatus()
  }
  else if (g === 'erro') {
    banner.value = { type: 'erro', text: 'Não foi possível conectar o Google. Tente de novo.' }
  }
  if (g) router.replace({ query: {} })
  if (crm.hasCalendars) await loadWeek()
})

// ----- Posicionamento dos eventos na grade -----
// left/width em % da coluna do dia: eventos que se sobrepõem dividem a largura em vez de
// ficarem um em cima do outro (com as agendas de vários usuários juntas, sobreposição é
// a regra, não a exceção — e o card de baixo sumia por completo).
interface Positioned { ev: CalEvent, top: number, height: number, color: typeof PALETTE[number], label: string, left: number, width: number }

// Reunião com presença confirmada do cliente: verde forte, bem destacado.
const ATTENDED = { bar: 'var(--accent-hi)', bg: 'rgba(var(--accent-rgb),.38)', fg: 'var(--accent-soft)' }
// Cliente NÃO compareceu (presença apurada): vermelho.
const NOSHOW = { bar: 'var(--c-orange-strong)', bg: 'rgba(255,90,60,.20)', fg: 'var(--c-orange-soft)' }

function colorFor(ev: CalEvent) {
  // Presença apurada fala mais alto que a agenda de origem: verde/vermelho continuam
  // sendo a leitura mais importante do card.
  if (ev.attended) return ATTENDED
  if (ev.no_show) return NOSHOW
  // Fora isso, a cor diz DE QUEM é a agenda — como no Google Agenda.
  if (ev.owner_color) return { bar: ev.owner_color, bg: `${ev.owner_color}2b`, fg: ev.owner_color }
  if (ev.task_id) return PALETTE[3]
  let h = 0
  for (const c of ev.id) h = (h + c.charCodeAt(0)) % PALETTE.length
  return PALETTE[h]
}
function hm(iso: string | null) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

function eventsForDay(day: Date): Positioned[] {
  // 1) Os eventos do dia, já com a posição vertical.
  const doDia: (Positioned & { ini: number, fim: number })[] = []
  for (const ev of crm.visibleEvents) {
    if (!ev.starts_at) continue
    const s = new Date(ev.starts_at)
    if (!sameDay(s, day) || ev.all_day) continue
    const e = ev.ends_at ? new Date(ev.ends_at) : new Date(s.getTime() + 3600_000)
    const startH = s.getHours() + s.getMinutes() / 60
    const endH = e.getHours() + e.getMinutes() / 60
    const top = Math.max(0, (startH - START_HOUR) * HOUR_H)
    const height = Math.max(26, (Math.min(endH, END_HOUR) - Math.max(startH, START_HOUR)) * HOUR_H - 3)
    doDia.push({
      ev, top, height, color: colorFor(ev), label: `${hm(ev.starts_at)} – ${hm(ev.ends_at)}`,
      left: 0, width: 100, ini: s.getTime(), fim: Math.max(e.getTime(), s.getTime() + 15 * 60_000),
    })
  }
  doDia.sort((a, b) => a.ini - b.ini || a.fim - b.fim)

  // 2) Divide a largura entre os que se sobrepõem. Um "grupo" é uma sequência encadeada de
  //    eventos que se tocam; dentro dele cada um pega a primeira coluna livre.
  let grupo: typeof doDia = []
  let fimDoGrupo = -Infinity
  const fechaGrupo = () => {
    if (!grupo.length) return
    const colunas: number[] = [] // fim do último evento de cada coluna
    const daColuna: number[] = []
    for (const p of grupo) {
      let c = colunas.findIndex(fim => fim <= p.ini)
      if (c === -1) { c = colunas.length; colunas.push(0) }
      colunas[c] = p.fim
      daColuna.push(c)
    }
    const total = colunas.length
    grupo.forEach((p, i) => { p.width = 100 / total; p.left = (daColuna[i] * 100) / total })
    grupo = []
    fimDoGrupo = -Infinity
  }
  for (const p of doDia) {
    if (p.ini >= fimDoGrupo) fechaGrupo()
    grupo.push(p)
    fimDoGrupo = Math.max(fimDoGrupo, p.fim)
  }
  fechaGrupo()

  return doDia
}

const allDayFor = (day: Date) => crm.visibleEvents.filter(ev => ev.starts_at && ev.all_day && sameDay(new Date(ev.starts_at), day))

// Lista lateral: eventos de hoje, ordenados por horário.
const todayEvents = computed(() => crm.visibleEvents
  .filter(ev => ev.starts_at && isToday(new Date(ev.starts_at)))
  .sort((a, b) => new Date(a.starts_at!).getTime() - new Date(b.starts_at!).getTime()))
const today = new Date()

// ----- Modal de evento -----
const modalOpen = ref(false)
const saving = ref(false)
const editingId = ref<string | null>(null)
const form = reactive({ title: '', date: '', start: '09:00', end: '10:00', location: '', description: '', deal_id: '' as string | number, conversation_slug: '', guests: '', add_meet: false, owner_id: 0 })

/** Agenda padrão ao criar um evento: a minha, se eu tiver Google; senão a 1ª da empresa. */
function defaultOwner(): number {
  return (crm.calendars.find(c => c.is_me) ?? crm.calendars[0])?.user_id ?? 0
}
const ownerName = (id?: number) => crm.calendars.find(c => c.user_id === id)?.name ?? ''
// Link do Meet do evento em edição (se já existir).
const editingMeet = ref<string | null>(null)

// Quebra a lista de convidados (vírgula / espaço / quebra de linha) em e-mails válidos.
function parseGuests(raw: string): string[] {
  return raw.split(/[\s,;]+/).map(s => s.trim()).filter(s => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s))
}

function ymd(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function openNew(day?: Date) {
  editingId.value = null
  editingMeet.value = null
  const base = day ?? new Date()
  Object.assign(form, { title: '', date: ymd(base), start: '09:00', end: '10:00', location: '', description: '', deal_id: '', conversation_slug: '', guests: '', add_meet: false, owner_id: defaultOwner() })
  modalOpen.value = true
}
function openEdit(ev: CalEvent) {
  editingId.value = ev.id
  editingMeet.value = ev.hangout_link ?? null
  const s = ev.starts_at ? new Date(ev.starts_at) : new Date()
  const e = ev.ends_at ? new Date(ev.ends_at) : new Date(s.getTime() + 3600_000)
  const hhmm = (d: Date) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  Object.assign(form, {
    title: ev.title, date: ymd(s), start: hhmm(s), end: hhmm(e),
    location: ev.location ?? '', description: ev.description ?? '', deal_id: ev.deal_id ?? '',
    conversation_slug: ev.conversation_slug ?? '',
    // Convidados existentes (sem o organizador) viram texto editável.
    guests: (ev.attendees ?? []).filter(a => !a.organizer).map(a => a.email).join(', '),
    add_meet: !!ev.hangout_link,
    // O evento vive na agenda de quem o criou; o Google não move evento entre agendas.
    owner_id: ev.owner_id ?? defaultOwner(),
  })
  modalOpen.value = true
}
function closeModal() { modalOpen.value = false }

// Abre a FICHA do cliente da reunião (a partir do popup de detalhes).
function openClient(ev: CalEvent) {
  if (ev.conversation_slug) {
    crm.activeId = ev.conversation_slug
    crm.go('contact')
  }
  else {
    openEdit(ev)
  }
}

async function save() {
  if (!form.title.trim() || !form.date) return
  saving.value = true
  // Envia datetime local (sem timezone) — o servidor interpreta em America/Sao_Paulo.
  const payload: Partial<CalEvent> & { deal_id?: number | null, attendees?: string[], add_meet?: boolean } = {
    title: form.title.trim(),
    location: form.location.trim() || null,
    description: form.description.trim() || null,
    starts_at: `${form.date}T${form.start}:00`,
    ends_at: `${form.date}T${form.end}:00`,
    deal_id: form.deal_id ? Number(form.deal_id) : null,
    conversation_slug: form.conversation_slug || null,
    attendees: parseGuests(form.guests),
    add_meet: form.add_meet,
    owner_id: form.owner_id || undefined,
  }
  try {
    if (editingId.value) await crm.updateEvent(editingId.value, payload)
    else await crm.createEvent(payload)
    modalOpen.value = false
  }
  catch { banner.value = { type: 'erro', text: 'Erro ao salvar o evento.' } }
  finally { saving.value = false }
}
async function removeEvent() {
  if (!editingId.value) return
  saving.value = true
  await crm.deleteEvent(editingId.value)
  saving.value = false
  modalOpen.value = false
}

// ===== Melhorias da agenda =====

// Visão: dia | semana | mês.
const viewMode = ref<'dia' | 'semana' | 'mes'>('semana')
const focusedDay = ref(new Date()) // dia em foco na visão "dia"

// Status de uma reunião (só vale p/ eventos com cliente vinculado / Meet).
type EvStatus = 'compareceu' | 'faltou' | 'aovivo' | 'pendente' | 'futura' | 'simples'
function evStatus(ev: CalEvent): EvStatus {
  if (ev.attended) return 'compareceu'
  if (ev.no_show) return 'faltou'
  if (!ev.starts_at) return 'simples'
  const now = Date.now()
  const s = new Date(ev.starts_at).getTime()
  const e = ev.ends_at ? new Date(ev.ends_at).getTime() : s + 3600_000
  if (now >= s && now <= e) return 'aovivo'
  const isMeeting = !!(ev.hangout_link || ev.conversation_slug)
  if (e < now && isMeeting && !ev.checked) return 'pendente'
  return 'futura'
}

// Legenda exibida no topo.
const LEGEND = [
  { label: 'Compareceu', color: 'var(--accent-hi)' },
  { label: 'Não compareceu', color: 'var(--c-orange-strong)' },
  { label: 'Ao vivo', color: 'var(--c-info)' },
  { label: 'Aguardando apuração', color: 'var(--c-warn)' },
  { label: 'Agendada', color: 'var(--c-ai)' },
]

// RSVP: quantos convidados (fora o organizador) aceitaram.
function rsvp(ev: CalEvent) {
  const guests = (ev.attendees ?? []).filter(a => !a.organizer)
  const yes = guests.filter(a => a.response === 'accepted').length
  return { total: guests.length, yes }
}

// Telefone do lead vinculado (p/ botão WhatsApp), via conversa do store.
function convOf(ev: CalEvent) {
  return ev.conversation_slug ? crm.conversations.find(c => c.id === ev.conversation_slug) : undefined
}
function openWhatsApp(ev: CalEvent) {
  const c = convOf(ev)
  if (c) { crm.activeId = c.id; crm.go('chat') }
}

// Resumo da reunião num popover.
const summaryFor = ref<CalEvent | null>(null)

// Contadores do dia (barra lateral).
const dayCounts = computed(() => {
  const evs = todayEvents.value.filter(e => !e.all_day)
  let compareceu = 0; let faltou = 0; let pendente = 0
  for (const e of evs) {
    const s = evStatus(e)
    if (s === 'compareceu') compareceu++
    else if (s === 'faltou') faltou++
    else if (s === 'pendente') pendente++
  }
  return { total: evs.length, compareceu, faltou, pendente }
})

// ----- Arrastar p/ remarcar (semana/dia) -----
const dragId = ref<string | null>(null)
function onEventDragStart(ev: CalEvent, e: DragEvent) {
  dragId.value = ev.id
  e.dataTransfer?.setData('text/plain', ev.id)
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
}
async function onSlotDrop(day: Date, hour: number, e: DragEvent) {
  const id = dragId.value || e.dataTransfer?.getData('text/plain')
  dragId.value = null
  if (!id) return
  const ev = crm.events.find(x => x.id === id)
  if (!ev || !ev.starts_at) return
  // Minuto pelo offset vertical dentro da hora (snap de 15 min).
  const rect = (e.currentTarget as HTMLElement).getBoundingClientRect()
  const frac = Math.min(0.999, Math.max(0, (e.clientY - rect.top) / rect.height))
  const minute = Math.round((frac * 60) / 15) * 15
  const durMs = (ev.ends_at ? new Date(ev.ends_at).getTime() : new Date(ev.starts_at).getTime() + 3600_000) - new Date(ev.starts_at).getTime()
  const ns = new Date(day); ns.setHours(hour, minute, 0, 0)
  const ne = new Date(ns.getTime() + durMs)
  const iso = (d: Date) => `${ymd(d)}T${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:00`
  try {
    await crm.updateEvent(id, { starts_at: iso(ns), ends_at: iso(ne) })
    banner.value = { type: 'ok', text: 'Reunião remarcada.' }
  }
  catch { banner.value = { type: 'erro', text: 'Não consegui remarcar.' } }
}

// ----- Visão mês -----
const monthCursor = ref(new Date())
const monthGrid = computed(() => {
  const y = monthCursor.value.getFullYear(); const m = monthCursor.value.getMonth()
  const first = new Date(y, m, 1)
  const start = startOfWeek(first)
  return Array.from({ length: 42 }, (_, i) => {
    const d = new Date(start); d.setDate(start.getDate() + i)
    return d
  })
})
const monthLabel = computed(() => `${MONTHS[monthCursor.value.getMonth()]} ${monthCursor.value.getFullYear()}`)
function shiftMonth(delta: number) {
  const d = new Date(monthCursor.value); d.setMonth(d.getMonth() + delta); monthCursor.value = d
  const from = new Date(d.getFullYear(), d.getMonth(), 1); from.setDate(from.getDate() - 7)
  const to = new Date(d.getFullYear(), d.getMonth() + 1, 7)
  crm.fetchEvents(from.toISOString(), to.toISOString())
}
function eventsOfDay(day: Date) {
  return crm.visibleEvents.filter(ev => ev.starts_at && sameDay(new Date(ev.starts_at), day) && !ev.all_day)
    .sort((a, b) => new Date(a.starts_at!).getTime() - new Date(b.starts_at!).getTime())
}
// Dias mostrados na grade conforme a visão (semana=7, dia=1).
const gridDays = computed(() => viewMode.value === 'dia' ? [focusedDay.value] : weekDays.value)
const gridCols = computed(() => `54px repeat(${gridDays.value.length},1fr)`)

// Rótulo do período conforme a visão.
const periodLabel = computed(() => {
  if (viewMode.value === 'mes') return monthLabel.value
  if (viewMode.value === 'dia') {
    const d = focusedDay.value
    return `${DAY_NAMES[d.getDay()]}, ${d.getDate()} de ${MONTHS[d.getMonth()]} ${d.getFullYear()}`
  }
  return rangeLabel.value
})

// Navegação anterior/próximo adaptada à visão.
function nav(delta: number) {
  if (viewMode.value === 'mes') { shiftMonth(delta); return }
  if (viewMode.value === 'dia') {
    const d = new Date(focusedDay.value); d.setDate(d.getDate() + delta); focusedDay.value = d
    const from = new Date(d); from.setHours(0, 0, 0, 0)
    const to = new Date(d); to.setHours(23, 59, 59, 999)
    crm.fetchEvents(from.toISOString(), to.toISOString())
    return
  }
  shiftWeek(delta)
}
/** Abre um dia específico na visão "Dia" — saída do "+N mais" da visão Mês. */
function abrirDia(d: Date) {
  focusedDay.value = new Date(d)
  viewMode.value = 'dia'
  const from = new Date(d); from.setHours(0, 0, 0, 0)
  const to = new Date(d); to.setHours(23, 59, 59, 999)
  crm.fetchEvents(from.toISOString(), to.toISOString())
}
function setView(v: 'dia' | 'semana' | 'mes') {
  viewMode.value = v
  if (v === 'mes') shiftMonth(0)
  else if (v === 'dia') { focusedDay.value = new Date(); nav(0) }
  else loadWeek()
}

// ===== Linha do "agora" =====

/**
 * O relógio da tela. A linha do horário atual só desce se algo re-renderizar: sem este
 * tick ela congelaria na hora em que a página abriu, que é pior do que não ter linha.
 * Meio minuto é o passo mais grosso que ainda parece contínuo (a linha anda ~0,5 px).
 */
const agora = ref(new Date())
let relogio: ReturnType<typeof setInterval> | null = null
onMounted(() => { relogio = setInterval(() => (agora.value = new Date()), 30_000) })
onBeforeUnmount(() => { if (relogio) clearInterval(relogio) })

/** Altura (px) do horário atual dentro da grade — null quando está fora da faixa 07h–21h. */
const nowTop = computed(() => {
  const h = agora.value.getHours() + agora.value.getMinutes() / 60
  if (h < START_HOUR || h > END_HOUR) return null
  return (h - START_HOUR) * HOUR_H
})
const nowLabel = computed(() => agora.value.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' }))

/** A grade abre já no horário atual em vez de às 07:00 — o dia começa onde você está. */
const gradeRef = ref<HTMLElement | null>(null)
onMounted(() => {
  requestAnimationFrame(() => {
    if (gradeRef.value && nowTop.value !== null) gradeRef.value.scrollTop = Math.max(0, nowTop.value - 140)
  })
})

/** A próxima reunião: a que está acontecendo agora ou, na falta, a primeira que ainda vem. */
const proxima = computed(() => {
  const t = agora.value.getTime()
  return crm.visibleEvents
    .filter(ev => ev.starts_at && !ev.all_day)
    .filter(ev => (ev.ends_at ? new Date(ev.ends_at).getTime() : new Date(ev.starts_at!).getTime() + 3600_000) >= t)
    .sort((a, b) => new Date(a.starts_at!).getTime() - new Date(b.starts_at!).getTime())[0] ?? null
})

/** "agora", "em 25 min", "em 2h10" — contagem regressiva curta p/ o chip do topo. */
function faltam(ev: CalEvent): string {
  const min = Math.round((new Date(ev.starts_at!).getTime() - agora.value.getTime()) / 60000)
  if (min <= 0) return 'agora'
  if (min < 60) return `em ${min} min`
  const h = Math.floor(min / 60)
  const m = min % 60
  return m ? `em ${h}h${String(m).padStart(2, '0')}` : `em ${h}h`
}
function ehHoje(ev: CalEvent) { return !!ev.starts_at && isToday(new Date(ev.starts_at)) }

// ===== Popup de detalhes do evento =====

/**
 * Clicar no card abre os DETALHES, não a ficha do cliente: o clique era um atalho que
 * levava para outra tela sem mostrar o que era a reunião. A ficha, a conversa e o Meet
 * viraram botões aqui dentro.
 */
const detalhe = ref<CalEvent | null>(null)
/** O evento pode ser recarregado (presença apurada, resumo) — segue o que está no store. */
const detalheAtual = computed(() => detalhe.value ? (crm.events.find(e => e.id === detalhe.value!.id) ?? detalhe.value) : null)
function openDetails(ev: CalEvent) { detalhe.value = ev }
function closeDetails() { detalhe.value = null }

const STATUS_TXT: Record<EvStatus, { label: string, color: string } | null> = {
  compareceu: { label: '✓ Cliente compareceu', color: 'var(--accent-hi)' },
  faltou: { label: '✗ Cliente não compareceu', color: 'var(--c-orange-strong)' },
  aovivo: { label: '🔴 Acontecendo agora', color: 'var(--c-info)' },
  pendente: { label: '⏳ Aguardando apuração de presença', color: 'var(--c-warn)' },
  futura: { label: 'Agendada', color: 'var(--c-ai)' },
  simples: null,
}

/** "Terça-feira, 4 de agosto · 16:30 – 17:30" */
function dataLonga(ev: CalEvent): string {
  if (!ev.starts_at) return ''
  const s = new Date(ev.starts_at)
  const diaSemana = s.toLocaleDateString('pt-BR', { weekday: 'long' })
  const dia = `${diaSemana.charAt(0).toUpperCase()}${diaSemana.slice(1)}, ${s.getDate()} de ${MONTHS[s.getMonth()].toLowerCase()}`
  return ev.all_day ? `${dia} · dia inteiro` : `${dia} · ${hm(ev.starts_at)} – ${hm(ev.ends_at)}`
}

/** Reunião já terminou? Só aí faz sentido perguntar se aconteceu. */
function jaPassou(ev: CalEvent): boolean {
  const fim = ev.ends_at || ev.starts_at
  return !!fim && new Date(fim).getTime() < Date.now()
}

const marcando = ref(false)
async function marcarPresenca(ev: CalEvent, attended: boolean) {
  marcando.value = true
  try { await crm.markAttendance(ev.id, attended) }
  catch { banner.value = { type: 'erro', text: 'Não consegui marcar a reunião. Tente de novo.' } }
  finally { marcando.value = false }
}

function editarDoDetalhe(ev: CalEvent) { closeDetails(); openEdit(ev) }
function fichaDoDetalhe(ev: CalEvent) { closeDetails(); openClient(ev) }
function conversaDoDetalhe(ev: CalEvent) { closeDetails(); openWhatsApp(ev) }
async function excluirDoDetalhe(ev: CalEvent) {
  saving.value = true
  try { await crm.deleteEvent(ev.id) }
  catch { banner.value = { type: 'erro', text: 'Não consegui excluir o evento.' } }
  finally { saving.value = false; closeDetails() }
}
</script>

<template>
  <div style="flex:1;display:flex;min-width:0;background:var(--c-bg-deep);">
    <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
      <!-- Cabeçalho -->
      <div style="padding:22px 30px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--c-surface-1);">
        <div>
          <div style="display:flex;align-items:center;gap:12px;">
            <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Agenda</div>
            <!-- Relógio + próxima reunião: dá a hora e o que vem a seguir sem ler a grade -->
            <span style="font-size:12.5px;font-weight:700;color:var(--c-danger);background:var(--c-surface-1);padding:4px 10px;border-radius:8px;font-variant-numeric:tabular-nums;">🕐 {{ nowLabel }}</span>
            <button
              v-if="proxima"
              style="background:var(--c-surface-1);border:none;color:var(--c-text-secondary);font-family:inherit;font-size:12.5px;font-weight:600;padding:4px 10px;border-radius:8px;cursor:pointer;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"
              :title="`Ver detalhes de ${proxima.title}`"
              @click="openDetails(proxima!)"
            >
              Próxima: <strong style="color:var(--c-text);">{{ hm(proxima.starts_at) }} {{ proxima.title }}</strong>
              <span style="color:var(--accent-soft);"> · {{ ehHoje(proxima) ? faltam(proxima) : 'outro dia' }}</span>
            </button>
          </div>
          <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:3px;">{{ periodLabel }}</div>
        </div>
        <div v-if="crm.hasCalendars" style="display:flex;gap:10px;align-items:center;">
          <!-- Seletor de visão -->
          <div style="display:flex;background:var(--c-surface-2);border-radius:10px;overflow:hidden;">
            <button v-for="v in (['dia','semana','mes'] as const)" :key="v" class="seg" :style="`border:none;font-family:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;cursor:pointer;${viewMode === v ? 'background:var(--accent);color:var(--accent-ink);' : 'background:transparent;color:var(--c-text-muted);'}`" @click="setView(v)">{{ v === 'dia' ? 'Dia' : v === 'semana' ? 'Semana' : 'Mês' }}</button>
          </div>
          <div style="display:flex;background:var(--c-surface-2);border-radius:10px;overflow:hidden;">
            <button class="seg" style="border:none;background:transparent;color:var(--c-text-muted);padding:8px 11px;cursor:pointer;" @click="nav(-1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 6-6 6 6 6" /></svg></button>
            <button class="seg" style="border:none;background:transparent;color:var(--c-text-muted);padding:8px 11px;cursor:pointer;" @click="nav(1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6" /></svg></button>
          </div>
          <button class="seg" style="border:none;background:var(--c-surface-2);border-radius:10px;color:var(--c-text-muted);font-family:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;cursor:pointer;" @click="setView('semana'); goToday()">Hoje</button>
          <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:6px;" @click="openNew()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Evento</button>
        </div>
      </div>

      <!-- Legenda das cores -->
      <div v-if="crm.hasCalendars" style="display:flex;flex-wrap:wrap;gap:14px;padding:10px 30px;border-bottom:1px solid var(--c-surface-1);">
        <span v-for="l in LEGEND" :key="l.label" style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;color:var(--c-text-muted);font-weight:600;"><span :style="{ width: '9px', height: '9px', borderRadius: '3px', background: l.color }" />{{ l.label }}</span>
      </div>

      <div v-if="banner" :style="`margin:14px 30px 0;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;${banner.type === 'ok' ? 'background:var(--c-accent-surf);color:var(--accent-soft);border:1px solid rgba(var(--accent-rgb),.3);' : 'background:var(--c-danger-bg);color:var(--c-danger-soft);border:1px solid rgba(255,107,107,.3);'}`">
        {{ banner.text }}
      </div>

      <!-- Nenhuma agenda na EMPRESA ainda: CTA. Basta um usuário conectar para todos verem. -->
      <div v-if="!crm.hasCalendars" style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:18px;padding:40px;">
        <div style="width:64px;height:64px;border-radius:18px;background:var(--c-surface-2);display:flex;align-items:center;justify-content:center;">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
        </div>
        <div style="text-align:center;max-width:380px;">
          <div style="font-size:18px;font-weight:700;">Conecte o Google Agenda da empresa</div>
          <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:6px;line-height:1.5;">Basta uma conta conectada: a agenda passa a valer para toda a equipe, com as reuniões que a IA marcar já aparecendo aqui.</div>
        </div>
        <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:14px;font-weight:700;padding:11px 20px;border-radius:11px;cursor:pointer;" @click="crm.connectGoogle()">Conectar com Google</button>
      </div>

      <!-- Grade da semana / dia -->
      <div v-else-if="viewMode !== 'mes'" ref="gradeRef" style="flex:1;overflow:auto;padding:0 30px 24px;">
        <!-- Cabeçalho dos dias -->
        <div :style="`display:grid;grid-template-columns:${gridCols};position:sticky;top:0;background:var(--c-bg-deep);z-index:3;padding-top:14px;`">
          <div />
          <div v-for="d in gridDays" :key="d.toISOString()" style="text-align:center;padding-bottom:12px;">
            <div :style="`font-size:11.5px;${isToday(d) ? 'color:var(--accent);font-weight:700;' : 'color:var(--c-text-muted);'}`">{{ DAY_NAMES[d.getDay()] }}</div>
            <div v-if="isToday(d)" style="width:34px;height:34px;border-radius:50%;background:var(--accent);color:var(--accent-ink);font-size:17px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:2px auto 0;">{{ d.getDate() }}</div>
            <div v-else style="font-size:18px;font-weight:700;margin-top:2px;">{{ d.getDate() }}</div>
            <!-- Eventos de dia inteiro -->
            <div v-for="ev in allDayFor(d)" :key="ev.id" style="margin-top:4px;background:rgba(83,189,235,.16);border-radius:6px;padding:3px 5px;font-size:10px;color:var(--c-info-soft);cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" @click="openEdit(ev)">{{ ev.title }}</div>
          </div>
        </div>

        <!-- Linhas de hora + colunas -->
        <div style="position:relative;">
          <div v-for="h in HOURS" :key="h" :style="`display:grid;grid-template-columns:${gridCols};`">
            <div :style="`font-size:11px;color:var(--c-text-muted);text-align:right;padding:0 10px;height:${HOUR_H}px;border-top:1px solid var(--c-surface-0);`">{{ String(h).padStart(2, '0') }}:00</div>
            <div v-for="d in gridDays" :key="h + d.toISOString()" style="border-top:1px solid var(--c-surface-0);border-left:1px solid var(--c-surface-0);cursor:pointer;" @click="openNew(d)" @dragover.prevent @drop.prevent="onSlotDrop(d, h, $event)" />
          </div>

          <!-- Camada dos eventos posicionados -->
          <div :style="`position:absolute;inset:0;display:grid;grid-template-columns:${gridCols};pointer-events:none;`">
            <!-- Coluna das horas: a etiqueta do horário atual, colada na linha -->
            <div style="position:relative;">
              <div
                v-if="nowTop !== null"
                :style="`position:absolute;right:6px;top:${nowTop - 9}px;background:var(--c-danger);color:#fff;font-size:10.5px;font-weight:800;padding:2px 6px;border-radius:6px;line-height:1.3;z-index:2;`"
              >{{ nowLabel }}</div>
            </div>
            <div v-for="d in gridDays" :key="`col${d.toISOString()}`" style="position:relative;">
              <!-- Linha do "agora": só no dia de hoje, descendo com o relógio -->
              <div
                v-if="isToday(d) && nowTop !== null"
                :style="`position:absolute;left:0;right:0;top:${nowTop}px;border-top:2px solid var(--c-danger);z-index:2;`"
              >
                <span :style="`position:absolute;left:-5px;top:-6px;width:10px;height:10px;border-radius:50%;background:var(--c-danger);`" />
              </div>
              <div
                v-for="p in eventsForDay(d)" :key="p.ev.id" :class="['evcard', { attended: p.ev.attended, live: evStatus(p.ev) === 'aovivo' }]"
                draggable="true"
                :style="`pointer-events:auto;position:absolute;left:calc(${p.left}% + 3px);width:calc(${p.width}% - 6px);top:${p.top}px;height:${p.height}px;border-radius:7px;padding:5px 8px;overflow:hidden;cursor:grab;` + (p.ev.attended
                  ? 'background:linear-gradient(135deg,var(--accent-hi),var(--accent-deep));border-left:5px solid var(--accent-hi);box-shadow:0 2px 14px rgba(var(--accent-rgb),.55);'
                  : `background:${p.color.bg};border-left:3px solid ${p.color.bar};${evStatus(p.ev) === 'aovivo' ? 'box-shadow:0 0 0 2px var(--c-info);' : ''}`)"
                :title="p.ev.conversation_name ? `Abrir ficha de ${p.ev.conversation_name}` : 'Abrir evento'"
                @click="openDetails(p.ev)"
                @dragstart="onEventDragStart(p.ev, $event)"
              >
                <button class="editpin" title="Editar evento" :style="`position:absolute;top:3px;right:3px;background:rgba(11,20,26,${p.ev.attended ? '.28' : '.6'});border:none;border-radius:6px;padding:2px;cursor:pointer;display:flex;color:${p.ev.attended ? 'var(--accent-ink)' : p.color.fg};`" @click.stop="openEdit(p.ev)"><svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                <div :style="`font-size:12px;font-weight:${p.ev.attended ? 800 : 700};color:${p.ev.attended ? 'var(--accent-ink)' : p.color.fg};overflow:hidden;text-overflow:ellipsis;white-space:nowrap;padding-right:16px;`">{{ p.ev.attended ? '✓ ' : (p.ev.no_show ? '✗ ' : (evStatus(p.ev) === 'aovivo' ? '🔴 ' : '')) }}{{ p.ev.title }}</div>
                <div :style="`font-size:10.5px;margin-top:1px;color:${p.ev.attended ? 'rgba(var(--accent-ink-rgb),.8)' : 'var(--c-text-muted)'};font-weight:${p.ev.attended ? 700 : 400};`">{{ p.label }}<span v-if="p.ev.reminder_sent && evStatus(p.ev) === 'futura'" title="Lembrete de WhatsApp enviado"> · 🔔</span></div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Visão mês -->
      <div v-else style="flex:1;overflow:auto;padding:14px 30px 24px;">
        <div style="display:grid;grid-template-columns:repeat(7,1fr);gap:1px;margin-bottom:6px;">
          <div v-for="dn in DAY_NAMES" :key="dn" style="text-align:center;font-size:11px;color:var(--c-text-muted);font-weight:700;padding:4px 0;">{{ dn }}</div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(7,1fr);grid-auto-rows:1fr;gap:6px;">
          <div
            v-for="d in monthGrid" :key="d.toISOString()"
            :style="`min-height:96px;background:${isToday(d) ? 'rgba(var(--accent-rgb),.06)' : 'var(--c-bg-deep)'};border:1px solid ${isToday(d) ? 'rgba(var(--accent-rgb),.4)' : 'var(--c-surface-0)'};border-radius:9px;padding:6px;display:flex;flex-direction:column;gap:3px;cursor:pointer;opacity:${d.getMonth() === monthCursor.getMonth() ? 1 : 0.4};`"
            @click="openNew(d)"
          >
            <div :style="`font-size:12px;font-weight:700;${isToday(d) ? 'color:var(--accent);' : 'color:var(--c-text-muted);'}`">{{ d.getDate() }}</div>
            <div
              v-for="ev in eventsOfDay(d).slice(0, 4)" :key="ev.id"
              :style="`font-size:10.5px;font-weight:600;border-radius:5px;padding:2px 5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;background:${colorFor(ev).bg};color:${colorFor(ev).fg};border-left:3px solid ${colorFor(ev).bar};`"
              :title="ev.title"
              @click.stop="openDetails(ev)"
            >{{ ev.attended ? '✓ ' : (ev.no_show ? '✗ ' : '') }}{{ hm(ev.starts_at) }} {{ ev.title }}</div>
            <div
              v-if="eventsOfDay(d).length > 4"
              style="font-size:10px;color:var(--accent);font-weight:700;cursor:pointer;"
              title="Ver o dia inteiro"
              @click.stop="abrirDia(d)"
            >+{{ eventsOfDay(d).length - 4 }} mais</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Painel lateral: agendas da empresa + hoje -->
    <div v-if="crm.hasCalendars" style="width:300px;flex-shrink:0;background:var(--c-bg);border-left:1px solid var(--c-surface-1);display:flex;flex-direction:column;">
      <!-- Seletor de agendas: cada usuário é uma camada que liga/desliga, como no Google Agenda -->
      <div style="padding:16px 20px 14px;border-bottom:1px solid var(--c-surface-1);">
        <div style="display:flex;align-items:center;justify-content:space-between;">
          <div style="font-weight:700;font-size:13px;">Agendas da empresa</div>
          <button
            v-if="crm.hiddenCalendars.length"
            style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:11.5px;font-weight:700;cursor:pointer;padding:0;"
            @click="crm.hiddenCalendars = []"
          >Mostrar todas</button>
        </div>
        <label
          v-for="c in crm.calendars" :key="c.user_id"
          style="display:flex;align-items:center;gap:9px;margin-top:9px;cursor:pointer;font-size:13px;"
          :title="c.email ?? ''"
        >
          <input
            type="checkbox" :checked="!crm.hiddenCalendars.includes(c.user_id)"
            :style="`width:15px;height:15px;cursor:pointer;accent-color:${c.color};flex-shrink:0;`"
            @change="crm.toggleCalendar(c.user_id)"
          >
          <span :style="`width:10px;height:10px;border-radius:3px;background:${c.color};flex-shrink:0;`" />
          <span style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ c.name }}<span v-if="c.is_me" style="color:var(--c-text-muted);"> (você)</span></span>
        </label>
        <button
          v-if="!crm.googleConnected"
          style="width:100%;margin-top:12px;background:transparent;border:1px dashed var(--c-surface-3);color:var(--accent);font-family:inherit;font-size:12px;font-weight:600;padding:8px;border-radius:9px;cursor:pointer;"
          @click="crm.connectGoogle()"
        >+ Adicionar minha agenda</button>
      </div>
      <div style="padding:16px 20px 14px;border-bottom:1px solid var(--c-surface-1);">
        <div style="font-weight:700;font-size:16px;">Hoje · {{ todayEvents.length }} {{ todayEvents.length === 1 ? 'evento' : 'eventos' }}</div>
        <div style="font-size:12.5px;color:var(--c-text-muted);margin-top:2px;">{{ DAY_NAMES[today.getDay()] }}, {{ today.getDate() }} de {{ MONTHS[today.getMonth()] }}</div>
        <div v-if="dayCounts.total" style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;">
          <span style="font-size:11px;font-weight:700;color:var(--accent-soft);background:rgba(var(--accent-rgb),.14);padding:3px 8px;border-radius:6px;">✓ {{ dayCounts.compareceu }} compareceu</span>
          <span v-if="dayCounts.faltou" style="font-size:11px;font-weight:700;color:var(--c-orange-soft);background:rgba(255,90,60,.16);padding:3px 8px;border-radius:6px;">✗ {{ dayCounts.faltou }} faltou</span>
          <span v-if="dayCounts.pendente" style="font-size:11px;font-weight:700;color:var(--c-warn-soft);background:rgba(255,180,67,.14);padding:3px 8px;border-radius:6px;">⏳ {{ dayCounts.pendente }} pendente</span>
        </div>
      </div>
      <div style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:11px;">
        <div v-if="!todayEvents.length" style="font-size:13px;color:var(--c-text-muted);text-align:center;margin-top:30px;">Nada agendado para hoje.</div>
        <div
          v-for="ev in todayEvents" :key="ev.id" :class="['evcard', { attended: ev.attended }]"
          :style="ev.attended
            ? 'position:relative;background:linear-gradient(135deg,var(--accent-hi),var(--accent-deep));border-radius:13px;padding:14px;cursor:pointer;box-shadow:0 2px 16px rgba(var(--accent-rgb),.5);'
            : ev.no_show
              ? 'position:relative;background:rgba(255,90,60,.18);border:1px solid rgba(255,90,60,.5);border-radius:13px;padding:14px;cursor:pointer;'
              : 'position:relative;background:var(--c-surface-2);border-radius:13px;padding:14px;cursor:pointer;'"
          :title="ev.conversation_name ? `Abrir ficha de ${ev.conversation_name}` : 'Abrir evento'"
          @click="openDetails(ev)"
        >
          <button class="editpin" title="Editar evento" :style="`position:absolute;top:10px;right:10px;background:${ev.attended ? 'rgba(var(--accent-ink-rgb),.18)' : 'var(--c-surface-1)'};border:none;border-radius:8px;padding:5px;cursor:pointer;display:flex;color:${ev.attended ? 'var(--accent-ink)' : 'var(--c-text-secondary)'};`" @click.stop="openEdit(ev)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
          <div :style="`font-size:11px;font-weight:700;color:${ev.attended ? 'rgba(var(--accent-ink-rgb),.85)' : (ev.no_show ? 'var(--c-orange-soft)' : 'var(--c-text-muted)')};`">{{ ev.attended ? '✓ COMPARECEU · ' : (ev.no_show ? '✗ NÃO COMPARECEU · ' : '') }}{{ ev.all_day ? 'Dia inteiro' : hm(ev.starts_at) }}</div>
          <div :style="`font-weight:800;font-size:14px;margin-top:6px;padding-right:26px;color:${ev.attended ? 'var(--accent-ink)' : 'var(--c-text)'};`">{{ ev.title }}</div>
          <div v-if="ev.location" :style="`font-size:12px;margin-top:3px;color:${ev.attended ? 'rgba(var(--accent-ink-rgb),.8)' : 'var(--c-text-muted)'};`">{{ ev.location }}</div>
          <div v-if="rsvp(ev).total" :style="`font-size:12px;margin-top:3px;color:${ev.attended ? 'rgba(var(--accent-ink-rgb),.8)' : 'var(--c-text-muted)'};`">👤 {{ rsvp(ev).total }} {{ rsvp(ev).total === 1 ? 'convidado' : 'convidados' }}<span v-if="rsvp(ev).yes"> · ✓ {{ rsvp(ev).yes }} confirmou</span></div>
          <div v-if="ev.reminder_sent && evStatus(ev) === 'futura'" :style="`font-size:11px;margin-top:4px;font-weight:600;color:${ev.attended ? 'var(--accent-ink)' : 'var(--accent-soft)'};`">🔔 Lembrete enviado ao cliente</div>
          <!-- Ações rápidas -->
          <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:10px;">
            <a v-if="ev.hangout_link" :href="ev.hangout_link" target="_blank" :style="`font-size:11.5px;text-decoration:none;font-weight:700;padding:5px 9px;border-radius:8px;background:${ev.attended ? 'rgba(var(--accent-ink-rgb),.15)' : 'var(--c-surface-1)'};color:${ev.attended ? 'var(--accent-ink)' : 'var(--accent)'};`" @click.stop>📹 Meet</a>
            <button v-if="convOf(ev)" :style="`font-size:11.5px;font-weight:700;border:none;cursor:pointer;padding:5px 9px;border-radius:8px;background:${ev.attended ? 'rgba(var(--accent-ink-rgb),.15)' : 'var(--c-surface-1)'};color:${ev.attended ? 'var(--accent-ink)' : 'var(--accent)'};`" @click.stop="openWhatsApp(ev)">💬 WhatsApp</button>
            <button v-if="ev.summary" :style="`font-size:11.5px;font-weight:700;border:none;cursor:pointer;padding:5px 9px;border-radius:8px;background:${ev.attended ? 'rgba(var(--accent-ink-rgb),.15)' : 'var(--c-surface-1)'};color:${ev.attended ? 'var(--accent-ink)' : 'var(--c-info-soft)'};`" @click.stop="summaryFor = ev">📋 Resumo</button>
            <button :style="`font-size:11.5px;font-weight:700;border:none;cursor:pointer;padding:5px 9px;border-radius:8px;background:${ev.attended ? 'rgba(var(--accent-ink-rgb),.15)' : 'var(--c-surface-1)'};color:${ev.attended ? 'var(--accent-ink)' : 'var(--c-text-secondary)'};`" @click.stop="openEdit(ev)">🕑 Remarcar</button>
          </div>
        </div>
      </div>
      <div v-if="crm.googleConnected" style="padding:14px 16px;border-top:1px solid var(--c-surface-1);">
        <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:8px;text-align:center;">Minha conta: {{ crm.googleEmail }}</div>
        <button style="width:100%;background:transparent;border:1px solid var(--c-surface-3);color:var(--c-text-muted);font-family:inherit;font-size:12.5px;padding:9px;border-radius:9px;cursor:pointer;" @click="crm.disconnectGoogle()">Desconectar minha agenda</button>
      </div>
    </div>

    <!-- Modal criar/editar evento -->
    <div v-if="modalOpen" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;" @click.self="closeModal">
      <div style="width:420px;max-width:92vw;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:22px;">
        <div style="font-size:17px;font-weight:800;margin-bottom:16px;">{{ editingId ? 'Editar evento' : 'Novo evento' }}</div>
        <!-- Em qual agenda da empresa o evento entra. Ao editar é só informativo: o Google
             não move um evento de uma agenda para outra. -->
        <template v-if="crm.calendars.length > 1">
          <label class="lbl">Agenda</label>
          <div v-if="editingId" style="display:flex;align-items:center;gap:8px;font-size:13px;padding:9px 0 2px;">
            <span :style="`width:10px;height:10px;border-radius:3px;background:${crm.calendars.find(c => c.user_id === form.owner_id)?.color ?? 'var(--c-text-muted)'};`" />
            {{ ownerName(form.owner_id) || '—' }}
          </div>
          <select v-else v-model.number="form.owner_id" class="inp">
            <option v-for="c in crm.calendars" :key="c.user_id" :value="c.user_id">{{ c.name }}{{ c.is_me ? ' (você)' : '' }}</option>
          </select>
        </template>
        <label class="lbl">Título</label>
        <input v-model="form.title" class="inp" placeholder="Ex.: Demo com cliente" >
        <div style="display:flex;gap:10px;">
          <div style="flex:1;"><label class="lbl">Data</label><input v-model="form.date" type="date" class="inp" ></div>
          <div style="width:90px;"><label class="lbl">Início</label><input v-model="form.start" type="time" class="inp" ></div>
          <div style="width:90px;"><label class="lbl">Fim</label><input v-model="form.end" type="time" class="inp" ></div>
        </div>
        <label class="lbl">Local / link</label>
        <input v-model="form.location" class="inp" placeholder="Sala, endereço ou link" >

        <label class="lbl">Convidados (e-mails separados por vírgula)</label>
        <textarea v-model="form.guests" class="inp" rows="2" placeholder="fulano@email.com, ciclano@email.com" />

        <label class="meet-toggle">
          <input v-model="form.add_meet" type="checkbox" >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 10l4.5-2.5v9L15 14M3 6h12v12H3z" stroke-linejoin="round" /></svg>
          Adicionar videochamada do Google Meet
        </label>
        <a v-if="editingMeet" :href="editingMeet" target="_blank" style="display:block;margin-top:8px;font-size:12.5px;color:var(--accent);text-decoration:none;">🎥 {{ editingMeet }}</a>

        <label class="lbl">Lead vinculado (opcional) — liga a reunião à ficha do cliente</label>
        <select v-model="form.conversation_slug" class="inp">
          <option value="">— Nenhum —</option>
          <option v-for="c in crm.conversations" :key="c.id" :value="c.id">{{ c.name }}</option>
        </select>

        <label class="lbl">Negócio vinculado (opcional)</label>
        <select v-model="form.deal_id" class="inp">
          <option value="">— Nenhum —</option>
          <option v-for="d in crm.dealList" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
        <label class="lbl">Descrição</label>
        <textarea v-model="form.description" class="inp" rows="2" placeholder="Detalhes" />

        <div style="display:flex;align-items:center;gap:10px;margin-top:18px;">
          <button v-if="editingId" style="background:transparent;border:1px solid var(--c-danger-bg);color:var(--c-danger-soft);font-family:inherit;font-size:13px;font-weight:600;padding:9px 13px;border-radius:9px;cursor:pointer;" :disabled="saving" @click="removeEvent">Excluir</button>
          <div style="flex:1;" />
          <button style="background:transparent;border:1px solid var(--c-surface-3);color:var(--c-text-muted);font-family:inherit;font-size:13px;padding:9px 15px;border-radius:9px;cursor:pointer;" @click="closeModal">Cancelar</button>
          <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 18px;border-radius:9px;cursor:pointer;" :disabled="saving" @click="save">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </div>
    </div>

    <!-- Popover: resumo da reunião -->
    <div v-if="summaryFor" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:55;padding:24px;" @click.self="summaryFor = null">
      <div style="width:520px;max-width:94vw;max-height:80vh;overflow-y:auto;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:10px;margin-bottom:4px;">
          <div style="font-size:17px;font-weight:800;">📋 Resumo da reunião</div>
          <button style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;font-size:22px;line-height:1;" @click="summaryFor = null">×</button>
        </div>
        <div style="font-size:12.5px;color:var(--c-text-muted);margin-bottom:14px;">{{ summaryFor.title }} · {{ hm(summaryFor.starts_at) }}</div>
        <div style="font-size:13.5px;line-height:1.6;color:var(--c-text);white-space:pre-wrap;">{{ summaryFor.summary }}</div>
        <button v-if="summaryFor.conversation_slug" class="wabtn" style="margin-top:18px;background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 16px;border-radius:9px;cursor:pointer;" @click="openClient(summaryFor!); summaryFor = null">Abrir ficha do cliente →</button>
      </div>
    </div>

    <!-- Detalhes do evento: o que abre ao clicar num card -->
    <div v-if="detalheAtual" style="position:fixed;inset:0;background:rgba(0,0,0,.5);display:flex;align-items:center;justify-content:center;z-index:52;padding:24px;" @click.self="closeDetails">
      <div style="width:440px;max-width:94vw;max-height:84vh;overflow-y:auto;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;box-shadow:0 18px 60px rgba(0,0,0,.45);">
        <!-- Faixa de cor do evento, igual à do card -->
        <div :style="`height:5px;border-radius:16px 16px 0 0;background:${colorFor(detalheAtual).bar};`" />
        <div style="padding:18px 22px 22px;">
          <div style="display:flex;align-items:flex-start;gap:10px;">
            <div style="flex:1;min-width:0;">
              <div style="font-size:18px;font-weight:800;line-height:1.3;">{{ detalheAtual.title }}</div>
              <div style="font-size:13px;color:var(--c-text-muted);margin-top:5px;">{{ dataLonga(detalheAtual) }}</div>
            </div>
            <button style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;font-size:24px;line-height:1;padding:0 2px;" title="Fechar" @click="closeDetails">×</button>
          </div>

          <div style="margin-top:12px;display:flex;flex-wrap:wrap;gap:7px;align-items:center;">
            <span v-if="STATUS_TXT[evStatus(detalheAtual)]" :style="`display:inline-block;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:7px;color:${STATUS_TXT[evStatus(detalheAtual)]!.color};background:var(--c-surface-1);`">{{ STATUS_TXT[evStatus(detalheAtual)]!.label }}</span>
            <!-- De qual agenda da empresa este evento veio -->
            <span v-if="detalheAtual.owner_name" style="display:inline-flex;align-items:center;gap:6px;font-size:11.5px;font-weight:700;padding:4px 10px;border-radius:7px;background:var(--c-surface-1);color:var(--c-text-secondary);">
              <span :style="`width:8px;height:8px;border-radius:2px;background:${detalheAtual.owner_color};`" />{{ detalheAtual.owner_name }}
            </span>
          </div>

          <div style="margin-top:14px;display:flex;flex-direction:column;gap:9px;font-size:13px;">
            <div v-if="detalheAtual.conversation_name" style="display:flex;gap:9px;">
              <span style="color:var(--c-text-faint);width:18px;">👤</span><span>{{ detalheAtual.conversation_name }}</span>
            </div>
            <div v-if="detalheAtual.location" style="display:flex;gap:9px;">
              <span style="color:var(--c-text-faint);width:18px;">📍</span><span>{{ detalheAtual.location }}</span>
            </div>
            <div v-if="rsvp(detalheAtual).total" style="display:flex;gap:9px;">
              <span style="color:var(--c-text-faint);width:18px;">✉️</span>
              <span>{{ rsvp(detalheAtual).total }} {{ rsvp(detalheAtual).total === 1 ? 'convidado' : 'convidados' }}<span v-if="rsvp(detalheAtual).yes" style="color:var(--accent-soft);"> · {{ rsvp(detalheAtual).yes }} confirmou</span></span>
            </div>
            <div v-if="detalheAtual.reminder_sent" style="display:flex;gap:9px;color:var(--accent-soft);">
              <span style="width:18px;">🔔</span><span>Lembrete enviado ao cliente</span>
            </div>
            <div v-if="detalheAtual.description" style="display:flex;gap:9px;">
              <span style="color:var(--c-text-faint);width:18px;">📝</span><span style="white-space:pre-wrap;line-height:1.5;color:var(--c-text-secondary);">{{ detalheAtual.description }}</span>
            </div>
          </div>

          <div v-if="detalheAtual.summary" style="margin-top:14px;background:var(--c-surface-0);border-radius:10px;padding:12px 14px;max-height:200px;overflow-y:auto;">
            <div style="font-size:11.5px;font-weight:700;color:var(--c-text-muted);margin-bottom:6px;">📋 RESUMO DA REUNIÃO</div>
            <div style="font-size:13px;line-height:1.6;white-space:pre-wrap;">{{ detalheAtual.summary }}</div>
          </div>

          <!-- Ações: Meet, conversa, ficha, editar -->
          <div style="display:flex;flex-wrap:wrap;gap:8px;margin-top:18px;">
            <a v-if="detalheAtual.hangout_link" class="wabtn" :href="detalheAtual.hangout_link" target="_blank" style="text-decoration:none;background:var(--accent);color:var(--accent-ink);font-size:13px;font-weight:700;padding:9px 14px;border-radius:9px;">📹 Entrar no Meet</a>
            <button v-if="convOf(detalheAtual)" style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13px;font-weight:700;padding:9px 14px;border-radius:9px;cursor:pointer;" @click="conversaDoDetalhe(detalheAtual!)">💬 Ir para a conversa</button>
            <button v-if="detalheAtual.conversation_slug" style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13px;font-weight:700;padding:9px 14px;border-radius:9px;cursor:pointer;" @click="fichaDoDetalhe(detalheAtual!)">📇 Ficha do cliente</button>
          </div>
          <!-- Marcação manual: o Meet só enxerga quem entrou na sala, então reunião por telefone,
               presencial ou em outro link precisa ser marcada aqui. Só aparece depois do horário. -->
          <div v-if="jaPassou(detalheAtual)" style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-top:12px;">
            <span style="font-size:12px;color:var(--c-text-muted);">Aconteceu?</span>
            <button
              :style="`background:${detalheAtual.attended ? 'var(--accent)' : 'var(--c-surface-2)'};color:${detalheAtual.attended ? 'var(--accent-ink)' : 'var(--c-text)'};border:none;font-family:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border-radius:9px;cursor:pointer;`"
              :disabled="marcando" @click="marcarPresenca(detalheAtual!, true)"
            >✓ Sim, foi realizada</button>
            <button
              :style="`background:${detalheAtual.no_show ? 'var(--c-orange-strong)' : 'var(--c-surface-2)'};color:${detalheAtual.no_show ? 'var(--accent-ink)' : 'var(--c-text)'};border:none;font-family:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border-radius:9px;cursor:pointer;`"
              :disabled="marcando" @click="marcarPresenca(detalheAtual!, false)"
            >✗ Não aconteceu</button>
          </div>

          <div style="display:flex;align-items:center;gap:8px;margin-top:10px;">
            <button style="background:transparent;border:1px solid var(--c-surface-3);color:var(--c-text-muted);font-family:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;cursor:pointer;" @click="editarDoDetalhe(detalheAtual!)">🕑 Editar / remarcar</button>
            <div style="flex:1;" />
            <button style="background:transparent;border:1px solid var(--c-danger-bg);color:var(--c-danger-soft);font-family:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;border-radius:9px;cursor:pointer;" :disabled="saving" @click="excluirDoDetalhe(detalheAtual!)">Excluir</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: var(--accent-hi) !important; }
.seg:hover { background: var(--c-surface-3) !important; }
.evcard { transition: filter .12s; }
.evcard:hover { filter: brightness(1.12); }
.evcard.live { animation: livepulse 1.6s ease-in-out infinite; }
@keyframes livepulse { 0%, 100% { box-shadow: 0 0 0 2px var(--c-info); } 50% { box-shadow: 0 0 10px 2px rgba(83,189,235,.7); } }
.editpin { opacity: 0; transition: opacity .12s; }
.evcard:hover .editpin { opacity: 1; }
.editpin:hover { filter: brightness(1.3); }
.lbl { display:block; font-size:11.5px; color:var(--c-text-muted); font-weight:600; margin:11px 0 5px; }
.inp {
  width:100%; box-sizing:border-box; background:var(--c-surface-2); border:1px solid var(--c-surface-3); color:var(--c-text);
  font-family:inherit; font-size:13.5px; padding:9px 11px; border-radius:9px; outline:none;
}
.inp:focus { border-color:var(--accent); }
.meet-toggle {
  display:flex; align-items:center; gap:8px; margin-top:13px; cursor:pointer;
  font-size:13px; color:var(--c-text); user-select:none;
}
.meet-toggle input { width:16px; height:16px; accent-color:var(--accent); cursor:pointer; }
.meet-toggle svg { color:var(--accent); }
</style>
