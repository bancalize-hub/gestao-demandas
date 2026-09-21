<script setup lang="ts">
const api = useApi()
const isMobile = useIsMobile()

interface Session { id: number, title: string, cwd: string, updated_at: string }
interface Msg { id: number, role: string, content: string }

const sessions = ref<Session[]>([])
const activeId = ref<number | null>(null)
const messages = ref<Msg[]>([])
const liveOutput = ref('') // saída do job em andamento (acumulada por deltas)
const running = ref(false)
const currentJob = ref<number | null>(null)
const jobError = ref('')
const startedAt = ref<number | null>(null)
const agora = ref(Date.now()) // relógio p/ o cronômetro do job
const input = ref('')
const sending = ref(false)
const scroller = ref<HTMLElement | null>(null)
const drawer = ref(false) // gaveta de sessões no mobile
let poll: any = null
let relogio: any = null
let recebido = 0 // bytes de saída já baixados (offset do polling incremental)

// ---------------------------------------------------------------------------
// Leitura da saída do agente
//
// O worker manda um texto corrido que mistura três coisas: o que o agente escreveu,
// os comandos que ele rodou (`$ ...`), as ferramentas que usou (`[Read arquivo]`) e a
// saída dessas ferramentas (entre ⟦saída⟧ e ⟦fim⟧). Sem separar, vira um parágrafo
// gigante onde não dá para achar o comando que quebrou.
// ---------------------------------------------------------------------------
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

    if (l.startsWith('$ ')) {
      soltaProsa()
      blocos.push({ tipo: 'exec', cmd: l.slice(2), saida: '' })
      continue
    }

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
      // Saída pertence ao comando logo acima; sem comando (histórico antigo), vira bloco solto.
      if (ultimo?.tipo === 'exec' && !ultimo.saida) { soltaProsa(); ultimo.saida = texto }
      else { soltaProsa(); blocos.push({ tipo: 'exec', saida: texto }) }
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

// Cache: o polling redesenha a cada segundo e reparsear a saída inteira toda vez é caro.
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

// Saídas longas ficam recolhidas (com scroll próprio); o usuário abre a que interessa.
const expandido = reactive<Record<string, boolean>>({})
function chaveBloco(msgId: number | string, i: number) { return `${msgId}:${i}` }
function linhas(t?: string) { return t ? t.split('\n').length : 0 }

const copiado = ref('')
async function copiar(txt: string, chave: string) {
  try {
    await navigator.clipboard.writeText(txt)
    copiado.value = chave
    setTimeout(() => { if (copiado.value === chave) copiado.value = '' }, 1500)
  }
  catch { /* clipboard bloqueado */ }
}

const decorrido = computed(() => {
  if (!startedAt.value) return ''
  const s = Math.max(0, Math.floor((agora.value - startedAt.value) / 1000))
  return s < 60 ? `${s}s` : `${Math.floor(s / 60)}m ${String(s % 60).padStart(2, '0')}s`
})

function quando(iso: string) {
  const d = new Date(iso)
  const min = Math.floor((Date.now() - d.getTime()) / 60000)
  if (min < 1) return 'agora'
  if (min < 60) return `${min} min`
  const h = Math.floor(min / 60)
  if (h < 24) return `${h}h`
  return `${Math.floor(h / 24)}d`
}

// ---------------------------------------------------------------------------

async function loadSessions() {
  sessions.value = await api<Session[]>('/api/agent/sessions')
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
  const s = await api<{ id: number, messages: Msg[], active_job_id: number | null }>(`/api/agent/sessions/${id}`)
  messages.value = s.messages || []
  await nextTick(); scrollDown()
  // Job ainda rodando nessa sessão? Retoma o streaming ao vivo.
  if (s.active_job_id) {
    currentJob.value = s.active_job_id
    running.value = true
    startPolling(s.active_job_id)
  }
}

async function newSession() {
  const s = await api<Session>('/api/agent/sessions', { method: 'POST', body: { title: 'Nova sessão' } })
  await loadSessions()
  await openSession(s.id)
}

async function delSession(s: Session) {
  // Sessão é registro de auditoria do que o agente fez na VPS — não pode sumir num clique torto.
  if (!confirm(`Apagar a sessão "${s.title}"? O histórico do que o agente fez nela some.`)) return
  await api(`/api/agent/sessions/${s.id}`, { method: 'DELETE' }).catch(() => {})
  if (activeId.value === s.id) { activeId.value = null; messages.value = [] }
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
    loadSessions() // o título da sessão vira o 1º pedido
  }
  catch (e: any) {
    messages.value.push({ id: Date.now() + 1, role: 'assistant', content: '⚠️ ' + (e?.response?._data?.message || e?.data?.message || 'Falha ao enviar.') })
  }
  finally {
    sending.value = false
  }
}

function startPolling(jobId: number) {
  stopPolling()
  startedAt.value = Date.now()
  relogio = setInterval(() => { agora.value = Date.now() }, 1000)
  poll = setInterval(async () => {
    try {
      // Só o pedaço novo: `from` é o que já temos (o servidor conta em bytes).
      const j = await api<{ status: string, chunk: string, len: number, error: string | null, started_at: string | null }>(`/api/agent/jobs/${jobId}?from=${recebido}`)
      if (j.chunk) {
        const wasNear = nearBottom()
        liveOutput.value += j.chunk
        recebido = j.len
        await nextTick(); if (wasNear) scrollDown()
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
      }
    }
    catch { /* segue tentando: queda de rede não pode matar o acompanhamento */ }
  }, 1000)
}

function nearBottom() {
  const el = scroller.value
  if (!el) return true
  return el.scrollHeight - el.scrollTop - el.clientHeight < 120
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
  <div style="display:flex;height:100%;min-height:0;background:var(--c-bg-deepest);color:var(--c-text);font-family:'JetBrains Mono',ui-monospace,monospace;position:relative;overflow:hidden;">
    <!-- sessões: fixa no desktop, gaveta no mobile -->
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
        <div style="font-weight:800;font-size:15px;color:var(--c-text);">🤖 Agente</div>
        <button style="background:var(--accent);border:none;color:var(--accent-ink);font-weight:700;font-size:12px;padding:6px 10px;border-radius:8px;cursor:pointer;" @click="newSession">+ Nova</button>
      </div>
      <div style="font-size:10.5px;color:var(--c-text-faint);padding:0 16px 10px;line-height:1.4;">Opera a VPS via Claude Code. Sem aprovação por ação — tudo é registrado.</div>
      <div style="flex:1;overflow-y:auto;padding:4px 8px;min-height:0;">
        <div v-for="s in sessions" :key="s.id" :style="{ display:'flex',alignItems:'center',gap:'6px',padding:'8px 10px',borderRadius:'9px',cursor:'pointer',marginBottom:'3px',background: s.id===activeId ? 'var(--c-surface-0)' : 'transparent' }" @click="openSession(s.id)">
          <div style="flex:1;min-width:0;">
            <div style="font-size:12.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;color:var(--c-text);">{{ s.title }}</div>
            <div style="font-size:10px;color:var(--c-text-faint);margin-top:2px;">{{ quando(s.updated_at) }}<template v-if="s.id === activeId && running"> · <span style="color:var(--accent);">rodando {{ decorrido }}</span></template></div>
          </div>
          <button title="Apagar" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:14px;flex-shrink:0;" @click.stop="delSession(s)">×</button>
        </div>
      </div>
      <button style="margin:10px;background:none;border:1px solid var(--c-surface-3);color:var(--c-text-muted);font-family:inherit;font-size:12px;padding:8px;border-radius:9px;cursor:pointer;" @click="navigateTo('/')">← Voltar ao CRM</button>
    </aside>

    <!-- backdrop da gaveta -->
    <div v-if="isMobile && drawer" style="position:absolute;inset:0;background:rgba(0,0,0,.5);z-index:35;" @click="drawer = false" />

    <!-- chat -->
    <main style="flex:1;display:flex;flex-direction:column;min-width:0;min-height:0;">
      <!-- topbar (mostra botão de menu no mobile) -->
      <div v-if="isMobile" style="display:flex;align-items:center;gap:12px;padding:11px 14px;border-bottom:1px solid var(--c-surface-1);background:var(--c-bg-deepest);flex-shrink:0;">
        <button style="background:var(--c-surface-0);border:none;color:var(--c-text);font-size:18px;width:36px;height:36px;border-radius:9px;cursor:pointer;" @click="drawer = true">☰</button>
        <div style="font-weight:700;font-size:14px;color:var(--c-text);">🤖 Agente</div>
        <div v-if="running" style="margin-left:auto;font-size:11px;color:var(--accent);">● {{ decorrido }}</div>
      </div>

      <div v-if="!activeId" style="margin:auto;text-align:center;color:var(--c-text-faint);padding:24px;">
        <div style="font-size:40px;margin-bottom:10px;">🤖</div>
        <div style="font-size:14px;">Crie ou abra uma sessão para conversar com o agente.</div>
        <button style="margin-top:16px;background:var(--accent);border:none;color:var(--accent-ink);font-weight:700;font-size:13px;padding:9px 16px;border-radius:9px;cursor:pointer;" @click="newSession">+ Nova sessão</button>
      </div>

      <template v-else>
        <div ref="scroller" style="flex:1;overflow-y:auto;min-height:0;padding:18px clamp(12px, 4vw, 40px);display:flex;flex-direction:column;gap:14px;">
          <template v-for="m in messages" :key="m.id">
            <!-- pedido do usuário -->
            <div v-if="m.role==='user'" style="align-self:flex-end;max-width:88%;background:var(--c-info-bg);border:1px solid var(--c-info-border);border-radius:12px 12px 3px 12px;padding:10px 14px;font-size:13px;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;">{{ m.content }}</div>

            <!-- resposta do agente, já separada em prosa / comandos / saída -->
            <div v-else style="align-self:stretch;display:flex;flex-direction:column;gap:8px;">
              <template v-for="(b, i) in blocosDe(m.content)" :key="i">
                <div v-if="b.tipo === 'sistema'" style="font-size:11.5px;color:var(--c-text-faint);padding-left:2px;">{{ b.txt }}</div>

                <div v-else-if="b.tipo === 'texto'" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-1);border-radius:12px;padding:13px 16px;font-size:12.5px;line-height:1.6;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;color:var(--c-text);">{{ b.txt }}</div>

                <!-- terminal: comando + saída -->
                <div v-else style="border:1px solid var(--c-surface-1);border-radius:12px;overflow:hidden;background:var(--c-bg-deepest);">
                  <div v-if="b.cmd || b.tool" style="display:flex;align-items:flex-start;gap:8px;padding:9px 12px;background:var(--c-bg-deep);border-bottom:1px solid var(--c-surface-1);">
                    <span v-if="b.cmd" style="color:var(--accent);font-weight:700;flex-shrink:0;">$</span>
                    <span v-else style="color:var(--c-ai-soft);font-weight:700;font-size:11.5px;flex-shrink:0;">{{ b.tool }}</span>
                    <span style="flex:1;min-width:0;font-size:12px;color:var(--c-text);white-space:pre-wrap;word-break:break-all;">{{ b.cmd || b.arg }}</span>
                    <button v-if="b.cmd" :title="copiado === chaveBloco(m.id, i) ? 'Copiado' : 'Copiar comando'" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:11px;flex-shrink:0;" @click="copiar(b.cmd!, chaveBloco(m.id, i))">{{ copiado === chaveBloco(m.id, i) ? '✓' : '⧉' }}</button>
                  </div>
                  <div v-if="b.saida" :style="{ padding: '10px 12px', fontSize: '11.5px', lineHeight: 1.5, color: 'var(--c-text-secondary)', whiteSpace: 'pre-wrap', wordBreak: 'break-word', overflowWrap: 'anywhere', overflowY: 'auto', maxHeight: expandido[chaveBloco(m.id, i)] ? 'none' : '220px' }">{{ b.saida }}</div>
                  <button v-if="linhas(b.saida) > 12" style="width:100%;background:var(--c-bg-deep);border:none;border-top:1px solid var(--c-surface-1);color:var(--c-text-muted);font-family:inherit;font-size:11px;padding:6px;cursor:pointer;" @click="expandido[chaveBloco(m.id, i)] = !expandido[chaveBloco(m.id, i)]">
                    {{ expandido[chaveBloco(m.id, i)] ? 'recolher' : `mostrar tudo (${linhas(b.saida)} linhas)` }}
                  </button>
                </div>
              </template>
            </div>
          </template>

          <!-- execução em andamento -->
          <div v-if="running" style="align-self:stretch;display:flex;flex-direction:column;gap:8px;">
            <div style="display:flex;align-items:center;gap:8px;font-size:11.5px;color:var(--accent);">
              <span style="width:7px;height:7px;border-radius:50%;background:var(--accent);animation:pulso 1.2s infinite;" />
              executando · {{ decorrido }}
            </div>
            <template v-for="(b, i) in blocosAoVivo" :key="'live' + i">
              <div v-if="b.tipo === 'sistema'" style="font-size:11.5px;color:var(--c-text-faint);padding-left:2px;">{{ b.txt }}</div>
              <div v-else-if="b.tipo === 'texto'" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-1);border-radius:12px;padding:13px 16px;font-size:12.5px;line-height:1.6;white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;color:var(--c-text);">{{ b.txt }}</div>
              <div v-else style="border:1px solid var(--c-surface-1);border-radius:12px;overflow:hidden;background:var(--c-bg-deepest);">
                <div v-if="b.cmd || b.tool" style="display:flex;align-items:flex-start;gap:8px;padding:9px 12px;background:var(--c-bg-deep);border-bottom:1px solid var(--c-surface-1);">
                  <span v-if="b.cmd" style="color:var(--accent);font-weight:700;flex-shrink:0;">$</span>
                  <span v-else style="color:var(--c-ai-soft);font-weight:700;font-size:11.5px;flex-shrink:0;">{{ b.tool }}</span>
                  <span style="flex:1;min-width:0;font-size:12px;color:var(--c-text);white-space:pre-wrap;word-break:break-all;">{{ b.cmd || b.arg }}</span>
                </div>
                <div v-if="b.saida" style="padding:10px 12px;font-size:11.5px;line-height:1.5;color:var(--c-text-secondary);white-space:pre-wrap;word-break:break-word;overflow-wrap:anywhere;max-height:220px;overflow-y:auto;">{{ b.saida }}</div>
              </div>
            </template>
            <div v-if="!liveOutput" style="font-size:12px;color:var(--c-text-faint);">⏳ iniciando…<span style="color:var(--accent);animation:blink 1s infinite;">▋</span></div>
          </div>

          <!-- falha do job (antes só sumia) -->
          <div v-if="jobError" style="align-self:stretch;background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:12px;padding:12px 14px;font-size:12px;color:var(--c-danger-soft);white-space:pre-wrap;word-break:break-word;">⚠️ {{ jobError }}</div>
        </div>

        <!-- input -->
        <div style="border-top:1px solid var(--c-surface-1);padding:12px clamp(12px,4vw,16px);background:var(--c-bg-deepest);flex-shrink:0;">
          <div style="display:flex;gap:10px;align-items:flex-end;">
            <textarea v-model="input" rows="1" :disabled="running" :placeholder="running ? 'O agente está trabalhando…' : 'Diga o que o agente deve fazer na VPS…  (Enter envia, Shift+Enter quebra linha)'" style="flex:1;min-width:0;resize:none;max-height:160px;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:11px;color:var(--c-text);font-family:inherit;font-size:13px;padding:11px 13px;outline:none;line-height:1.4;" @keydown="onKey" @input="(e:any)=>{e.target.style.height='auto';e.target.style.height=Math.min(e.target.scrollHeight,160)+'px'}" />
            <button v-if="running" style="background:var(--c-danger);border:none;color:var(--c-on-accent);font-family:inherit;font-weight:700;font-size:13px;padding:12px 16px;border-radius:11px;cursor:pointer;flex-shrink:0;" @click="stopJob">⛔ Parar</button>
            <button v-else :disabled="sending || !input.trim()" :style="{ background:'var(--accent)',border:'none',color:'var(--accent-ink)',fontFamily:'inherit',fontWeight:700,fontSize:'13px',padding:'12px 18px',borderRadius:'11px',cursor:(sending||!input.trim())?'default':'pointer',opacity:(sending||!input.trim())?0.5:1,flexShrink:0 }" @click="send">Enviar</button>
          </div>
        </div>
      </template>
    </main>
  </div>
</template>

<style scoped>
@keyframes blink { 0%,50% { opacity: 1 } 50.01%,100% { opacity: 0 } }
@keyframes pulso { 0%,100% { opacity: 1 } 50% { opacity: .25 } }
</style>
