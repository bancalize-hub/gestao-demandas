<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()

const inputRef = ref<HTMLTextAreaElement | null>(null)
const msgsRef = ref<HTMLElement | null>(null)

// Busca + filtros da lista de conversas
const search = ref('')
const filter = ref<'tudo' | 'unread' | 'minhas' | 'arquivadas'>('tudo')
const menuFor = ref('')
function pillStyle(active: boolean) {
  return active
    ? { fontSize: '12px', fontWeight: 700, color: '#062014', background: '#25D366', padding: '5px 13px', borderRadius: '20px', cursor: 'pointer', border: 'none' }
    : { fontSize: '12px', fontWeight: 600, color: '#8696a0', background: '#202c33', padding: '5px 13px', borderRadius: '20px', cursor: 'pointer', border: 'none' }
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
const broken = reactive(new Set<string>())

// Polling p/ mensagens novas (tempo real via webhook do WhatsApp).
let pollTimer: ReturnType<typeof setInterval> | null = null
onMounted(() => { pollTimer = setInterval(() => crm.refresh(), 7000) })
onBeforeUnmount(() => { if (pollTimer) clearInterval(pollTimer) })

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

// Etiquetas
const showLabels = ref(false)
const newLabel = ref('')
function convTags(): { label: string, color: string }[] {
  return conv.value?.tags ?? []
}
function hasLabel(name: string) {
  return convTags().some(t => t.label === name)
}
function toggleLabel(l: { name: string, color: string }) {
  const id = conv.value?.id
  if (!id) return
  const tags = hasLabel(l.name)
    ? convTags().filter(t => t.label !== l.name)
    : [...convTags(), { label: l.name, color: l.color }]
  crm.setConvLabels(id, tags)
}
async function addNewLabel() {
  const name = newLabel.value.trim()
  if (!name) return
  const created = await crm.createLabel({ name, color: '#7c6cf5' })
  newLabel.value = ''
  toggleLabel({ name: created.name, color: created.color })
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
    id: c.id, name: c.name, initials: c.initials, avatar: c.avatar, role: c.role,
    statusText: c.statusText, statusColor: c.online ? '#25D366' : '#8696a0',
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
  id: c.id, name: c.name, initials: c.initials, avatar: c.avatar, preview: c.preview, time: c.time,
  unread: c.unread, online: c.online, hot: !!c.hot, hasUnread: c.unread > 0, archived: c.archived, inMemory: c.inMemory, tags: c.tags || [],
  rowStyle: { display: 'flex', gap: '12px', padding: '11px 12px', borderRadius: '13px', cursor: 'pointer', alignItems: 'center', position: 'relative', background: c.id === crm.activeId ? '#202c33' : 'transparent' },
  avatarStyle: { width: '48px', height: '48px', borderRadius: '50%', background: c.color, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '16px', flexShrink: 0, position: 'relative' },
  dotStyle: { position: 'absolute', bottom: '1px', right: '1px', width: '12px', height: '12px', borderRadius: '50%', background: '#25D366', border: `2.5px solid ${c.id === crm.activeId ? '#202c33' : '#111b21'}` },
})))

const filteredList = computed(() => {
  const q = search.value.trim().toLowerCase()
  return list.value.filter((c) => {
    if (filter.value === 'arquivadas') {
      if (!c.archived) return false
    }
    else if (c.archived) {
      return false
    }
    if (filter.value === 'unread' && !c.hasUnread) return false
    if (q && !(`${c.name} ${c.preview}`.toLowerCase().includes(q))) return false
    return true
  })
})

const counts = computed(() => ({
  tudo: list.value.filter(c => !c.archived).length,
  unread: list.value.filter(c => c.hasUnread && !c.archived).length,
  minhas: list.value.filter(c => !c.archived).length,
  arquivadas: list.value.filter(c => c.archived).length,
}))

const thread = computed(() => (conv.value?.thread || []).map((m) => {
  const isOut = !!m.isOut
  const baseBg = isOut ? '#005c4b' : '#202c33'
  const align = isOut ? 'flex-end' : 'flex-start'
  return {
    ...m, isOut,
    isDivider: m.type === 'divider', isText: m.type === 'text',
    isImage: m.type === 'image', isVoice: m.type === 'voice', isVideo: m.type === 'video', isFile: m.type === 'file',
    isMedia: m.type === 'image' || m.type === 'voice' || m.type === 'video' || m.type === 'file',
    avColor: conv.value?.color, avInitials: conv.value?.initials,
    dividerStyle: { alignSelf: 'center', background: '#1c2a33', color: '#8696a0', fontSize: '11px', fontWeight: 600, padding: '5px 13px', borderRadius: '8px', margin: '2px 0 6px' },
    bubbleText: { alignSelf: align, maxWidth: '64%', background: baseBg, padding: '9px 13px', borderRadius: isOut ? '9px 9px 2px 9px' : '9px 9px 9px 2px' },
    bubbleMedia: { alignSelf: align, maxWidth: '64%', background: baseBg, padding: '8px', borderRadius: '9px' },
  }
}))

function send() {
  const el = inputRef.value
  if (!el) return
  const t = el.value
  el.value = ''
  crm.send(t)
}
function onKeyDown(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() }
}
function useAISuggestion() {
  const el = inputRef.value
  if (el && crm.aiSuggestion) { el.value = crm.aiSuggestion; el.focus() }
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
    <div style="width:340px;flex-shrink:0;background:#111b21;border-right:1px solid #1c2730;display:flex;flex-direction:column;">
      <div style="padding:18px 18px 12px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div style="font-size:19px;font-weight:800;letter-spacing:-.2px;">Conversas</div>
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
          <div v-for="c in filteredList" :key="c.id" :style="c.rowStyle" class="convrow" @click="crm.selectConv(c.id); menuFor = ''">
            <div :style="c.avatarStyle">
              <img v-if="c.avatar && !broken.has(c.id)" :src="c.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" @error="broken.add(c.id)">
              <template v-else>{{ c.initials }}</template>
              <span v-if="c.online" :style="c.dotStyle" />
            </div>
            <div style="flex:1;min-width:0;">
              <div style="display:flex;justify-content:space-between;align-items:center;">
                <span style="font-weight:700;font-size:14.5px;">{{ c.name }}</span>
                <span style="font-size:11px;color:#8696a0;">{{ c.time }}</span>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:3px;gap:8px;">
                <span style="font-size:13px;color:#aebac1;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;">{{ c.preview }}</span>
                <span v-if="c.hasUnread" style="background:#25D366;color:#062014;font-size:11px;font-weight:700;min-width:19px;height:19px;border-radius:10px;display:flex;align-items:center;justify-content:center;padding:0 5px;flex-shrink:0;">{{ c.unread }}</span>
              </div>
              <div v-if="c.hot" style="margin-top:6px;display:inline-flex;font-size:10.5px;font-weight:700;color:#ff7a45;background:rgba(255,122,69,.13);padding:2px 8px;border-radius:6px;">🔥 Lead quente</div>
              <div v-if="c.tags && c.tags.length" style="display:flex;gap:4px;flex-wrap:wrap;margin-top:5px;">
                <span v-for="(t, i) in c.tags" :key="i" :style="{ fontSize: '10px', fontWeight: 700, color: t.color, background: `${t.color}22`, padding: '1px 7px', borderRadius: '5px' }">{{ t.label }}</span>
              </div>
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
    <div style="position:relative;flex:1;display:flex;flex-direction:column;min-width:0;background:#0b141a;background-image:radial-gradient(circle at 20% 30%,rgba(37,211,102,.04),transparent 40%),radial-gradient(circle at 80% 70%,rgba(124,108,245,.04),transparent 40%);">
      <div v-if="crm.isOffline" style="display:flex;align-items:center;gap:9px;background:rgba(255,180,67,.13);color:#ffce80;font-size:12.5px;font-weight:600;padding:9px 22px;border-bottom:1px solid rgba(255,180,67,.22);">
        <span style="width:8px;height:8px;border-radius:50%;background:#ffb443;animation:recpulse 1.2s infinite;" />Sem conexão — tentando reconectar…
      </div>

      <!-- header -->
      <div style="display:flex;align-items:center;gap:13px;padding:13px 22px;background:#111b21;border-bottom:1px solid #1c2730;">
        <div :style="active.avatarHeader">
          <img v-if="active.avatar && !broken.has(active.id)" :src="active.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" @error="broken.add(active.id)">
          <template v-else>{{ active.initials }}</template>
        </div>
        <div style="flex:1;min-width:0;">
          <div style="font-weight:700;font-size:15.5px;">{{ active.name }}</div>
          <div :style="{ fontSize: '12px', color: active.statusColor }">{{ active.statusText }}</div>
        </div>
        <div style="display:flex;gap:7px;align-items:center;">
          <div style="display:flex;gap:6px;margin-right:6px;">
            <span v-for="(t, i) in active.tags" :key="i" :style="t.style">{{ t.label }}</span>
          </div>
          <div style="position:relative;">
            <button class="iconbtn" title="Etiquetas" style="width:38px;height:38px;border-radius:11px;border:none;background:#202c33;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;" @click.stop="showLabels = !showLabels">
              <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0l-7.2-7.2A2 2 0 0 1 2.8 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.4 7.4a2 2 0 0 1 0 2.8Z" stroke-linejoin="round" /><circle cx="7.5" cy="7.5" r="1.4" fill="currentColor" stroke="none" /></svg>
            </button>
            <div v-if="showLabels" style="position:absolute;top:46px;right:0;z-index:40;background:#202c33;border:1px solid #2a3942;border-radius:12px;padding:9px;min-width:220px;box-shadow:0 12px 32px rgba(0,0,0,.5);" @click.stop>
              <div style="font-size:10.5px;color:#8696a0;font-weight:700;letter-spacing:.5px;padding:3px 7px 7px;">ETAPA DO FUNIL</div>
              <button v-for="l in crm.stages" :key="l.key" class="mitem" style="width:100%;display:flex;align-items:center;gap:9px;background:none;border:none;color:#e9edef;font-family:inherit;font-size:13px;padding:7px 8px;border-radius:7px;cursor:pointer;text-align:left;" @click="toggleLabel(l)">
                <span :style="{ width: '11px', height: '11px', borderRadius: '3px', background: l.color, flexShrink: 0 }" />
                <span style="flex:1;">{{ l.name }}</span>
                <svg v-if="hasLabel(l.name)" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.5"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </button>
              <div style="font-size:11px;color:#5f6f78;padding:7px 8px 2px;">Edite as etapas no Funil.</div>
            </div>
          </div>
          <button class="iconbtn" style="width:38px;height:38px;border-radius:11px;border:none;background:#202c33;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;" @click="navigateTo('/reuniao/' + (conv?.id || 'sala'))">
            <svg width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="6" width="13" height="12" rx="2.5" /><path d="M15.5 10l6-3.2v10.4l-6-3.2" /></svg>
          </button>
          <button class="iconbtn" style="width:38px;height:38px;border-radius:11px;border:none;background:#202c33;color:#e9edef;display:flex;align-items:center;justify-content:center;cursor:pointer;">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2Z" /></svg>
          </button>
        </div>
      </div>

      <!-- mensagens -->
      <button v-if="!atBottom" title="Descer" style="position:absolute;bottom:120px;right:26px;z-index:15;width:42px;height:42px;border-radius:50%;border:none;background:#202c33;color:#e9edef;box-shadow:0 4px 14px rgba(0,0,0,.45);cursor:pointer;display:flex;align-items:center;justify-content:center;" @click="scrollDown">
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 5v14M6 13l6 6 6-6" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </button>
      <div ref="msgsRef" style="flex:1;overflow-y:auto;padding:24px 18% 18px;display:flex;flex-direction:column;gap:9px;" @scroll="onScroll">
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
            <div v-else-if="m.isText" :style="m.bubbleText">
              <div style="font-size:14px;line-height:1.42;">{{ m.text }}</div>
              <div style="display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:3px;">
                <span style="font-size:10.5px;color:#8696a0;">{{ m.time }}</span>
                <svg v-if="m.isOut" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#53bdeb" stroke-width="2.4"><path d="m4 13 3.5 3.5L14 8M11 16l1.5 1.5L20 9" /></svg>
              </div>
            </div>
            <div v-else-if="m.isMedia" :style="m.bubbleMedia">
              <template v-if="media[m.id]">
                <img v-if="m.isImage" :src="media[m.id]" style="max-width:260px;width:100%;border-radius:7px;display:block;">
                <video v-else-if="m.isVideo" :src="media[m.id]" controls style="max-width:260px;width:100%;border-radius:7px;display:block;" />
                <audio v-else-if="m.isVoice" :src="media[m.id]" controls style="width:230px;display:block;" />
                <a v-else :href="media[m.id]" :download="m.fileName || 'arquivo'" style="display:flex;align-items:center;gap:10px;background:rgba(0,0,0,.18);border-radius:7px;padding:10px 12px;color:#e9edef;text-decoration:none;font-size:13px;"><svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#a7d4c5" stroke-width="1.8"><path d="M12 3v12m0 0 4-4m-4 4-4-4M5 21h14" stroke-linecap="round" stroke-linejoin="round" /></svg>Baixar arquivo</a>
              </template>
              <button v-else :disabled="mediaLoading[m.id]" style="display:flex;align-items:center;gap:9px;background:rgba(0,0,0,.18);border:none;color:#e9edef;font-family:inherit;font-size:13px;padding:11px 14px;border-radius:7px;cursor:pointer;width:100%;min-width:170px;" @click="loadMedia(m.id)">
                <span style="font-size:18px;">{{ m.isImage ? '📷' : m.isVoice ? '🎵' : m.isVideo ? '🎬' : '📄' }}</span>
                <span style="flex:1;text-align:left;">{{ mediaLoading[m.id] ? 'Carregando…' : (m.isImage ? 'Ver imagem' : m.isVoice ? 'Tocar áudio' : m.isVideo ? 'Ver vídeo' : 'Baixar arquivo') }}</span>
              </button>
              <div v-if="m.text" style="font-size:13.5px;line-height:1.4;margin-top:6px;padding:0 2px;">{{ m.text }}</div>
              <div style="display:flex;align-items:center;justify-content:flex-end;gap:4px;margin-top:4px;padding-right:2px;">
                <span style="font-size:10.5px;color:#8696a0;">{{ m.time }}</span>
                <svg v-if="m.isOut" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#53bdeb" stroke-width="2.4"><path d="m4 13 3.5 3.5L14 8M11 16l1.5 1.5L20 9" /></svg>
              </div>
            </div>
          </template>
          <div v-if="crm.typing" style="align-self:flex-start;background:#202c33;padding:13px 16px;border-radius:9px 9px 9px 2px;display:flex;gap:4px;align-items:center;">
            <span style="width:6px;height:6px;border-radius:50%;background:#8696a0;animation:typing 1.2s infinite;" /><span style="width:6px;height:6px;border-radius:50%;background:#8696a0;animation:typing 1.2s infinite .2s;" /><span style="width:6px;height:6px;border-radius:50%;background:#8696a0;animation:typing 1.2s infinite .4s;" />
          </div>
        </template>
      </div>

      <!-- sugestão IA (Claude via assinatura) -->
      <div style="margin:0 22px 0;display:flex;align-items:center;gap:10px;background:linear-gradient(90deg,rgba(124,108,245,.14),rgba(124,108,245,.04));border:1px solid rgba(124,108,245,.3);border-radius:12px;padding:9px 13px;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="#7c6cf5"><path d="m12 2 2.4 6.6L21 11l-6.6 2.4L12 20l-2.4-6.6L3 11l6.6-2.4z" /></svg>
        <span v-if="crm.aiLoading" style="font-size:13px;color:#cdc6f7;flex:1;"><b style="color:#a89bf9;">IA</b> está escrevendo uma sugestão…</span>
        <span v-else-if="crm.aiSuggestion" style="font-size:13px;color:#cdc6f7;flex:1;"><b style="color:#a89bf9;">IA sugere:</b> "{{ crm.aiSuggestion }}"</span>
        <span v-else style="font-size:13px;color:#cdc6f7;flex:1;"><b style="color:#a89bf9;">IA:</b> gere uma resposta com base nesta conversa.</span>
        <button v-if="crm.aiSuggestion && !crm.aiLoading" class="aibtn" style="font-size:12px;font-weight:700;color:#fff;background:#7c6cf5;border:none;padding:7px 15px;border-radius:9px;cursor:pointer;flex-shrink:0;" @click="useAISuggestion">Usar</button>
        <button v-else class="aibtn" :disabled="crm.aiLoading" :style="{ fontSize: '12px', fontWeight: 700, color: '#fff', background: '#7c6cf5', border: 'none', padding: '7px 15px', borderRadius: '9px', cursor: crm.aiLoading ? 'default' : 'pointer', flexShrink: 0, opacity: crm.aiLoading ? 0.6 : 1 }" @click="crm.suggestReply()">{{ crm.aiLoading ? '…' : 'Gerar' }}</button>
      </div>

      <!-- composer -->
      <div style="padding:13px 22px 18px;">
        <div style="display:flex;align-items:flex-end;gap:10px;background:#202c33;border-radius:15px;padding:7px 9px 7px 13px;">
          <button style="width:36px;height:36px;border-radius:50%;border:none;background:transparent;color:#8696a0;display:flex;align-items:center;justify-content:center;cursor:pointer;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 9.5a1 1 0 1 0 0-.1M15 9.5a1 1 0 1 0 0-.1M8.5 14.5a4.5 4.5 0 0 0 7 0" stroke-linecap="round" /><circle cx="12" cy="12" r="9.5" /></svg></button>
          <button style="width:36px;height:36px;border-radius:50%;border:none;background:transparent;color:#8696a0;display:flex;align-items:center;justify-content:center;cursor:pointer;"><svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21.4 11.05 12.25 20.2a5.5 5.5 0 0 1-7.78-7.78l8.49-8.49a3.67 3.67 0 0 1 5.19 5.19l-8.5 8.49a1.83 1.83 0 0 1-2.59-2.59l7.78-7.78" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
          <textarea ref="inputRef" placeholder="Digite uma mensagem" rows="1" style="flex:1;background:transparent;border:none;outline:none;resize:none;color:#e9edef;font-family:inherit;font-size:14px;padding:9px 0;line-height:1.4;" @keydown="onKeyDown" />
          <button style="width:42px;height:42px;border-radius:50%;border:none;background:#25D366;color:#062014;display:flex;align-items:center;justify-content:center;cursor:pointer;flex-shrink:0;box-shadow:0 4px 12px rgba(37,211,102,.3);" @click="send"><svg width="20" height="20" viewBox="0 0 24 24" fill="currentColor"><path d="M3.4 20.4 21.85 12 3.4 3.6l.05 6.53L17 12 3.45 13.87z" /></svg></button>
        </div>
      </div>
    </div>

    <!-- painel de contexto -->
    <div style="width:312px;flex-shrink:0;background:#111b21;border-left:1px solid #1c2730;display:flex;flex-direction:column;overflow-y:auto;">
      <div style="padding:24px 20px 18px;text-align:center;border-bottom:1px solid #1c2730;">
        <div :style="active.avatarBig">
          <img v-if="active.avatar && !broken.has(active.id)" :src="active.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;border-radius:50%;object-fit:cover;" @error="broken.add(active.id)">
          <template v-else>{{ active.initials }}</template>
        </div>
        <div style="font-weight:700;font-size:17px;">{{ active.name }}</div>
        <div style="font-size:13px;color:#8696a0;margin-top:2px;">{{ active.role }}</div>
        <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;">
          <button class="ghost" style="flex:1;background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:12.5px;font-weight:600;padding:9px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 16.9v3a2 2 0 0 1-2.2 2 19.8 19.8 0 0 1-8.6-3 19.5 19.5 0 0 1-6-6 19.8 19.8 0 0 1-3-8.6A2 2 0 0 1 4.1 2h3a2 2 0 0 1 2 1.7c.1.9.4 1.8.7 2.7a2 2 0 0 1-.5 2.1L8.1 9.9a16 16 0 0 0 6 6l1.4-1.2a2 2 0 0 1 2.1-.5c.9.3 1.8.6 2.7.7a2 2 0 0 1 1.7 2Z" /></svg>Ligar</button>
          <button class="ghost" style="flex:1;background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:12.5px;font-weight:600;padding:9px;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;" @click="crm.go('contact')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" stroke-linecap="round" /></svg>Ficha</button>
        </div>
      </div>

      <div style="padding:16px 20px;border-bottom:1px solid #1c2730;">
        <div style="font-size:11px;font-weight:700;color:#8696a0;letter-spacing:.5px;margin-bottom:11px;">NEGÓCIO</div>
        <div style="background:#202c33;border-radius:12px;padding:14px;">
          <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:13px;color:#8696a0;">Valor</span><span style="font-size:17px;font-weight:800;color:#25D366;">{{ active.dealValue }}<span style="font-size:12px;color:#8696a0;font-weight:600;">{{ active.dealUnit }}</span></span></div>
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
        <button class="wabtn" style="width:100%;background:#25D366;border:none;color:#062014;font-family:inherit;font-size:14px;font-weight:700;padding:13px;border-radius:12px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 6px 16px rgba(37,211,102,.28);" @click="crm.go('agenda')"><svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4.5" width="18" height="16" rx="2.5" /><path d="M3 9h18M8 2.5v4M16 2.5v4" stroke-linecap="round" /></svg>Agendar reunião</button>
      </div>
    </div>

    <div v-if="memoToast" style="position:fixed;bottom:18px;left:50%;transform:translateX(-50%);z-index:60;background:#2a3942;color:#e9edef;font-size:13px;font-weight:600;padding:11px 20px;border-radius:11px;box-shadow:0 8px 24px rgba(0,0,0,.45);">{{ memoToast }}</div>
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
</style>
