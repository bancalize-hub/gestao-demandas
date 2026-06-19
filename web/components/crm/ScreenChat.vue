<script setup lang="ts">
import { fmtListTime, maskPhone, useCrmStore } from '~/stores/crm'

const crm = useCrmStore()

const inputRef = ref<HTMLTextAreaElement | null>(null)
const msgsRef = ref<HTMLElement | null>(null)

// Busca + filtros da lista de conversas
const search = ref('')
const filter = ref<'tudo' | 'unread' | 'minhas' | 'arquivadas'>('tudo')
const menuFor = ref('')
const stageFor = ref('')
function pillStyle(active: boolean) {
  return active
    ? { fontSize: '12px', fontWeight: 700, color: '#062014', background: '#25D366', padding: '5px 13px', borderRadius: '20px', cursor: 'pointer', border: 'none' }
    : { fontSize: '12px', fontWeight: 600, color: '#8696a0', background: '#202c33', padding: '5px 13px', borderRadius: '20px', cursor: 'pointer', border: 'none' }
}
function tabPill(active: boolean) {
  return active
    ? { fontSize: '12px', fontWeight: 700, color: '#fff', background: '#7c6cf5', padding: '5px 12px', borderRadius: '20px', cursor: 'pointer', border: 'none', whiteSpace: 'nowrap', flexShrink: 0 }
    : { fontSize: '12px', fontWeight: 600, color: '#aebac1', background: '#202c33', padding: '5px 12px', borderRadius: '20px', cursor: 'pointer', border: 'none', whiteSpace: 'nowrap', flexShrink: 0 }
}
function useQuick(text: string) {
  const el = inputRef.value
  if (el) { el.value = text; el.focus() }
}

// Cadastro de respostas rápidas
const addingQR = ref(false)
const qrLabel = ref('')
const qrText = ref('')
const savingQR = ref(false)
async function saveQR() {
  if (savingQR.value || !qrLabel.value.trim() || !qrText.value.trim()) return
  savingQR.value = true
  try {
    await crm.addQuickReply({ label: qrLabel.value.trim(), text: qrText.value.trim() })
    qrLabel.value = ''
    qrText.value = ''
    addingQR.value = false
  }
  finally {
    savingQR.value = false
  }
}

const conv = computed(() => crm.activeConv)
const isMobile = useIsMobile()
function backToList() { crm.chatOpen = false }
const broken = reactive(new Set<string>())

// Polling p/ mensagens novas (tempo real via webhook do WhatsApp).
// O polling em tempo real é global (layouts/default.vue) — sem timer próprio aqui.

// Editar o valor do negócio pela ficha (lateral direita).
// Enquanto o campo está em edição usamos um rascunho LOCAL (valueDraft) em vez de
// ler direto de active.dealValue. Assim o refresh do websocket (que reescreve a
// conversa inteira) não apaga o que o usuário está digitando. null = não editando.
const valueDraft = ref<string | null>(null)
function onEditValue(v: string) {
  const id = conv.value?.id
  if (id) crm.setConvValue(id, v)
}

// Memória (aprender a conversa)
const memoToast = ref('')
async function doMemorize(id: string) {
  menuFor.value = ''
  memoToast.value = 'Aprendendo a conversa… (~30s)'
  try {
    await crm.memorize(id)
    memoToast.value = 'Adicionada à memória ✓'
  }
  catch {
    memoToast.value = 'Falha ao adicionar à memória'
  }
  setTimeout(() => { memoToast.value = '' }, 3500)
}

// Ajustar a sugestão da IA por comando + salvar a correção como regra
const adjust = ref('')
const lastInstruction = ref('')
async function doAdjust() {
  const ins = adjust.value.trim()
  if (!ins) return
  await crm.suggestReply(ins)
  lastInstruction.value = ins
  adjust.value = ''
}
async function doSaveRule() {
  if (!lastInstruction.value) return
  await crm.saveRule(lastInstruction.value)
  memoToast.value = 'Correção salva na memória ✓'
  lastInstruction.value = ''
  setTimeout(() => { memoToast.value = '' }, 3000)
}

// Mídia (carregada sob demanda — descriptografada pelo Evolution)
const apiClient = useApi()
const media = reactive<Record<number, string>>({})
const mediaLoading = reactive<Record<number, boolean>>({})
async function loadMedia(id?: number) {
  if (!id || media[id] || mediaLoading[id]) return
  mediaLoading[id] = true
  try {
    const r = await apiClient<{ data: string }>(`/api/wpp/media/${id}`)
    media[id] = r.data
  }
  catch { /* */ }
  finally { mediaLoading[id] = false }
}

// Apagar uma mensagem do chat (só no CRM; não remove no WhatsApp do cliente).
function removeMsg(m: any) {
  if (!m?.id) return
  if (!confirm('Apagar esta mensagem? (some apenas aqui no sistema)')) return
  crm.removeMessage(m.id)
}

// Etiquetas = etapa do funil (uma por conversa). Selecionar move a conversa no pipeline.
const showLabels = ref(false)
function setStage(l: { key: string }) {
  const id = conv.value?.id
  if (!id) return
  crm.setConvStage(id, l.key)
  showLabels.value = false
}

// Agendar reunião (IA lê a conversa + Google Agenda)
const schedOpen = ref(false)
const schedLoading = ref(false)
const schedError = ref('')
const schedResult = ref<{ scheduled: boolean, message: string, slot_label?: string, note?: string, event?: any } | null>(null)
async function agendarReuniao() {
  const id = conv.value?.id
  if (!id) return
  schedOpen.value = true
  schedLoading.value = true
  schedError.value = ''
  schedResult.value = null
  try {
    schedResult.value = await crm.scheduleMeeting(id)
  }
  catch (e: any) {
    schedError.value = e?.data?.message || 'Não foi possível agendar agora. Tente de novo.'
  }
  finally {
    schedLoading.value = false
  }
}
function enviarSugestao() {
  const msg = schedResult.value?.message
  if (msg) crm.send(msg)
  schedOpen.value = false
}

// ----- Importar conversa (.txt exportado do WhatsApp) -----
const showImport = ref(false)
const importText = ref('')
const importFileName = ref('')
const importNumber = ref('')
const importName = ref('')
const importSenders = ref<string[]>([])
const importClient = ref('')
const importErr = ref('')
const importSaving = ref(false)
const importInfo = ref('')
function extractSenders(text: string): string[] {
  const re = /^\[?\d{1,2}\/\d{1,2}\/\d{2,4},?\s+\d{1,2}:\d{2}(?::\d{2})?\s*(?:[AaPp][Mm])?\]?\s*(?:-\s*)?([^:\n]{1,60}):\s/gm
  const set = new Set<string>()
  let m: RegExpExecArray | null
  // eslint-disable-next-line no-cond-assign
  while ((m = re.exec(text))) set.add(m[1].trim())
  return [...set]
}
function onImportFile(e: Event) {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  importFileName.value = file.name
  importErr.value = ''
  const reader = new FileReader()
  reader.onload = () => {
    importText.value = String(reader.result || '')
    importSenders.value = extractSenders(importText.value)
    // pré-seleciona o cliente como o participante que NÃO é você (heurística: 2º nome)
    importClient.value = importSenders.value[importSenders.value.length - 1] || ''
    if (!importSenders.value.length) importErr.value = 'Não detectei mensagens nesse arquivo. É o .txt do WhatsApp?'
  }
  reader.readAsText(file)
}
async function doImport() {
  if (importSaving.value || !importText.value || !importNumber.value.trim()) return
  importSaving.value = true
  importErr.value = ''
  try {
    const r: any = await crm.importTxt({
      text: importText.value,
      number: importNumber.value.trim(),
      name: importName.value.trim() || undefined,
      client_sender: importClient.value || undefined,
    })
    importInfo.value = `${r.added} mensagens importadas (de ${r.parsed} lidas).`
    setTimeout(() => { showImport.value = false; importInfo.value = ''; importText.value = ''; importFileName.value = ''; importSenders.value = [] }, 1500)
  }
  catch (e: any) {
    importErr.value = e?.data?.message || 'Falha ao importar.'
  }
  finally {
    importSaving.value = false
  }
}

// Editar nome do cliente
const editingName = ref(false)
const nameDraft = ref('')
function startEditName() {
  nameDraft.value = conv.value?.nameSaved ? (conv.value?.name || '') : ''
  editingName.value = true
}
function saveName() {
  const id = conv.value?.id
  const n = nameDraft.value.trim()
  if (id && n) crm.setConvName(id, n)
  editingName.value = false
}

// ----- Nova conversa (por número) -----
const showNewConv = ref(false)
const newConvNumber = ref('')
const newConvName = ref('')
const newConvError = ref('')
const newConvSaving = ref(false)
async function createConversation() {
  const number = newConvNumber.value.trim()
  if (!number || newConvSaving.value) return
  newConvSaving.value = true
  newConvError.value = ''
  try {
    await crm.startConversation({ number, name: newConvName.value.trim() || undefined })
    showNewConv.value = false
    newConvNumber.value = ''
    newConvName.value = ''
  }
  catch (e: any) {
    newConvError.value = e?.data?.message || 'Não foi possível iniciar a conversa.'
  }
  finally {
    newConvSaving.value = false
  }
}

// ----- Tabs por etiqueta -----
const activeTab = ref<number | null>(null)
const activeTabStageNames = computed(() => {
  const tab = activeTab.value ? crm.chatTabs.find(t => t.id === activeTab.value) : null
  if (!tab) return null as Set<string> | null
  return new Set(crm.stages.filter(s => tab.stages.includes(s.key)).map(s => s.name))
})
function visibleTags(tags: { label: string, color: string }[]) {
  const names = activeTabStageNames.value
  if (!names) return tags || []
  return (tags || []).filter(t => names.has(t.label))
}
function tabCount(t: { stages: string[] }) {
  return crm.conversations.filter(c => !c.archived && t.stages.includes(c.stage)).length
}

// Editor de tabs
const showTabs = ref(false)
const tabName = ref('')
const tabStages = ref<string[]>([])
const editingTabId = ref<number | null>(null)
function newTabForm() { editingTabId.value = null; tabName.value = ''; tabStages.value = [] }
function editTabForm(t: { id: number, name: string, stages: string[] }) { editingTabId.value = t.id; tabName.value = t.name; tabStages.value = [...t.stages] }
function toggleTabStage(key: string) {
  const i = tabStages.value.indexOf(key)
  if (i >= 0) tabStages.value.splice(i, 1)
  else tabStages.value.push(key)
}
async function saveTab() {
  const name = tabName.value.trim()
  if (!name) return
  if (editingTabId.value) crm.updateChatTab(editingTabId.value, { name, stages: [...tabStages.value] })
  else await crm.createChatTab({ name, stages: [...tabStages.value] })
  newTabForm()
}
function deleteTab(id: number) {
  if (!confirm('Excluir esta tab?')) return
  crm.removeChatTab(id)
  if (activeTab.value === id) activeTab.value = null
  if (editingTabId.value === id) newTabForm()
}

const EMPTY = {
  id: '', avatar: '', name: '', initials: '', role: '', statusText: '', statusColor: '#8696a0',
  dealValue: '', dealUnit: '', stage: '', probText: '',
  stageStyle: {}, avatarHeader: { width: '42px', height: '42px', borderRadius: '50%', background: '#202c33' },
  avatarBig: { width: '74px', height: '74px', borderRadius: '50%', background: '#202c33', margin: '0 auto 11px' },
  progStyle: { width: '0%', height: '100%' }, tags: [] as { label: string, style: Record<string, string> }[],
}

const active = computed(() => {
  const c = conv.value
  if (!c) return EMPTY
  return {
    id: c.id, name: c.name, initials: c.initials, avatar: c.avatar, role: c.role, autoReply: c.autoReply,
    phone: maskPhone(c.phone), statusText: c.statusText, statusColor: c.online ? '#25D366' : '#8696a0',
    dealValue: c.dealValue, dealUnit: c.dealUnit || '', stage: c.stage,
    probText: `${c.prob}% de probabilidade de fechamento`,
    stageStyle: { fontSize: '12px', fontWeight: 700, color: c.stageColor, background: `${c.stageColor}22`, padding: '3px 10px', borderRadius: '7px' },
    avatarHeader: { width: '42px', height: '42px', borderRadius: '50%', background: c.color, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '15px', flexShrink: 0 },
    avatarBig: { width: '74px', height: '74px', borderRadius: '50%', background: c.color, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '26px', margin: '0 auto 11px' },
    progStyle: { width: `${c.prob}%`, height: '100%', background: `linear-gradient(90deg,#25D366,${c.stageColor})`, borderRadius: '4px' },
    tags: (c.tags || []).map(t => ({ label: t.label, style: { fontSize: '11px', fontWeight: 700, color: t.color, background: `${t.color}22`, padding: '4px 10px', borderRadius: '7px' } })),
  }
})

const list = computed(() => crm.conversations.map(c => ({
  id: c.id, name: c.name, initials: c.initials, avatar: c.avatar, preview: c.preview, time: fmtListTime(c.lastMessageAt, c.time),
  unread: c.unread, online: c.online, hot: !!c.hot, hasUnread: c.unread > 0, archived: c.archived, inMemory: c.inMemory, tags: c.tags || [], stage: c.stage,
  autoReply: c.autoReply, lastOut: c.lastOut, stageColor: c.stageColor,
  stageName: (crm.stages.find(s => s.key === c.stage)?.name) || c.stage,
  rowStyle: { display: 'flex', gap: '12px', padding: '11px 12px', borderRadius: '13px', cursor: 'pointer', alignItems: 'center', position: 'relative', background: c.id === crm.activeId ? '#202c33' : 'transparent' },
  avatarStyle: { width: '48px', height: '48px', borderRadius: '50%', background: c.color, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '16px', flexShrink: 0, position: 'relative' },
  dotStyle: { position: 'absolute', bottom: '1px', right: '1px', width: '12px', height: '12px', borderRadius: '50%', background: '#25D366', border: `2.5px solid ${c.id === crm.activeId ? '#202c33' : '#111b21'}` },
})))

// Base filtrada pela TAB ativa (Todas/SDR/CLOSER/CS). Os contadores de status (Tudo/Não
// lidas/Arquivadas) e a lista derivam daqui — assim ficam dinâmicos com a tab selecionada.
const tabBase = computed(() => {
  const tab = activeTab.value ? crm.chatTabs.find(t => t.id === activeTab.value) : null
  return tab ? list.value.filter(c => tab.stages.includes(c.stage)) : list.value
})

const filteredList = computed(() => {
  const q = search.value.trim().toLowerCase()
  return tabBase.value.filter((c) => {
    if (filter.value === 'arquivadas') {
      if (!c.archived) return false
    }
    else if (c.archived) {
      return false
    }
    if (filter.value === 'unread' && !c.hasUnread && c.id !== crm.activeId) return false
    if (q && !(`${c.name} ${c.preview}`.toLowerCase().includes(q))) return false
    return true
  })
})

const counts = computed(() => ({
  tudo: tabBase.value.filter(c => !c.archived).length,
  unread: tabBase.value.filter(c => c.hasUnread && !c.archived).length,
  minhas: tabBase.value.filter(c => !c.archived).length,
  arquivadas: tabBase.value.filter(c => c.archived).length,
}))

const dividerStyle = { alignSelf: 'center', background: '#1c2a33', color: '#8696a0', fontSize: '11px', fontWeight: 600, padding: '5px 13px', borderRadius: '8px', margin: '8px 0 6px' }

// Rótulo de data estilo WhatsApp: Hoje / Ontem / dia da semana (últimos 7 dias) / dd/mm/aaaa.
const WEEKDAYS = ['domingo', 'segunda-feira', 'terça-feira', 'quarta-feira', 'quinta-feira', 'sexta-feira', 'sábado']
function dayKey(d: Date) { return `${d.getFullYear()}-${d.getMonth()}-${d.getDate()}` }
function dayLabel(ts: number) {
  const d = new Date(ts * 1000)
  const now = new Date()
  const today = dayKey(now)
  const yesterday = new Date(now.getFullYear(), now.getMonth(), now.getDate() - 1)
  if (dayKey(d) === today) return 'Hoje'
  if (dayKey(d) === dayKey(yesterday)) return 'Ontem'
  const diffDays = Math.floor((new Date(now.getFullYear(), now.getMonth(), now.getDate()).getTime() - new Date(d.getFullYear(), d.getMonth(), d.getDate()).getTime()) / 86400000)
  if (diffDays > 1 && diffDays < 7) return WEEKDAYS[d.getDay()]
  return `${String(d.getDate()).padStart(2, '0')}/${String(d.getMonth() + 1).padStart(2, '0')}/${d.getFullYear()}`
}

const unreadDividerStyle = { alignSelf: 'center', background: 'rgba(37,211,102,.14)', color: '#7ee6a8', fontSize: '11px', fontWeight: 700, padding: '5px 14px', borderRadius: '8px', margin: '8px 0 6px' }

// Recibo (tick) por status: relógio (pendente) · 1 traço (enviado) · 2 traços (entregue) · 2 azuis (lido).
function msgTick(status?: string | null) {
  switch (status) {
    case 'pending': return { kind: 'clock', color: '#8696a0' }
    case 'sent': return { kind: 'one', color: '#8696a0' }
    case 'delivered': return { kind: 'two', color: '#8696a0' }
    case 'read': return { kind: 'two', color: '#53bdeb' }
    case 'error': return { kind: 'err', color: '#ff6b6b' }
    default: return { kind: 'two', color: '#8696a0' } // legado (sem status): assume entregue
  }
}

const thread = computed(() => {
  const raw = conv.value?.thread || []
  const q = threadSearch.value.trim().toLowerCase()
  // Busca dentro da conversa: mostra só as mensagens que casam (divisores de data recalculam).
  const msgs = q
    ? raw.filter((m: any) => m.type !== 'divider' && `${m.text || ''} ${m.transcript || ''}`.toLowerCase().includes(q))
    : raw
  // Linha "não lidas": antes das últimas N mensagens (N = não lidas ao abrir). Some durante a busca.
  const unreadStart = (!q && unreadMark.value > 0 && unreadMark.value < msgs.length) ? msgs.length - unreadMark.value : -1

  const out: any[] = []
  let lastDay: string | null = null
  let prevIsOut: boolean | null = null
  let prevTs = 0
  let idx = 0
  for (const m of msgs) {
    if (idx === unreadStart) {
      out.push({ isDivider: true, label: `${unreadMark.value} não lida${unreadMark.value > 1 ? 's' : ''}`, dividerStyle: unreadDividerStyle, key: 'unread' })
      prevIsOut = null
    }
    idx++
    // Divisória de data quando o dia muda (ignora itens sem ts, ex.: dividers já existentes).
    if (m.type !== 'divider' && m.ts) {
      const d = new Date(m.ts * 1000)
      const k = dayKey(d)
      if (k !== lastDay) {
        lastDay = k
        out.push({ isDivider: true, label: dayLabel(m.ts), dividerStyle, key: `d-${k}` })
        prevIsOut = null
      }
    }
    const isOut = !!m.isOut
    const baseBg = isOut ? '#005c4b' : '#202c33'
    const align = isOut ? 'flex-end' : 'flex-start'
    // Agrupa mensagens consecutivas do mesmo lado em até 5 min (espaçamento menor, estilo WhatsApp).
    const grouped = prevIsOut === isOut && !!m.ts && (m.ts - prevTs) < 300
    prevIsOut = isOut
    prevTs = m.ts || prevTs
    out.push({
      ...m, isOut, grouped, tick: msgTick(m.status),
      isDivider: m.type === 'divider', isText: m.type === 'text',
      isImage: m.type === 'image', isVoice: m.type === 'voice', isVideo: m.type === 'video', isFile: m.type === 'file',
      isMedia: m.type === 'image' || m.type === 'voice' || m.type === 'video' || m.type === 'file',
      avColor: conv.value?.color, avInitials: conv.value?.initials,
      dividerStyle,
      bubbleText: { position: 'relative', alignSelf: align, maxWidth: isMobile.value ? '82%' : '64%', background: baseBg, padding: '9px 13px', borderRadius: isOut ? '9px 9px 2px 9px' : '9px 9px 9px 2px', marginTop: grouped ? '2px' : '8px' },
      bubbleMedia: { position: 'relative', alignSelf: align, maxWidth: isMobile.value ? '82%' : '64%', background: baseBg, padding: '8px', borderRadius: '9px', marginTop: grouped ? '2px' : '8px' },
    })
  }
  return out
})

// Texto digitado? (controla a troca do botão mic ↔ enviar, estilo WhatsApp).
const hasInput = ref(false)

// Cresce o textarea conforme digita (até ~130px), estilo WhatsApp.
function autogrow() {
  const el = inputRef.value
  if (!el) return
  el.style.height = 'auto'
  el.style.height = `${Math.min(el.scrollHeight, 130)}px`
  hasInput.value = !!el.value.trim()
}

async function send() {
  const el = inputRef.value
  if (!el) return
  // Anexo pendente: envia o arquivo (a legenda é o texto digitado).
  if (pendingFile.value) {
    if (sendingMedia.value) return
    sendingMedia.value = true
    const f = pendingFile.value
    const cap = el.value
    const ok = await crm.sendMedia(f, cap)
    sendingMedia.value = false
    if (ok) {
      el.value = ''
      el.style.height = 'auto'
      hasInput.value = false
      cancelAttach()
      await nextTick()
      scrollDown()
      const last = thread.value[thread.value.length - 1]
      if (last?.id && last.isMedia) loadMedia(last.id)
    }
    else {
      memoToast.value = 'Falha ao enviar a mídia — tente de novo'
      setTimeout(() => { memoToast.value = '' }, 2200)
    }
    return
  }
  const t = el.value
  // Citação nativa: manda o trecho como quoted (aparece como resposta no WhatsApp do cliente).
  const reply = replyTo.value
    ? { waId: replyTo.value.waId, excerpt: (replyTo.value.text || '').slice(0, 180) }
    : undefined
  el.value = ''
  el.style.height = 'auto'
  hasInput.value = false
  replyTo.value = null
  crm.send(t, reply)
}
function onKeyDown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() }
}
function useAISuggestion() {
  const el = inputRef.value
  if (el && crm.aiSuggestion) { el.value = crm.aiSuggestion; el.focus(); nextTick(autogrow) }
}

// ---- Tier 1: emoji · responder · menu de contexto · lightbox · busca na thread ----
const EMOJIS = ['😀', '😁', '😂', '🤣', '😊', '😍', '😘', '😎', '🤩', '🥳', '🙂', '😉', '😅', '🤔', '😬', '🥲', '😢', '😡', '🙏', '👍', '👎', '👌', '🙌', '👏', '💪', '🤝', '🔥', '✨', '🎉', '✅', '❌', '⚠️', '💰', '📅', '📌', '🚀', '💡', '❤️', '🧡', '💚', '💙', '💜', '👀', '💬', '⏰', '📲', '🫶', '😄']
const REACTIONS = ['👍', '❤️', '😂', '😮', '😢', '🙏']
const showEmoji = ref(false)
function insertEmoji(e: string) {
  const el = inputRef.value
  if (!el) return
  const s = el.selectionStart ?? el.value.length
  const en = el.selectionEnd ?? el.value.length
  el.value = el.value.slice(0, s) + e + el.value.slice(en)
  const pos = s + e.length
  el.focus()
  nextTick(() => { el.selectionStart = el.selectionEnd = pos; autogrow() })
}

// Menu de contexto por mensagem (Responder / Copiar / Apagar).
const msgMenu = ref<number | string | null>(null)
function toggleMsgMenu(m: any, i: number) {
  const k = m.id ?? `i${i}`
  msgMenu.value = msgMenu.value === k ? null : k
}
async function copyMsg(m: any) {
  try {
    await navigator.clipboard.writeText(m.text || '')
    memoToast.value = 'Mensagem copiada'
    setTimeout(() => { memoToast.value = '' }, 1500)
  }
  catch {}
  msgMenu.value = null
}

// Responder/citar.
const replyTo = ref<{ id?: number, waId?: string | null, text: string, isOut: boolean } | null>(null)
function startReply(m: any) {
  const txt = m.text || (m.isVoice ? '🎵 Áudio' : m.isImage ? '📷 Imagem' : m.isVideo ? '🎬 Vídeo' : m.isFile ? '📄 Arquivo' : '')
  replyTo.value = { id: m.id, waId: m.waId ?? null, text: txt, isOut: !!m.isOut }
  msgMenu.value = null
  inputRef.value?.focus()
}

// Lightbox (imagem/vídeo em tela cheia).
const lightbox = ref<string | null>(null)

// Encaminhar mensagem para outra conversa.
const forwardMsg = ref<any | null>(null)
const forwardSearch = ref('')
const forwardSending = ref(false)
function startForward(m: any) {
  forwardMsg.value = m
  forwardSearch.value = ''
  msgMenu.value = null
}
const forwardList = computed(() => {
  const q = forwardSearch.value.trim().toLowerCase()
  return crm.conversations
    .filter(c => !c.archived && (!q || `${c.name} ${c.phone}`.toLowerCase().includes(q)))
    .slice(0, 50)
})
async function doForward(convId: string) {
  if (forwardSending.value || !forwardMsg.value) return
  forwardSending.value = true
  const ok = await crm.forwardMessage(convId, forwardMsg.value.id)
  forwardSending.value = false
  forwardMsg.value = null
  memoToast.value = ok ? 'Mensagem encaminhada ✓' : 'Falha ao encaminhar'
  setTimeout(() => { memoToast.value = '' }, 1800)
}

// Fecha emoji e menu de contexto ao clicar fora (os gatilhos usam @click.stop).
function closeOverlays() { showEmoji.value = false; msgMenu.value = null }
onMounted(() => document.addEventListener('click', closeOverlays))
onUnmounted(() => document.removeEventListener('click', closeOverlays))

// Anexo (foto/vídeo/documento) pendente antes de enviar.
const fileInput = ref<HTMLInputElement | null>(null)
const pendingFile = ref<File | null>(null)
const pendingPreview = ref<string | null>(null)
const sendingMedia = ref(false)
function pickFile() { fileInput.value?.click() }
function onFileChosen(e: Event) {
  const input = e.target as HTMLInputElement
  const f = input.files?.[0]
  input.value = ''
  if (!f) return
  pendingFile.value = f
  pendingPreview.value = f.type.startsWith('image/') ? URL.createObjectURL(f) : null
  inputRef.value?.focus()
}
function cancelAttach() {
  if (pendingPreview.value) URL.revokeObjectURL(pendingPreview.value)
  pendingFile.value = null
  pendingPreview.value = null
}
function fmtSize(n: number) {
  return n >= 1048576 ? `${(n / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(n / 1024))} KB`
}

// ---- Gravação de áudio (nota de voz) ----
const recording = ref(false)
const recSeconds = ref(0)
let mediaRecorder: MediaRecorder | null = null
let recChunks: Blob[] = []
let recTimer: ReturnType<typeof setInterval> | null = null
let recStream: MediaStream | null = null
const recLabel = computed(() => `${String(Math.floor(recSeconds.value / 60)).padStart(2, '0')}:${String(recSeconds.value % 60).padStart(2, '0')}`)

async function startRec() {
  if (recording.value || sendingMedia.value) return
  try {
    recStream = await navigator.mediaDevices.getUserMedia({ audio: true })
  }
  catch {
    memoToast.value = 'Permita o microfone para gravar'
    setTimeout(() => { memoToast.value = '' }, 2200)
    return
  }
  recChunks = []
  const mime = MediaRecorder.isTypeSupported('audio/webm') ? 'audio/webm' : ''
  mediaRecorder = new MediaRecorder(recStream, mime ? { mimeType: mime } : undefined)
  mediaRecorder.ondataavailable = (e: BlobEvent) => { if (e.data.size) recChunks.push(e.data) }
  mediaRecorder.start()
  recording.value = true
  recSeconds.value = 0
  recTimer = setInterval(() => { recSeconds.value++ }, 1000)
}
function stopTracks() {
  if (recTimer) { clearInterval(recTimer); recTimer = null }
  recStream?.getTracks().forEach(t => t.stop())
  recStream = null
}
function cancelRec() {
  if (mediaRecorder && mediaRecorder.state !== 'inactive') { mediaRecorder.onstop = null; mediaRecorder.stop() }
  stopTracks()
  recording.value = false
  recChunks = []
}
function sendRec() {
  const mr = mediaRecorder
  if (!mr) return
  const dur = recLabel.value
  mr.onstop = async () => {
    stopTracks()
    recording.value = false
    const blob = new Blob(recChunks, { type: mr.mimeType || 'audio/webm' })
    recChunks = []
    if (blob.size < 800) return // gravação curta demais — descarta
    const ext = (mr.mimeType || '').includes('ogg') ? 'ogg' : 'webm'
    const file = new File([blob], `audio-${Date.now()}.${ext}`, { type: mr.mimeType || 'audio/webm' })
    sendingMedia.value = true
    const ok = await crm.sendMedia(file, '', dur)
    sendingMedia.value = false
    if (ok) {
      await nextTick()
      scrollDown()
      const last = thread.value[thread.value.length - 1]
      if (last?.id && last.isMedia) loadMedia(last.id)
    }
    else {
      memoToast.value = 'Falha ao enviar o áudio'
      setTimeout(() => { memoToast.value = '' }, 2200)
    }
  }
  if (mr.state !== 'inactive') mr.stop()
}
onUnmounted(() => { if (recording.value) cancelRec() })

// Busca dentro da conversa.
const showThreadSearch = ref(false)
const threadSearch = ref('')
function toggleThreadSearch() {
  showThreadSearch.value = !showThreadSearch.value
  if (!showThreadSearch.value) threadSearch.value = ''
}

// Marca de "não lidas": guarda quantas estavam por ler ao abrir a conversa.
const unreadMark = ref(0)
function openConv(c: any) {
  unreadMark.value = c.unread || 0
  crm.selectConv(c.id)
  menuFor.value = ''
  stageFor.value = ''
}

// Rola para o fim quando a thread cresce / troca de conversa.
// Rolagem: desce sozinho só quando já está embaixo; botão pra descer.
const atBottom = ref(true)
function onScroll() {
  const el = msgsRef.value
  if (el) atBottom.value = el.scrollHeight - el.scrollTop - el.clientHeight < 90
}
function scrollDown() {
  const el = msgsRef.value
  if (el) { el.scrollTop = el.scrollHeight; atBottom.value = true }
}
// troca de conversa: sempre desce até o fim
watch(() => crm.activeId, async () => { await nextTick(); scrollDown() })
// mensagem nova: só desce se o usuário já estava no fim (não atrapalha quem lê o histórico)
watch(() => thread.value.length, async () => { await nextTick(); if (atBottom.value) scrollDown() })

// Auto-carrega as mídias da conversa aberta (sem precisar clicar).
watch(() => crm.activeId, () => {
  for (const m of thread.value) {
    if (m.isMedia && m.id) loadMedia(m.id)
  }
}, { immediate: true })
</script>

<template>
  <div style="flex:1;display:flex;min-width:0;">
    <!-- lista de conversas -->
    <div :style="{ width: isMobile ? '100%' : '340px', flexShrink: 0, background: '#111b21', borderRight: '1px solid #1c2730', flexDirection: 'column', display: (isMobile && crm.chatOpen) ? 'none' : 'flex' }">
      <div style="padding:18px 18px 12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div style="font-size:19px;font-weight:800;letter-spacing:-.2px;">Conversas</div>
          <div style="display:flex;gap:6px;">
            <button title="Importar conversa (.txt)" class="iconbtn" style="background:#202c33;border:none;color:#8696a0;font-family:inherit;font-size:12.5px;font-weight:600;padding:7px 10px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:5px;" @click="showImport = true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 3v12m0 0 4-4m-4 4-4-4M4 17v2a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2v-2" stroke-linecap="round" stroke-linejoin="round" /></svg>Importar</button>
            <button title="Nova conversa" class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:12.5px;font-weight:700;padding:7px 12px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:5px;" @click="showNewConv = true"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.6"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Nova</button>
          </div>
        </div>

        <!-- Tabs por etiqueta (independentes dos filtros abaixo) -->
        <div style="display:flex;align-items:center;gap:7px;margin-bottom:11px;overflow-x:auto;padding-bottom:2px;">
          <button v-if="crm.chatTabs.length" :style="tabPill(activeTab === null)" @click="activeTab = null">Todas</button>
          <button v-for="t in crm.chatTabs" :key="t.id" :style="tabPill(activeTab === t.id)" @click="activeTab = activeTab === t.id ? null : t.id">{{ t.name }} · {{ tabCount(t) }}</button>
          <button title="Gerenciar tabs" style="flex-shrink:0;background:#202c33;border:1px dashed #3a4a54;color:#8696a0;width:28px;height:28px;border-radius:9px;cursor:pointer;display:flex;align-items:center;justify-content:center;" @click="showTabs = true; newTabForm()"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg></button>
        </div>

        <div style="display:flex;align-items:center;gap:9px;background:#202c33;border-radius:11px;padding:9px 13px;">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
          <input v-model="search" placeholder="Buscar conversa ou contato" style="flex:1;background:transparent;border:none;outline:none;color:#e9edef;font-family:inherit;font-size:13.5px;">
        </div>
        <div style="display:flex;gap:7px;margin-top:13px;">
          <button :style="pillStyle(filter === 'tudo')" @click="filter = 'tudo'">Tudo · {{ counts.tudo }}</button>
          <button :style="pillStyle(filter === 'unread')" @click="filter = 'unread'">Não lidas · {{ counts.unread }}</button>
          <button :style="pillStyle(filter === 'arquivadas')" @click="filter = 'arquivadas'">Arquivadas · {{ counts.arquivadas }}</button>
        </div>
      </div>

      <div style="flex:1;overflow-y:auto;padding:0 8px;">
        <!-- skeleton -->
        <template v-if="crm.loading">
          <div v-for="i in 5" :key="i" style="display:flex;gap:12px;padding:11px 12px;align-items:center;">
            <div style="width:48px;height:48px;border-radius:50%;background:#1c2730;flex-shrink:0;animation:pulse 1.4s infinite;" />
            <div style="flex:1;">
              <div style="height:12px;width:55%;background:#1c2730;border-radius:5px;animation:pulse 1.4s infinite;" />
              <div style="height:10px;width:85%;background:#16222a;border-radius:5px;margin-top:9px;animation:pulse 1.4s infinite;" />
            </div>
          </div>
        </template>

        <template v-else>
          <div v-if="!filteredList.length" style="padding:30px 14px;text-align:center;color:#8696a0;font-size:13px;">Nenhuma conversa encontrada</div>
          <div v-for="c in filteredList" :key="c.id" :style="c.rowStyle" class="convrow" @click="openConv(c)">
            <div :style="c.avatarStyle">
              <img v-if="c.avatar && !broken.has(c.id)" :src="c.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" @error="broken.add(c.id)">
              <template v-else>{{ c.initials }}</template>
              <span v-if="c.online" :style="c.dotStyle" />
            </div>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;justify-content:space-between;align-items:center;padding-right:20px;">
                <span style="font-weight:700;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.name }}</span>
                <span style="font-size:11px;color:#8696a0;flex-shrink:0;margin-left:8px;">{{ c.time }}</span>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:3px;gap:8px;">
                <span style="font-size:13px;color:#aebac1;overflow:hidden;flex:1;min-width:0;display:flex;align-items:center;gap:4px;">
                  <!-- ✓✓ quando a última mensagem foi enviada por nós (estilo WhatsApp) -->
                  <svg v-if="c.lastOut" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2.4" style="flex-shrink:0;"><path d="m4 13 3.5 3.5L14 8M11 16l1.5 1.5L20 9" /></svg>
                  <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.preview }}</span>
                </span>
                <span v-if="c.hasUnread" style="background:#25D366;color:#062014;font-size:11px;font-weight:700;min-width:19px;height:19px;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0;">{{ c.unread }}</span>
              </div>
              <div v-if="c.hot" style="margin-top:6px;display:inline-flex;font-size:10.5px;font-weight:700;color:#ff7a45;background:rgba(255,122,69,.13);padding:2px 8px;border-radius:6px;">🔥 Lead quente</div>
              <!-- Etiqueta (etapa) clicável + toggle de atendimento automático, direto na lista -->
              <div style="display:flex;align-items:center;gap:6px;margin-top:6px;flex-wrap:wrap;">
                <button class="ghost" :style="{ fontSize: '10.5px', fontWeight: 700, color: c.stageColor, background: `${c.stageColor}22`, padding: '2px 9px', borderRadius: '6px', border: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '4px' }" @click.stop="stageFor = stageFor === c.id ? '' : c.id; menuFor = ''">{{ c.stageName }} ▾</button>
                <button class="ghost" :title="c.autoReply ? 'Atendimento automático ligado — clique para desligar' : 'Ativar atendimento automático (IA responde sozinha)'" :style="{ fontSize: '10.5px', fontWeight: 700, padding: '2px 9px', borderRadius: '6px', border: 'none', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '4px', color: c.autoReply ? '#062014' : '#8696a0', background: c.autoReply ? '#25D366' : '#202c33' }" @click.stop="crm.toggleAutoReply(c.id)">🤖 {{ c.autoReply ? 'IA ligada' : 'IA' }}</button>
              </div>
            </div>
            <div v-if="stageFor === c.id" style="position:absolute;left:62px;top:58px;z-index:31;background:#202c33;border:1px solid #2a3942;border-radius:10px;padding:5px;min-width:170px;box-shadow:0 10px 28px rgba(0,0,0,.45);" @click.stop>
              <div style="font-size:10px;color:#8696a0;font-weight:700;letter-spacing:.5px;padding:3px 8px 6px;">MUDAR ETIQUETA</div>
              <button v-for="l in crm.stages" :key="l.key" class="mitem" style="width:100%;display:flex;align-items:center;gap:9px;background:none;border:none;color:#e9edef;font-family:inherit;font-size:13px;padding:7px 8px;border-radius:7px;cursor:pointer;text-align:left;" @click="crm.setConvStage(c.id, l.key); stageFor = ''">
                <span :style="{ width: '11px', height: '11px', borderRadius: '3px', background: l.color, flexShrink: 0 }" />
                <span style="flex:1;">{{ l.name }}</span>
                <svg v-if="c.stage === l.key" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.5"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </button>
            </div>
            <button class="rowmenu" title="Ações" style="position:absolute;top:8px;right:6px;background:#202c33;border:none;color:#cfd6db;width:22px;height:22px;border-radius:6px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:15px;line-height:1;" @click.stop="menuFor = menuFor === c.id ? '' : c.id">⋮</button>
            <div v-if="menuFor === c.id" style="position:absolute;top:30px;right:6px;z-index:30;background:#202c33;border:1px solid #2a3942;border-radius:10px;padding:5px;min-width:180px;box-shadow:0 10px 28px rgba(0,0,0,.45);" @click.stop>
              <button class="mitem" style="width:100%;text-align:left;background:none;border:none;color:#e9edef;font-family:inherit;font-size:13px;padding:8px 11px;border-radius:7px;cursor:pointer;" @click="crm.markUnread(c.id); menuFor = ''">Marcar como não lida</button>
              <button class="mitem" style="width:100%;text-align:left;background:none;border:none;color:#e9edef;font-family:inherit;font-size:13px;padding:8px 11px;border-radius:7px;cursor:pointer;" @click="crm.toggleArchive(c.id); menuFor = ''">{{ c.archived ? 'Desarquivar' : 'Arquivar' }}</button>
              <button class="mitem" style="width:100%;text-align:left;background:none;border:none;color:#a89bf9;font-family:inherit;font-size:13px;padding:8px 11px;border-radius:7px;cursor:pointer;" @click="doMemorize(c.id)">{{ c.inMemory ? '✓ Na memória' : '🧠 Adicionar à memória' }}</button>
            </div>
          </div>
        </template>
      </div>
    </div>

    <!-- thread -->
    <div :style="{ position:'relative', flex:1, flexDirection:'column', minWidth:0, background:'#0b141a', backgroundImage:'radial-gradient(circle at 20% 30%,rgba(37,211,102,.04),transparent 40%),radial-gradient(circle at 80% 70%,rgba(124,108,245,.04),transparent 40%)', display: (isMobile && !crm.chatOpen) ? 'none' : 'flex' }">
      <div v-if="crm.isOffline" style="display:flex;align-items:center;gap:9px;background:rgba(255,180,67,.13);color:#ffce80;font-size:12.5px;font-weight:600;padding:9px 22px;border-bottom:1px solid rgba(255,180,67,.22);">
        <span style="width:8px;height:8px;border-radius:50%;background:#ffb443;animation:recpulse 1.2s infinite;" />Sem conexão — tentando reconectar…
      </div>

      <!-- header -->
      <div :style="{ display: 'flex', alignItems: 'center', gap: isMobile ? '9px' : '13px', padding: isMobile ? '10px 12px' : '13px 22px', background: '#111b21', borderBottom: '1px solid #1c2730' }">
        <button v-if="isMobile" title="Voltar" style="background:none;border:none;color:#e9edef;cursor:pointer;display:flex;align-items:center;padding:0;margin-right:-4px;flex-shrink:0;" @click="backToList">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 19l-7-7 7-7" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
        <div :style="active.avatarHeader">
          <img v-if="active.avatar && !broken.has(active.id)" :src="active.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" @error="broken.add(active.id)">
          <template v-else>{{ active.initials }}</template>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;font-size:15.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ active.name }}</div>
          <div style="display:flex;align-items:center;gap:8px;">
            <span v-if="active.phone" style="font-size:12.5px;color:#aebac1;">{{ active.phone }}</span>
            <span v-if="active.statusText" :style="{ fontSize: '11.5px', color: active.statusColor }">{{ active.statusText }}</span>
          </div>
        </div>
        <div style="display:flex;gap:7px;align-items:center;">
          <div v-if="!isMobile" style="display:flex;gap:6px;margin-right:6px;">
            <span v-for="(t, i) in active.tags" :key="i" :style="t.style">{{ t.label }}</span>
          </div>
          <button class="iconbtn" :title="showThreadSearch ? 'Fechar busca' : 'Buscar nesta conversa'" :style="`width:38px;height:38px;border-radius:11px;border:none;display:flex;align-items:center;justify-content:center;cursor:pointer;${showThreadSearch ? 'background:#25D366;color:#062014;' : 'background:#202c33;color:#e9edef;'}`" @click="toggleThreadSearch">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
          </button>
          <div style="position:relative;">
            <button class="iconbtn" title="Etiquetas" style="width:38px;height:38px;border-radius:11px;border:none;background:#202c33;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;" @click.stop="showLabels = !showLabels">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0l-7.2-7.2A2 2 0 0 1 2.8 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.4 7.4a2 2 0 0 1 0 2.8Z" stroke-linejoin="round" /><circle cx="7.5" cy="7.5" r="1.4" fill="currentColor" stroke="none" /></svg>
            </button>
            <div v-if="showLabels" style="position:absolute;top:46px;right:0;z-index:40;background:#202c33;border:1px solid #2a3942;border-radius:12px;padding:9px;min-width:220px;box-shadow:0 12px 32px rgba(0,0,0,.5);" @click.stop>
              <div style="font-size:10.5px;color:#8696a0;font-weight:700;letter-spacing:.5px;padding:3px 7px 7px;">ETAPA DO FUNIL</div>
              <button v-for="l in crm.stages" :key="l.key" class="mitem" style="width:100%;display:flex;align-items:center;gap:9px;background:none;border:none;color:#e9edef;font-family:inherit;font-size:13px;padding:7px 8px;border-radius:7px;cursor:pointer;text-align:left;" @click="setStage(l)">
                <span :style="{ width: '11px', height: '11px', borderRadius: '3px', background: l.color, flexShrink: 0 }" />
                <span style="flex:1;">{{ l.name }}</span>
                <svg v-if="conv?.stage === l.key" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.5"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </button>
              <div style="font-size:11px;color:#5f6f78;padding:7px 8px 2px;">Edite as etapas no Funil.</div>
            </div>
          </div>
          <button class="iconbtn" :title="active.autoReply ? 'Atendimento automático LIGADO — a IA responde sozinha. Clique para desligar.' : 'Ativar atendimento automático (a IA responde o lead sozinha)'" :style="{ height: '38px', borderRadius: '11px', border: 'none', display: 'flex', alignItems: 'center', gap: '6px', padding: '0 12px', cursor: 'pointer', fontFamily: 'inherit', fontSize: '12.5px', fontWeight: 700, color: active.autoReply ? '#062014' : '#25D366', background: active.autoReply ? '#25D366' : '#202c33' }" @click="crm.toggleAutoReply(active.id)">
            <span style="font-size:15px;line-height:1;">🤖</span>
            {{ active.autoReply ? 'IA ligada' : 'Ativar IA' }}
          </button>
          <button v-if="!isMobile" class="iconbtn agbtn" title="Agendar reunião com IA" style="height:38px;border-radius:11px;border:none;background:#202c33;color:#25D366;display:flex;align-items:center;gap:6px;padding:0 12px;cursor:pointer;font-family:inherit;font-size:12.5px;font-weight:700;" @click="agendarReuniao">
            <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
            Agendar
          </button>
        </div>
      </div>

      <!-- busca dentro da conversa -->
      <div v-if="showThreadSearch" style="display:flex;align-items:center;gap:10px;padding:9px 22px;background:#0b141a;border-bottom:1px solid #1c2730;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2" style="flex-shrink:0;"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
        <input v-model="threadSearch" placeholder="Buscar mensagens nesta conversa" style="flex:1;background:transparent;border:none;outline:none;color:#e9edef;font-family:inherit;font-size:13.5px;">
        <span v-if="threadSearch.trim()" style="font-size:12px;color:#8696a0;flex-shrink:0;">{{ thread.filter(m => !m.isDivider).length }} resultado(s)</span>
        <button title="Fechar" style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:18px;line-height:1;flex-shrink:0;" @click="toggleThreadSearch">✕</button>
      </div>

      <!-- mensagens -->
      <div style="position:relative;flex:1;display:flex;flex-direction:column;min-height:0;">
        <div ref="msgsRef" :style="{ flex: 1, overflowY: 'auto', padding: isMobile ? '14px 12px 14px' : '24px 18% 18px', display: 'flex', flexDirection: 'column', gap: '0', backgroundImage: 'radial-gradient(rgba(255,255,255,.02) 1px, transparent 1px)', backgroundSize: '22px 22px' }" @scroll="onScroll">
        <template v-if="crm.loading">
          <div style="align-self:center;width:70px;height:20px;border-radius:8px;background:#1c2a33;animation:pulse 1.4s infinite;margin-bottom:6px;" />
          <div style="align-self:flex-start;width:46%;height:54px;border-radius:9px;background:#1c2730;animation:pulse 1.4s infinite;" />
          <div style="align-self:flex-end;width:52%;height:42px;border-radius:9px;background:#143a31;animation:pulse 1.4s infinite;" />
          <div style="align-self:flex-start;width:38%;height:40px;border-radius:9px;background:#1c2730;animation:pulse 1.4s infinite;" />
          <div style="align-self:flex-end;width:58%;height:64px;border-radius:9px;background:#143a31;animation:pulse 1.4s infinite;" />
        </template>

        <div v-else-if="crm.threadError" style="margin:auto;display:flex;flex-direction:column;align-items:center;gap:13px;text-align:center;max-width:300px;">
          <div style="width:64px;height:64px;border-radius:50%;background:rgba(255,77,77,.12);display:flex;align-items:center;justify-content:center;">
            <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#ff6b6b" stroke-width="1.8"><path d="M12 8.5v5M12 16.8v.2M10.3 3.9 2.4 18a1.5 1.5 0 0 0 1.3 2.3h16.6a1.5 1.5 0 0 0 1.3-2.3L13.7 3.9a1.5 1.5 0 0 0-2.6 0Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </div>
          <div style="font-size:16px;font-weight:700;color:#e9edef;">Não foi possível carregar as mensagens</div>
          <div style="font-size:13px;color:#8696a0;">Verifique sua conexão e tente novamente.</div>
          <button style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:10px 18px;border-radius:10px;cursor:pointer;" @click="crm.retryThread()">Tentar novamente</button>
        </div>

        <template v-else>
          <template v-for="(m, i) in thread" :key="i">
            <div v-if="m.isDivider" :style="m.dividerStyle">{{ m.label }}</div>
            <div v-else-if="m.isText" class="bubble" :style="m.bubbleText" @dblclick="startReply(m)">
              <button v-if="m.id" class="msgmenu-btn" :style="{ [m.isOut ? 'left' : 'right']: '-9px' }" title="Opções" @click.stop="toggleMsgMenu(m, i)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="12" cy="19" r="1.8" /></svg>
              </button>
              <div v-if="m.replyExcerpt" style="border-left:3px solid #25D366;background:rgba(0,0,0,.18);border-radius:5px;padding:5px 9px;margin-bottom:5px;font-size:12.5px;color:#aebac1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">↩ {{ m.replyExcerpt }}</div>
              <div style="font-size:14px;line-height:1.42;word-break:break-word;overflow-wrap:anywhere;white-space:pre-wrap;">{{ m.text }}</div>
              <div style="display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:3px;">
                <span style="font-size:10.5px;color:#8696a0;">{{ m.time }}</span>
                <template v-if="m.isOut">
                  <svg v-if="m.tick.kind === 'clock'" width="15" height="15" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7.5V12l3 2" stroke-linecap="round" /></svg>
                  <svg v-else-if="m.tick.kind === 'one'" width="16" height="16" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2.4"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  <svg v-else-if="m.tick.kind === 'err'" width="15" height="15" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16v.2" stroke-linecap="round" /></svg>
                  <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2.4"><path d="m4 13 3.5 3.5L14 8M11 16l1.5 1.5L20 9" /></svg>
                </template>
              </div>
              <span v-if="m.reaction" class="react-badge" :style="{ [m.isOut ? 'right' : 'left']: '8px' }">{{ m.reaction }}</span>
              <div v-if="msgMenu === (m.id ?? ('i' + i))" class="msg-ctx" :style="{ [m.isOut ? 'right' : 'left']: '4px' }" @click.stop>
                <div v-if="m.id" class="react-row">
                  <button v-for="e in REACTIONS" :key="e" class="reactbtn" :class="{ on: m.reaction === e }" @click="crm.react(m.id, e); msgMenu = null">{{ e }}</button>
                </div>
                <button class="ctxitem" @click="startReply(m)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 5 5v3" stroke-linecap="round" stroke-linejoin="round" /></svg>Responder</button>
                <button v-if="m.id" class="ctxitem" @click="startForward(m)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 14l5-5-5-5M20 9H9a5 5 0 0 0-5 5v3" stroke-linecap="round" stroke-linejoin="round" /></svg>Encaminhar</button>
                <button class="ctxitem" @click="copyMsg(m)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>Copiar</button>
                <button v-if="m.id" class="ctxitem danger" @click="removeMsg(m); msgMenu = null"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14" stroke-linecap="round" stroke-linejoin="round" /></svg>Apagar</button>
              </div>
            </div>
            <div v-else-if="m.isMedia" class="bubble" :style="m.bubbleMedia" @dblclick="startReply(m)">
              <button v-if="m.id" class="msgmenu-btn" :style="{ [m.isOut ? 'left' : 'right']: '-9px' }" title="Opções" @click.stop="toggleMsgMenu(m, i)">
                <svg width="14" height="14" viewBox="0 0 24 24" fill="currentColor"><circle cx="12" cy="5" r="1.8" /><circle cx="12" cy="12" r="1.8" /><circle cx="12" cy="19" r="1.8" /></svg>
              </button>
              <div v-if="m.replyExcerpt" style="border-left:3px solid #25D366;background:rgba(0,0,0,.18);border-radius:5px;padding:5px 9px;margin-bottom:6px;font-size:12.5px;color:#aebac1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">↩ {{ m.replyExcerpt }}</div>
              <template v-if="media[m.id]">
                <img v-if="m.isImage" :src="media[m.id]" title="Ampliar" style="max-width:260px;width:100%;border-radius:7px;display:block;cursor:zoom-in;" @click="lightbox = media[m.id]">
                <video v-else-if="m.isVideo" :src="media[m.id]" controls style="max-width:260px;width:100%;border-radius:7px;display:block;" />
                <audio v-else-if="m.isVoice" :src="media[m.id]" controls style="width:230px;display:block;" />
                <a v-else :href="media[m.id]" :download="m.fileName || 'arquivo'" style="display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.18);border-radius:7px;padding:10px 12px;color:#e9edef;text-decoration:none;font-size:13px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a7d4c5" stroke-width="1.8"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke-linecap="round" stroke-linejoin="round" /></svg>Baixar arquivo</a>
              </template>
              <button v-else :disabled="mediaLoading[m.id]" style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.18);border:none;color:#e9edef;font-family:inherit;font-size:13px;padding:11px 14px;border-radius:7px;cursor:pointer;width:100%;min-width:170px;" @click="loadMedia(m.id)">
                <span style="font-size:18px;">{{ m.isImage ? '📷' : m.isVoice ? '🎵' : m.isVideo ? '🎬' : '📄' }}</span>
                <span style="flex:1;text-align:left;">{{ mediaLoading[m.id] ? 'Carregando…' : (m.isImage ? 'Ver imagem' : m.isVoice ? 'Tocar áudio' : m.isVideo ? 'Ver vídeo' : 'Baixar arquivo') }}</span>
              </button>
              <div v-if="m.text" style="font-size:13.5px;line-height:1.4;margin-top:6px;padding:0 2px;word-break:break-word;overflow-wrap:anywhere;">{{ m.text }}</div>
              <div v-if="m.isVoice && m.transcript" style="font-size:12.5px;line-height:1.4;margin-top:6px;padding:0 2px;color:#cfd9de;font-style:italic;word-break:break-word;overflow-wrap:anywhere;">📝 {{ m.transcript }}</div>
              <div style="display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:4px;padding-right:2px;">
                <span style="font-size:10.5px;color:#8696a0;">{{ m.time }}</span>
                <template v-if="m.isOut">
                  <svg v-if="m.tick.kind === 'clock'" width="15" height="15" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 7.5V12l3 2" stroke-linecap="round" /></svg>
                  <svg v-else-if="m.tick.kind === 'one'" width="16" height="16" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2.4"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                  <svg v-else-if="m.tick.kind === 'err'" width="15" height="15" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16v.2" stroke-linecap="round" /></svg>
                  <svg v-else width="16" height="16" viewBox="0 0 24 24" fill="none" :stroke="m.tick.color" stroke-width="2.4"><path d="m4 13 3.5 3.5L14 8M11 16l1.5 1.5L20 9" /></svg>
                </template>
              </div>
              <span v-if="m.reaction" class="react-badge" :style="{ [m.isOut ? 'right' : 'left']: '8px' }">{{ m.reaction }}</span>
              <div v-if="msgMenu === (m.id ?? ('i' + i))" class="msg-ctx" :style="{ [m.isOut ? 'right' : 'left']: '4px' }" @click.stop>
                <div v-if="m.id" class="react-row">
                  <button v-for="e in REACTIONS" :key="e" class="reactbtn" :class="{ on: m.reaction === e }" @click="crm.react(m.id, e); msgMenu = null">{{ e }}</button>
                </div>
                <button class="ctxitem" @click="startReply(m)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 14 4 9l5-5M4 9h11a5 5 0 0 1 5 5v3" stroke-linecap="round" stroke-linejoin="round" /></svg>Responder</button>
                <button v-if="m.id" class="ctxitem" @click="startForward(m)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 14l5-5-5-5M20 9H9a5 5 0 0 0-5 5v3" stroke-linecap="round" stroke-linejoin="round" /></svg>Encaminhar</button>
                <button v-if="m.text" class="ctxitem" @click="copyMsg(m)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="9" width="11" height="11" rx="2" /><path d="M5 15V5a2 2 0 0 1 2-2h10" /></svg>Copiar</button>
                <button v-if="m.id" class="ctxitem danger" @click="removeMsg(m); msgMenu = null"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14" stroke-linecap="round" stroke-linejoin="round" /></svg>Apagar</button>
              </div>
            </div>
          </template>
          <div v-if="crm.typing" style="align-self:flex-start;margin-top:8px;background:#202c33;padding:13px 16px;border-radius:9px 9px 9px 2px;display:flex;gap:4px;align-items:center;">
            <span style="width:6px;height:6px;border-radius:50%;background:#8696a0;animation:typing 1.2s infinite;" /><span style="width:6px;height:6px;border-radius:50%;background:#8696a0;animation:typing 1.2s infinite .2s;" /><span style="width:6px;height:6px;border-radius:50%;background:#8696a0;animation:typing 1.2s infinite .4s;" />
          </div>
        </template>
        </div>
        <button v-if="!atBottom" title="Descer" style="position:absolute;bottom:14px;right:18px;z-index:15;width:42px;height:42px;border-radius:50%;border:none;background:#202c33;color:#e9edef;box-shadow:0 4px 14px rgba(0,0,0,.45);cursor:pointer;display:flex;align-items:center;justify-content:center;" @click="scrollDown">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M6 13l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
        </button>
      </div>

      <!-- sugestão IA (Claude via assinatura) -->
      <div :style="{ margin: isMobile ? '0 12px' : '0 22px 0', background: 'linear-gradient(90deg,rgba(124,108,245,.14),rgba(124,108,245,.04))', border: '1px solid rgba(124,108,245,.3)', borderRadius: '12px', padding: '9px 13px' }">
        <div style="display:flex;align-items:center;gap:10px;">
          <svg width="17" height="17" viewBox="0 0 24 24" fill="#7c6cf5" style="flex-shrink:0;"><path d="m12 2 2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z" /></svg>
          <span v-if="crm.aiLoading" style="font-size:13px;color:#cdc6f7;flex:1;"><b style="color:#a89bf9;">IA</b> está escrevendo…</span>
          <span v-else-if="crm.aiSuggestion" style="font-size:13px;color:#cdc6f7;flex:1;"><b style="color:#a89bf9;">IA sugere:</b> "{{ crm.aiSuggestion }}"</span>
          <span v-else style="font-size:13px;color:#cdc6f7;flex:1;"><b style="color:#a89bf9;">IA:</b> gere uma resposta com base nesta conversa.</span>
          <button v-if="crm.aiSuggestion && !crm.aiLoading" class="aibtn" style="font-size:12px;font-weight:700;color:#fff;background:#7c6cf5;border:none;padding:7px 15px;border-radius:9px;cursor:pointer;flex-shrink:0;" @click="useAISuggestion">Usar</button>
          <button v-else class="aibtn" :disabled="crm.aiLoading" :style="{ fontSize: '12px', fontWeight: 700, color: '#fff', background: '#7c6cf5', border: 'none', padding: '7px 15px', borderRadius: '9px', cursor: crm.aiLoading ? 'default' : 'pointer', flexShrink: 0, opacity: crm.aiLoading ? 0.6 : 1 }" @click="crm.suggestReply()">{{ crm.aiLoading ? '…' : 'Gerar' }}</button>
        </div>
        <div v-if="crm.aiSuggestion && !crm.aiLoading" style="display:flex;align-items:center;gap:7px;margin-top:9px;">
          <input v-model="adjust" placeholder="Ajustar (ex: mais informal, não fale de preço)" style="flex:1;background:#111b21;border:1px solid #2a3942;border-radius:8px;padding:7px 10px;color:#e9edef;font-family:inherit;font-size:12.5px;outline:none;" @keydown.enter="doAdjust">
          <button style="font-size:12px;font-weight:700;color:#a89bf9;background:#202c33;border:none;padding:7px 13px;border-radius:8px;cursor:pointer;flex-shrink:0;" @click="doAdjust">Refazer</button>
          <button v-if="lastInstruction" title="Salvar essa correção na memória (não erra de novo)" style="font-size:12px;font-weight:700;color:#7ee6a8;background:rgba(37,211,102,.12);border:1px solid rgba(37,211,102,.3);padding:7px 12px;border-radius:8px;cursor:pointer;flex-shrink:0;" @click="doSaveRule">💾 Salvar correção</button>
        </div>
      </div>

      <!-- composer -->
      <div :style="{ padding: isMobile ? '10px 12px 12px' : '13px 22px 18px', position: 'relative' }">
        <!-- barra de citação (responder) -->
        <div v-if="replyTo" style="display:flex;align-items:stretch;gap:0;background:#202c33;border-radius:11px 11px 0 0;margin-bottom:-6px;overflow:hidden;">
          <div style="width:4px;background:#25D366;flex-shrink:0;" />
          <div style="flex:1;min-width:0;padding:8px 12px;">
            <div style="font-size:12px;font-weight:700;color:#25D366;">{{ replyTo.isOut ? 'Você' : active.name }}</div>
            <div style="font-size:12.5px;color:#aebac1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ replyTo.text }}</div>
          </div>
          <button title="Cancelar" style="background:none;border:none;color:#8696a0;cursor:pointer;padding:0 12px;font-size:17px;" @click="replyTo = null">✕</button>
        </div>

        <!-- pré-visualização do anexo -->
        <div v-if="pendingFile" style="display:flex;align-items:center;gap:11px;background:#202c33;border-radius:11px 11px 0 0;border-bottom:1px solid #1c2730;margin-bottom:-6px;padding:9px 12px;">
          <img v-if="pendingPreview" :src="pendingPreview" style="width:46px;height:46px;border-radius:8px;object-fit:cover;flex-shrink:0;">
          <div v-else style="width:46px;height:46px;border-radius:8px;background:#0b141a;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:22px;">{{ pendingFile.type.startsWith('video/') ? '🎬' : '📄' }}</div>
          <div style="flex:1;min-width:0;">
            <div style="font-size:13px;font-weight:600;color:#e9edef;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ pendingFile.name }}</div>
            <div style="font-size:11.5px;color:#8696a0;">{{ fmtSize(pendingFile.size) }} · adicione uma legenda (opcional)</div>
          </div>
          <button title="Remover anexo" style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:17px;flex-shrink:0;" @click="cancelAttach">✕</button>
        </div>

        <!-- seletor de emoji -->
        <div v-if="showEmoji" style="position:absolute;bottom:100%;left:22px;z-index:20;background:#233138;border:1px solid #2a3942;border-radius:14px;padding:10px;width:300px;box-shadow:0 12px 32px rgba(0,0,0,.5);display:flex;flex-wrap:wrap;gap:2px;" @click.stop>
          <button v-for="e in EMOJIS" :key="e" style="background:none;border:none;font-size:21px;line-height:1;padding:5px;border-radius:8px;cursor:pointer;" class="emojibtn" @click="insertEmoji(e)">{{ e }}</button>
        </div>

        <input ref="fileInput" type="file" accept="image/*,video/*,application/pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv,.zip" style="display:none;" @change="onFileChosen">

        <!-- gravando nota de voz -->
        <div v-if="recording" style="display:flex;align-items:center;gap:12px;background:#202c33;border-radius:15px;padding:9px 12px;">
          <button title="Cancelar gravação" style="width:40px;height:40px;border-radius:50%;border:none;background:transparent;color:#ff6b6b;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;" @click="cancelRec"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 6h18M8 6V4h8v2m-9 0 1 14h8l1-14" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
          <span style="width:11px;height:11px;border-radius:50%;background:#ff5a3c;flex-shrink:0;animation:recpulse 1.1s infinite;" />
          <span style="font-size:15px;font-weight:700;color:#e9edef;font-variant-numeric:tabular-nums;">{{ recLabel }}</span>
          <span style="flex:1;font-size:13px;color:#8696a0;">Gravando áudio… toque no ✓ para enviar</span>
          <button title="Enviar áudio" style="width:42px;height:42px;border-radius:50%;border:none;background:#25D366;color:#062014;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;box-shadow:0 4px 12px rgba(37,211,102,.3);" @click="sendRec"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
        </div>

        <div v-else style="display:flex;align-items:flex-end;gap:10px;background:#202c33;border-radius:15px;padding:7px 9px 7px 13px;">
          <button v-if="!isMobile" :title="showEmoji ? 'Fechar emojis' : 'Emojis'" :style="`width:36px;height:36px;border-radius:50%;border:none;background:transparent;display:flex;align-items:center;justify-content:center;cursor:pointer;${showEmoji ? 'color:#25D366;' : 'color:#8696a0;'}`" @click.stop="showEmoji = !showEmoji"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 9.5a1 1 0 1 0 0-.1M15 9.5a1 1 0 1 0 0-.1M8.5 14.5a4.5 4.5 0 0 0 7 0" stroke-linecap="round" /><circle cx="12" cy="12" r="9.5" /></svg></button>
          <button title="Anexar foto, vídeo ou documento" style="width:36px;height:36px;border-radius:50%;border:none;background:transparent;color:#8696a0;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;" @click="pickFile"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21.4 11.05 12.25 20.2a5.5 5.5 0 0 1-7.78-7.78l8.49-8.49a3.67 3.67 0 0 1 5.19 5.19l-8.5 8.49a1.83 1.83 0 0 1-2.59-2.59l7.78-7.78" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
          <button class="agbtn" title="Agendar reunião com IA" style="height:36px;border-radius:18px;border:none;background:rgba(37,211,102,.14);color:#25D366;display:flex;align-items:center;gap:6px;padding:0 13px;cursor:pointer;font-family:inherit;font-size:12.5px;font-weight:700;flex-shrink:0;" @click="agendarReuniao"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18M9 16l2 2 4-4" stroke-linecap="round" stroke-linejoin="round" /></svg>Agendar</button>
          <textarea ref="inputRef" :placeholder="pendingFile ? 'Legenda (opcional)…' : 'Digite uma mensagem'" rows="1" style="flex:1;background:transparent;border:none;outline:none;resize:none;color:#e9edef;font-family:inherit;font-size:14px;padding:9px 0;line-height:1.4;max-height:130px;" @keydown="onKeyDown" @input="autogrow" />
          <button v-if="!hasInput && !pendingFile" title="Gravar áudio" style="width:42px;height:42px;border-radius:50%;border:none;background:#25D366;color:#062014;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;box-shadow:0 4px 12px rgba(37,211,102,.3);" @click="startRec"><svg width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="9" y="2" width="6" height="12" rx="3" /><path d="M5 11a7 7 0 0 0 14 0M12 18v3M8.5 21h7" stroke-linecap="round" /></svg></button>
          <button v-else :disabled="sendingMedia" :style="`width:42px;height:42px;border-radius:50%;border:none;background:#25D366;color:#062014;display:flex;align-items:center;justify-content:center;cursor:${sendingMedia ? 'default' : 'pointer'};flex-shrink:0;box-shadow:0 4px 12px rgba(37,211,102,.3);opacity:${sendingMedia ? 0.6 : 1};`" @click="send">
            <svg v-if="sendingMedia" class="spin" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M21 12a9 9 0 1 1-6.2-8.5" stroke-linecap="round" /></svg>
            <svg v-else width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4 21.85 12 3.4 3.6l.05 6.53L17 12 3.45 13.87z" /></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- painel de contexto -->
    <div :style="{ width:'312px', flexShrink:0, background:'#111b21', borderLeft:'1px solid #1c2730', flexDirection:'column', overflowY:'auto', display: isMobile ? 'none' : 'flex' }">
      <div style="padding:24px 20px 18px;text-align:center;border-bottom:1px solid #1c2730;">
        <div :style="active.avatarBig">
          <img v-if="active.avatar && !broken.has(active.id)" :src="active.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" @error="broken.add(active.id)">
          <template v-else>{{ active.initials }}</template>
        </div>
        <div v-if="!editingName" style="display:flex;align-items:center;justify-content:center;gap:6px;">
          <span style="font-weight:700;font-size:17px;">{{ active.name }}</span>
          <button title="Editar nome" style="background:none;border:none;color:#8696a0;cursor:pointer;padding:2px;display:flex;flex-shrink:0;" @click="startEditName"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
        </div>
        <div v-else style="display:flex;align-items:center;justify-content:center;gap:6px;">
          <input v-model="nameDraft" :placeholder="active.name" autofocus style="background:#202c33;border:1px solid #2a3942;border-radius:8px;padding:6px 10px;color:#e9edef;font-family:inherit;font-size:14px;font-weight:700;text-align:center;outline:none;width:170px;" @keydown.enter="saveName" @keydown.esc="editingName = false">
          <button title="Salvar" style="background:#25D366;border:none;color:#062014;border-radius:8px;width:30px;height:30px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;" @click="saveName"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
        </div>
        <div v-if="active.phone" style="font-size:13.5px;color:#aebac1;margin-top:3px;font-weight:600;">{{ active.phone }}</div>
        <div v-if="active.role" style="font-size:13px;color:#8696a0;margin-top:2px;">{{ active.role }}</div>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;">
          <button class="ghost" style="flex:1;background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:12.5px;font-weight:600;padding:9px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;" @click="crm.go('contact')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" stroke-linecap="round" /></svg>Ficha</button>
        </div>
      </div>

      <div style="padding:16px 20px;border-bottom:1px solid #1c2730;">
        <div style="font-size:11px;font-weight:700;color:#8696a0;letter-spacing:.5px;margin-bottom:11px;">NEGÓCIO</div>
        <div style="background:#202c33;border-radius:12px;padding:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <span style="font-size:13px;color:#8696a0;flex-shrink:0;">Valor</span>
            <input :value="valueDraft ?? active.dealValue" placeholder="R$ 0" title="Editar valor do negócio" style="width:130px;text-align:right;background:#111b21;border:1px solid #2a3942;border-radius:8px;padding:5px 9px;font-size:16px;font-weight:800;color:#25D366;font-family:inherit;outline:none;" @focus="valueDraft = active.dealValue" @input="valueDraft = ($event.target as HTMLInputElement).value" @change="onEditValue(($event.target as HTMLInputElement).value)" @blur="valueDraft = null" @keydown.enter="($event.target as HTMLInputElement).blur()" >
          </div>
          <div style="display:flex;justify-content:space-between;align-items:center;margin-top:11px;"><span style="font-size:13px;color:#8696a0;">Estágio</span><span :style="active.stageStyle">{{ active.stage }}</span></div>
          <div style="margin-top:12px;height:5px;border-radius:4px;background:#0b141a;overflow:hidden;"><div :style="active.progStyle" /></div>
          <div style="font-size:11px;color:#8696a0;margin-top:6px;">{{ active.probText }}</div>
        </div>
      </div>

      <div style="padding:16px 20px;border-bottom:1px solid #1c2730;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:11px;">
          <div style="display:flex;align-items:center;gap:7px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="#7c6cf5"><path d="m12 2 2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z" /></svg><span style="font-size:11px;font-weight:700;color:#a89bf9;letter-spacing:.5px;">RESPOSTAS RÁPIDAS</span></div>
          <button title="Nova resposta rápida" style="background:#202c33;border:none;color:#a89bf9;width:24px;height:24px;border-radius:7px;cursor:pointer;display:flex;align-items:center;justify-content:center;" @click="addingQR = !addingQR"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg></button>
        </div>

        <div v-if="addingQR" style="background:#202c33;border-radius:10px;padding:11px;margin-bottom:9px;display:flex;flex-direction:column;gap:8px;">
          <input v-model="qrLabel" placeholder="Título (ex: Agendar demo)" style="background:#111b21;border:1px solid #2a3942;border-radius:8px;padding:8px 10px;color:#e9edef;font-family:inherit;font-size:12.5px;outline:none;">
          <textarea v-model="qrText" rows="2" placeholder="Texto da mensagem" style="background:#111b21;border:1px solid #2a3942;border-radius:8px;padding:8px 10px;color:#e9edef;font-family:inherit;font-size:12.5px;outline:none;resize:vertical;" />
          <div style="display:flex;gap:7px;justify-content:flex-end;">
            <button style="background:transparent;border:none;color:#8696a0;font-family:inherit;font-size:12px;font-weight:600;cursor:pointer;padding:6px 10px;" @click="addingQR = false">Cancelar</button>
            <button :disabled="savingQR" style="background:#7c6cf5;border:none;color:#fff;font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;padding:6px 13px;border-radius:8px;" @click="saveQR">Salvar</button>
          </div>
        </div>

        <div style="display:flex;flex-direction:column;gap:8px;">
          <div v-if="!crm.quickReplies.length && !addingQR" style="font-size:12.5px;color:#5f6f78;padding:4px 2px;">Nenhuma resposta rápida. Clique no + para criar.</div>
          <div v-for="qr in crm.quickReplies" :key="qr.id" class="ghost qrrow" style="background:#202c33;border-radius:10px;padding:10px 12px;font-size:13px;cursor:pointer;display:flex;justify-content:space-between;align-items:center;gap:8px;" @click="useQuick(qr.text)">
            <span style="white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ qr.label }}</span>
            <button class="qrdel" title="Excluir" style="background:none;border:none;color:#8696a0;cursor:pointer;flex-shrink:0;display:flex;align-items:center;padding:0;" @click.stop="crm.removeQuickReply(qr.id)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
          </div>
        </div>
      </div>

      <div style="padding:16px 20px;">
        <button class="wabtn" style="width:100%;background:#25D366;border:none;color:#062014;font-family:inherit;font-size:14px;font-weight:700;padding:13px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 6px 16px rgba(37,211,102,.28);" @click="agendarReuniao"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4.5" width="18" height="16" rx="2.5" /><path d="M3 9h18M8 2.5v4M16 2.5v4" stroke-linecap="round" /></svg>Agendar reunião com IA</button>
      </div>
    </div>

    <div v-if="memoToast" style="position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:60;background:#2a3942;color:#e9edef;font-size:13px;font-weight:600;padding:11px 20px;border-radius:11px;box-shadow:0 8px 24px rgba(0,0,0,.45);">{{ memoToast }}</div>

    <!-- Encaminhar: escolher conversa de destino -->
    <div v-if="forwardMsg" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:80;padding:20px;" @click.self="forwardMsg = null">
      <div style="width:420px;max-width:100%;max-height:80vh;display:flex;flex-direction:column;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:18px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;flex-shrink:0;">
          <div style="font-size:16px;font-weight:800;">Encaminhar para…</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="forwardMsg = null">✕</button>
        </div>
        <div style="display:flex;align-items:center;gap:9px;background:#202c33;border-radius:10px;padding:8px 11px;margin-bottom:11px;flex-shrink:0;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
          <input v-model="forwardSearch" placeholder="Buscar conversa" style="flex:1;background:transparent;border:none;outline:none;color:#e9edef;font-family:inherit;font-size:13.5px;">
        </div>
        <div style="flex:1;overflow-y:auto;display:flex;flex-direction:column;gap:2px;">
          <div v-if="!forwardList.length" style="font-size:13px;color:#8696a0;text-align:center;padding:20px;">Nenhuma conversa encontrada.</div>
          <button v-for="c in forwardList" :key="c.id" class="mitem" :disabled="forwardSending" style="display:flex;align-items:center;gap:11px;background:none;border:none;color:#e9edef;font-family:inherit;font-size:14px;padding:9px 10px;border-radius:10px;cursor:pointer;text-align:left;width:100%;" @click="doForward(c.id)">
            <span :style="{ width: '38px', height: '38px', borderRadius: '50%', background: c.color, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '14px', flexShrink: 0 }">{{ c.initials }}</span>
            <span style="flex:1;min-width:0;"><span style="display:block;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.name }}</span><span style="display:block;font-size:12px;color:#8696a0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.preview }}</span></span>
          </button>
        </div>
      </div>
    </div>

    <!-- Lightbox: imagem em tela cheia -->
    <div v-if="lightbox" style="position:fixed;inset:0;background:rgba(0,0,0,.9);display:flex;align-items:center;justify-content:center;z-index:80;cursor:zoom-out;" @click="lightbox = null">
      <img :src="lightbox" style="max-width:92vw;max-height:92vh;border-radius:8px;object-fit:contain;box-shadow:0 10px 40px rgba(0,0,0,.6);">
      <button title="Fechar" style="position:absolute;top:18px;right:22px;background:rgba(255,255,255,.12);border:none;color:#fff;width:42px;height:42px;border-radius:50%;cursor:pointer;font-size:22px;line-height:1;" @click.stop="lightbox = null">✕</button>
    </div>

    <!-- Modal: importar conversa (.txt) -->
    <div v-if="showImport" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:60;padding:24px;" @click.self="showImport = false">
      <div style="width:440px;max-width:100%;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div style="font-size:18px;font-weight:800;">Importar conversa (.txt)</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="showImport = false">×</button>
        </div>
        <div style="font-size:12.5px;color:#8696a0;margin-bottom:16px;">No WhatsApp: abra a conversa → ⋮ / nome → <b>Exportar conversa</b> → Sem mídia → salve o .txt e suba aqui.</div>

        <label class="lbl2">Arquivo .txt</label>
        <input type="file" accept=".txt,text/plain" style="width:100%;font-size:12.5px;color:#aebac1;margin-bottom:4px;" @change="onImportFile">
        <div v-if="importFileName" style="font-size:11.5px;color:#7ee6a8;margin-bottom:8px;">📄 {{ importFileName }}</div>

        <template v-if="importSenders.length">
          <label class="lbl2" style="margin-top:8px;">Quem é o cliente? (o resto vira "você")</label>
          <select v-model="importClient" style="width:100%;box-sizing:border-box;background:#202c33;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13.5px;outline:none;color-scheme:dark;">
            <option v-for="s in importSenders" :key="s" :value="s">{{ s }}</option>
          </select>
        </template>

        <label class="lbl2" style="margin-top:11px;">Número do contato (com DDD)</label>
        <input v-model="importNumber" placeholder="+55 11 99999-9999" style="width:100%;box-sizing:border-box;background:#202c33;border:1px solid #2a3942;border-radius:9px;padding:10px 12px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;">
        <label class="lbl2" style="margin-top:11px;">Nome (opcional)</label>
        <input v-model="importName" :placeholder="importClient || 'Ex.: João Silva'" style="width:100%;box-sizing:border-box;background:#202c33;border:1px solid #2a3942;border-radius:9px;padding:10px 12px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;">

        <div v-if="importErr" style="background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-size:12.5px;font-weight:600;padding:10px 12px;border-radius:9px;margin-top:12px;">{{ importErr }}</div>
        <div v-if="importInfo" style="background:#16241c;border:1px solid rgba(37,211,102,.3);color:#7ee6a8;font-size:12.5px;font-weight:600;padding:10px 12px;border-radius:9px;margin-top:12px;">{{ importInfo }}</div>

        <div style="display:flex;gap:10px;align-items:center;margin-top:18px;">
          <div style="flex:1;" />
          <button style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13.5px;padding:10px 16px;border-radius:10px;cursor:pointer;" @click="showImport = false">Cancelar</button>
          <button class="wabtn" :disabled="importSaving || !importText || !importNumber.trim()" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 18px;border-radius:10px;cursor:pointer;" @click="doImport">{{ importSaving ? 'Importando…' : 'Importar' }}</button>
        </div>
      </div>
    </div>

    <!-- Modal: nova conversa por número -->
    <div v-if="showNewConv" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:60;padding:24px;" @click.self="showNewConv = false">
      <div style="width:400px;max-width:100%;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div style="font-size:18px;font-weight:800;">Nova conversa</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="showNewConv = false">×</button>
        </div>
        <div style="font-size:12.5px;color:#8696a0;margin-bottom:16px;">Inicie uma conversa com um número do WhatsApp (mesmo sem histórico).</div>
        <label class="lbl2">Número (com DDD)</label>
        <input v-model="newConvNumber" placeholder="+55 97 8426-2557" style="width:100%;box-sizing:border-box;background:#202c33;border:1px solid #2a3942;border-radius:9px;padding:10px 12px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;" @keydown.enter="createConversation">
        <label class="lbl2" style="margin-top:11px;">Nome (opcional)</label>
        <input v-model="newConvName" placeholder="Ex.: João Silva" style="width:100%;box-sizing:border-box;background:#202c33;border:1px solid #2a3942;border-radius:9px;padding:10px 12px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;" @keydown.enter="createConversation">
        <div v-if="newConvError" style="background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-size:12.5px;font-weight:600;padding:10px 12px;border-radius:9px;margin-top:12px;">{{ newConvError }}</div>
        <div style="display:flex;gap:10px;align-items:center;margin-top:18px;">
          <div style="flex:1;" />
          <button style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13.5px;padding:10px 16px;border-radius:10px;cursor:pointer;" @click="showNewConv = false">Cancelar</button>
          <button class="wabtn" :disabled="newConvSaving || !newConvNumber.trim()" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 18px;border-radius:10px;cursor:pointer;" @click="createConversation">{{ newConvSaving ? 'Abrindo…' : 'Iniciar conversa' }}</button>
        </div>
      </div>
    </div>

    <!-- Modal: gerenciar tabs por etiqueta -->
    <div v-if="showTabs" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:60;padding:24px;" @click.self="showTabs = false">
      <div style="width:440px;max-width:100%;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:6px;">
          <div style="font-size:18px;font-weight:800;">Tabs por etiqueta</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="showTabs = false">×</button>
        </div>
        <div style="font-size:12.5px;color:#8696a0;margin-bottom:16px;">Crie tabs que filtram as conversas por etiqueta (ex.: "SDR" = Lead + Contato feito).</div>

        <!-- tabs existentes -->
        <div v-if="crm.chatTabs.length" style="display:flex;flex-direction:column;gap:7px;margin-bottom:16px;">
          <div v-for="t in crm.chatTabs" :key="t.id" style="display:flex;align-items:center;gap:8px;background:#202c33;border-radius:10px;padding:8px 11px;">
            <span style="flex:1;font-size:13.5px;font-weight:600;">{{ t.name }}</span>
            <span style="font-size:11.5px;color:#8696a0;">{{ t.stages.length }} etiqueta(s)</span>
            <button title="Editar" style="background:none;border:none;color:#8696a0;cursor:pointer;padding:2px 4px;" @click="editTabForm(t)">✎</button>
            <button title="Excluir" style="background:none;border:none;color:#ff6b6b;cursor:pointer;padding:2px 4px;" @click="deleteTab(t.id)">✕</button>
          </div>
        </div>

        <!-- form criar/editar -->
        <div style="border-top:1px solid #1c2730;padding-top:14px;">
          <div style="font-size:12px;font-weight:700;color:#a89bf9;margin-bottom:8px;">{{ editingTabId ? 'Editar tab' : 'Nova tab' }}</div>
          <input v-model="tabName" placeholder="Nome da tab (ex.: SDR)" style="width:100%;box-sizing:border-box;background:#202c33;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13.5px;outline:none;margin-bottom:11px;">
          <div style="font-size:11.5px;color:#8696a0;margin-bottom:7px;">Etiquetas que esta tab mostra:</div>
          <div style="display:flex;flex-wrap:wrap;gap:7px;">
            <button v-for="s in crm.stages" :key="s.key" :style="{ fontSize: '12px', fontWeight: 600, padding: '5px 11px', borderRadius: '8px', cursor: 'pointer', border: tabStages.includes(s.key) ? `1px solid ${s.color}` : '1px solid #2a3942', background: tabStages.includes(s.key) ? `${s.color}22` : '#202c33', color: tabStages.includes(s.key) ? '#e9edef' : '#8696a0' }" @click="toggleTabStage(s.key)">
              <span :style="{ display: 'inline-block', width: '8px', height: '8px', borderRadius: '2px', background: s.color, marginRight: '6px' }" />{{ s.name }}
            </button>
          </div>
          <div style="display:flex;gap:10px;align-items:center;margin-top:16px;">
            <button v-if="editingTabId" style="background:#202c33;border:none;color:#8696a0;font-family:inherit;font-size:13px;padding:9px 14px;border-radius:10px;cursor:pointer;" @click="newTabForm">Cancelar edição</button>
            <div style="flex:1;" />
            <button class="wabtn" :disabled="!tabName.trim()" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:9px 18px;border-radius:10px;cursor:pointer;" @click="saveTab">{{ editingTabId ? 'Salvar' : 'Criar tab' }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: agendar reunião com IA -->
    <div v-if="schedOpen" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:60;padding:24px;" @click.self="schedOpen = false">
      <div style="width:440px;max-width:100%;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div style="font-size:18px;font-weight:800;">Agendar reunião</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="schedOpen = false">×</button>
        </div>

        <!-- Carregando -->
        <div v-if="schedLoading" style="display:flex;flex-direction:column;align-items:center;gap:12px;padding:24px 0;">
          <div class="spin" style="width:30px;height:30px;border:3px solid #2a3942;border-top-color:#25D366;border-radius:50%;" />
          <div style="font-size:13.5px;color:#8696a0;text-align:center;">Lendo a conversa e consultando sua agenda…</div>
        </div>

        <!-- Erro -->
        <div v-else-if="schedError" style="padding:6px 0 4px;">
          <div style="background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-size:13px;font-weight:600;padding:12px 14px;border-radius:11px;">{{ schedError }}</div>
          <div style="display:flex;justify-content:flex-end;margin-top:16px;">
            <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 18px;border-radius:11px;cursor:pointer;" @click="agendarReuniao">Tentar de novo</button>
          </div>
        </div>

        <!-- Resultado -->
        <div v-else-if="schedResult">
          <div v-if="schedResult.scheduled" style="display:flex;align-items:center;gap:9px;background:#16241c;border:1px solid rgba(37,211,102,.3);border-radius:11px;padding:12px 14px;">
            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.5" style="flex-shrink:0;"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
            <div><div style="font-size:14px;font-weight:700;color:#7ee6a8;">Reunião marcada</div><div style="font-size:12.5px;color:#8fb3a0;margin-top:1px;">{{ schedResult.slot_label }}</div></div>
          </div>
          <div v-else style="background:#23201a;border:1px solid rgba(255,180,67,.3);border-radius:11px;padding:12px 14px;font-size:13px;color:#ffd494;">
            {{ schedResult.note || 'O lead ainda não confirmou um horário. Envie a sugestão abaixo para ele confirmar a disponibilidade — a reunião só é marcada depois.' }}
          </div>

          <a v-if="schedResult.event?.hangout_link" :href="schedResult.event.hangout_link" target="_blank" style="display:inline-block;margin-top:11px;font-size:13px;color:#25D366;text-decoration:none;">🎥 Link do Google Meet</a>

          <div style="font-size:11.5px;color:#8696a0;font-weight:600;margin:16px 0 6px;">MENSAGEM PARA O CLIENTE</div>
          <div style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:12px 14px;font-size:13.5px;color:#e9edef;white-space:pre-wrap;line-height:1.5;">{{ schedResult.message }}</div>

          <div style="display:flex;gap:10px;align-items:center;margin-top:18px;">
            <div style="flex:1;" />
            <button style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 16px;border-radius:11px;cursor:pointer;" @click="schedOpen = false">Fechar</button>
            <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 18px;border-radius:11px;cursor:pointer;" @click="enviarSugestao">Enviar ao cliente</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.convrow:hover { background: #19242b !important; }
.rowmenu { opacity: 0; transition: opacity .15s; }
.convrow:hover .rowmenu { opacity: 1; }
.mitem:hover { background: #2a3942 !important; }
.iconbtn:hover { background: #2a3942 !important; }
.aibtn:hover { background: #8b7cff !important; }
.ghost:hover { background: #2a3942 !important; }
.wabtn:hover { background: #2ee070 !important; }
.agbtn:hover { background: #2a3942 !important; }
.lbl2 { display:block; font-size:11.5px; color:#8696a0; font-weight:600; margin-bottom:5px; }
.spin { animation: spin 0.8s linear infinite; }
@keyframes spin { to { transform: rotate(360deg); } }
@keyframes recpulse { 0%, 100% { opacity: 1; } 50% { opacity: .3; } }
.bubble .del-btn { position: absolute; top: -10px; width: 23px; height: 23px; border-radius: 50%; border: none; background: #2a3942; color: #ff6b6b; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s; box-shadow: 0 2px 6px rgba(0,0,0,.4); z-index: 2; }
.bubble:hover .del-btn { opacity: 1; }
.bubble .del-btn:hover { background: #3a4a54; }
/* botão de opções (menu de contexto) da mensagem */
.bubble .msgmenu-btn { position: absolute; top: -10px; width: 23px; height: 23px; border-radius: 50%; border: none; background: #2a3942; color: #cfd6db; cursor: pointer; display: flex; align-items: center; justify-content: center; opacity: 0; transition: opacity .15s; box-shadow: 0 2px 6px rgba(0,0,0,.4); z-index: 3; }
.bubble:hover .msgmenu-btn { opacity: 1; }
.bubble .msgmenu-btn:hover { background: #3a4a54; }
.msg-ctx { position: absolute; top: 100%; margin-top: 4px; z-index: 20; background: #233138; border: 1px solid #2a3942; border-radius: 10px; padding: 5px; min-width: 150px; box-shadow: 0 12px 30px rgba(0,0,0,.5); display: flex; flex-direction: column; }
.ctxitem { display: flex; align-items: center; gap: 9px; width: 100%; text-align: left; background: none; border: none; color: #e9edef; font-family: inherit; font-size: 13px; padding: 8px 10px; border-radius: 7px; cursor: pointer; }
.ctxitem:hover { background: #2a3942; }
.ctxitem.danger { color: #ff8d8d; }
.emojibtn:hover { background: #2a3942; }
.react-row { display: flex; gap: 2px; padding: 2px 2px 6px; margin-bottom: 4px; border-bottom: 1px solid #2a3942; }
.reactbtn { background: none; border: none; font-size: 20px; line-height: 1; padding: 4px 5px; border-radius: 8px; cursor: pointer; }
.reactbtn:hover { background: #2a3942; transform: scale(1.15); }
.reactbtn.on { background: rgba(37,211,102,.2); }
.react-badge { position: absolute; bottom: -11px; background: #233138; border: 2px solid #111b21; border-radius: 11px; padding: 1px 5px; font-size: 12px; line-height: 1.3; box-shadow: 0 2px 5px rgba(0,0,0,.4); z-index: 2; }
</style>
