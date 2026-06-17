<script setup lang="ts">
const api = useApi()

const state = ref<'open' | 'connecting' | 'close'>('connecting')
const number = ref<string | null>(null)
const qr = ref('')
const loading = ref(true)
const qrLoading = ref(false)

// Pareamento por código
const pairNumber = ref('')
const pairCode = ref('')
const pairLoading = ref(false)
const pairError = ref('')

let statusTimer: ReturnType<typeof setInterval> | null = null
let qrTimer: ReturnType<typeof setInterval> | null = null

const connected = computed(() => state.value === 'open')

async function loadStatus() {
  try {
    const r = await api<{ state: typeof state.value, number: string | null }>('/api/wpp/status')
    state.value = r.state
    number.value = r.number
  }
  catch { /* */ }
  loading.value = false
  if (connected.value) {
    qr.value = ''
    if (qrTimer) { clearInterval(qrTimer); qrTimer = null }
  }
  else if (!qrTimer) {
    startQr()
  }
}

async function loadQr() {
  if (connected.value) return
  qrLoading.value = true
  try {
    const r = await api<{ base64: string | null }>('/api/wpp/qr')
    qr.value = r.base64 || ''
  }
  catch { /* */ }
  finally { qrLoading.value = false }
}

function startQr() {
  loadQr()
  qrTimer = setInterval(loadQr, 12000)
}

async function getPairCode() {
  const num = pairNumber.value.replace(/\D/g, '')
  if (num.length < 12) { pairError.value = 'Informe o número com DDI+DDD (ex: 5511987654321).'; return }
  pairError.value = ''
  pairLoading.value = true
  pairCode.value = ''
  try {
    const r = await api<{ pairingCode: string | null }>(`/api/wpp/pair?number=${num}`)
    pairCode.value = r.pairingCode || ''
    if (!pairCode.value) pairError.value = 'Não foi possível gerar o código. Tente o QR.'
  }
  catch {
    pairError.value = 'Falha ao gerar o código.'
  }
  finally { pairLoading.value = false }
}

async function disconnect() {
  if (!confirm('Desconectar o WhatsApp deste número?')) return
  try { await api('/api/wpp/logout', { method: 'DELETE' }) }
  catch { /* */ }
  state.value = 'connecting'
  number.value = null
  await loadQr()
  if (!qrTimer) startQr()
}

onMounted(() => {
  loadStatus()
  statusTimer = setInterval(loadStatus, 4000)
})
onBeforeUnmount(() => {
  if (statusTimer) clearInterval(statusTimer)
  if (qrTimer) clearInterval(qrTimer)
})
</script>

<template>
  <div style="flex:1;min-width:0;background:#0b141a;display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid #1c2730;display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:#25D366;display:flex;align-items:center;justify-content:center;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="#0b141a"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" /></svg>
      </div>
      <div>
        <div style="font-weight:800;font-size:15px;">WhatsApp</div>
        <div style="font-size:12px;color:#8696a0;">Conexão do número · somente administradores</div>
      </div>
    </div>

    <div style="flex:1;display:flex;align-items:flex-start;justify-content:center;padding:38px 24px 60px;">
      <div style="width:480px;max-width:100%;">
        <div v-if="loading" style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:40px;text-align:center;color:#8696a0;font-size:14px;">Carregando…</div>

        <!-- conectado -->
        <div v-else-if="connected" style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:34px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:14px;">
          <div style="width:70px;height:70px;border-radius:50%;background:rgba(37,211,102,.14);display:flex;align-items:center;justify-content:center;"><svg width="34" height="34" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.4"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg></div>
          <div style="font-size:20px;font-weight:800;">WhatsApp conectado</div>
          <div v-if="number" style="font-size:14px;color:#aebac1;">Número: <b style="color:#e9edef;">+{{ number }}</b></div>
          <div style="font-size:13px;color:#8696a0;max-width:340px;line-height:1.5;">As conversas serão sincronizadas e as mensagens enviadas pelo chat sairão por este número.</div>
          <button style="margin-top:6px;background:rgba(255,77,77,.12);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-family:inherit;font-size:13.5px;font-weight:700;padding:11px 20px;border-radius:11px;cursor:pointer;" @click="disconnect">Desconectar</button>
        </div>

        <!-- pareamento (QR) -->
        <div v-else style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:30px;">
          <div style="font-size:19px;font-weight:800;">Conectar WhatsApp</div>
          <div style="font-size:13.5px;color:#8696a0;margin-top:5px;line-height:1.5;">Abra o WhatsApp no celular → <b style="color:#aebac1;">Aparelhos conectados</b> → <b style="color:#aebac1;">Conectar um aparelho</b> e aponte para o QR abaixo.</div>

          <div style="margin:22px auto 0;width:260px;height:260px;background:#fff;border-radius:14px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
            <img v-if="qr" :src="qr" alt="QR WhatsApp" style="width:100%;height:100%;object-fit:contain;">
            <span v-else style="color:#667;font-size:13px;">{{ qrLoading ? 'Gerando QR…' : 'Aguardando QR…' }}</span>
          </div>

          <div style="display:flex;align-items:center;justify-content:center;gap:8px;margin-top:18px;font-size:12.5px;color:#8696a0;">
            <span style="width:8px;height:8px;border-radius:50%;background:#ffb443;animation:none;" />
            {{ state === 'connecting' ? 'Aguardando leitura do QR…' : 'Desconectado' }}
          </div>

          <button style="width:100%;margin-top:18px;background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13px;font-weight:600;padding:11px;border-radius:11px;cursor:pointer;" @click="loadQr">Gerar novo QR</button>

          <div style="margin-top:18px;border-top:1px solid #1c2730;padding-top:16px;">
            <div style="font-size:12.5px;font-weight:700;color:#aebac1;margin-bottom:9px;">Ou conecte com o número</div>
            <div style="display:flex;gap:8px;">
              <input v-model="pairNumber" placeholder="5511987654321" style="flex:1;background:#202c33;border:1px solid #2a3942;border-radius:10px;padding:10px 12px;color:#e9edef;font-family:inherit;font-size:13.5px;outline:none;" @keydown.enter="getPairCode">
              <button :disabled="pairLoading" :style="{ background: '#7c6cf5', border: 'none', color: '#fff', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '0 16px', borderRadius: '10px', cursor: pairLoading ? 'default' : 'pointer', whiteSpace: 'nowrap' }" @click="getPairCode">{{ pairLoading ? '…' : 'Gerar código' }}</button>
            </div>
            <div v-if="pairError" style="margin-top:8px;font-size:12.5px;color:#ff8d8d;">{{ pairError }}</div>
            <div v-if="pairCode" style="margin-top:13px;text-align:center;background:#202c33;border-radius:11px;padding:14px;">
              <div style="font-size:12px;color:#8696a0;line-height:1.45;">No WhatsApp: <b style="color:#aebac1;">Aparelhos conectados → Conectar um aparelho → "Conectar com número"</b> e digite:</div>
              <div style="font-size:27px;font-weight:800;letter-spacing:4px;color:#25D366;margin-top:9px;font-family:monospace;">{{ pairCode }}</div>
            </div>
          </div>

          <div style="margin-top:16px;font-size:11.5px;color:#5f6f78;line-height:1.5;border-top:1px solid #1c2730;padding-top:14px;">⚠️ Use um número dedicado. É integração não-oficial e há risco de bloqueio do número pelo WhatsApp.</div>
        </div>
      </div>
    </div>
  </div>
</template>
