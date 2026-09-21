<script setup lang="ts">
interface Account { id: number, name: string, role: string, state: string, provider?: 'evolution' | 'cloud' }
interface Campaign {
  id: number
  name: string
  status: 'draft' | 'running' | 'paused' | 'done'
  objective: string | null
  wa_account_id: number
  min_gap_s: number
  max_gap_s: number
  daily_cap: number
  window_start: string
  window_end: string
  total: number
  sent: number
  replied: number
  failed: number
  pending?: number
  sent_today?: number
  restante_hoje?: number
  motivo?: string | null
  starts_at?: string | null
  template_name?: string | null
  account?: { id: number, name: string, role: string, provider?: string, state?: string }
}

const api = useApi()

const campaigns = ref<Campaign[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)

// ---- Nova campanha ----
const open = ref(false)
const form = ref({ name: '', wa_account_id: 0, objective: '', daily_cap: 40, min_gap_s: 60, max_gap_s: 180, window_start: '09:00', window_end: '18:00', template_name: '', template_params: [] as string[], starts_at: '' })
const saving = ref(false)

// Números de prospecção dos DOIS canais. O que muda é COMO se dispara: na Evolution a
// IA escreve por contato; no oficial só sai template aprovado (o contato nunca escreveu,
// então a janela de 24h está fechada).
// O principal da Evolution fica de fora (disparo no Baileys arrisca o número que
// atende os anúncios). No canal oficial não há esse risco: template aprovado é o
// caminho da própria Meta, e quem tem um número só precisa poder usá-lo.
const outreach = computed(() => accounts.value.filter(a => a.provider === 'cloud' || a.role === 'outreach'))
const contaEscolhida = computed(() => accounts.value.find(a => a.id === form.value.wa_account_id))
const ehOficial = computed(() => contaEscolhida.value?.provider === 'cloud')

// ---- Templates aprovados (só canal oficial) ----
interface Template { name: string, language: string, category: string, body: string, params: number, status: string }
const templates = ref<Template[]>([])
const templatesCarregando = ref(false)
const templateErro = ref('')

async function carregarTemplates(accountId: number) {
  templates.value = []
  templateErro.value = ''
  if (!accountId) return
  templatesCarregando.value = true
  try {
    const r = await api<{ templates: Template[] }>(`/api/wpp/cloud/accounts/${accountId}/templates`)
    templates.value = r.templates || []
    if (!templates.value.length) templateErro.value = 'Nenhum template nesta conta. Crie um no Gerenciador da Meta.'
    else if (!templates.value.some(t => t.status === 'APPROVED')) templateErro.value = 'Seus templates ainda estão em análise na Meta. Assim que um for aprovado, ele aparece aqui.'
  }
  catch { templateErro.value = 'Não consegui carregar os templates.' }
  finally { templatesCarregando.value = false }
}

const templateEscolhido = computed(() => templates.value.find(t => t.name === form.value.template_name))

// Ao trocar o número: se for oficial, busca os templates; se não, limpa o que estava escolhido.
watch(() => form.value.wa_account_id, (id) => {
  form.value.template_name = ''
  form.value.template_params = []
  if (accounts.value.find(a => a.id === id)?.provider === 'cloud') carregarTemplates(id)
  else templates.value = []
})
watch(templateEscolhido, (t) => {
  form.value.template_params = Array.from({ length: t?.params || 0 }, (_, i) => form.value.template_params[i] || '')
})

// ---- Listas de contatos ----
interface Lista { id: number, name: string, kind: string, contacts_count: number }
const listas = ref<Lista[]>([])
const listasEscolhidas = ref<number[]>([])
const carregandoListas = ref<number | null>(null)

function alternarLista(id: number) {
  const i = listasEscolhidas.value.indexOf(id)
  if (i >= 0) listasEscolhidas.value.splice(i, 1)
  else listasEscolhidas.value.push(id)
}
async function usarListas(c: Campaign) {
  if (!listasEscolhidas.value.length || carregandoListas.value) return
  carregandoListas.value = c.id
  try {
    const r = await api<{ added: number, skipped: number, total: number }>(`/api/campaigns/${c.id}/contacts/from-lists`, {
      method: 'POST',
      body: { list_ids: listasEscolhidas.value },
    })
    alert(`${r.added} contatos adicionados${r.skipped ? ` · ${r.skipped} já estavam na campanha` : ''}.`)
    listasEscolhidas.value = []
    await load()
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao carregar as listas.') }
  finally { carregandoListas.value = null }
}

async function load() {
  try {
    const [c, a] = await Promise.all([
      api<{ campaigns: Campaign[] }>('/api/campaigns'),
      api<{ accounts: Account[] }>('/api/wpp/accounts'),
    ])
    campaigns.value = c.campaigns
    accounts.value = a.accounts
    try { listas.value = (await api<{ lists: Lista[] }>('/api/contact-lists')).lists }
    catch { /* listas são opcionais: dá para importar CSV direto na campanha */ }
  }
  catch { /* */ }
  finally { loading.value = false }
}

async function create() {
  if (!form.value.name.trim() || !form.value.wa_account_id || saving.value) return
  saving.value = true
  try {
    const nova = await api<Campaign>('/api/campaigns', {
      method: 'POST',
      body: { ...form.value, template_language: templateEscolhido.value?.language, template_body: templateEscolhido.value?.body },
    })
    // Listas marcadas no formulário já entram na campanha recém-criada.
    if (listasEscolhidas.value.length) {
      await api(`/api/campaigns/${nova.id}/contacts/from-lists`, { method: 'POST', body: { list_ids: listasEscolhidas.value } })
      listasEscolhidas.value = []
    }
    open.value = false
    form.value = { name: '', wa_account_id: 0, objective: '', daily_cap: 40, min_gap_s: 60, max_gap_s: 180, window_start: '09:00', window_end: '18:00', template_name: '', template_params: [], starts_at: '' }
    await load()
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao criar campanha.') }
  finally { saving.value = false }
}

async function escolherTemplateDaCampanha(c: Campaign, nome: string) {
  const t = templates.value.find(x => x.name === nome)
  if (!t) return
  try {
    await api(`/api/campaigns/${c.id}`, {
      method: 'PATCH',
      body: {
        template_name: t.name,
        template_language: t.language,
        template_body: t.body,
        // Um valor por {{n}}; o padrão cobre o caso comum (a 1ª variável é o nome).
        template_params: Array.from({ length: t.params }, (_, i) => (i === 0 ? '{nome}' : '-')),
      },
    })
    await load()
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao salvar o template.') }
}

async function setStatus(c: Campaign, status: string) {
  try {
    await api(`/api/campaigns/${c.id}`, { method: 'PATCH', body: { status } })
    await load()
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao atualizar.') }
}
async function remove(c: Campaign) {
  if (!confirm(`Excluir a campanha "${c.name}" e todos os contatos dela?`)) return
  try { await api(`/api/campaigns/${c.id}`, { method: 'DELETE' }); await load() }
  catch { /* */ }
}

// ---- Upload de CSV ----
const uploadingId = ref<number | null>(null)
const uploadMsg = ref<Record<number, string>>({})
async function uploadCsv(c: Campaign, e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  uploadingId.value = c.id
  uploadMsg.value[c.id] = 'Enviando…'
  try {
    const fd = new FormData()
    fd.append('file', file)
    const r = await api<{ added: number, skipped: number, total: number }>(`/api/campaigns/${c.id}/contacts`, { method: 'POST', body: fd })
    uploadMsg.value[c.id] = `${r.added} adicionados · ${r.skipped} ignorados · ${r.total} no total`
    await load()
  }
  catch (e: any) { uploadMsg.value[c.id] = e?.response?._data?.message || 'Falha ao importar CSV.' }
  finally { uploadingId.value = null; input.value = '' }
}

function statusPill(s: string) {
  const map: Record<string, [string, string]> = {
    draft: ['Rascunho', 'var(--c-text-muted)'],
    running: ['Disparando', 'var(--accent)'],
    paused: ['Pausada', 'var(--c-warn)'],
    done: ['Concluída', 'var(--c-ai)'],
  }
  return map[s] || ['—', 'var(--c-text-muted)']
}
function progress(c: Campaign) {
  return c.total > 0 ? Math.round((c.sent / c.total) * 100) : 0
}
function taxaResposta(c: Campaign) {
  return c.sent > 0 ? Math.round((c.replied / c.sent) * 100) : 0
}
function dataHora(iso?: string | null) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' })
}
function abrirNova() {
  open.value = true
  listasEscolhidas.value = []
}

let timer: ReturnType<typeof setInterval> | null = null
onMounted(() => { load(); timer = setInterval(load, 5000) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <!-- topo -->
    <div class="r-wrap" style="padding:16px clamp(12px,4vw,30px);border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--c-ai);display:flex;align-items:center;justify-content:center;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--c-on-accent)" stroke-width="2"><path d="M3 11v2a1 1 0 0 0 1 1h2l3.5 3.5V7.5L6 11H4Z" stroke-linecap="round" stroke-linejoin="round" /><path d="m9.5 7.5 9-4v17l-9-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </div>
      <div style="flex:1;">
        <div style="font-weight:800;font-size:15px;">Campanhas</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Disparo para listas de contatos · somente administradores</div>
      </div>
      <button style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;flex-shrink:0;" @click="abrirNova">+ Nova campanha</button>
    </div>

    <div style="flex:1;padding:22px clamp(12px,4vw,30px) 40px;">
      <div v-if="loading" style="color:var(--c-text-muted);font-size:14px;text-align:center;padding:40px;">Carregando…</div>

      <template v-else>
        <div v-if="!outreach.length" style="background:rgba(255,180,67,.1);border:1px solid rgba(255,180,67,.3);border-radius:14px;padding:16px;font-size:13px;color:var(--c-warn-soft);line-height:1.5;margin-bottom:18px;">
          Você ainda não tem número disponível para disparo. Vá em <b>WhatsApp</b> e conecte um número antes de criar campanhas.
        </div>

        <div v-if="!campaigns.length" style="text-align:center;color:var(--c-text-muted);font-size:13.5px;padding:50px 20px;">
          <div style="font-size:34px;margin-bottom:10px;">📣</div>
          Nenhuma campanha ainda. Crie uma e escolha as listas de contatos.
        </div>

        <!-- cards -->
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(360px,100%),1fr));gap:16px;">
          <div v-for="c in campaigns" :key="c.id" style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:18px;display:flex;flex-direction:column;gap:12px;">
            <!-- cabeçalho -->
            <div style="display:flex;align-items:flex-start;gap:8px;">
              <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;">
                  <span style="font-weight:800;font-size:15px;">{{ c.name }}</span>
                  <span :style="{ fontSize: '10.5px', fontWeight: 700, padding: '2px 9px', borderRadius: '20px', background: statusPill(c.status)[1] + '22', color: statusPill(c.status)[1] }">{{ statusPill(c.status)[0] }}</span>
                </div>
                <div style="font-size:11.5px;color:var(--c-text-muted);margin-top:3px;">
                  {{ c.account?.name || '—' }} · {{ c.account?.provider === 'cloud' ? 'API oficial' : 'Evolution' }}
                  <template v-if="c.account?.provider !== 'cloud'"> · {{ c.window_start }}–{{ c.window_end }} · {{ c.daily_cap }}/dia</template>
                  <template v-else-if="c.starts_at"> · começa {{ dataHora(c.starts_at) }}</template>
                </div>
              </div>
              <button title="Excluir" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:15px;flex-shrink:0;padding:2px 4px;" @click="remove(c)">✕</button>
            </div>

            <div v-if="c.motivo" style="background:rgba(255,180,67,.1);border:1px solid rgba(255,180,67,.28);border-radius:10px;padding:8px 11px;font-size:11.5px;color:var(--c-warn-soft);">
              ⏸ Parada agora: <b>{{ c.motivo }}</b>
            </div>

            <!-- progresso -->
            <div>
              <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--c-text-faint);margin-bottom:5px;">
                <span>{{ progress(c) }}% da lista</span><span>{{ c.sent }}/{{ c.total }}</span>
              </div>
              <div style="height:7px;background:var(--c-surface-2);border-radius:6px;overflow:hidden;">
                <div :style="{ width: progress(c) + '%', height: '100%', background: 'var(--accent)', transition: 'width .4s' }" />
              </div>
            </div>

            <!-- métricas -->
            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:7px;">
              <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
                <div style="font-size:16px;font-weight:800;letter-spacing:-.5px;">{{ c.total }}</div>
                <div style="font-size:10px;color:var(--c-text-faint);">contatos</div>
              </div>
              <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
                <div style="font-size:16px;font-weight:800;letter-spacing:-.5px;color:var(--accent);">{{ c.sent }}</div>
                <div style="font-size:10px;color:var(--c-text-faint);">enviados</div>
              </div>
              <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
                <div style="font-size:16px;font-weight:800;letter-spacing:-.5px;color:var(--c-ai-soft);">{{ c.replied }}</div>
                <div style="font-size:10px;color:var(--c-text-faint);">responderam<template v-if="c.sent"> · {{ taxaResposta(c) }}%</template></div>
              </div>
              <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
                <div style="font-size:16px;font-weight:800;letter-spacing:-.5px;">{{ c.pending ?? 0 }}</div>
                <div style="font-size:10px;color:var(--c-text-faint);">na fila</div>
              </div>
              <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
                <div :style="{ fontSize:'16px',fontWeight:800,letterSpacing:'-.5px', color: c.failed ? 'var(--c-danger-soft)' : 'var(--c-text)' }">{{ c.failed }}</div>
                <div style="font-size:10px;color:var(--c-text-faint);">falhas</div>
              </div>
              <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
                <div style="font-size:16px;font-weight:800;letter-spacing:-.5px;">{{ c.sent_today ?? 0 }}</div>
                <div style="font-size:10px;color:var(--c-text-faint);">hoje</div>
              </div>
            </div>

            <!-- mensagem que sai -->
            <div v-if="c.account?.provider === 'cloud'">
              <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:6px;">
                Template: <b :style="{ color: c.template_name ? 'var(--c-text-secondary)' : 'var(--c-danger-soft)' }">{{ c.template_name || 'nenhum escolhido' }}</b>
              </div>
              <select :value="c.template_name || ''" style="width:100%;box-sizing:border-box;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:12px;outline:none;" @focus="carregarTemplates(c.wa_account_id)" @change="(e:any) => escolherTemplateDaCampanha(c, e.target.value)">
                <option value="">Escolher template…</option>
                <option v-for="t in templates" :key="t.name" :value="t.name" :disabled="t.status !== 'APPROVED'">
                  {{ t.status === 'APPROVED' ? '' : '⏳ ' }}{{ t.name }} ({{ t.language }}){{ t.status === 'APPROVED' ? '' : ' — em análise' }}
                </option>
              </select>
            </div>

            <!-- contatos -->
            <div style="border-top:1px solid var(--c-surface-1);padding-top:11px;">
              <div v-if="listas.length" style="display:flex;gap:5px;flex-wrap:wrap;margin-bottom:9px;">
                <button
                  v-for="l in listas" :key="l.id"
                  :style="{ fontSize:'11px',fontWeight:700,padding:'5px 10px',borderRadius:'20px',border:'1px solid',cursor:'pointer',fontFamily:'inherit',
                            background: listasEscolhidas.includes(l.id) ? 'var(--c-ai)' : 'transparent',
                            borderColor: listasEscolhidas.includes(l.id) ? 'var(--c-ai)' : 'var(--c-surface-3)',
                            color: listasEscolhidas.includes(l.id) ? 'var(--c-on-accent)' : 'var(--c-text-muted)' }"
                  @click="alternarLista(l.id)"
                >{{ l.name }} · {{ l.contacts_count }}</button>
              </div>
              <div style="display:flex;gap:7px;flex-wrap:wrap;align-items:center;">
                <button v-if="listas.length" :disabled="!listasEscolhidas.length || carregandoListas === c.id" :style="{ background:'var(--c-surface-2)',border:'none',color:'var(--c-text)',fontFamily:'inherit',fontSize:'12px',fontWeight:700,padding:'7px 12px',borderRadius:'9px', cursor:(!listasEscolhidas.length || carregandoListas === c.id)?'default':'pointer', opacity:(!listasEscolhidas.length || carregandoListas === c.id)?0.5:1 }" @click="usarListas(c)">
                  {{ carregandoListas === c.id ? 'Carregando…' : '+ Carregar listas' }}
                </button>
                <label :style="{ background: 'var(--c-surface-2)', color: 'var(--c-text)', fontSize: '12px', fontWeight: 700, padding: '7px 12px', borderRadius: '9px', cursor: 'pointer' }">
                  {{ uploadingId === c.id ? 'Enviando…' : '⬆ CSV' }}
                  <input type="file" accept=".csv,text/csv,text/plain" style="display:none;" :disabled="uploadingId === c.id" @change="(e) => uploadCsv(c, e)">
                </label>
              </div>
              <div v-if="uploadMsg[c.id]" style="font-size:11.5px;color:var(--c-ai-soft);margin-top:7px;">{{ uploadMsg[c.id] }}</div>
            </div>

            <!-- ações -->
            <div style="display:flex;gap:7px;">
              <button v-if="c.status === 'draft' || c.status === 'paused'" style="flex:1;background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:12.5px;font-weight:700;padding:9px;border-radius:9px;cursor:pointer;" @click="setStatus(c, 'running')">▶ Iniciar</button>
              <button v-if="c.status === 'running'" style="flex:1;background:rgba(255,180,67,.14);border:1px solid rgba(255,180,67,.3);color:var(--c-warn);font-family:inherit;font-size:12.5px;font-weight:700;padding:9px;border-radius:9px;cursor:pointer;" @click="setStatus(c, 'paused')">⏸ Pausar</button>
              <button v-if="c.status === 'done'" disabled style="flex:1;background:var(--c-surface-2);border:none;color:var(--c-text-faint);font-family:inherit;font-size:12.5px;font-weight:700;padding:9px;border-radius:9px;">Concluída</button>
            </div>
          </div>
        </div>
      </template>
    </div>

    <!-- ============ POPUP: nova campanha ============ -->
    <div v-if="open" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:60;padding:24px;" @click.self="open = false">
      <div style="width:520px;max-width:100%;max-height:88vh;overflow-y:auto;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <div style="font-size:18px;font-weight:800;">Nova campanha</div>
          <button style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;font-size:20px;line-height:1;" @click="open = false">×</button>
        </div>

        <input v-model="form.name" placeholder="Nome da campanha (ex.: Leads de anúncio — julho)" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:11px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;">

        <div style="margin-top:12px;font-size:12.5px;font-weight:700;color:var(--c-text-secondary);margin-bottom:6px;">Número que vai disparar</div>
        <select v-model.number="form.wa_account_id" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:11px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;">
          <option :value="0" disabled>Selecione um número…</option>
          <option v-for="a in outreach" :key="a.id" :value="a.id">{{ a.name }} · {{ a.provider === 'cloud' ? 'API oficial' : 'Evolution' }} {{ a.state === 'open' ? '(conectado)' : '(desconectado)' }}</option>
        </select>

        <!-- CANAL OFICIAL: template + agendamento -->
        <template v-if="ehOficial">
          <div style="margin-top:12px;background:rgba(37,211,102,.08);border:1px solid rgba(37,211,102,.25);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-text-secondary);line-height:1.5;">
            Na <b>API oficial</b> a mensagem é um <b>template aprovado</b> pela Meta e não há teto nem janela para evitar bloqueio — a Meta cuida do ritmo. Você só escolhe quando começar.
          </div>

          <div style="margin-top:12px;font-size:12.5px;font-weight:700;color:var(--c-text-secondary);margin-bottom:6px;">Template aprovado</div>
          <div v-if="templatesCarregando" style="font-size:12.5px;color:var(--c-text-muted);">Carregando templates…</div>
          <div v-else-if="templateErro" style="font-size:12.5px;color:var(--c-warn-soft);margin-bottom:8px;">{{ templateErro }}</div>
          <select v-if="!templatesCarregando && templates.length" v-model="form.template_name" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:11px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;">
            <option value="" disabled>Escolha o template…</option>
            <option v-for="t in templates" :key="t.name" :value="t.name" :disabled="t.status !== 'APPROVED'">
              {{ t.status === 'APPROVED' ? '' : '⏳ ' }}{{ t.name }} ({{ t.language }}{{ t.params ? ` · ${t.params} variáveis` : '' }}){{ t.status === 'APPROVED' ? '' : ' — em análise na Meta' }}
            </option>
          </select>

          <div v-if="templateEscolhido" style="margin-top:10px;background:var(--c-surface-2);border-radius:10px;padding:11px 12px;font-size:12.5px;color:var(--c-text-secondary);line-height:1.5;white-space:pre-wrap;">{{ templateEscolhido.body }}</div>

          <template v-if="templateEscolhido && templateEscolhido.params">
            <div style="margin-top:12px;font-size:12.5px;font-weight:700;color:var(--c-text-secondary);margin-bottom:6px;">O que vai em cada variável</div>
            <div v-for="(_, i) in form.template_params" :key="i" style="margin-bottom:7px;">
              <input v-model="form.template_params[i]" :placeholder="`Variável {{${i + 1}}} — ex.: {nome}`" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;box-sizing:border-box;">
            </div>
            <div style="font-size:11.5px;color:var(--c-text-faint);line-height:1.5;">
              Use <b>{nome}</b>, <b>{nome_completo}</b>, <b>{telefone}</b> ou uma coluna da planilha (ex.: <b>{empresa}</b>). Texto fixo também vale.
            </div>
          </template>

          <div style="margin-top:14px;font-size:12.5px;font-weight:700;color:var(--c-text-secondary);margin-bottom:6px;">Começar em (opcional)</div>
          <input v-model="form.starts_at" type="datetime-local" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
          <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:6px;">Em branco = começa assim que você apertar Iniciar.</div>
        </template>

        <!-- CANAL NÃO-OFICIAL: IA + anti-ban -->
        <template v-else>
          <div style="margin-top:12px;font-size:12.5px;font-weight:700;color:var(--c-text-secondary);margin-bottom:6px;">Objetivo (a IA usa isso para escrever a abordagem)</div>
          <textarea v-model="form.objective" rows="3" placeholder="Ex.: Apresentar a plataforma e agendar uma conversa de 15 min com quem tiver interesse." style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:11px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;resize:vertical;" />

          <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:10px;line-height:1.5;">Número não-oficial: os limites abaixo existem para o WhatsApp não bloquear o número.</div>
          <div style="display:flex;gap:12px;margin-top:8px;flex-wrap:wrap;">
            <div>
              <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:4px;">Teto diário</div>
              <input v-model.number="form.daily_cap" type="number" min="1" max="1000" style="width:90px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
            </div>
            <div>
              <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:4px;">Intervalo (seg)</div>
              <div style="display:flex;align-items:center;gap:5px;">
                <input v-model.number="form.min_gap_s" type="number" min="15" style="width:64px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
                <span style="color:var(--c-text-muted);">a</span>
                <input v-model.number="form.max_gap_s" type="number" min="15" style="width:64px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
              </div>
            </div>
            <div>
              <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:4px;">Janela de horário</div>
              <div style="display:flex;align-items:center;gap:5px;">
                <input v-model="form.window_start" type="time" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
                <span style="color:var(--c-text-muted);">–</span>
                <input v-model="form.window_end" type="time" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
              </div>
            </div>
          </div>
        </template>

        <!-- listas -->
        <div v-if="listas.length" style="margin-top:16px;border-top:1px solid var(--c-surface-1);padding-top:14px;">
          <div style="font-size:12.5px;font-weight:700;color:var(--c-text-secondary);margin-bottom:8px;">Para quem disparar</div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button
              v-for="l in listas" :key="l.id"
              :style="{ fontSize:'11.5px',fontWeight:700,padding:'6px 11px',borderRadius:'20px',border:'1px solid',cursor:'pointer',fontFamily:'inherit',
                        background: listasEscolhidas.includes(l.id) ? 'var(--c-ai)' : 'transparent',
                        borderColor: listasEscolhidas.includes(l.id) ? 'var(--c-ai)' : 'var(--c-surface-3)',
                        color: listasEscolhidas.includes(l.id) ? 'var(--c-on-accent)' : 'var(--c-text-muted)' }"
              @click="alternarLista(l.id)"
            >{{ l.name }} · {{ l.contacts_count }}</button>
          </div>
          <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:7px;">Dá para deixar em branco e carregar depois, no cartão da campanha.</div>
        </div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:20px;">
          <button style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:13px;padding:10px 16px;border-radius:10px;cursor:pointer;" @click="open = false">Cancelar</button>
          <button :disabled="saving || !form.name.trim() || !form.wa_account_id" :style="{ background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '10px 20px', borderRadius: '10px', cursor: (saving || !form.name.trim() || !form.wa_account_id) ? 'default' : 'pointer', opacity: (saving || !form.name.trim() || !form.wa_account_id) ? 0.6 : 1 }" @click="create">{{ saving ? 'Criando…' : 'Criar campanha' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>
