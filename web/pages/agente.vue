<script setup lang="ts">
const api = useApi()
const isMobile = useIsMobile()

interface Session { id: number, title: string, cwd: string, updated_at: string }
interface Msg { id: number, role: string, content: string }

const sessions = ref<Session[]>([])
const activeId = ref<number | null>(null)
const messages = ref<Msg[]>([])
const liveOutput = ref('')        // saída do job em andamento (streaming)
const running = ref(false)
const currentJob = ref<number | null>(null)
const input = ref('')
const sending = ref(false)
const scroller = ref<HTMLElement | null>(null)
const drawer = ref(false)         // gaveta de sessões no mobile
let poll: any = null

async function loadSessions() {
  sessions.value = await api<Session[]>('/api/agent/sessions')
}

async function openSession(id: number) {
  stopPolling()
  activeId.value = id
  liveOutput.value = ''
  running.value = false
  currentJob.value = null
  drawer.value = false
  const s = await api<{ id: number, messages: Msg[], active_job_id: number | null }>(`/api/agent/sessions/${id}`)
  messages.value = s.messages || []
  await nextTick(); scrollDown()
  // Job ainda rodando nessa sessão? Retoma o streaming ao vivo.
  if (s.active_job_id) {
    currentJob.value = s.active_job_id
    running.value = true
    liveOutput.value = ''
    startPolling(s.active_job_id)
  }
}

async function newSession() {
  const s = await api<Session>('/api/agent/sessions', { method: 'POST', body: { title: 'Nova sessão' } })
  await loadSessions()
  await openSession(s.id)
}

async function delSession(id: number) {
  await api(`/api/agent/sessions/${id}`, { method: 'DELETE' }).catch(() => {})
  if (activeId.value === id) { activeId.value = null; messages.value = [] }
  await loadSessions()
}

function scrollDown() {
  const el = scroller.value
  if (el) el.scrollTop = el.scrollHeight
}

async function send() {
  const text = input.value.trim()
  if (!text || !activeId.value || running.value) return
  sending.value = true
  messages.value.push({ id: Date.now(), role: 'user', content: text })
  input.value = ''
  await nextTick(); scrollDown()
  try {
    const r = await api<{ job_id: number }>(`/api/agent/sessions/${activeId.value}/messages`, { method: 'POST', body: { prompt: text } })
    currentJob.value = r.job_id
    running.value = true
    liveOutput.value = ''
    startPolling(r.job_id)
  }
  catch (e: any) {
    messages.value.push({ id: Date.now() + 1, role: 'assistant', content: '⚠️ ' + (e?.data?.message || 'Falha ao enviar.') })
  }
  finally {
    sending.value = false
  }
}

function startPolling(jobId: number) {
  stopPolling()
  poll = setInterval(async () => {
    try {
      const j = await api<{ status: string, output: string }>(`/api/agent/jobs/${jobId}`)
      const wasNear = nearBottom()
      liveOutput.value = j.output || ''
      await nextTick(); if (wasNear) scrollDown()
      if (['done', 'error', 'canceled'].includes(j.status)) {
        stopPolling()
        running.value = false
        currentJob.value = null
        if (activeId.value) {
          const s = await api<{ messages: Msg[] }>(`/api/agent/sessions/${activeId.value}`)
          messages.value = s.messages || []
          liveOutput.value = ''
          await nextTick(); scrollDown()
        }
      }
    }
    catch { /* segue tentando */ }
  }, 1000)
}

function nearBottom() {
  const el = scroller.value
  if (!el) return true
  return el.scrollHeight - el.scrollTop - el.clientHeight < 120
}

function stopPolling() {
  if (poll) { clearInterval(poll); poll = null }
}

async function stopJob() {
  if (!currentJob.value) return
  await api(`/api/agent/jobs/${currentJob.value}/stop`, { method: 'POST' }).catch(() => {})
}

function onKey(e: KeyboardEvent) {
  // Enter envia; Shift+Enter quebra linha.
  if (e.key === 'Enter' && !e.shiftKey) { e.preventDefault(); send() }
}

onMounted(async () => {
  await loadSessions()
  if (sessions.value.length) await openSession(sessions.value[0].id)
})
onBeforeUnmount(stopPolling)
</script>

<template>
  <div style="display:flex;height:100dvh;background:#0b0f12;color:#d6e0e6;font-family:'JetBrains Mono',ui-monospace,monospace;position:relative;overflow:hidden;">
    <!-- sessões: fixa no desktop, gaveta no mobile -->
    <aside
      :style="{
        width: '260px', flexShrink: 0, background: '#0a0f12', borderRight: '1px solid #1c2730',
        display: 'flex', flexDirection: 'column', zIndex: 40,
        position: isMobile ? 'absolute' : 'relative', top: 0, bottom: 0, left: 0,
        transform: (isMobile && !drawer) ? 'translateX(-100%)' : 'translateX(0)',
        transition: 'transform .2s', boxShadow: isMobile ? '0 0 40px rgba(0,0,0,.6)' : 'none',
      }"
    >
      <div style="padding:16px 16px 10px;display:flex;align-items:center;justify-content:space-between;">
        <div style="font-weight:800;font-size:15px;color:#e9edef;">🤖 Agente</div>
        <button style="background:#25D366;border:none;color:#062014;font-weight:700;font-size:12px;padding:6px 10px;border-radius:8px;cursor:pointer;" @click="newSession">+ Nova</button>
      </div>
      <div style="font-size:10.5px;color:#5f6f78;padding:0 16px 10px;line-height:1.4;">Opera a VPS via Claude Code. Sem aprovação por ação — tudo é registrado.</div>
      <div style="flex:1;overflow-y:auto;padding:4px 8px;min-height:0;">
        <div v-for="s in sessions" :key="s.id" :style="{ display:'flex',alignItems:'center',gap:'6px',padding:'9px 10px',borderRadius:'9px',cursor:'pointer',marginBottom:'3px',background: s.id===activeId ? '#16232b' : 'transparent' }" @click="openSession(s.id)">
          <span style="flex:1;font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:#cfd6db;">{{ s.title }}</span>
          <button title="Apagar" style="background:none;border:none;color:#5f6f78;cursor:pointer;font-size:14px;" @click.stop="delSession(s.id)">×</button>
        </div>
      </div>
      <button style="margin:10px;background:none;border:1px solid #2a3942;color:#8696a0;font-family:inherit;font-size:12px;padding:8px;border-radius:9px;cursor:pointer;" @click="navigateTo('/')">← Voltar ao CRM</button>
    </aside>

    <!-- backdrop da gaveta -->
    <div v-if="isMobile && drawer" style="position:absolute;inset:0;background:rgba(0,0,0,.5);z-index:35;" @click="drawer = false" />

    <!-- chat -->
    <main style="flex:1;display:flex;flex-direction:column;min-width:0;min-height:0;">
      <!-- topbar (mostra botão de menu no mobile) -->
      <div v-if="isMobile" style="display:flex;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid #1c2730;background:#0a0f12;flex-shrink:0;">
        <button style="background:#16232b;border:none;color:#cfd6db;font-size:18px;width:36px;height:36px;border-radius:9px;cursor:pointer;" @click="drawer = true">☰</button>
        <div style="font-weight:700;font-size:14px;color:#e9edef;">🤖 Agente</div>
      </div>

      <div v-if="!activeId" style="margin:auto;text-align:center;color:#5f6f78;padding:24px;">
        <div style="font-size:40px;margin-bottom:10px;">🤖</div>
        <div style="font-size:14px;">Crie ou abra uma sessão para conversar com o agente.</div>
        <button style="margin-top:16px;background:#25D366;border:none;color:#062014;font-weight:700;font-size:13px;padding:9px 16px;border-radius:9px;cursor:pointer;" @click="newSession">+ Nova sessão</button>
      </div>

      <template v-else>
        <div ref="scroller" style="flex:1;overflow-y:auto;min-height:0;padding:18px clamp(12px, 4vw, 40px);display:flex;flex-direction:column;gap:14px;">
          <template v-for="m in messages" :key="m.id">
            <div v-if="m.role==='user'" style="align-self:flex-end;max-width:88%;background:#16323f;border:1px solid #1d4250;border-radius:12px 12px 3px 12px;padding:10px 14px;font-size:13px;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;">{{ m.content }}</div>
            <div v-else style="align-self:stretch;background:#0e151a;border:1px solid #1c2730;border-radius:12px;padding:14px 16px;font-size:12.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;color:#cdd9df;">{{ m.content }}</div>
          </template>
          <!-- saída ao vivo do job em execução -->
          <div v-if="running" style="align-self:stretch;background:#0e151a;border:1px solid #214a2e;border-radius:12px;padding:14px 16px;font-size:12.5px;line-height:1.55;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;color:#cdd9df;">{{ liveOutput || '⏳ iniciando…' }}<span style="color:#25D366;animation:blink 1s infinite;">▋</span></div>
        </div>

        <!-- input -->
        <div style="border-top:1px solid #1c2730;padding:12px clamp(12px,4vw,16px);background:#0a0f12;flex-shrink:0;">
          <div style="display:flex;gap:10px;align-items:flex-end;">
            <textarea v-model="input" rows="1" :disabled="running" placeholder="Diga o que o agente deve fazer na VPS…  (Enter envia, Shift+Enter quebra linha)" style="flex:1;min-width:0;resize:none;max-height:160px;background:#0e151a;border:1px solid #2a3942;border-radius:11px;color:#e9edef;font-family:inherit;font-size:13px;padding:11px 13px;outline:none;line-height:1.4;" @keydown="onKey" @input="(e:any)=>{e.target.style.height='auto';e.target.style.height=Math.min(e.target.scrollHeight,160)+'px'}" />
            <button v-if="running" style="background:#b3261e;border:none;color:#fff;font-family:inherit;font-weight:700;font-size:13px;padding:12px 16px;border-radius:11px;cursor:pointer;flex-shrink:0;" @click="stopJob">⛔ Parar</button>
            <button v-else :disabled="sending || !input.trim()" :style="{ background:'#25D366',border:'none',color:'#062014',fontFamily:'inherit',fontWeight:700,fontSize:'13px',padding:'12px 18px',borderRadius:'11px',cursor:(sending||!input.trim())?'default':'pointer',opacity:(sending||!input.trim())?0.5:1,flexShrink:0 }" @click="send">Enviar</button>
          </div>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
@keyframes blink { 0%,50% { opacity: 1 } 50.01%,100% { opacity: 0 } }
</style>
