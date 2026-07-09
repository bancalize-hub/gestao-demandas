<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

interface Account {
  id: number
  name: string
  instance: string
  phone: string | null
  role: 'primary' | 'outreach'
  state: 'open' | 'connecting' | 'close'
  is_active: boolean
  daily_cap: number
  warmup_day: number
  sent_today: number
  remaining_today?: number
}

const api = useApi()
const crm = useCrmStore()

const accounts = ref<Account[]>([])
const loading = ref(true)

async function loadAccounts() {
  try {
    const r = await api<{ accounts: Account[] }>('/api/wpp/accounts')
    accounts.value = r.accounts
  }
  catch { /* */ }
  finally { loading.value = false }
}

// ---- Conexão (QR / código) de um número específico ----
const connectingId = ref<number | null>(null)
const qr = ref('')
const qrLoading = ref(false)
const pairNumber = ref('')
const pairCode = ref('')
const pairLoading = ref(false)
const pairError = ref('')

async function openConnect(acc: Account) {
  connectingId.value = acc.id
  qr.value = ''
  pairCode.value = ''
  pairNumber.value = ''
  pairError.value = ''
  await loadQr()
}
function closeConnect() {
  connectingId.value = null
  qr.value = ''
}
async function loadQr() {
  if (connectingId.value == null) return
  qrLoading.value = true
  try {
    const r = await api<{ base64: string | null }>(`/api/wpp/qr?account=${connectingId.value}`)
    qr.value = r.base64 || ''
  }
  catch { /* */ }
  finally { qrLoading.value = false }
}
async function getPairCode() {
  if (connectingId.value == null) return
  const num = pairNumber.value.replace(/\D/g, '')
  if (num.length < 12) { pairError.value = 'Informe o número com DDI+DDD (ex: 5511987654321).'; return }
  pairError.value = ''
  pairLoading.value = true
  pairCode.value = ''
  try {
    const r = await api<{ pairingCode: string | null }>(`/api/wpp/pair?account=${connectingId.value}&number=${num}`)
    pairCode.value = r.pairingCode || ''
    if (!pairCode.value) pairError.value = 'Não foi possível gerar o código. Tente o QR.'
  }
  catch { pairError.value = 'Falha ao gerar o código.' }
  finally { pairLoading.value = false }
}

// ---- Adicionar / remover número ----
const adding = ref(false)
const newName = ref('')
const newCap = ref(40)
const addOpen = ref(false)
async function addAccount() {
  const name = newName.value.trim()
  if (!name || adding.value) return
  adding.value = true
  try {
    const acc = await api<Account>('/api/wpp/accounts', { method: 'POST', body: { name, daily_cap: newCap.value } })
    newName.value = ''
    addOpen.value = false
    await loadAccounts()
    const created = accounts.value.find(a => a.id === acc.id)
    if (created) openConnect(created) // já abre o QR para conectar
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao criar o número.') }
  finally { adding.value = false }
}
async function removeAccount(acc: Account) {
  if (!confirm(`Remover o número "${acc.name}"? As conversas dele ficam no histórico, mas a instância é apagada.`)) return
  try {
    await api(`/api/wpp/accounts/${acc.id}`, { method: 'DELETE' })
    if (connectingId.value === acc.id) closeConnect()
    await loadAccounts()
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao remover.') }
}
async function disconnect(acc: Account) {
  if (!confirm(`Desconectar o WhatsApp de "${acc.name}"?`)) return
  try { await api(`/api/wpp/logout?account=${acc.id}`, { method: 'DELETE' }) }
  catch { /* */ }
  await loadAccounts()
}

// ---- Sincronizar / importar (somente número principal) ----
const syncing = ref(false)
const syncMsg = ref('')
async function syncWpp() {
  if (syncing.value) return
  syncing.value = true
  syncMsg.value = 'Sincronizando… isso pode levar até ~30s.'
  try {
    const r = await api<{ imported: number }>('/api/wpp/sync', { method: 'POST', body: { remove_demo: true, limit: 25 } })
    await crm.init()
    syncMsg.value = `${r.imported} conversas importadas. Abrindo o chat…`
    setTimeout(() => navigateTo('/'), 900)
  }
  catch { syncMsg.value = 'Falha ao sincronizar.' }
  finally { syncing.value = false }
}
const impNumber = ref('')
const importing = ref(false)
const impMsg = ref('')
async function importOne() {
  const n = impNumber.value.replace(/\D/g, '')
  if (!n || importing.value) return
  importing.value = true
  impMsg.value = ''
  try {
    await api('/api/wpp/import', { method: 'POST', body: { number: n } })
    await crm.init()
    impMsg.value = 'Conversa importada. Abrindo o chat…'
    setTimeout(() => navigateTo('/'), 800)
  }
  catch (e: any) { impMsg.value = e?.response?._data?.message || 'Falha ao importar.' }
  finally { importing.value = false }
}

let timer: ReturnType<typeof setInterval> | null = null
let qrTimer: ReturnType<typeof setInterval> | null = null
onMounted(() => {
  loadAccounts()
  timer = setInterval(loadAccounts, 4000)
  qrTimer = setInterval(() => { if (connectingId.value != null) loadQr() }, 12000)
})
onBeforeUnmount(() => {
  if (timer) clearInterval(timer)
  if (qrTimer) clearInterval(qrTimer)
})

// Fecha o QR sozinho quando o número conecta.
watch(accounts, (list) => {
  if (connectingId.value != null) {
    const a = list.find(x => x.id === connectingId.value)
    if (a && a.state === 'open') closeConnect()
  }
})

function stateLabel(s: string) {
  return s === 'open' ? 'Conectado' : (s === 'connecting' ? 'Aguardando conexão' : 'Desconectado')
}
function stateColor(s: string) {
  return s === 'open' ? 'var(--accent)' : (s === 'connecting' ? 'var(--c-warn)' : 'var(--c-danger)')
}
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="var(--c-bg-deep)"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" /></svg>
      </div>
      <div>
        <div style="font-weight:800;font-size:15px;">Números do WhatsApp</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Principal atende anúncios · números de prospecção fazem disparo · somente administradores</div>
      </div>
    </div>

    <div style="flex:1;display:flex;align-items:flex-start;justify-content:center;padding:28px 24px 60px;">
      <div style="width:560px;max-width:100%;display:flex;flex-direction:column;gap:16px;">
        <div v-if="loading" style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:40px;text-align:center;color:var(--c-text-muted);font-size:14px;">Carregando…</div>

        <template v-else>
          <!-- cartões de cada número -->
          <div v-for="acc in accounts" :key="acc.id" style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px;">
            <div style="display:flex;align-items:center;gap:12px;">
              <div style="width:44px;height:44px;border-radius:12px;background:rgba(var(--accent-rgb),.12);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
                <span :style="{ width: '12px', height: '12px', borderRadius: '50%', background: stateColor(acc.state) }" />
              </div>
              <div style="flex:1;min-width:0;">
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                  <span style="font-weight:800;font-size:15.5px;">{{ acc.name }}</span>
                  <span :style="{ fontSize: '10.5px', fontWeight: 700, padding: '2px 8px', borderRadius: '20px', background: acc.role === 'primary' ? 'rgba(124,108,245,.18)' : 'rgba(var(--accent-rgb),.14)', color: acc.role === 'primary' ? 'var(--c-ai-soft)' : 'var(--accent)' }">
                    {{ acc.role === 'primary' ? 'Principal · anúncios + IA' : 'Prospecção' }}
                  </span>
                </div>
                <div style="font-size:12.5px;color:var(--c-text-muted);margin-top:2px;">
                  <span :style="{ color: stateColor(acc.state) }">{{ stateLabel(acc.state) }}</span>
                  <template v-if="acc.phone"> · <b style="color:var(--c-text-secondary);">+{{ acc.phone }}</b></template>
                  <template v-if="acc.role === 'outreach' && acc.state === 'open'">
                    · enviados hoje: {{ acc.sent_today }}<template v-if="acc.warmup_day < 5"> · 🔥 aquecendo (dia {{ acc.warmup_day }})</template>
                  </template>
                </div>
              </div>
              <div style="display:flex;gap:8px;flex-shrink:0;">
                <button v-if="acc.state !== 'open' && connectingId !== acc.id" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 14px;border-radius:9px;cursor:pointer;" @click="openConnect(acc)">Conectar</button>
                <button v-if="acc.state === 'open'" style="background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.25);color:var(--c-danger-soft);font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 12px;border-radius:9px;cursor:pointer;" @click="disconnect(acc)">Desconectar</button>
                <button v-if="acc.role !== 'primary'" title="Remover número" style="background:none;border:1px solid var(--c-surface-3);color:var(--c-text-faint);font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 11px;border-radius:9px;cursor:pointer;" @click="removeAccount(acc)">✕</button>
              </div>
            </div>

            <!-- painel de conexão (QR + código) deste número -->
            <div v-if="connectingId === acc.id" style="margin-top:18px;border-top:1px solid var(--c-surface-1);padding-top:18px;">
              <div style="font-size:13px;color:var(--c-text-muted);line-height:1.5;margin-bottom:12px;">No WhatsApp do número: <b style="color:var(--c-text-secondary);">Aparelhos conectados → Conectar um aparelho</b> e leia o QR.</div>
              <div style="margin:0 auto;width:230px;height:230px;background:var(--c-on-accent);border-radius:14px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
                <img v-if="qr" :src="qr" alt="QR" style="width:100%;height:100%;object-fit:contain;">
                <span v-else style="color:var(--c-text-faint);font-size:13px;">{{ qrLoading ? 'Gerando QR…' : 'Aguardando QR…' }}</span>
              </div>
              <div style="display:flex;gap:8px;margin-top:14px;">
                <button style="flex:1;background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:12.5px;font-weight:600;padding:10px;border-radius:10px;cursor:pointer;" @click="loadQr">Gerar novo QR</button>
                <button style="flex:1;background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:12.5px;font-weight:600;padding:10px;border-radius:10px;cursor:pointer;" @click="closeConnect">Fechar</button>
              </div>
              <div style="margin-top:14px;">
                <div style="font-size:12px;font-weight:700;color:var(--c-text-secondary);margin-bottom:8px;">Ou conecte com o número (código)</div>
                <div style="display:flex;gap:8px;">
                  <input v-model="pairNumber" placeholder="5511987654321" style="flex:1;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;" @keydown.enter="getPairCode">
                  <button :disabled="pairLoading" style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:13px;font-weight:700;padding:0 16px;border-radius:10px;cursor:pointer;white-space:nowrap;" @click="getPairCode">{{ pairLoading ? '…' : 'Gerar código' }}</button>
                </div>
                <div v-if="pairError" style="margin-top:8px;font-size:12.5px;color:var(--c-danger-soft);">{{ pairError }}</div>
                <div v-if="pairCode" style="margin-top:12px;text-align:center;background:var(--c-surface-2);border-radius:11px;padding:12px;font-size:25px;font-weight:800;letter-spacing:4px;color:var(--accent);font-family:monospace;">{{ pairCode }}</div>
              </div>
            </div>

            <!-- principal conectado: sincronizar / importar conversas -->
            <div v-if="acc.role === 'primary' && acc.state === 'open'" style="margin-top:18px;border-top:1px solid var(--c-surface-1);padding-top:18px;">
              <button :disabled="syncing" :style="{ width: '100%', background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '12px', borderRadius: '11px', cursor: syncing ? 'default' : 'pointer', opacity: syncing ? 0.7 : 1 }" @click="syncWpp">
                {{ syncing ? 'Sincronizando…' : 'Sincronizar conversas do WhatsApp' }}
              </button>
              <div v-if="syncMsg" style="margin-top:9px;font-size:12.5px;color:var(--c-ai-soft);text-align:center;">{{ syncMsg }}</div>
              <div style="margin-top:14px;display:flex;gap:8px;">
                <input v-model="impNumber" placeholder="Importar nº específico: 5511987654321" style="flex:1;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;" @keydown.enter="importOne">
                <button :disabled="importing" style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13px;font-weight:700;padding:0 16px;border-radius:10px;cursor:pointer;white-space:nowrap;" @click="importOne">{{ importing ? '…' : 'Importar' }}</button>
              </div>
              <div v-if="impMsg" style="margin-top:8px;font-size:12.5px;color:var(--c-ai-soft);">{{ impMsg }}</div>
            </div>
          </div>

          <!-- adicionar número de prospecção -->
          <div style="background:var(--c-bg);border:1px dashed var(--c-surface-3);border-radius:18px;padding:20px;">
            <template v-if="!addOpen">
              <button style="width:100%;background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13.5px;font-weight:700;padding:12px;border-radius:11px;cursor:pointer;" @click="addOpen = true">+ Adicionar número de prospecção</button>
            </template>
            <template v-else>
              <div style="font-size:14px;font-weight:800;margin-bottom:10px;">Novo número de prospecção</div>
              <input v-model="newName" placeholder="Apelido (ex.: Prospecção 1)" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:11px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;" @keydown.enter="addAccount">
              <div style="display:flex;align-items:center;gap:8px;margin-top:10px;font-size:12.5px;color:var(--c-text-muted);flex-wrap:wrap;">
                Teto diário de envios:
                <input v-model.number="newCap" type="number" min="1" max="1000" style="width:80px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;">
                <span style="font-size:11.5px;">(começa baixo e sobe com o aquecimento)</span>
              </div>
              <div style="display:flex;gap:8px;margin-top:14px;">
                <button :disabled="adding || !newName.trim()" :style="{ flex: 1, background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '11px', borderRadius: '10px', cursor: (adding || !newName.trim()) ? 'default' : 'pointer', opacity: (adding || !newName.trim()) ? 0.6 : 1 }" @click="addAccount">{{ adding ? 'Criando…' : 'Criar e conectar' }}</button>
                <button style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:13px;font-weight:600;padding:0 16px;border-radius:10px;cursor:pointer;" @click="addOpen = false">Cancelar</button>
              </div>
            </template>
          </div>

          <div style="font-size:11.5px;color:var(--c-text-faint);line-height:1.5;padding:0 4px;">⚠️ Integração não-oficial: use números dedicados para prospecção. Disparo em massa tem risco de bloqueio — os limites e o aquecimento ajudam a reduzir, mas não eliminam.</div>
        </template>
      </div>
    </div>
  </div>
</template>
