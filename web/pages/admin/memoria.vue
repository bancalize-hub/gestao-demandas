<script setup lang="ts">
interface Chunk { id: number, kind: string, gatilho: string, conteudo: string, keywords: string, chat_tab_id: number | null }
// Time = tab do chat (SDR, Closer, CS): agrupa etapas do funil e tem objetivo próprio.
interface Team { id: number, name: string, stages: string[], objetivo: string | null }

const api = useApi()
const { user } = useAuth()

// ---- Credencial da IA (super-admin) ----
// O token da assinatura Claude vivia só no .env: quando foi revogado, tudo que usa IA
// parou por dias sem sinal nenhum na tela. Aqui dá para ver o estado e trocar na hora.
interface AiStatus { origem: string, tem_token: boolean, no_ar: boolean, motivo: string | null }
const ai = ref<AiStatus | null>(null)
const aiToken = ref('')
const aiBusy = ref(false)
const aiMsg = ref('')
const aiOk = ref(false)

async function loadAi() {
  if (!user.value?.is_super_admin) return
  try {
    ai.value = await api<AiStatus>('/api/ai/status')
    if (!ai.value.no_ar) aberto.ai = true // problema não pode ficar escondido atrás de um clique
  }
  catch { ai.value = null }
}
async function testAi() {
  aiBusy.value = true
  aiMsg.value = ''
  try {
    const r = await api<{ ok: boolean, mensagem: string }>('/api/ai/test', { method: 'POST' })
    aiOk.value = r.ok
    aiMsg.value = r.mensagem
  }
  catch (e: any) { aiOk.value = false; aiMsg.value = e?.response?._data?.message || 'Falha ao testar.' }
  finally { aiBusy.value = false; loadAi() }
}
// Login pela tela: o servidor sobe o `claude setup-token` num terminal virtual, devolve
// a URL de autorização, e o código que a Anthropic mostra volta por aqui.
const aiLogin = ref<{ sessao: string, url: string } | null>(null)
const aiCode = ref('')
async function startAiLogin() {
  aiBusy.value = true
  aiMsg.value = ''
  aiLogin.value = null
  try {
    const r = await api<{ ok: boolean, sessao?: string, url?: string, mensagem?: string }>('/api/ai/login/start', { method: 'POST' })
    if (r.ok && r.url && r.sessao) { aiLogin.value = { sessao: r.sessao, url: r.url }; aiCode.value = '' }
    else { aiOk.value = false; aiMsg.value = r.mensagem || 'Não consegui iniciar o login.' }
  }
  catch (e: any) { aiOk.value = false; aiMsg.value = e?.response?._data?.message || 'Falha ao iniciar o login.' }
  finally { aiBusy.value = false }
}
async function finishAiLogin() {
  const codigo = aiCode.value.trim()
  if (!aiLogin.value || !codigo || aiBusy.value) return
  aiBusy.value = true
  aiMsg.value = ''
  try {
    const r = await api<{ ok: boolean, mensagem?: string }>('/api/ai/login/finish', { method: 'POST', body: { sessao: aiLogin.value.sessao, codigo } })
    aiOk.value = r.ok
    aiMsg.value = r.ok ? 'Conectado! A IA voltou a responder.' : (r.mensagem || 'Não consegui concluir o login.')
    if (r.ok) { aiLogin.value = null; aiCode.value = '' }
  }
  catch (e: any) { aiOk.value = false; aiMsg.value = e?.response?._data?.message || 'Falha ao concluir o login.' }
  finally { aiBusy.value = false; loadAi() }
}

async function saveAiToken() {
  const t = aiToken.value.trim()
  if (!t || aiBusy.value) return
  aiBusy.value = true
  aiMsg.value = ''
  try {
    const r = await api<{ ok: boolean, mensagem: string }>('/api/ai/token', { method: 'POST', body: { token: t } })
    aiOk.value = r.ok
    aiMsg.value = r.ok ? 'Token salvo e funcionando.' : `Token salvo, mas o teste falhou: ${r.mensagem}`
    if (r.ok) aiToken.value = ''
  }
  catch (e: any) { aiOk.value = false; aiMsg.value = e?.response?._data?.message || 'Falha ao salvar.' }
  finally { aiBusy.value = false; loadAi() }
}

// ---- Materiais que a IA envia (PDF etc.) ----
interface Material { id: number, name: string, quando: string | null, filename: string, mime: string, size: number, is_active: boolean, chat_tab_id: number | null }
const materials = ref<Material[]>([])
const matForm = reactive({ name: '', quando: '', chat_tab_id: null as number | null })
const matFile = ref<File | null>(null)
const matSaving = ref(false)
const matError = ref('')
const matInput = ref<HTMLInputElement | null>(null)

function pickMaterial(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] || null
  matFile.value = f
  if (f && !matForm.name.trim()) matForm.name = f.name.replace(/\.[^.]+$/, '')
}
async function loadMaterials() {
  try { materials.value = (await api<{ materials: Material[] }>('/api/materials')).materials }
  catch { /* */ }
}
async function saveMaterial() {
  if (!matFile.value || !matForm.name.trim() || matSaving.value) return
  matSaving.value = true
  matError.value = ''
  try {
    const fd = new FormData()
    fd.append('file', matFile.value)
    fd.append('name', matForm.name.trim())
    if (matForm.quando.trim()) fd.append('quando', matForm.quando.trim())
    if (matForm.chat_tab_id) fd.append('chat_tab_id', String(matForm.chat_tab_id))
    const m = await api<Material>('/api/materials', { method: 'POST', body: fd })
    materials.value.unshift(m)
    matFile.value = null
    if (matInput.value) matInput.value.value = ''
    Object.assign(matForm, { name: '', quando: '', chat_tab_id: null })
  }
  catch (e: any) {
    const errs = e?.response?._data?.errors
    matError.value = errs ? Object.values(errs).flat().join(' ') : (e?.response?._data?.message || 'Erro ao enviar.')
  }
  finally { matSaving.value = false }
}
async function delMaterial(m: Material) {
  if (!confirm(`Excluir "${m.name}"? A IA deixa de poder enviar este material.`)) return
  materials.value = materials.value.filter(x => x.id !== m.id)
  try { await api(`/api/materials/${m.id}`, { method: 'DELETE' }) }
  catch { /* */ }
}
function fmtSize(b: number) {
  return b > 1048576 ? `${(b / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`
}

// ---- IA automática para leads novos (veio da antiga tela de Automações) ----
const autoReplyNewLeads = ref(false)
const savingSetting = ref(false)
async function loadSettings() {
  try { autoReplyNewLeads.value = (await api<{ auto_reply_new_leads: boolean }>('/api/automation-settings')).auto_reply_new_leads }
  catch { /* */ }
}
async function toggleAutoReplyNewLeads() {
  if (savingSetting.value) return
  savingSetting.value = true
  try {
    const r = await api<{ auto_reply_new_leads: boolean }>('/api/automation-settings', {
      method: 'PATCH',
      body: { auto_reply_new_leads: !autoReplyNewLeads.value },
    })
    autoReplyNewLeads.value = r.auto_reply_new_leads
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Não consegui salvar.') }
  finally { savingSetting.value = false }
}

// Sanfona: a página junta conexão da IA, materiais, times, voz, regras e 70+ conhecimentos.
// Tudo aberto vira um paredão — cada seção abre no clique. A conexão da IA é a exceção:
// quando está fora do ar ela abre sozinha, porque esconder falha foi o que já custou 4 dias.
const aberto = reactive<Record<string, boolean>>({ ai: false, materiais: false, times: false, voz: false, regras: false, base: false })
function alternar(k: string) { aberto[k] = !aberto[k] }

const chunks = ref<Chunk[]>([])
const teams = ref<Team[]>([])
const savingTeam = ref<number | null>(null)
const savedTeam = ref<number | null>(null)
const teamFilter = ref<number | null | 'all'>('all')
const style = ref('')
const samples = ref<{ id: number, text: string }[]>([])
const rules = ref<{ id: number, rule: string }[]>([])
const loading = ref(true)
const savingStyle = ref(false)
const styleSaved = ref(false)

const kinds = ['faq', 'preco', 'objecao', 'procedimento', 'fato']
const editingId = ref<number | null>(null) // null = nenhum, 0 = novo, >0 = editando
const form = reactive({ kind: 'faq', gatilho: '', conteudo: '', keywords: '', chat_tab_id: null as number | null })
const savingChunk = ref(false)
const chunkError = ref('')

function startAdd() {
  aberto.base = true // o formulário não pode abrir dentro de uma seção fechada
  editingId.value = 0
  // Adicionando com um time filtrado? Já nasce daquele time.
  Object.assign(form, { kind: 'faq', gatilho: '', conteudo: '', keywords: '', chat_tab_id: teamFilter.value === 'all' ? null : teamFilter.value })
  chunkError.value = ''
}

function startEdit(c: Chunk) {
  editingId.value = c.id
  Object.assign(form, { kind: c.kind, gatilho: c.gatilho, conteudo: c.conteudo, keywords: c.keywords || '', chat_tab_id: c.chat_tab_id ?? null })
  chunkError.value = ''
}

function cancelEdit() {
  editingId.value = null
  chunkError.value = ''
}

async function saveChunk() {
  if (savingChunk.value) return
  if (!form.gatilho.trim() || !form.conteudo.trim()) {
    chunkError.value = 'Preencha o gatilho e o conteúdo.'
    return
  }
  savingChunk.value = true
  chunkError.value = ''
  try {
    if (editingId.value === 0) {
      const c = await api<Chunk>('/api/memory/chunks', { method: 'POST', body: { ...form } })
      chunks.value.unshift(c)
    }
    else {
      const id = editingId.value!
      const c = await api<Chunk>(`/api/memory/chunks/${id}`, { method: 'PATCH', body: { ...form } })
      const i = chunks.value.findIndex(x => x.id === id)
      if (i !== -1) chunks.value[i] = c
    }
    editingId.value = null
  }
  catch (e: any) {
    const errs = e?.response?._data?.errors
    chunkError.value = errs ? Object.values(errs).flat().join(' ') : (e?.response?._data?.message || 'Erro ao salvar.')
  }
  finally { savingChunk.value = false }
}

async function load() {
  loading.value = true
  try {
    const r = await api<{ chunks: Chunk[], style: string, samples: { id: number, text: string }[], rules: { id: number, rule: string }[], teams: Team[] }>('/api/memory')
    chunks.value = r.chunks
    style.value = r.style
    samples.value = r.samples
    rules.value = r.rules
    teams.value = (r.teams || []).map(t => ({ ...t, objetivo: t.objetivo ?? '' }))
  }
  catch { /* */ }
  finally { loading.value = false }
}

async function saveStyle() {
  savingStyle.value = true
  styleSaved.value = false
  try {
    await api('/api/memory/style', { method: 'PUT', body: { summary: style.value } })
    styleSaved.value = true
    setTimeout(() => { styleSaved.value = false }, 2500)
  }
  catch { /* */ }
  finally { savingStyle.value = false }
}

async function delChunk(id: number) {
  if (!confirm('Excluir este conhecimento da memória?')) return
  chunks.value = chunks.value.filter(c => c.id !== id)
  try { await api(`/api/memory/chunks/${id}`, { method: 'DELETE' }) }
  catch { /* */ }
}

async function delRule(id: number) {
  rules.value = rules.value.filter(r => r.id !== id)
  try { await api(`/api/memory/rules/${id}`, { method: 'DELETE' }) }
  catch { /* */ }
}

// Salva o objetivo de um time (o que a IA persegue com os leads das etapas dele).
async function saveTeam(t: Team) {
  savingTeam.value = t.id
  savedTeam.value = null
  try {
    await api(`/api/chat-tabs/${t.id}`, { method: 'PATCH', body: { objetivo: t.objetivo } })
    savedTeam.value = t.id
    setTimeout(() => { if (savedTeam.value === t.id) savedTeam.value = null }, 2500)
  }
  catch { alert('Não consegui salvar o objetivo deste time.') }
  finally { savingTeam.value = null }
}

function teamName(id: number | null) {
  return id ? (teams.value.find(t => t.id === id)?.name || 'time removido') : 'Todos os times'
}

// Conhecimento visível: todos, ou só o do time filtrado (+ os que valem para todos).
const visibleChunks = computed(() => {
  if (teamFilter.value === 'all') return chunks.value
  return chunks.value.filter(c => c.chat_tab_id === teamFilter.value || c.chat_tab_id === null)
})

function kindColor(k: string) {
  return ({ preco: '#25D366', faq: '#53bdeb', objecao: '#ff6b6b', procedimento: '#ffb443', fato: '#a89bf9' } as Record<string, string>)[k] || '#8696a0'
}

onMounted(() => { load(); loadAi(); loadMaterials(); loadSettings() })
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--c-ai);display:flex;align-items:center;justify-content:center;font-size:17px;">🧠</div>
      <div>
        <div style="font-weight:800;font-size:15px;">Memória da IA</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Base de conhecimento + seu jeito de falar · usados para sugerir respostas</div>
      </div>
    </div>

    <div style="flex:1;display:flex;justify-content:center;padding:30px 24px 60px;">
      <div style="width:780px;max-width:100%;display:flex;flex-direction:column;gap:24px;">
        <div v-if="loading" style="color:var(--c-text-muted);font-size:14px;text-align:center;padding:40px;">Carregando…</div>

        <template v-else>
          <!-- credencial da IA (só o dono da plataforma vê) -->
          <div v-if="ai" :style="{ background: 'var(--c-bg)', border: `1px solid ${ai.no_ar ? 'var(--c-surface-1)' : 'rgba(255,77,77,.35)'}`, borderRadius: '16px', padding: '24px' }">
            <div role="button" tabindex="0" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;cursor:pointer;user-select:none;" @click="alternar('ai')" @keydown.enter="alternar('ai')">
              <svg :style="{ transform: aberto.ai ? 'rotate(90deg)' : 'none', transition: 'transform .15s', flexShrink: 0 }" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-faint)" stroke-width="2.5"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
              <span style="font-size:16px;font-weight:800;">Conexão da IA</span>
              <span :style="{ fontSize: '11px', fontWeight: 700, padding: '3px 10px', borderRadius: '20px', background: ai.no_ar ? 'rgba(37,211,102,.16)' : 'rgba(255,77,77,.14)', color: ai.no_ar ? 'var(--accent)' : 'var(--c-danger-soft)' }">
                {{ ai.no_ar ? 'no ar' : 'fora do ar' }}
              </span>
              <span style="font-size:11px;color:var(--c-text-faint);">token: {{ ai.origem === 'painel' ? 'salvo aqui' : (ai.origem === 'env' ? 'do .env' : 'nenhum') }}</span>
              <div style="flex:1;" />
              <button v-if="aberto.ai" :disabled="aiBusy" :style="{ background: 'var(--c-surface-2)', border: 'none', color: 'var(--c-text)', fontFamily: 'inherit', fontSize: '12.5px', fontWeight: 700, padding: '8px 14px', borderRadius: '9px', cursor: 'pointer', opacity: aiBusy ? 0.6 : 1 }" @click.stop="testAi">{{ aiBusy ? 'Testando…' : 'Testar agora' }}</button>
            </div>
            <div v-if="aberto.ai">
            <div style="font-size:12.5px;color:var(--c-text-muted);line-height:1.5;margin-top:9px;">
              É esta credencial que faz a IA responder os leads, sugerir mensagens, aprender a memória e escrever as campanhas. Quando ela cai, tudo isso para em silêncio — por isso o estado fica aqui.
            </div>

            <div v-if="!ai.no_ar && ai.motivo" style="margin-top:12px;background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.25);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-danger-soft);font-family:ui-monospace,monospace;white-space:pre-wrap;word-break:break-word;">{{ ai.motivo }}</div>

            <!-- login pela tela: dois passos, sem SSH -->
            <div style="margin-top:16px;border-top:1px solid var(--c-surface-1);padding-top:14px;">
              <template v-if="!aiLogin">
                <button :disabled="aiBusy" :style="{ width: '100%', background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '12px', borderRadius: '11px', cursor: aiBusy ? 'default' : 'pointer', opacity: aiBusy ? 0.7 : 1 }" @click="startAiLogin">
                  {{ aiBusy ? 'Abrindo…' : 'Conectar com o Claude' }}
                </button>
                <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:8px;text-align:center;">Abre o login da Anthropic e guarda o token aqui. Leva menos de um minuto.</div>
              </template>

              <template v-else>
                <div style="font-size:12.5px;color:var(--c-text-secondary);line-height:1.6;margin-bottom:10px;">
                  <b>1.</b> Abra este endereço, entre na sua conta e autorize. <b>2.</b> Copie o código que aparecer e cole abaixo.
                </div>
                <a :href="aiLogin.url" target="_blank" rel="noopener" style="display:block;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:10px 12px;font-size:12px;color:var(--accent);word-break:break-all;text-decoration:none;line-height:1.4;">{{ aiLogin.url }}</a>
                <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                  <input v-model="aiCode" placeholder="Cole aqui o código da Anthropic" autocomplete="off" style="flex:1;min-width:220px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:10px 12px;color:var(--c-text);font-family:ui-monospace,monospace;font-size:13px;outline:none;" @keydown.enter="finishAiLogin">
                  <button :disabled="aiBusy || !aiCode.trim()" :style="{ background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '10px 18px', borderRadius: '10px', cursor: (aiBusy || !aiCode.trim()) ? 'default' : 'pointer', opacity: (aiBusy || !aiCode.trim()) ? 0.6 : 1 }" @click="finishAiLogin">{{ aiBusy ? 'Conectando…' : 'Concluir' }}</button>
                  <button style="background:none;border:1px solid var(--c-surface-3);color:var(--c-text-muted);font-family:inherit;font-size:13px;padding:10px 14px;border-radius:10px;cursor:pointer;" @click="aiLogin = null">Cancelar</button>
                </div>
              </template>

              <div style="font-size:12px;color:var(--c-text-faint);line-height:1.6;margin-top:14px;padding-top:12px;border-top:1px solid var(--c-surface-1);">
                Prefere pelo terminal? Rode <code style="background:var(--c-surface-2);padding:2px 6px;border-radius:5px;">claude setup-token</code> onde já estiver logado e cole o token aqui:
              </div>
              <div style="display:flex;gap:8px;flex-wrap:wrap;">
                <input v-model="aiToken" type="password" placeholder="sk-ant-oat…" autocomplete="off" style="flex:1;min-width:220px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:10px 12px;color:var(--c-text);font-family:ui-monospace,monospace;font-size:13px;outline:none;" @keydown.enter="saveAiToken">
                <button :disabled="aiBusy || !aiToken.trim()" :style="{ background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '10px 18px', borderRadius: '10px', cursor: (aiBusy || !aiToken.trim()) ? 'default' : 'pointer', opacity: (aiBusy || !aiToken.trim()) ? 0.6 : 1 }" @click="saveAiToken">Salvar e conectar</button>
              </div>
              <div v-if="aiMsg" :style="{ marginTop: '10px', fontSize: '12.5px', lineHeight: 1.5, color: aiOk ? 'var(--accent)' : 'var(--c-danger-soft)', fontFamily: 'ui-monospace,monospace', whiteSpace: 'pre-wrap', wordBreak: 'break-word' }">{{ aiMsg }}</div>
            </div>
            </div>
          </div>

          <!-- perfil de voz -->
          <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
            <div role="button" tabindex="0" style="width:100%;cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none;" @click="alternar('voz')" @keydown.enter="alternar('voz')">
              <svg :style="{ transform: aberto.voz ? 'rotate(90deg)' : 'none', transition: 'transform .15s', flexShrink: 0 }" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-faint)" stroke-width="2.5"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
              <span style="font-size:16px;font-weight:800;">Seu jeito de falar (voz)</span>
              <span style="flex:1;" />
              <button v-if="aberto.voz" :disabled="savingStyle" :style="{ background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '9px 16px', borderRadius: '10px', cursor: 'pointer', opacity: savingStyle ? 0.7 : 1 }" @click.stop="saveStyle">{{ savingStyle ? 'Salvando…' : (styleSaved ? 'Salvo ✓' : 'Salvar') }}</button>
            </div>
            <div v-if="aberto.voz">
            <div style="font-size:12.5px;color:var(--c-text-muted);margin-top:9px;">Refinado a cada conversa adicionada. Pode editar à mão.</div>
            <textarea v-model="style" rows="8" placeholder="Ainda vazio — adicione conversas à memória pra começar." style="width:100%;margin-top:14px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:12px 13px;color:var(--c-text);font-family:inherit;font-size:13px;line-height:1.55;outline:none;resize:vertical;" />
            <div v-if="samples.length" style="margin-top:14px;">
              <div style="font-size:11px;font-weight:700;color:var(--c-text-muted);letter-spacing:.4px;margin-bottom:8px;">EXEMPLOS DE MENSAGENS SUAS ({{ samples.length }})</div>
              <div style="display:flex;flex-direction:column;gap:6px;">
                <div v-for="s in samples.slice(0, 6)" :key="s.id" style="font-size:12.5px;color:var(--c-text-secondary);background:var(--c-surface-2);border-radius:8px;padding:8px 11px;">“{{ s.text }}”</div>
              </div>
            </div>
            </div>
          </div>

          <!-- regras de resposta -->
          <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
            <div role="button" tabindex="0" style="width:100%;cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none;" @click="alternar('regras')" @keydown.enter="alternar('regras')">
              <svg :style="{ transform: aberto.regras ? 'rotate(90deg)' : 'none', transition: 'transform .15s', flexShrink: 0 }" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-faint)" stroke-width="2.5"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
              <span style="font-size:16px;font-weight:800;">Regras de resposta</span>
              <span style="font-size:12px;font-weight:700;color:var(--c-text-faint);">{{ rules.length }}</span>
              <span style="flex:1;" />
            </div>
            <div v-if="aberto.regras">
            <div style="font-size:12.5px;color:var(--c-text-muted);margin:9px 0 16px;">Correções que você salvou — a IA segue todas ao sugerir. Crie pelo botão "Salvar correção" no chat.</div>
            <div v-if="!rules.length" style="font-size:13px;color:var(--c-text-faint);">Nenhuma regra ainda.</div>
            <div v-else style="display:flex;flex-direction:column;gap:8px;">
              <div v-for="r in rules" :key="r.id" style="background:var(--c-surface-2);border-radius:10px;padding:11px 14px;display:flex;gap:12px;align-items:center;">
                <span style="color:var(--accent-soft);flex-shrink:0;">✓</span>
                <div style="flex:1;font-size:13px;">{{ r.rule }}</div>
                <button title="Excluir" class="delk" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;flex-shrink:0;display:flex;" @click="delRule(r.id)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              </div>
            </div>
            </div>
          </div>

          <!-- atendimento automático de leads novos (veio da tela de Automações) -->
          <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:22px;display:flex;align-items:center;gap:14px;flex-wrap:wrap;">
            <div style="flex:1;min-width:200px;">
              <div style="font-size:15px;font-weight:800;">IA atende os leads novos sozinha</div>
              <div style="font-size:12.5px;color:var(--c-text-muted);margin-top:3px;line-height:1.5;">
                Conversa nova que chega no número principal já nasce com o atendimento automático ligado. Prospecção continua com humano.
              </div>
            </div>
            <button
              :disabled="savingSetting"
              :style="{ width: '52px', height: '30px', borderRadius: '20px', border: 'none', cursor: savingSetting ? 'default' : 'pointer', position: 'relative', flexShrink: 0, background: autoReplyNewLeads ? 'var(--accent)' : 'var(--c-surface-3)', opacity: savingSetting ? 0.6 : 1, transition: 'background .15s' }"
              @click="toggleAutoReplyNewLeads"
            >
              <span :style="{ position: 'absolute', top: '3px', left: autoReplyNewLeads ? '25px' : '3px', width: '24px', height: '24px', borderRadius: '50%', background: 'var(--c-on-accent)', transition: 'left .15s' }" />
            </button>
          </div>

          <!-- materiais que a IA envia -->
          <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
            <div role="button" tabindex="0" style="width:100%;cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none;" @click="alternar('materiais')" @keydown.enter="alternar('materiais')">
              <svg :style="{ transform: aberto.materiais ? 'rotate(90deg)' : 'none', transition: 'transform .15s', flexShrink: 0 }" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-faint)" stroke-width="2.5"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
              <span style="font-size:16px;font-weight:800;">Materiais da IA</span>
              <span style="font-size:12px;font-weight:700;color:var(--c-text-faint);">{{ materials.length }}</span>
              <span style="flex:1;" />
            </div>
            <div v-if="aberto.materiais">
            <div style="font-size:12.5px;color:var(--c-text-muted);margin:9px 0 16px;line-height:1.5;">
              Arquivos que a IA pode mandar para o lead (apresentação, tabela de taxas, contrato). Ela lê o campo <b>quando enviar</b> e decide na hora se aquele material ajuda — você não precisa amarrar a uma etapa.
            </div>

            <div style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px;margin-bottom:14px;">
              <div style="display:flex;gap:10px;flex-wrap:wrap;">
                <input ref="matInput" type="file" accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.png,.jpg,.jpeg" style="flex:1;min-width:220px;font-size:12.5px;color:var(--c-text-muted);font-family:inherit;" @change="pickMaterial">
                <select v-model="matForm.chat_tab_id" title="Quem pode enviar" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;cursor:pointer;">
                  <option :value="null">Todos os times</option>
                  <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
              </div>
              <input v-model="matForm.name" placeholder="Nome do material (ex.: Apresentação institucional)" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
              <textarea v-model="matForm.quando" rows="2" placeholder="Quando enviar — ex.: quando o lead pedir material, ou quando perguntar sobre taxas antes da reunião" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:10px 11px;color:var(--c-text);font-family:inherit;font-size:13px;line-height:1.5;outline:none;resize:vertical;" />
              <div v-if="matError" style="color:var(--c-danger);font-size:12px;">{{ matError }}</div>
              <div style="display:flex;justify-content:flex-end;">
                <button :disabled="matSaving || !matFile || !matForm.name.trim()" :style="{ background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '9px 16px', borderRadius: '9px', cursor: (matSaving || !matFile || !matForm.name.trim()) ? 'default' : 'pointer', opacity: (matSaving || !matFile || !matForm.name.trim()) ? 0.6 : 1 }" @click="saveMaterial">{{ matSaving ? 'Enviando…' : 'Adicionar material' }}</button>
              </div>
            </div>

            <div v-if="!materials.length" style="font-size:13px;color:var(--c-text-faint);">Nenhum material ainda.</div>
            <div v-else style="display:flex;flex-direction:column;gap:9px;">
              <div v-for="m in materials" :key="m.id" style="background:var(--c-surface-2);border-radius:12px;padding:12px 14px;display:flex;gap:12px;align-items:flex-start;">
                <span style="font-size:19px;flex-shrink:0;line-height:1.2;">📄</span>
                <div style="flex:1;min-width:0;">
                  <div style="font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                    {{ m.name }}
                    <span :style="{ fontSize: '10px', fontWeight: 700, padding: '2px 7px', borderRadius: '20px', background: m.chat_tab_id ? 'rgba(124,108,245,.18)' : 'var(--c-bg-deep)', color: m.chat_tab_id ? 'var(--c-ai-soft)' : 'var(--c-text-faint)' }">{{ teamName(m.chat_tab_id) }}</span>
                  </div>
                  <div v-if="m.quando" style="font-size:12.5px;color:var(--c-text-secondary);margin-top:3px;line-height:1.45;">Enviar quando: {{ m.quando }}</div>
                  <div style="font-size:11px;color:var(--c-text-faint);margin-top:4px;">{{ m.filename }} · {{ fmtSize(m.size) }}</div>
                </div>
                <button title="Excluir" class="delk" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;flex-shrink:0;display:flex;" @click="delMaterial(m)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              </div>
            </div>
            </div>
          </div>

          <!-- objetivo por time (SDR / Closer / CS) -->
          <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
            <div role="button" tabindex="0" style="width:100%;cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none;" @click="alternar('times')" @keydown.enter="alternar('times')">
              <svg :style="{ transform: aberto.times ? 'rotate(90deg)' : 'none', transition: 'transform .15s', flexShrink: 0 }" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-faint)" stroke-width="2.5"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
              <span style="font-size:16px;font-weight:800;">Objetivo por time</span>
              <span style="font-size:12px;font-weight:700;color:var(--c-text-faint);">{{ teams.length }}</span>
              <span style="flex:1;" />
            </div>
            <div v-if="aberto.times">
            <div style="font-size:12.5px;color:var(--c-text-muted);margin:9px 0 16px;">
              Cada time é uma tab do chat e cobre etapas do funil. A IA lê o objetivo do time em que o lead está — e só o conhecimento daquele time (mais o que vale para todos).
            </div>
            <div v-if="!teams.length" style="font-size:13px;color:var(--c-text-faint);">Nenhum time ainda. Crie as tabs no chat (⋮ → Tabs por etiqueta).</div>
            <div v-else style="display:flex;flex-direction:column;gap:14px;">
              <div v-for="t in teams" :key="t.id" style="background:var(--c-surface-2);border-radius:12px;padding:14px;">
                <div style="display:flex;align-items:center;gap:9px;flex-wrap:wrap;margin-bottom:9px;">
                  <span style="font-size:13.5px;font-weight:800;">{{ t.name }}</span>
                  <span style="font-size:11px;color:var(--c-text-faint);flex:1;min-width:120px;">etapas: {{ (t.stages || []).join(', ') || '— nenhuma' }}</span>
                  <button :disabled="savingTeam === t.id" :style="{ background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '12.5px', fontWeight: 700, padding: '7px 14px', borderRadius: '9px', cursor: 'pointer', opacity: savingTeam === t.id ? 0.7 : 1 }" @click="saveTeam(t)">
                    {{ savingTeam === t.id ? 'Salvando…' : (savedTeam === t.id ? 'Salvo ✓' : 'Salvar') }}
                  </button>
                </div>
                <textarea v-model="t.objetivo" rows="5" placeholder="O que a IA deve fazer com os leads deste time (missão, tom, o que buscar, o que NÃO fazer)." style="width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:10px;padding:11px 12px;color:var(--c-text);font-family:inherit;font-size:13px;line-height:1.55;outline:none;resize:vertical;box-sizing:border-box;" />
              </div>
            </div>
            </div>
          </div>

          <!-- base de conhecimento -->
          <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
            <div role="button" tabindex="0" style="width:100%;cursor:pointer;display:flex;align-items:center;gap:10px;user-select:none;" @click="alternar('base')" @keydown.enter="alternar('base')">
              <svg :style="{ transform: aberto.base ? 'rotate(90deg)' : 'none', transition: 'transform .15s', flexShrink: 0 }" width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-faint)" stroke-width="2.5"><path d="m9 6 6 6-6 6" stroke-linecap="round" stroke-linejoin="round" /></svg>
              <span style="font-size:16px;font-weight:800;">Base de conhecimento</span>
              <span style="font-size:12px;font-weight:700;color:var(--c-text-faint);">{{ visibleChunks.length }}</span>
              <span style="flex:1;" />
              <button v-if="aberto.base && editingId !== 0" style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:13px;font-weight:700;padding:9px 14px;border-radius:10px;cursor:pointer;flex-shrink:0;white-space:nowrap;" @click.stop="startAdd">+ Adicionar</button>
            </div>
            <div v-if="aberto.base">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin:9px 0 16px;">
              <div>
                <div style="font-size:12.5px;color:var(--c-text-muted);">O que a IA "sabe" para responder os clientes. Adicione, edite ou exclua.</div>
                <div v-if="teams.length" style="display:flex;gap:6px;flex-wrap:wrap;margin-top:11px;">
                  <button :style="{ fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '20px', border: 'none', cursor: 'pointer', fontFamily: 'inherit', background: teamFilter === 'all' ? 'var(--c-ai)' : 'var(--c-surface-2)', color: teamFilter === 'all' ? 'var(--c-on-accent)' : 'var(--c-text-muted)' }" @click="teamFilter = 'all'">Tudo</button>
                  <button v-for="t in teams" :key="t.id" :style="{ fontSize: '11.5px', fontWeight: 700, padding: '5px 11px', borderRadius: '20px', border: 'none', cursor: 'pointer', fontFamily: 'inherit', background: teamFilter === t.id ? 'var(--c-ai)' : 'var(--c-surface-2)', color: teamFilter === t.id ? 'var(--c-on-accent)' : 'var(--c-text-muted)' }" @click="teamFilter = t.id">{{ t.name }}</button>
                </div>
              </div>
            </div>

            <!-- formulário de novo conhecimento -->
            <div v-if="editingId === 0" style="margin-bottom:12px;">
              <div style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                  <select v-model="form.kind" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;cursor:pointer;">
                    <option v-for="k in kinds" :key="k" :value="k">{{ k }}</option>
                  </select>
                  <select v-model="form.chat_tab_id" title="Time que usa este conhecimento" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;cursor:pointer;">
                    <option :value="null">Todos os times</option>
                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                  </select>
                  <input v-model="form.gatilho" placeholder="Gatilho — tema ou pergunta curta" style="flex:1;min-width:200px;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
                </div>
                <textarea v-model="form.conteudo" rows="3" placeholder="Conteúdo — a resposta em 1 a 3 frases" style="width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:10px 11px;color:var(--c-text);font-family:inherit;font-size:13px;line-height:1.5;outline:none;resize:vertical;" />
                <input v-model="form.keywords" placeholder="Palavras-chave separadas por vírgula (opcional)" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
                <div v-if="chunkError" style="color:var(--c-danger);font-size:12px;">{{ chunkError }}</div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                  <button style="background:none;border:1px solid var(--c-surface-3);color:var(--c-text-secondary);font-family:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px;cursor:pointer;" @click="cancelEdit">Cancelar</button>
                  <button :disabled="savingChunk" :style="{ background: 'var(--accent)', border: 'none', color: 'var(--c-accent-surf)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '8px 16px', borderRadius: '9px', cursor: 'pointer', opacity: savingChunk ? 0.7 : 1 }" @click="saveChunk">{{ savingChunk ? 'Salvando…' : 'Salvar' }}</button>
                </div>
              </div>
            </div>

            <div v-if="!visibleChunks.length && editingId !== 0" style="font-size:13px;color:var(--c-text-faint);padding:14px 0;">Nada ainda. Clique em "+ Adicionar" ou vá no chat → menu ⋮ da conversa → "Adicionar à memória".</div>
            <div v-else-if="visibleChunks.length" style="display:flex;flex-direction:column;gap:10px;">
              <template v-for="c in visibleChunks" :key="c.id">
                <!-- formulário de edição -->
                <div v-if="editingId === c.id" style="background:var(--c-surface-2);border:1px solid var(--c-ai);border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px;">
                  <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <select v-model="form.kind" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;cursor:pointer;">
                      <option v-for="k in kinds" :key="k" :value="k">{{ k }}</option>
                    </select>
                    <select v-model="form.chat_tab_id" title="Time que usa este conhecimento" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;cursor:pointer;">
                    <option :value="null">Todos os times</option>
                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                  </select>
                  <input v-model="form.gatilho" placeholder="Gatilho — tema ou pergunta curta" style="flex:1;min-width:200px;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
                  </div>
                  <textarea v-model="form.conteudo" rows="3" placeholder="Conteúdo — a resposta em 1 a 3 frases" style="width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:10px 11px;color:var(--c-text);font-family:inherit;font-size:13px;line-height:1.5;outline:none;resize:vertical;" />
                  <input v-model="form.keywords" placeholder="Palavras-chave separadas por vírgula (opcional)" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
                  <div v-if="chunkError" style="color:var(--c-danger);font-size:12px;">{{ chunkError }}</div>
                  <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button style="background:none;border:1px solid var(--c-surface-3);color:var(--c-text-secondary);font-family:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px;cursor:pointer;" @click="cancelEdit">Cancelar</button>
                    <button :disabled="savingChunk" :style="{ background: 'var(--accent)', border: 'none', color: 'var(--c-accent-surf)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '8px 16px', borderRadius: '9px', cursor: 'pointer', opacity: savingChunk ? 0.7 : 1 }" @click="saveChunk">{{ savingChunk ? 'Salvando…' : 'Salvar' }}</button>
                  </div>
                </div>
                <!-- card normal -->
                <div v-else style="background:var(--c-surface-2);border-radius:12px;padding:13px 15px;display:flex;gap:12px;align-items:flex-start;">
                  <span :style="{ fontSize: '10px', fontWeight: 700, color: kindColor(c.kind), background: `${kindColor(c.kind)}22`, padding: '3px 8px', borderRadius: '6px', flexShrink: 0, marginTop: '2px' }">{{ c.kind }}</span>
                  <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;font-weight:700;display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                      {{ c.gatilho }}
                      <span :style="{ fontSize: '10px', fontWeight: 700, padding: '2px 7px', borderRadius: '20px', background: c.chat_tab_id ? 'rgba(124,108,245,.18)' : 'var(--c-bg-deep)', color: c.chat_tab_id ? 'var(--c-ai-soft)' : 'var(--c-text-faint)' }">{{ teamName(c.chat_tab_id) }}</span>
                    </div>
                    <div style="font-size:13px;color:var(--c-text-secondary);margin-top:3px;line-height:1.45;">{{ c.conteudo }}</div>
                    <div v-if="c.keywords" style="font-size:11px;color:var(--c-text-faint);margin-top:5px;">{{ c.keywords }}</div>
                  </div>
                  <button title="Editar" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;flex-shrink:0;display:flex;" class="editk" @click="startEdit(c)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                  <button title="Excluir" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;flex-shrink:0;display:flex;" class="delk" @click="delChunk(c.id)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                </div>
              </template>
            </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.delk:hover { color: var(--c-danger) !important; }
.editk:hover { color: var(--c-ai) !important; }
</style>
