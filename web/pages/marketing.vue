<script setup lang="ts">
// Módulo de marketing: terminal do agente de Facebook Ads + biblioteca de criativos
// + credenciais. O terminal reusa as rotas /api/agent (sessões com kind=marketing);
// o que muda é o que o agente pode fazer — só as ferramentas mcp__fbads__*.
const api = useApi()
const isMobile = useIsMobile()

type Aba = 'conversa' | 'criativos' | 'config'
const aba = ref<Aba>('conversa')

interface Session { id: number, title: string, updated_at: string }
interface Msg { id: number, role: string, content: string }
interface Criativo {
  id: number, name: string, number: number, original_name: string | null
  mime: string | null, size: number, notes: string | null, no_facebook: boolean, url: string
}
interface Status {
  app_id: string | null, ad_account_id: string | null, page_id: string | null
  graph_version: string, tem_token: boolean, tem_app_secret: boolean
  checked_at: string | null, last_error: string | null
}

const sessions = ref<Session[]>([])
const activeId = ref<number | null>(null)
const messages = ref<Msg[]>([])
const liveOutput = ref('')
const running = ref(false)
const currentJob = ref<number | null>(null)
const jobError = ref('')
const startedAt = ref<number | null>(null)
const agora = ref(Date.now())
const input = ref('')
const sending = ref(false)
const scroller = ref<HTMLElement | null>(null)
const drawer = ref(false)
let poll: any = null
let relogio: any = null
let recebido = 0

// --- leitura da saída (mesmo formato do agente operacional) -----------------
interface Bloco { tipo: 'texto' | 'exec' | 'sistema', txt?: string, cmd?: string, tool?: string, arg?: string, saida?: string }
const ANSI = /\x1B\[[0-9;?]*[a-zA-Z]/g

function parseSaida(raw: string): Bloco[] {
  const linhas = (raw || '').replace(ANSI, '').split('\n')
  const blocos: Bloco[] = []
  let prosa: string[] = []
  const soltaProsa = () => {
    const t = prosa.join('\n').trim()
    if (t) blocos.push({ tipo: 'texto', txt: t })
    prosa = []
  }

  for (let i = 0; i < linhas.length; i++) {
    const l = linhas[i]
    const ferramenta = l.match(/^\[([A-Za-z][\w-]*)(?:\s+(.*))?\]$/)
    if (ferramenta) {
      soltaProsa()
      blocos.push({ tipo: 'exec', tool: ferramenta[1], arg: ferramenta[2] || '', saida: '' })
      continue
    }
    if (l === '⟦saída⟧') {
      const corpo: string[] = []
      i++
      while (i < linhas.length && linhas[i] !== '⟦fim⟧') { corpo.push(linhas[i]); i++ }
      const texto = corpo.join('\n').replace(/\s+$/, '')
      const ultimo = blocos[blocos.length - 1]
      soltaProsa()
      if (ultimo?.tipo === 'exec' && !ultimo.saida) ultimo.saida = texto
      else blocos.push({ tipo: 'exec', saida: texto })
      continue
    }
    if (l.startsWith('🟢') || l.startsWith('⛔') || l.startsWith('⚠️')) {
      soltaProsa()
      blocos.push({ tipo: 'sistema', txt: l })
      continue
    }
    prosa.push(l)
  }
  soltaProsa()
  return blocos
}

const cacheBlocos = new Map<string, Bloco[]>()
function blocosDe(txt: string): Bloco[] {
  let b = cacheBlocos.get(txt)
  if (!b) {
    b = parseSaida(txt)
    if (cacheBlocos.size > 60) cacheBlocos.clear()
    cacheBlocos.set(txt, b)
  }
  return b
}
const blocosAoVivo = computed(() => blocosDe(liveOutput.value))

const decorrido = computed(() => {
  if (!startedAt.value) return ''
  const s = Math.max(0, Math.floor((agora.value - startedAt.value) / 1000))
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, '0')}s`
})

function quando(iso: string) {
  const min = Math.floor((Date.now() - new Date(iso).getTime()) / 60000)
  if (min < 1) return 'agora'
  if (min < 60) return `${min} min`
  const h = Math.floor(min / 60)
  return h < 24 ? `${h}h` : `${Math.floor(h / 24)}d`
}

// --- sessões ---------------------------------------------------------------
async function loadSessions() {
  sessions.value = await api<Session[]>('/api/agent/sessions?kind=marketing')
}

async function openSession(id: number) {
  stopPolling()
  activeId.value = id
  liveOutput.value = ''
  jobError.value = ''
  running.value = false
  currentJob.value = null
  startedAt.value = null
  recebido = 0
  drawer.value = false
  aba.value = 'conversa'
  const s = await api<{ messages: Msg[], active_job_id: number | null }>(`/api/agent/sessions/${id}`)
  messages.value = s.messages || []
  await nextTick(); scrollDown()
  if (s.active_job_id) {
    currentJob.value = s.active_job_id
    running.value = true
    startPolling(s.active_job_id)
  }
}

async function newSession() {
  const s = await api<Session>('/api/agent/sessions', { method: 'POST', body: { title: 'Nova campanha', kind: 'marketing' } })
  await loadSessions()
  await openSession(s.id)
}

async function delSession(s: Session) {
  if (!confirm(`Apagar a conversa "${s.title}"? O histórico do que o agente fez nela some.`)) return
  await api(`/api/agent/sessions/${s.id}`, { method: 'DELETE' }).catch(() => {})
  if (activeId.value === s.id) { activeId.value = null; messages.value = [] }
  await loadSessions()
}

function scrollDown() {
  const el = scroller.value
  if (el) el.scrollTop = el.scrollHeight
}
function nearBottom() {
  const el = scroller.value
  return !el || el.scrollHeight - el.scrollTop - el.clientHeight < 120
}

async function send() {
  const text = input.value.trim()
  if (!text || !activeId.value || running.value) return
  sending.value = true
  jobError.value = ''
  messages.value.push({ id: Date.now(), role: 'user', content: text })
  input.value = ''
  await nextTick(); scrollDown()
  try {
    const r = await api<{ job_id: number }>(`/api/agent/sessions/${activeId.value}/messages`, { method: 'POST', body: { prompt: text } })
    currentJob.value = r.job_id
    running.value = true
    liveOutput.value = ''
    recebido = 0
    startPolling(r.job_id)
    loadSessions()
  }
  catch (e: any) {
    messages.value.push({ id: Date.now() + 1, role: 'assistant', content: '⚠️ ' + (e?.response?._data?.message || e?.data?.message || 'Falha ao enviar.') })
  }
  finally { sending.value = false }
}

function startPolling(jobId: number) {
  stopPolling()
  startedAt.value = Date.now()
  relogio = setInterval(() => { agora.value = Date.now() }, 1000)
  poll = setInterval(async () => {
    try {
      const j = await api<{ status: string, chunk: string, len: number, error: string | null, started_at: string | null }>(`/api/agent/jobs/${jobId}?from=${recebido}`)
      if (j.chunk) {
        const perto = nearBottom()
        liveOutput.value += j.chunk
        recebido = j.len
        await nextTick(); if (perto) scrollDown()
      }
      if (j.started_at && startedAt.value === null) startedAt.value = new Date(j.started_at).getTime()
      if (['done', 'error', 'canceled'].includes(j.status)) {
        stopPolling()
        running.value = false
        currentJob.value = null
        jobError.value = j.status === 'error' ? (j.error || 'O agente terminou com erro.') : ''
        if (activeId.value) {
          const s = await api<{ messages: Msg[] }>(`/api/agent/sessions/${activeId.value}`)
          messages.value = s.messages || []
          liveOutput.value = ''
          recebido = 0
          await nextTick(); scrollDown()
        }
        // Uma campanha pode ter subido um criativo ao Facebook — reflete na aba.
        carregarCriativos()
      }
    }
    catch { /* segue tentando */ }
  }, 1000)
}

function stopPolling() {
  if (poll) { clearInterval(poll); poll = null }
  if (relogio) { clearInterval(relogio); relogio = null }
}

async function stopJob() {
  if (!currentJob.value) return
  await api(`/api/agent/jobs/${currentJob.value}/stop`, { method: 'POST' }).catch(() => {})
}

function onKey(e: KeyboardEvent) {
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() }
}

// --- criativos -------------------------------------------------------------
const criativos = ref<Criativo[]>([])
const subindo = ref(false)
const erroUpload = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

async function carregarCriativos() {
  criativos.value = await api<Criativo[]>('/api/marketing/creatives').catch(() => [])
}

async function subir(files: FileList | null) {
  if (!files?.length) return
  subindo.value = true
  erroUpload.value = ''
  // Um por vez: a numeração é sequencial e o servidor trava a linha por upload.
  for (const file of Array.from(files)) {
    const fd = new FormData()
    fd.append('file', file)
    try {
      await api('/api/marketing/creatives', { method: 'POST', body: fd })
    }
    catch (e: any) {
      erroUpload.value = e?.response?._data?.message || e?.data?.message || `Falha ao enviar ${file.name}.`
    }
  }
  await carregarCriativos()
  subindo.value = false
  if (fileInput.value) fileInput.value.value = ''
}

async function salvarNota(c: Criativo) {
  await api(`/api/marketing/creatives/${c.id}`, { method: 'PATCH', body: { notes: c.notes } }).catch(() => {})
}

async function apagarCriativo(c: Criativo) {
  if (!confirm(`Apagar o ${c.name}? Anúncios já criados no Facebook continuam lá.`)) return
  await api(`/api/marketing/creatives/${c.id}`, { method: 'DELETE' }).catch(() => {})
  await carregarCriativos()
}

function tamanho(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / 1048576).toFixed(1)} MB`
}

// --- configuração ----------------------------------------------------------
const status = ref<Status | null>(null)
const form = reactive({ app_id: '', app_secret: '', access_token: '', ad_account_id: '', page_id: '', graph_version: 'v23.0' })
const salvando = ref(false)
const testando = ref(false)
const resultado = ref<{ ok: boolean, texto: string } | null>(null)

async function carregarStatus() {
  status.value = await api<Status>('/api/marketing/status').catch(() => null)
  if (status.value) {
    form.app_id = status.value.app_id || ''
    form.ad_account_id = status.value.ad_account_id || ''
    form.page_id = status.value.page_id || ''
    form.graph_version = status.value.graph_version || 'v23.0'
  }
}

function descreve(r: any): string {
  return r?.ok
    ? `Conectado como ${r.usuario} · conta "${r.conta}"${r.moeda ? ` (${r.moeda})` : ''}`
    : (r?.mensagem || 'Falhou.')
}

async function salvarConfig() {
  salvando.value = true
  resultado.value = null
  try {
    const r: any = await api('/api/marketing/credentials', { method: 'POST', body: { ...form } })
    resultado.value = { ok: !!r.ok && r.ok !== false, texto: descreve(r) }
    form.app_secret = ''
    form.access_token = ''
    await carregarStatus()
  }
  catch (e: any) {
    resultado.value = { ok: false, texto: e?.response?._data?.message || e?.data?.message || 'Não consegui salvar.' }
  }
  finally { salvando.value = false }
}

async function testarConfig() {
  testando.value = true
  resultado.value = null
  try {
    const r: any = await api('/api/marketing/test', { method: 'POST' })
    resultado.value = { ok: !!r.ok, texto: descreve(r) }
    await carregarStatus()
  }
  finally { testando.value = false }
}

const pronto = computed(() => !!status.value?.tem_token && !!status.value?.ad_account_id)

onMounted(async () => {
  await Promise.all([loadSessions(), carregarCriativos(), carregarStatus()])
  if (sessions.value.length) await openSession(sessions.value[0].id)
  else if (!pronto.value) aba.value = 'config'
})
onBeforeUnmount(stopPolling)

const campo = 'width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;color:var(--c-text);font-family:inherit;font-size:13px;padding:9px 11px;outline:none;'
const rotulo = 'display:block;font-size:11.5px;color:var(--c-text-muted);margin-bottom:5px;font-weight:600;'
</script>

<template>
  <div style="display:flex;height:100dvh;background:var(--c-bg-deepest);color:var(--c-text);font-family:'JetBrains Mono',ui-monospace,monospace;position:relative;overflow:hidden;">
    <!-- lateral -->
    <aside
      :style="{
        width: '260px', flexShrink: 0, background: 'var(--c-bg-deepest)', borderRight: '1px solid var(--c-surface-1)',
        display: 'flex', flexDirection: 'column', zIndex: 40,
        position: isMobile ? 'absolute' : 'relative', top: 0, bottom: 0, left: 0,
        transform: (isMobile && !drawer) ? 'translateX(-100%)' : 'translateX(0)',
        transition: 'transform .2s', boxShadow: isMobile ? '0 0 40px rgba(0,0,0,.6)' : 'none',
      }"
    >
      <div style="padding:16px 16px 10px;display:flex;align-items:center;justify-content:space-between;">
        <div style="font-weight:800;font-size:15px;">📣 Marketing</div>
        <button style="background:var(--accent);border:none;color:var(--accent-ink);font-weight:700;font-size:12px;padding:6px 10px;border-radius:8px;cursor:pointer;" @click="newSession">+ Nova</button>
      </div>
      <div style="font-size:10.5px;color:var(--c-text-faint);padding:0 16px 10px;line-height:1.4;">
        Cria anúncios no Facebook Ads. Tudo nasce <strong style="color:var(--c-text-muted);">pausado</strong> — publicar é com você.
      </div>

      <div style="display:flex;gap:4px;padding:0 10px 10px;">
        <button v-for="t in (['conversa','criativos','config'] as Aba[])" :key="t" :style="{ flex:1,background: aba===t ? 'var(--c-surface-0)' : 'transparent', border:'1px solid ' + (aba===t ? 'var(--c-surface-3)' : 'transparent'), color: aba===t ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily:'inherit', fontSize:'11px', padding:'6px 4px', borderRadius:'8px', cursor:'pointer' }" @click="aba = t">
          {{ t === 'conversa' ? 'Conversa' : t === 'criativos' ? `Criativos${criativos.length ? ' ' + criativos.length : ''}` : 'Config' }}
        </button>
      </div>

      <div style="flex:1;overflow-y:auto;padding:4px 8px;min-height:0;">
        <div v-for="s in sessions" :key="s.id" :style="{ display:'flex',alignItems:'center',gap:'6px',padding:'8px 10px',borderRadius:'9px',cursor:'pointer',marginBottom:'3px',background: s.id===activeId ? 'var(--c-surface-0)' : 'transparent' }" @click="openSession(s.id)">
          <div style="flex:1;min-width:0;">
            <div style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ s.title }}</div>
            <div style="font-size:10px;color:var(--c-text-faint);margin-top:2px;">{{ quando(s.updated_at) }}<template v-if="s.id === activeId && running"> · <span style="color:var(--accent);">rodando {{ decorrido }}</span></template></div>
          </div>
          <button title="Apagar" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:14px;flex-shrink:0;" @click.stop="delSession(s)">×</button>
        </div>
      </div>
      <button style="margin:10px;background:none;border:1px solid var(--c-surface-3);color:var(--c-text-muted);font-family:inherit;font-size:12px;padding:8px;border-radius:9px;cursor:pointer;" @click="navigateTo('/')">← Voltar ao CRM</button>
    </aside>

    <div v-if="isMobile && drawer" style="position:absolute;inset:0;background:rgba(0,0,0,.5);z-index:35;" @click="drawer = false" />

    <main style="flex:1;display:flex;flex-direction:column;min-width:0;min-height:0;">
      <div v-if="isMobile" style="display:flex;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid var(--c-surface-1);flex-shrink:0;">
        <button style="background:var(--c-surface-0);border:none;color:var(--c-text);font-size:18px;width:36px;height:36px;border-radius:9px;cursor:pointer;" @click="drawer = true">☰</button>
        <div style="font-weight:700;font-size:14px;">📣 Marketing</div>
        <div v-if="running" style="margin-left:auto;font-size:11px;color:var(--accent);">● {{ decorrido }}</div>
      </div>

      <!-- ================= CONVERSA ================= -->
      <template v-if="aba === 'conversa'">
        <div v-if="!pronto" style="margin:16px clamp(12px,4vw,40px) 0;background:rgba(255,170,0,.08);border:1px solid rgba(255,170,0,.3);border-radius:11px;padding:12px 14px;font-size:12px;color:var(--c-text-secondary);">
          ⚠️ Facebook Ads ainda não conectado. O agente consegue conversar, mas não vai criar nada até você preencher a aba <button style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:12px;cursor:pointer;padding:0;text-decoration:underline;" @click="aba = 'config'">Config</button>.
        </div>

        <div v-if="!activeId" style="margin:auto;text-align:center;color:var(--c-text-faint);padding:24px;">
          <div style="font-size:40px;margin-bottom:10px;">📣</div>
          <div style="font-size:14px;">Abra uma conversa para criar anúncios.</div>
          <button style="margin-top:16px;background:var(--accent);border:none;color:var(--accent-ink);font-weight:700;font-size:13px;padding:9px 16px;border-radius:9px;cursor:pointer;" @click="newSession">+ Nova conversa</button>
        </div>

        <template v-else>
          <div ref="scroller" style="flex:1;overflow-y:auto;min-height:0;padding:18px clamp(12px, 4vw, 40px);display:flex;flex-direction:column;gap:14px;">
            <template v-for="m in messages" :key="m.id">
              <div v-if="m.role==='user'" style="align-self:flex-end;max-width:88%;background:var(--c-info-bg);border:1px solid var(--c-info-border);border-radius:12px 12px 3px 12px;padding:10px 14px;font-size:13px;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;">{{ m.content }}</div>
              <div v-else style="align-self:stretch;display:flex;flex-direction:column;gap:8px;">
                <template v-for="(b, i) in blocosDe(m.content)" :key="i">
                  <div v-if="b.tipo === 'sistema'" style="font-size:11.5px;color:var(--c-text-faint);padding-left:2px;">{{ b.txt }}</div>
                  <div v-else-if="b.tipo === 'texto'" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-1);border-radius:12px;padding:13px 16px;font-size:12.5px;line-height:1.6;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;">{{ b.txt }}</div>
                  <div v-else style="border:1px solid var(--c-surface-1);border-radius:12px;overflow:hidden;background:var(--c-bg-deepest);">
                    <div v-if="b.tool" style="display:flex;align-items:flex-start;gap:8px;padding:9px 12px;background:var(--c-bg-deep);border-bottom:1px solid var(--c-surface-1);">
                      <span style="color:var(--c-ai-soft);font-weight:700;font-size:11.5px;flex-shrink:0;">{{ b.tool }}</span>
                      <span style="flex:1;min-width:0;font-size:12px;white-space:pre-wrap;word-break:break-word;">{{ b.arg }}</span>
                    </div>
                    <div v-if="b.saida" style="padding:10px 12px;font-size:11.5px;line-height:1.5;color:var(--c-text-secondary);white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;max-height:260px;overflow-y:auto;">{{ b.saida }}</div>
                  </div>
                </template>
              </div>
            </template>

            <div v-if="running" style="align-self:stretch;display:flex;flex-direction:column;gap:8px;">
              <div style="display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--accent);">
                <span style="width:7px;height:7px;border-radius:50%;background:var(--accent);animation:pulso 1.2s infinite;" />
                trabalhando · {{ decorrido }}
              </div>
              <template v-for="(b, i) in blocosAoVivo" :key="'live' + i">
                <div v-if="b.tipo === 'sistema'" style="font-size:11.5px;color:var(--c-text-faint);padding-left:2px;">{{ b.txt }}</div>
                <div v-else-if="b.tipo === 'texto'" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-1);border-radius:12px;padding:13px 16px;font-size:12.5px;line-height:1.6;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;">{{ b.txt }}</div>
                <div v-else style="border:1px solid var(--c-surface-1);border-radius:12px;overflow:hidden;background:var(--c-bg-deepest);">
                  <div v-if="b.tool" style="display:flex;align-items:flex-start;gap:8px;padding:9px 12px;background:var(--c-bg-deep);border-bottom:1px solid var(--c-surface-1);">
                    <span style="color:var(--c-ai-soft);font-weight:700;font-size:11.5px;flex-shrink:0;">{{ b.tool }}</span>
                    <span style="flex:1;min-width:0;font-size:12px;white-space:pre-wrap;word-break:break-word;">{{ b.arg }}</span>
                  </div>
                  <div v-if="b.saida" style="padding:10px 12px;font-size:11.5px;line-height:1.5;color:var(--c-text-secondary);white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;max-height:260px;overflow-y:auto;">{{ b.saida }}</div>
                </div>
              </template>
              <div v-if="!liveOutput" style="font-size:12px;color:var(--c-text-faint);">⏳ iniciando…</div>
            </div>

            <div v-if="jobError" style="align-self:stretch;background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:12px;padding:12px 14px;font-size:12px;color:var(--c-danger-soft);white-space:pre-wrap;">⚠️ {{ jobError }}</div>
          </div>

          <div style="border-top:1px solid var(--c-surface-1);padding:12px clamp(12px,4vw,16px);flex-shrink:0;">
            <div style="display:flex;gap:10px;align-items:flex-end;">
              <textarea v-model="input" rows="1" :disabled="running" :placeholder="running ? 'O agente está trabalhando…' : 'Ex.: cria uma campanha de tráfego, R$50/dia, com o Criativo 1 para o site…'" style="flex:1;min-width:0;resize:none;max-height:160px;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:11px;color:var(--c-text);font-family:inherit;font-size:13px;padding:11px 13px;outline:none;line-height:1.4;" @keydown="onKey" @input="(e:any)=>{e.target.style.height='auto';e.target.style.height=Math.min(e.target.scrollHeight,160)+'px'}" />
              <button v-if="running" style="background:var(--c-danger);border:none;color:var(--c-on-accent);font-family:inherit;font-weight:700;font-size:13px;padding:12px 16px;border-radius:11px;cursor:pointer;flex-shrink:0;" @click="stopJob">⛔ Parar</button>
              <button v-else :disabled="sending || !input.trim()" :style="{ background:'var(--accent)',border:'none',color:'var(--accent-ink)',fontFamily:'inherit',fontWeight:700,fontSize:'13px',padding:'12px 18px',borderRadius:'11px',cursor:(sending||!input.trim())?'default':'pointer',opacity:(sending||!input.trim())?0.5:1,flexShrink:0 }" @click="send">Enviar</button>
            </div>
          </div>
        </template>
      </template>

      <!-- ================= CRIATIVOS ================= -->
      <div v-else-if="aba === 'criativos'" style="flex:1;overflow-y:auto;padding:20px clamp(12px,4vw,40px);">
        <div style="font-size:15px;font-weight:800;margin-bottom:4px;">Criativos</div>
        <div style="font-size:12px;color:var(--c-text-muted);margin-bottom:16px;line-height:1.5;">
          Cada imagem enviada vira <strong>Criativo 1</strong>, <strong>Criativo 2</strong>… É por esse nome que você fala dela com o agente.
          A observação é lida por ele — descreva o que a peça mostra e para quem serve.
        </div>

        <label style="display:block;border:1.5px dashed var(--c-surface-3);border-radius:12px;padding:22px;text-align:center;cursor:pointer;margin-bottom:18px;background:var(--c-bg-deep);">
          <input ref="fileInput" type="file" accept="image/*" multiple hidden @change="(e:any) => subir(e.target.files)">
          <div style="font-size:26px;margin-bottom:6px;">🖼️</div>
          <div style="font-size:13px;font-weight:700;">{{ subindo ? 'Enviando…' : 'Clique para enviar criativos' }}</div>
          <div style="font-size:11px;color:var(--c-text-faint);margin-top:4px;">JPG, PNG, GIF ou WebP · até 30 MB cada · pode selecionar vários</div>
        </label>

        <div v-if="erroUpload" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-danger-soft);margin-bottom:14px;">{{ erroUpload }}</div>

        <div v-if="!criativos.length" style="font-size:12.5px;color:var(--c-text-faint);">Nenhum criativo ainda.</div>

        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;">
          <div v-for="c in criativos" :key="c.id" style="border:1px solid var(--c-surface-1);border-radius:12px;overflow:hidden;background:var(--c-bg-deep);display:flex;flex-direction:column;">
            <img :src="c.url" :alt="c.name" style="width:100%;aspect-ratio:1;object-fit:cover;background:var(--c-bg-deepest);">
            <div style="padding:10px 12px;display:flex;flex-direction:column;gap:7px;">
              <div style="display:flex;align-items:center;gap:6px;">
                <strong style="font-size:13px;">{{ c.name }}</strong>
                <span v-if="c.no_facebook" title="Já enviado ao Facebook" style="font-size:10px;color:var(--c-ai-soft);">● no FB</span>
                <button title="Apagar" style="margin-left:auto;background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:15px;" @click="apagarCriativo(c)">×</button>
              </div>
              <div style="font-size:10.5px;color:var(--c-text-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.original_name }} · {{ tamanho(c.size) }}</div>
              <textarea v-model="c.notes" rows="2" placeholder="O que essa peça mostra? (a IA lê isto)" :style="campo + 'resize:vertical;font-size:11.5px;padding:7px 9px;'" @blur="salvarNota(c)" />
            </div>
          </div>
        </div>
      </div>

      <!-- ================= CONFIG ================= -->
      <div v-else style="flex:1;overflow-y:auto;padding:20px clamp(12px,4vw,40px);">
        <div style="max-width:560px;">
          <div style="font-size:15px;font-weight:800;margin-bottom:4px;">Conexão com o Facebook Ads</div>
          <div style="font-size:12px;color:var(--c-text-muted);margin-bottom:16px;line-height:1.5;">
            Credenciais do seu app na Meta. O token e o app secret ficam criptografados no banco e nunca voltam para a tela —
            deixe em branco para manter o que já está salvo.
          </div>

          <div v-if="status" :style="{ display:'flex',alignItems:'center',gap:'8px',padding:'10px 12px',borderRadius:'10px',marginBottom:'16px',fontSize:'12px', background: pronto ? 'rgba(35,197,98,.08)' : 'rgba(255,170,0,.08)', border: '1px solid ' + (pronto ? 'rgba(35,197,98,.3)' : 'rgba(255,170,0,.3)') }">
            <span>{{ pronto ? '●' : '○' }}</span>
            <span>{{ pronto ? 'Credencial preenchida' : 'Falta token de acesso ou conta de anúncios' }}</span>
            <span v-if="status.checked_at" style="margin-left:auto;color:var(--c-text-faint);font-size:11px;">testada {{ quando(status.checked_at) }} atrás</span>
          </div>

          <div v-if="status?.last_error" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:11.5px;color:var(--c-danger-soft);margin-bottom:16px;white-space:pre-wrap;">Último erro: {{ status.last_error }}</div>

          <div style="display:flex;flex-direction:column;gap:13px;">
            <div>
              <label :style="rotulo">Token de acesso {{ status?.tem_token ? '(salvo — preencha só para trocar)' : '(obrigatório)' }}</label>
              <input v-model="form.access_token" type="password" autocomplete="off" placeholder="EAAG…" :style="campo">
            </div>
            <div>
              <label :style="rotulo">ID da conta de anúncios (obrigatório)</label>
              <input v-model="form.ad_account_id" placeholder="act_123456789 ou só 123456789" :style="campo">
            </div>
            <div>
              <label :style="rotulo">ID da página do Facebook (obrigatório para criar anúncio)</label>
              <input v-model="form.page_id" placeholder="123456789" :style="campo">
            </div>
            <div style="display:flex;gap:12px;">
              <div style="flex:1;">
                <label :style="rotulo">App ID (opcional)</label>
                <input v-model="form.app_id" :style="campo">
              </div>
              <div style="width:120px;">
                <label :style="rotulo">Versão da API</label>
                <input v-model="form.graph_version" placeholder="v23.0" :style="campo">
              </div>
            </div>
            <div>
              <label :style="rotulo">App secret (opcional) {{ status?.tem_app_secret ? '— salvo' : '' }}</label>
              <input v-model="form.app_secret" type="password" autocomplete="off" :style="campo">
            </div>
          </div>

          <div v-if="resultado" :style="{ marginTop:'14px',padding:'10px 12px',borderRadius:'10px',fontSize:'12px',whiteSpace:'pre-wrap', background: resultado.ok ? 'rgba(35,197,98,.08)' : 'rgba(255,77,77,.08)', border:'1px solid ' + (resultado.ok ? 'rgba(35,197,98,.3)' : 'rgba(255,77,77,.3)'), color: resultado.ok ? 'var(--c-text-secondary)' : 'var(--c-danger-soft)' }">
            {{ resultado.ok ? '✓ ' : '✗ ' }}{{ resultado.texto }}
          </div>

          <div style="display:flex;gap:10px;margin-top:16px;">
            <button :disabled="salvando" :style="{ background:'var(--accent)',border:'none',color:'var(--accent-ink)',fontFamily:'inherit',fontWeight:700,fontSize:'13px',padding:'10px 18px',borderRadius:'10px',cursor: salvando ? 'default':'pointer',opacity: salvando ? .6 : 1 }" @click="salvarConfig">{{ salvando ? 'Salvando…' : 'Salvar e testar' }}</button>
            <button :disabled="testando || !status?.tem_token" style="background:none;border:1px solid var(--c-surface-3);color:var(--c-text-secondary);font-family:inherit;font-size:13px;padding:10px 16px;border-radius:10px;cursor:pointer;" @click="testarConfig">{{ testando ? 'Testando…' : 'Testar conexão' }}</button>
          </div>

          <div style="margin-top:22px;font-size:11.5px;color:var(--c-text-faint);line-height:1.6;">
            O token precisa dos escopos <code>ads_management</code> e <code>ads_read</code>, e a conta de anúncios tem que estar
            no mesmo Business Manager do app. Um token de usuário do sistema (System User) não expira — é o recomendado aqui.
          </div>
        </div>
      </div>
    </main>
  </div>
</template>

<style scoped>
@keyframes pulso { 0%,100% { opacity: 1 } 50% { opacity: .25 } }
</style>
