<script setup lang="ts">
interface Account { id: number, name: string, role: string, state: string }
interface Campaign {
  id: number
  name: string
  status: 'draft' | 'running' | 'paused' | 'done'
  objective: string | null
  wa_account_id: number
  account?: { id: number, name: string, role: string }
  min_gap_s: number
  max_gap_s: number
  daily_cap: number
  window_start: string
  window_end: string
  total: number
  sent: number
  replied: number
  failed: number
}

const api = useApi()

const campaigns = ref<Campaign[]>([])
const accounts = ref<Account[]>([])
const loading = ref(true)

const outreach = computed(() => accounts.value.filter(a => a.role === 'outreach'))

async function load() {
  try {
    const [c, a] = await Promise.all([
      api<{ campaigns: Campaign[] }>('/api/campaigns'),
      api<{ accounts: Account[] }>('/api/wpp/accounts'),
    ])
    campaigns.value = c.campaigns
    accounts.value = a.accounts
  }
  catch { /* */ }
  finally { loading.value = false }
}

// ---- Nova campanha ----
const open = ref(false)
const form = ref({ name: '', wa_account_id: 0, objective: '', daily_cap: 40, min_gap_s: 60, max_gap_s: 180, window_start: '09:00', window_end: '18:00' })
const saving = ref(false)
async function create() {
  if (!form.value.name.trim() || !form.value.wa_account_id || saving.value) return
  saving.value = true
  try {
    await api('/api/campaigns', { method: 'POST', body: form.value })
    open.value = false
    form.value = { name: '', wa_account_id: 0, objective: '', daily_cap: 40, min_gap_s: 60, max_gap_s: 180, window_start: '09:00', window_end: '18:00' }
    await load()
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao criar campanha.') }
  finally { saving.value = false }
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
    draft: ['Rascunho', '#8696a0'],
    running: ['Disparando', '#25D366'],
    paused: ['Pausada', '#ffb443'],
    done: ['Concluída', '#7c6cf5'],
  }
  return map[s] || ['—', '#8696a0']
}
function progress(c: Campaign) {
  return c.total > 0 ? Math.round((c.sent / c.total) * 100) : 0
}

let timer: ReturnType<typeof setInterval> | null = null
onMounted(() => { load(); timer = setInterval(load, 5000) })
onBeforeUnmount(() => { if (timer) clearInterval(timer) })
</script>

<template>
  <div style="flex:1;min-width:0;background:#0b141a;display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid #1c2730;display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:#7c6cf5;display:flex;align-items:center;justify-content:center;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2"><path d="M3 11v2a1 1 0 0 0 1 1h2l3.5 3.5V7.5L6 11H4Z" stroke-linecap="round" stroke-linejoin="round" /><path d="m9.5 7.5 9-4v17l-9-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </div>
      <div style="flex:1;">
        <div style="font-weight:800;font-size:15px;">Campanhas de prospecção</div>
        <div style="font-size:12px;color:#8696a0;">Disparo em massa e prospecção ativa com mensagem da IA · pelos números de prospecção</div>
      </div>
      <button style="background:#7c6cf5;border:none;color:#fff;font-family:inherit;font-size:13px;font-weight:700;padding:9px 16px;border-radius:10px;cursor:pointer;" @click="open = !open">{{ open ? 'Fechar' : '+ Nova campanha' }}</button>
    </div>

    <div style="flex:1;display:flex;align-items:flex-start;justify-content:center;padding:24px;">
      <div style="width:640px;max-width:100%;display:flex;flex-direction:column;gap:16px;">
        <!-- aviso sem número de prospecção -->
        <div v-if="!loading && outreach.length === 0" style="background:rgba(255,180,67,.1);border:1px solid rgba(255,180,67,.3);border-radius:14px;padding:16px;font-size:13px;color:#ffd28a;line-height:1.5;">
          Você ainda não tem nenhum <b>número de prospecção</b>. Vá em <b>WhatsApp</b> e adicione/conecte um número antes de criar campanhas.
        </div>

        <!-- nova campanha -->
        <div v-if="open" style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:22px;">
          <div style="font-size:15px;font-weight:800;margin-bottom:14px;">Nova campanha</div>
          <input v-model="form.name" placeholder="Nome da campanha (ex.: Black Friday — leads frios)" style="width:100%;background:#202c33;border:1px solid #2a3942;border-radius:10px;padding:11px 12px;color:#e9edef;font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;">

          <div style="margin-top:12px;font-size:12.5px;font-weight:700;color:#aebac1;margin-bottom:6px;">Número que vai disparar</div>
          <select v-model.number="form.wa_account_id" style="width:100%;background:#202c33;border:1px solid #2a3942;border-radius:10px;padding:11px 12px;color:#e9edef;font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;">
            <option :value="0" disabled>Selecione um número de prospecção…</option>
            <option v-for="a in outreach" :key="a.id" :value="a.id">{{ a.name }} {{ a.state === 'open' ? '(conectado)' : '(desconectado)' }}</option>
          </select>

          <div style="margin-top:12px;font-size:12.5px;font-weight:700;color:#aebac1;margin-bottom:6px;">Objetivo (a IA usa isso para escrever a abordagem)</div>
          <textarea v-model="form.objective" rows="3" placeholder="Ex.: Apresentar nossa consultoria de crédito e agendar uma conversa de 15 min com quem tiver interesse." style="width:100%;background:#202c33;border:1px solid #2a3942;border-radius:10px;padding:11px 12px;color:#e9edef;font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;resize:vertical;" />

          <div style="display:flex;gap:12px;margin-top:12px;flex-wrap:wrap;">
            <div>
              <div style="font-size:11.5px;color:#8696a0;margin-bottom:4px;">Teto diário</div>
              <input v-model.number="form.daily_cap" type="number" min="1" max="1000" style="width:90px;background:#202c33;border:1px solid #2a3942;border-radius:8px;padding:8px 10px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
            </div>
            <div>
              <div style="font-size:11.5px;color:#8696a0;margin-bottom:4px;">Intervalo (seg)</div>
              <div style="display:flex;align-items:center;gap:5px;">
                <input v-model.number="form.min_gap_s" type="number" min="15" style="width:64px;background:#202c33;border:1px solid #2a3942;border-radius:8px;padding:8px 10px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
                <span style="color:#8696a0;">a</span>
                <input v-model.number="form.max_gap_s" type="number" min="15" style="width:64px;background:#202c33;border:1px solid #2a3942;border-radius:8px;padding:8px 10px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
              </div>
            </div>
            <div>
              <div style="font-size:11.5px;color:#8696a0;margin-bottom:4px;">Janela de horário</div>
              <div style="display:flex;align-items:center;gap:5px;">
                <input v-model="form.window_start" type="time" style="background:#202c33;border:1px solid #2a3942;border-radius:8px;padding:7px 9px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
                <span style="color:#8696a0;">–</span>
                <input v-model="form.window_end" type="time" style="background:#202c33;border:1px solid #2a3942;border-radius:8px;padding:7px 9px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
              </div>
            </div>
          </div>

          <button :disabled="saving || !form.name.trim() || !form.wa_account_id" :style="{ marginTop: '16px', width: '100%', background: '#7c6cf5', border: 'none', color: '#fff', fontFamily: 'inherit', fontSize: '14px', fontWeight: 700, padding: '12px', borderRadius: '11px', cursor: (saving || !form.name.trim() || !form.wa_account_id) ? 'default' : 'pointer', opacity: (saving || !form.name.trim() || !form.wa_account_id) ? 0.6 : 1 }" @click="create">{{ saving ? 'Criando…' : 'Criar campanha (rascunho)' }}</button>
        </div>

        <div v-if="loading" style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:40px;text-align:center;color:#8696a0;">Carregando…</div>
        <div v-else-if="campaigns.length === 0 && !open" style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:36px;text-align:center;color:#8696a0;font-size:13.5px;">Nenhuma campanha ainda. Clique em <b style="color:#a89bf9;">+ Nova campanha</b> para começar.</div>

        <!-- lista de campanhas -->
        <div v-for="c in campaigns" :key="c.id" style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:20px;">
          <div style="display:flex;align-items:flex-start;gap:10px;">
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                <span style="font-weight:800;font-size:15px;">{{ c.name }}</span>
                <span :style="{ fontSize: '10.5px', fontWeight: 700, padding: '2px 9px', borderRadius: '20px', background: statusPill(c.status)[1] + '22', color: statusPill(c.status)[1] }">{{ statusPill(c.status)[0] }}</span>
              </div>
              <div style="font-size:12px;color:#8696a0;margin-top:3px;">Número: <b style="color:#aebac1;">{{ c.account?.name || '—' }}</b> · janela {{ c.window_start }}–{{ c.window_end }} · teto {{ c.daily_cap }}/dia · intervalo {{ c.min_gap_s }}–{{ c.max_gap_s }}s</div>
            </div>
            <div style="display:flex;gap:7px;flex-shrink:0;">
              <button v-if="c.status === 'draft' || c.status === 'paused'" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 14px;border-radius:9px;cursor:pointer;" @click="setStatus(c, 'running')">Iniciar</button>
              <button v-if="c.status === 'running'" style="background:rgba(255,180,67,.14);border:1px solid rgba(255,180,67,.3);color:#ffb443;font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 14px;border-radius:9px;cursor:pointer;" @click="setStatus(c, 'paused')">Pausar</button>
              <button title="Excluir" style="background:none;border:1px solid #2a3942;color:#6a7c86;font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 11px;border-radius:9px;cursor:pointer;" @click="remove(c)">✕</button>
            </div>
          </div>

          <!-- progresso -->
          <div style="margin-top:14px;">
            <div style="height:8px;background:#202c33;border-radius:6px;overflow:hidden;">
              <div :style="{ width: progress(c) + '%', height: '100%', background: '#25D366', transition: 'width .4s' }" />
            </div>
            <div style="display:flex;gap:16px;margin-top:8px;font-size:12px;color:#8696a0;">
              <span><b style="color:#e9edef;">{{ c.total }}</b> contatos</span>
              <span><b style="color:#25D366;">{{ c.sent }}</b> enviados</span>
              <span><b style="color:#a89bf9;">{{ c.replied }}</b> responderam</span>
              <span v-if="c.failed"><b style="color:#ff8d8d;">{{ c.failed }}</b> falhas</span>
            </div>
          </div>

          <!-- upload CSV -->
          <div style="margin-top:14px;border-top:1px solid #1c2730;padding-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
            <label :style="{ background: '#202c33', color: '#e9edef', fontSize: '12.5px', fontWeight: 700, padding: '9px 14px', borderRadius: '9px', cursor: 'pointer' }">
              {{ uploadingId === c.id ? 'Enviando…' : '⬆ Importar contatos (CSV)' }}
              <input type="file" accept=".csv,text/csv,text/plain" style="display:none;" :disabled="uploadingId === c.id" @change="(e) => uploadCsv(c, e)">
            </label>
            <span style="font-size:11.5px;color:#5f6f78;">CSV com colunas <b>telefone</b> e <b>nome</b> (demais colunas viram variáveis p/ a IA).</span>
            <span v-if="uploadMsg[c.id]" style="font-size:12px;color:#a89bf9;flex-basis:100%;">{{ uploadMsg[c.id] }}</span>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
