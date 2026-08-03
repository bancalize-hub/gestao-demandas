<script setup lang="ts">
import { leadTemperature, sinceLabel, useCrmStore } from '~/stores/crm'

const crm = useCrmStore()

// Filtro "esfriando": mostra só os leads morno+frio (fila de follow-up).
const coolingOnly = ref(false)

// Filtro de DATA por início da conversa (1ª mensagem). Vazio = período total.
const dateFrom = ref('') // yyyy-mm-dd
const dateTo = ref('')
const dateActive = computed(() => !!dateFrom.value || !!dateTo.value)

// Início da conversa (ts em segundos) dentro do período filtrado?
function inDateRange(startedAt: number | null | undefined): boolean {
  if (!dateActive.value) return true
  if (!startedAt) return false
  const ms = startedAt * 1000
  if (dateFrom.value) {
    const f = new Date(`${dateFrom.value}T00:00:00`).getTime()
    if (ms < f) return false
  }
  if (dateTo.value) {
    const t = new Date(`${dateTo.value}T23:59:59`).getTime()
    if (ms > t) return false
  }
  return true
}
function ymdLocal(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function presetToday() { const d = ymdLocal(new Date()); dateFrom.value = d; dateTo.value = d }
function presetYesterday() { const d = new Date(); d.setDate(d.getDate() - 1); const s = ymdLocal(d); dateFrom.value = s; dateTo.value = s }
function preset7d() { const a = new Date(); a.setDate(a.getDate() - 6); dateFrom.value = ymdLocal(a); dateTo.value = ymdLocal(new Date()) }
function presetMonth() { const n = new Date(); dateFrom.value = ymdLocal(new Date(n.getFullYear(), n.getMonth(), 1)); dateTo.value = ymdLocal(n) }
function clearDate() { dateFrom.value = ''; dateTo.value = '' }
// Rótulo curto do filtro ativo (pra legenda do card Conversas).
const dateLabel = computed(() => {
  if (!dateActive.value) return ''
  const fmt = (s: string) => s ? s.split('-').reverse().slice(0, 2).join('/') : ''
  if (dateFrom.value && dateFrom.value === dateTo.value) return fmt(dateFrom.value)
  if (dateFrom.value && dateTo.value) return `${fmt(dateFrom.value)}–${fmt(dateTo.value)}`
  return dateFrom.value ? `a partir de ${fmt(dateFrom.value)}` : `até ${fmt(dateTo.value)}`
})

// --- Modal de negócio (criar/editar = ficha) ---
const showNew = ref(false)
const saving = ref(false)
const error = ref('')
const editId = ref<number | null>(null)
const form = reactive({ name: '', sub: '', value: '', stage: 'novo', hot: false })

const stages = computed(() => crm.stages.map(s => ({ id: s.key, title: s.name })))

function openNew() {
  editId.value = null
  error.value = ''
  Object.assign(form, { name: '', sub: '', value: '', stage: crm.stages[0]?.key || 'novo', hot: false })
  showNew.value = true
}

function openEdit(card: any) {
  editId.value = card.id
  error.value = ''
  Object.assign(form, { name: card.name, sub: card.sub || '', value: card.value || '', stage: card.stage, hot: !!card.hot })
  showNew.value = true
}

async function submitNew() {
  if (saving.value) return
  if (!form.name.trim()) { error.value = 'Informe o nome do negócio.'; return }
  saving.value = true
  error.value = ''
  try {
    if (editId.value) crm.updateDeal(editId.value, { ...form, name: form.name.trim() })
    else await crm.createDeal({ ...form, name: form.name.trim() })
    showNew.value = false
  }
  catch {
    error.value = 'Não foi possível salvar o negócio.'
  }
  finally {
    saving.value = false
  }
}

// --- Editar etapas do funil ---
const showStages = ref(false)
const newStageName = ref('')
// Etiquetas do WhatsApp Business (para vincular a cada etapa).
const waLabels = ref<{ id: string, name: string, color?: string }[]>([])
// Na API oficial da Meta não existem etiquetas: o vínculo etapa↔etiqueta fica indisponível.
const waLabelsSupported = ref(true)
async function openStagesModal() {
  showStages.value = true
  if (!waLabels.value.length) {
    try {
      const r = await useApi()<{ labels: any[], supported?: boolean }>('/api/wpp/labels')
      waLabels.value = (r.labels || []).map(l => ({ id: String(l.id), name: l.name, color: l.color }))
      waLabelsSupported.value = r.supported !== false
    }
    catch { /* WhatsApp pode estar desconectado */ }
  }
}
function addStage() {
  const n = newStageName.value.trim()
  if (!n) return
  crm.createStage({ name: n, color: 'var(--c-text-muted)' })
  newStageName.value = ''
}
function moveStage(i: number, dir: number) {
  const j = i + dir
  if (j < 0 || j >= crm.stages.length) return
  const arr = crm.stages as any[]
  ;[arr[i], arr[j]] = [arr[j], arr[i]]
  crm.reorderStages()
}

const stats = computed(() => {
  const ds = crm.dealList
  const val = (v: string) => Number.parseInt(String(v || '').replace(/[^\d]/g, ''), 10) || 0
  // Conversas no período filtrado (ou todas, se "período total"). Filtro pela data de início (1ª msg).
  const convs = crm.conversations.filter(c => !c.archived && inDateRange(c.startedAt))
  // Valor total do pipeline = conversas (do período) + negócios. Negócios só entram em "período total".
  const totalPipeline = convs.reduce((a, c) => a + val(c.dealValue), 0)
    + (dateActive.value ? 0 : ds.reduce((a, d) => a + val(d.value), 0))
  const won = ds.filter(d => d.won || d.stage === 'fechado').length
  return {
    totalPipeline: totalPipeline.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' }),
    taxa: ds.length ? Math.round((won / ds.length) * 100) : 0,
    conversas: convs.length,
    ativos: ds.filter(d => d.stage !== 'fechado').length,
  }
})

function delDeal(id: number) {
  if (confirm('Excluir este negócio?')) { crm.removeDeal(id); showNew.value = false }
}

const val = (v: string) => Number.parseInt(String(v || '').replace(/[^\d]/g, ''), 10) || 0

// Valor formatado em reais (sem centavos) p/ exibir no card. Vazio/0 → "R$ 0".
function fmtMoney(v: string | number) {
  const n = Number.parseInt(String(v ?? '').replace(/[^\d]/g, ''), 10) || 0
  return `R$ ${n.toLocaleString('pt-BR')}`
}

// Edição inline do valor direto no card (clica no valor → edita → Enter/clica fora salva, Esc cancela).
// Mesmo comportamento do chat. Funciona p/ conversa (setConvValue) e negócio (updateDeal).
const editingValueKey = ref<string | null>(null)
const valueDraft = ref('')
const vFocus = { mounted: (el: HTMLInputElement) => { el.focus(); el.select() } }
function valueKey(card: any) { return `${card.kind}:${card.id}` }
function startEditValue(card: any) {
  editingValueKey.value = valueKey(card)
  valueDraft.value = String(card.value ?? '').replace(/[^\d]/g, '')
}
function saveValue(card: any) {
  if (editingValueKey.value !== valueKey(card)) return
  const digits = valueDraft.value.replace(/[^\d]/g, '')
  editingValueKey.value = null
  if (card.kind === 'conv') crm.setConvValue(String(card.id), digits)
  else crm.updateDeal(Number(card.id), { value: digits })
}

// Abre o card: conversa abre a FICHA do cliente; negócio abre a ficha de edição.
function openCard(card: any) {
  if (card.kind === 'conv') {
    crm.activeId = card.id
    crm.go('contact')
  }
  else {
    openEdit(card)
  }
}

// Botão de WhatsApp no card: vai direto para a conversa (chat).
function openChat(card: any) {
  crm.activeId = card.id
  crm.go('chat')
}

const cols = computed(() => crm.stages.map((st) => {
  const key = `pipeline:${st.key}`
  const over = crm.dragOverCol === key
  const isLast = crm.stages[crm.stages.length - 1]?.key === st.key

  // Conversas (cada conversa = um negócio) na etapa atual.
  const convCards = crm.conversations
    .filter(c => !c.archived && c.stage === st.key && inDateRange(c.startedAt))
    .map(c => ({
      kind: 'conv' as const,
      id: c.id,
      name: c.name,
      sub: c.company || c.role || c.preview || '',
      value: c.dealValue || '',
      hot: c.hot,
      won: isLast,
      tag: c.origin || 'Conversa',
      tagStrong: isLast,
      // Etapa final (Fechado) não tem temperatura — negócio concluído.
      temp: isLast ? null : leadTemperature(c),
    }))

  // Negócios criados manualmente ("Novo negócio").
  const dealCards = crm.dealList
    .filter(d => d.stage === st.key)
    .slice().sort((a, b) => a.position - b.position || a.id - b.id)
    .map(d => ({
      kind: 'deal' as const,
      id: d.id,
      name: d.name,
      sub: d.sub,
      value: d.value,
      stage: d.stage,
      hot: d.hot,
      won: d.won,
      tag: d.tag,
      tagStrong: d.tagStrong,
      temp: null as ReturnType<typeof leadTemperature> | null,
    }))

  // Filtro "esfriando": só leads morno/frio (deixa de fora quentes e negócios sem temperatura).
  // Filtro de DATA ativo: mostra só conversas do período (esconde negócios manuais sem data de início).
  const visible = coolingOnly.value
    ? convCards.filter(c => c.temp && c.temp.key !== 'quente')
    : (dateActive.value ? convCards : [...convCards, ...dealCards])

  const cards = visible.map(card => ({
    ...card,
    // Borda esquerda na cor da TEMPERATURA (a coluna já indica a etapa); negócios/Fechado usam a cor da etapa.
    cardStyle: { background: card.won ? 'var(--c-accent-surf)' : (card.hot ? 'var(--c-surface-2)' : 'var(--c-surface-1)'), borderRadius: '12px', padding: '13px', cursor: 'grab', borderLeft: `4px solid ${card.temp ? card.temp.color : st.color}` },
    tagStyle: card.tagStrong
      ? { fontSize: '10.5px', fontWeight: 700, color: 'var(--accent-ink)', background: 'var(--accent)', padding: '3px 8px', borderRadius: '6px' }
      : { fontSize: '10.5px', color: 'var(--c-text-muted)', background: 'var(--c-bg-deep)', padding: '3px 8px', borderRadius: '6px' },
  }))

  const total = cards.reduce((a, c) => a + val(c.value), 0)
  return {
    id: st.key,
    title: st.name,
    dot: st.color,
    count: cards.length,
    sum: total >= 1000 ? `R$ ${(total / 1000).toFixed(1).replace('.', ',')}k` : `R$ ${total}`,
    bodyStyle: { display: 'flex', flexDirection: 'column', gap: '10px', overflowY: 'auto', flex: 1, borderRadius: '10px', minHeight: '70px', transition: 'background .15s', background: over ? 'rgba(var(--accent-rgb),.08)' : 'transparent', outline: over ? '2px dashed rgba(var(--accent-rgb),.45)' : '2px dashed transparent', outlineOffset: '-2px' },
    cards,
  }
}))
</script>

<template>
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;background:var(--c-bg-deep);">
    <div style="padding:22px 30px 0;">
      <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
          <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Funil de vendas</div>
          <div style="display:flex;align-items:center;gap:14px;font-size:12px;color:var(--c-text-muted);margin-top:5px;flex-wrap:wrap;">
            <span>Arraste os cards entre as etapas</span>
            <span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--c-orange-strong);" />Quente <span style="opacity:.7;">≤2d</span></span>
            <span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--c-warn);" />Morno <span style="opacity:.7;">3–5d</span></span>
            <span style="display:inline-flex;align-items:center;gap:5px;"><span style="width:8px;height:8px;border-radius:50%;background:var(--c-info);" />Frio <span style="opacity:.7;">&gt;5d</span></span>
          </div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <div style="display:flex;align-items:center;gap:8px;background:var(--c-surface-2);border-radius:11px;padding:9px 13px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-muted)" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg><input placeholder="Buscar negócio" style="background:transparent;border:none;outline:none;color:var(--c-text);font-family:inherit;font-size:13px;width:130px;"></div>
          <button :title="coolingOnly ? 'Mostrando só leads esfriando' : 'Mostrar só leads esfriando (morno/frio)'" :style="`border:none;font-family:inherit;font-size:13px;font-weight:600;padding:10px 14px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;${coolingOnly ? 'background:var(--c-warn);color:var(--c-warn-bg);' : 'background:var(--c-surface-2);color:var(--c-text);'}`" @click="coolingOnly = !coolingOnly"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M14 4v8.5a4 4 0 1 1-4 0V4a2 2 0 0 1 4 0Z" stroke-linejoin="round" /></svg>{{ coolingOnly ? 'Esfriando ✓' : 'Esfriando' }}</button>
          <button style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13px;font-weight:600;padding:10px 14px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="openStagesModal"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round" /></svg>Editar etapas</button>
          <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 17px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="openNew"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Novo negócio</button>
        </div>
      </div>

      <!-- Filtro de data por início da conversa (1ª mensagem). Padrão: período total. -->
      <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:18px;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:12px;padding:10px 13px;">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-muted)" stroke-width="1.8" style="flex-shrink:0;"><rect x="3" y="4.5" width="18" height="16" rx="2.5" /><path d="M3 9h18M8 2.5v4M16 2.5v4" stroke-linecap="round" /></svg>
        <span style="font-size:12.5px;color:var(--c-text-muted);font-weight:600;">Início:</span>
        <input v-model="dateFrom" type="date" :max="dateTo || undefined" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:6px 9px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;color-scheme:dark;">
        <span style="font-size:12.5px;color:var(--c-text-muted);">até</span>
        <input v-model="dateTo" type="date" :min="dateFrom || undefined" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:6px 9px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;color-scheme:dark;">
        <button class="datebtn" @click="presetToday">Hoje</button>
        <button class="datebtn" @click="presetYesterday">Ontem</button>
        <button class="datebtn" @click="preset7d">7 dias</button>
        <button class="datebtn" @click="presetMonth">Este mês</button>
        <button v-if="dateActive" class="datebtn" style="color:var(--c-danger-soft);" @click="clearDate">✕ Limpar</button>
        <span style="flex:1;" />
        <span v-if="dateActive" style="font-size:12px;color:var(--accent-soft);font-weight:600;">Filtrando: {{ dateLabel }}</span>
        <span v-else style="font-size:12px;color:var(--c-text-muted);">Período total</span>
      </div>

      <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;margin-top:14px;">
        <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:var(--c-text-muted);">{{ dateActive ? 'Valor no período' : 'Valor total do pipeline' }}</div><div style="font-size:25px;font-weight:800;margin-top:5px;">{{ stats.totalPipeline }}</div><div style="font-size:11.5px;color:var(--c-text-muted);margin-top:4px;font-weight:600;">{{ dateActive ? 'conversas iniciadas no período' : 'somando todas as etapas' }}</div></div>
        <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:var(--c-text-muted);">Conversas</div><div style="font-size:25px;font-weight:800;margin-top:5px;">{{ stats.conversas }}</div><div style="font-size:11.5px;color:var(--c-text-muted);margin-top:4px;font-weight:600;">{{ dateActive ? `iniciadas em ${dateLabel}` : 'total no CRM' }}</div></div>
      </div>
    </div>

    <div style="flex:1;overflow-x:auto;overflow-y:hidden;padding:22px 30px 26px;">
      <div style="display:flex;gap:16px;height:100%;min-width:max-content;">
        <div v-for="col in cols" :key="col.id" style="width:280px;display:flex;flex-direction:column;background:var(--c-bg-deep);border-radius:14px;padding:13px;flex-shrink:0;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:13px;padding:0 3px;">
            <div style="display:flex;align-items:center;gap:8px;"><span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: col.dot }" /><span style="font-weight:700;font-size:13.5px;">{{ col.title }}</span><span style="font-size:12px;color:var(--c-text-muted);">{{ col.count }}</span></div>
            <span style="font-size:12px;color:var(--c-text-muted);font-weight:600;">{{ col.sum }}</span>
          </div>
          <div
            :style="col.bodyStyle"
            @dragover.prevent="crm.setDragOver(`pipeline:${col.id}`)"
            @drop.prevent="crm.dropTo('pipeline', col.id)"
          >
            <div
              v-for="card in col.cards" :key="card.kind + ':' + card.id"
              draggable="true" :style="card.cardStyle" class="card"
              @dragstart="crm.setDrag('pipeline', col.id, card.id, card.kind)"
              @dragend="crm.setDragOver(null)"
              @click="openCard(card)"
            >
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div style="font-weight:700;font-size:14px;">{{ card.name }}</div>
                <button v-if="card.kind === 'conv'" class="wachat" title="Abrir conversa no WhatsApp" style="background:rgba(var(--accent-rgb),.15);border:none;border-radius:7px;padding:3px 6px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;" @click.stop="openChat(card)"><svg width="15" height="15" viewBox="0 0 24 24" fill="var(--accent)"><path d="M12.04 2c-5.46 0-9.91 4.45-9.91 9.91 0 1.75.46 3.45 1.32 4.95L2.05 22l5.25-1.38c1.45.79 3.08 1.21 4.74 1.21 5.46 0 9.91-4.45 9.91-9.91S17.5 2 12.04 2zm5.8 14.01c-.24.68-1.42 1.31-1.96 1.36-.5.05-.95.24-3.2-.67-2.7-1.06-4.42-3.82-4.56-4-.13-.18-1.1-1.46-1.1-2.79s.7-1.98.94-2.25c.24-.27.53-.34.7-.34.18 0 .35 0 .5.01.16.01.38-.06.59.45.24.58.81 2 .88 2.14.07.14.12.31.02.49-.09.18-.14.29-.28.45-.14.16-.29.36-.42.48-.14.14-.28.29-.12.57.16.27.71 1.17 1.53 1.9 1.05.94 1.94 1.23 2.21 1.37.27.14.43.12.59-.07.16-.18.68-.79.86-1.06.18-.27.36-.23.61-.14.24.09 1.55.73 1.82.87.27.14.45.2.51.31.07.11.07.64-.17 1.31z" /></svg></button>
                <span v-if="card.hot" style="font-size:10px;font-weight:700;color:var(--c-orange);background:rgba(255,122,69,.15);padding:2px 7px;border-radius:6px;flex-shrink:0;">🔥</span>
                <svg v-if="card.won" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2.5" style="flex-shrink:0;"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <button v-if="card.kind === 'deal'" class="delbtn" title="Excluir" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;padding:0;flex-shrink:0;display:flex;" @click.stop="delDeal(Number(card.id))"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              </div>
              <div style="font-size:12px;color:var(--c-text-muted);margin-top:2px;">{{ card.sub }}</div>
              <div v-if="card.temp" style="display:flex;align-items:center;gap:6px;margin-top:9px;flex-wrap:wrap;">
                <span :style="{ display: 'inline-flex', alignItems: 'center', gap: '5px', fontSize: '10.5px', fontWeight: 700, color: card.temp.color, background: `${card.temp.color}22`, padding: '2px 8px', borderRadius: '6px' }"><span :style="{ width: '7px', height: '7px', borderRadius: '50%', background: card.temp.color }" />{{ card.temp.label }}</span>
                <span style="font-size:10.5px;color:var(--c-text-muted);">{{ sinceLabel(card.temp.days) }}</span>
                <span v-if="card.temp.awaiting" title="A última mensagem foi do lead — responda antes que esfrie" style="font-size:10px;font-weight:700;color:var(--c-warn-hi);background:rgba(255,209,102,.14);padding:2px 7px;border-radius:6px;">⚠️ aguardando você</span>
              </div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:11px;gap:8px;">
                <input
                  v-if="editingValueKey === `${card.kind}:${card.id}`"
                  v-model="valueDraft" v-focus inputmode="numeric" title="Enter ou clique fora p/ salvar · Esc cancela"
                  style="width:96px;background:var(--c-bg-deep);border:1px solid var(--accent);border-radius:6px;padding:2px 7px;font-size:13.5px;font-weight:700;color:var(--accent);font-family:inherit;outline:none;"
                  @click.stop @mousedown.stop @keydown.enter="($event.target as HTMLInputElement).blur()" @keydown.esc="editingValueKey = null" @blur="saveValue(card)"
                >
                <span v-else title="Clique para editar o valor" style="font-size:13.5px;font-weight:700;color:var(--accent);cursor:text;" @click.stop="startEditValue(card)">{{ fmtMoney(card.value) }}</span>
                <span :style="card.tagStyle">{{ card.tag }}</span>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- modal: novo negócio -->
    <div v-if="showNew" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:24px;" @click.self="showNew = false">
      <div style="width:460px;max-width:100%;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:26px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
          <div style="font-size:19px;font-weight:800;">{{ editId ? 'Editar negócio' : 'Novo negócio' }}</div>
          <button style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;font-size:20px;line-height:1;" @click="showNew = false">×</button>
        </div>
        <div style="font-size:13px;color:var(--c-text-muted);margin-bottom:20px;">Adicione um negócio ao funil.</div>

        <div style="display:flex;flex-direction:column;gap:15px;">
          <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Nome*</span><input v-model="form.name" placeholder="Ex: Vértice Pro — 12 licenças" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"></label>
          <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Cliente / contato</span><input v-model="form.sub" placeholder="Ex: Mariana Costa" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Valor</span><input v-model="form.value" placeholder="R$ 4.200" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"></label>
            <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Etapa</span><select v-model="form.stage" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;color-scheme:dark;"><option v-for="s in stages" :key="s.id" :value="s.id">{{ s.title }}</option></select></label>
          </div>
          <label style="display:flex;align-items:center;gap:9px;font-size:13.5px;color:var(--c-text-secondary);cursor:pointer;"><input v-model="form.hot" type="checkbox" style="width:16px;height:16px;accent-color:var(--c-orange);cursor:pointer;">Marcar como lead quente 🔥</label>

          <div v-if="error" style="background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:var(--c-danger-soft);font-size:13px;font-weight:600;padding:10px 13px;border-radius:10px;">{{ error }}</div>

          <div style="display:flex;gap:10px;align-items:center;margin-top:4px;">
            <button v-if="editId" style="background:rgba(255,77,77,.12);border:1px solid rgba(255,77,77,.3);color:var(--c-danger-soft);font-family:inherit;font-size:13px;font-weight:700;padding:11px 16px;border-radius:11px;cursor:pointer;" @click="delDeal(editId)">Excluir</button>
            <div style="flex:1;" />
            <button style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13.5px;font-weight:600;padding:11px 18px;border-radius:11px;cursor:pointer;" @click="showNew = false">Cancelar</button>
            <button :disabled="saving" :style="{ background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '11px 20px', borderRadius: '11px', cursor: saving ? 'default' : 'pointer', opacity: saving ? 0.7 : 1 }" @click="submitNew">{{ saving ? 'Salvando…' : (editId ? 'Salvar' : 'Criar negócio') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- modal: editar etapas do funil -->
    <div v-if="showStages" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:24px;" @click.self="showStages = false">
      <div style="width:480px;max-width:100%;max-height:90vh;display:flex;flex-direction:column;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:26px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;flex-shrink:0;">
          <div style="font-size:19px;font-weight:800;">Etapas do funil</div>
          <button style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;font-size:20px;line-height:1;" @click="showStages = false">×</button>
        </div>
        <div style="font-size:13px;color:var(--c-text-muted);margin-bottom:18px;flex-shrink:0;">Estas etapas também são as etiquetas das conversas no chat. Defina um <b style="color:var(--c-ai-soft);">objetivo da IA</b> em cada etapa — as sugestões de resposta vão conduzir a conversa rumo a ele.</div>
        <div style="display:flex;flex-direction:column;gap:10px;overflow-y:auto;flex:1;min-height:0;margin:0 -4px;padding:2px 4px;">
          <div v-for="(s, i) in crm.stages" :key="s.id || s.key" style="display:flex;flex-direction:column;gap:8px;background:var(--c-surface-2);border-radius:10px;padding:10px;">
            <div style="display:flex;align-items:center;gap:8px;">
              <input type="color" :value="s.color" style="width:26px;height:26px;border:none;background:none;cursor:pointer;padding:0;flex-shrink:0;" @input="s.id && crm.updateStage(s.id, { color: ($event.target as HTMLInputElement).value })">
              <input :value="s.name" style="flex:1;background:var(--c-bg);border:1px solid var(--c-surface-3);border-radius:8px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;" @change="s.id && crm.updateStage(s.id, { name: ($event.target as HTMLInputElement).value })">
              <button title="Subir" :disabled="i === 0" style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;padding:2px 4px;" @click="moveStage(i, -1)">▲</button>
              <button title="Descer" :disabled="i === crm.stages.length - 1" style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;padding:2px 4px;" @click="moveStage(i, 1)">▼</button>
              <button title="Excluir" style="background:none;border:none;color:var(--c-danger);cursor:pointer;padding:2px 4px;" @click="s.id && confirm('Excluir a etapa? Os negócios dela vão para a primeira etapa.') && crm.removeStage(s.id)">✕</button>
            </div>
            <textarea :value="s.goal ?? ''" rows="2" placeholder="Objetivo da IA nesta etapa (ex.: conduzir sutilmente o lead a agendar uma reunião)" style="background:var(--c-bg);border:1px solid var(--c-surface-3);border-radius:8px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;resize:vertical;line-height:1.4;" @change="s.id && crm.updateStage(s.id, { goal: ($event.target as HTMLTextAreaElement).value })" />
            <div style="display:flex;align-items:center;gap:8px;">
              <span style="font-size:11.5px;color:var(--c-text-muted);display:flex;align-items:center;gap:5px;flex-shrink:0;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="2"><path d="M20.6 13.4 13.4 20.6a2 2 0 0 1-2.8 0l-7.2-7.2A2 2 0 0 1 2.8 12V5a2 2 0 0 1 2-2h7a2 2 0 0 1 1.4.6l7.4 7.4a2 2 0 0 1 0 2.8Z" stroke-linejoin="round"/></svg>Etiqueta WhatsApp</span>
              <select v-if="waLabelsSupported" :value="s.wa_label_id ?? ''" style="flex:1;background:var(--c-bg);border:1px solid var(--c-surface-3);border-radius:8px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;color-scheme:dark;" @change="s.id && crm.updateStage(s.id, { wa_label_id: ($event.target as HTMLSelectElement).value || null })">
                <option value="">— Não sincronizar —</option>
                <option v-for="l in waLabels" :key="l.id" :value="l.id">{{ l.name }}</option>
              </select>
              <span v-else style="flex:1;font-size:11.5px;color:var(--c-text-faint);line-height:1.35;">Indisponível no número oficial da Meta — a Cloud API não expõe as etiquetas do app Business.</span>
            </div></div>
        </div>
        <div style="display:flex;gap:8px;margin-top:14px;flex-shrink:0;">
          <input v-model="newStageName" placeholder="Nova etapa" style="flex:1;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;" @keydown.enter="addStage">
          <button style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:13px;font-weight:700;padding:0 18px;border-radius:10px;cursor:pointer;" @click="addStage">Adicionar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: var(--accent-hi) !important; }
.card:hover { filter: brightness(1.12); }
.card .delbtn { opacity: 0; transition: opacity .15s; }
.card:hover .delbtn { opacity: 1; }
.delbtn:hover { color: var(--c-danger) !important; }
.datebtn { background: var(--c-surface-2); border: none; color: var(--c-text-secondary); font-family: inherit; font-size: 12px; font-weight: 600; padding: 6px 11px; border-radius: 8px; cursor: pointer; }
.datebtn:hover { background: var(--c-surface-3); color: var(--c-text); }
</style>
