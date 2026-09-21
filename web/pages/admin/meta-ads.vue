<script setup lang="ts">
// Conexão com o Facebook Ads (credencial da Meta). Era a aba "Config" do /marketing;
// virou página própria, alcançada pelo mini menu do avatar.
const api = useApi()

interface Status {
  app_id: string | null; ad_account_id: string | null; page_id: string | null
  dataset_id: string | null; capi_test_code: string | null
  graph_version: string; tem_token: boolean; tem_app_secret: boolean
  checked_at: string | null; last_error: string | null
}

const status = ref<Status | null>(null)
const salvando = ref(false)
const testando = ref(false)
const resultado = ref<{ ok: boolean, texto: string } | null>(null)

const form = reactive({ access_token: '', ad_account_id: '', page_id: '', app_id: '', app_secret: '', graph_version: '', dataset_id: '', capi_test_code: '' })

const pronto = computed(() => !!(status.value?.tem_token && status.value?.ad_account_id))

async function carregar() {
  try {
    const s = await api<Status>('/api/marketing/status')
    status.value = s
    // Segredos nunca voltam do servidor: os campos de token ficam em branco e, em
    // branco, o backend mantém o que já está salvo.
    form.ad_account_id = s.ad_account_id || ''
    form.page_id = s.page_id || ''
    form.app_id = s.app_id || ''
    form.dataset_id = s.dataset_id || ''
    form.capi_test_code = s.capi_test_code || ''
    form.graph_version = s.graph_version || 'v23.0'
  }
  catch { status.value = null }
}
onMounted(carregar)

async function salvar() {
  salvando.value = true
  resultado.value = null
  try {
    const r = await api<{ ok: boolean, usuario?: string, conta?: string, moeda?: string, mensagem?: string }>('/api/marketing/credentials', { method: 'POST', body: form })
    resultado.value = r.ok
      ? { ok: true, texto: `Conectado como ${r.usuario} · conta ${r.conta}${r.moeda ? ` (${r.moeda})` : ''}` }
      : { ok: false, texto: r.mensagem || 'Não consegui falar com o Facebook.' }
    form.access_token = ''
    form.app_secret = ''
    await carregar()
  }
  catch (e: any) { resultado.value = { ok: false, texto: e?.response?._data?.message || e?.message || 'Erro ao salvar.' } }
  finally { salvando.value = false }
}

async function testar() {
  testando.value = true
  resultado.value = null
  try {
    const r = await api<{ ok: boolean, usuario?: string, conta?: string, moeda?: string, mensagem?: string }>('/api/marketing/test', { method: 'POST' })
    resultado.value = r.ok
      ? { ok: true, texto: `Conectado como ${r.usuario} · conta ${r.conta}${r.moeda ? ` (${r.moeda})` : ''}` }
      : { ok: false, texto: r.mensagem || 'Falhou.' }
    await carregar()
  }
  catch (e: any) { resultado.value = { ok: false, texto: e?.message || 'Erro ao testar.' } }
  finally { testando.value = false }
}

function quando(iso: string | null) {
  if (!iso) return ''
  const min = Math.round((Date.now() - new Date(iso).getTime()) / 60000)
  if (min < 1) return 'agora'
  if (min < 60) return `${min} min`
  if (min < 1440) return `${Math.floor(min / 60)} h`
  return `${Math.floor(min / 1440)} d`
}

const campo = 'width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;'
const rotulo = 'display:block;font-size:11.5px;color:var(--c-text-muted);margin-bottom:5px;font-weight:600;'
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <!-- a barra não quebrava linha: em 360 o título passava por cima do botão "← Gerenciador
         de anúncios" e os 24px fixos de padding comiam mais tela que o resto do app -->
    <div class="r-wrap r-pad" style="padding:16px 24px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-ink)" stroke-width="1.9"><path d="M3 11v2a1 1 0 0 0 1 1h2v4h2v-4l10 4.5v-15L8 8H4a1 1 0 0 0-1 1Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </div>
      <div class="r-break" style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Conexão com o Facebook Ads</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Credenciais do app na Meta · somente administradores</div>
      </div>
      <!-- o espaçador só serve para empurrar o botão no desktop; com a barra quebrando
           linha ele viraria um item que cresce e desalinharia o link -->
      <div class="r-hide" style="flex:1;" />
      <NuxtLink to="/marketing" class="r-tap r-noshrink" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">← Gerenciador de anúncios</NuxtLink>
    </div>

    <div class="r-pad" style="padding:20px 24px;">
      <!-- o teto de 560px existia, mas sem centralizar: em 1600+ o formulário inteiro
           ficava grudado na borda esquerda com mil pixels de vazio à direita -->
      <div class="r-center" style="max-width:560px;">
        <div style="font-size:12px;color:var(--c-text-muted);margin-bottom:16px;line-height:1.5;">
          O token e o app secret ficam criptografados no banco e nunca voltam para a tela — deixe em branco para manter o que já está salvo.
        </div>

        <!-- sem quebra de linha, em 360 a frase de status e o carimbo "testada X atrás"
             disputavam a mesma linha e o carimbo ficava ilegível no canto -->
        <div v-if="status" class="r-wrap" :style="{ display: 'flex', alignItems: 'center', gap: '8px', padding: '10px 12px', borderRadius: '10px', marginBottom: '16px', fontSize: '12px', background: pronto ? 'rgba(35,197,98,.08)' : 'rgba(255,170,0,.08)', border: '1px solid ' + (pronto ? 'rgba(35,197,98,.3)' : 'rgba(255,170,0,.3)') }">
          <span>{{ pronto ? '●' : '○' }}</span>
          <span class="r-min0">{{ pronto ? 'Credencial preenchida' : 'Falta token de acesso ou conta de anúncios' }}</span>
          <span v-if="status.checked_at" style="margin-left:auto;color:var(--c-text-faint);font-size:11px;">testada {{ quando(status.checked_at) }} atrás</span>
        </div>

        <!-- a mensagem vem crua da Graph API (URL/JSON sem espaço) e o pre-wrap sozinho
             não quebra token longo: a caixa passava dos 560px e empurrava a página -->
        <div v-if="status?.last_error" class="r-break" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:11.5px;color:var(--c-danger-soft);margin-bottom:16px;white-space:pre-wrap;">Último erro: {{ status.last_error }}</div>

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
          <!-- 222px de largura intrínseca do campo (fonte de 16px do celular) + 120px fixos
               + gap não cabem nos ~312px de 360: a linha estourava e a página rolava no X -->
          <div class="r-xs-stack" style="display:flex;gap:12px;">
            <div class="r-min0" style="flex:1;">
              <label :style="rotulo">App ID (opcional)</label>
              <input v-model="form.app_id" :style="campo">
            </div>
            <div class="r-xs-full" style="width:120px;">
              <label :style="rotulo">Versão da API</label>
              <input v-model="form.graph_version" placeholder="v23.0" :style="campo">
            </div>
          </div>
          <div>
            <label :style="rotulo">App secret (opcional) {{ status?.tem_app_secret ? '— salvo' : '' }}</label>
            <input v-model="form.app_secret" type="password" autocomplete="off" :style="campo">
          </div>
        </div>

        <div style="margin-top:22px;padding-top:18px;border-top:1px solid var(--c-surface-1);">
          <div style="font-size:13.5px;font-weight:800;margin-bottom:4px;">Eventos de conversão (CAPI)</div>
          <div style="font-size:12px;color:var(--c-text-muted);margin-bottom:14px;line-height:1.5;">
            O CRM avisa o Facebook quando o lead avança: chegou, marcou reunião, compareceu, comprou.
            É o que permite campanha otimizada por reunião em vez de por clique.
          </div>
          <div style="display:flex;flex-direction:column;gap:13px;">
            <div>
              <label :style="rotulo">ID do dataset (Gerenciador de Eventos)</label>
              <input v-model="form.dataset_id" placeholder="1212705657368191" :style="campo">
            </div>
            <div>
              <label :style="rotulo">Código de teste — enquanto preenchido, os eventos vão só para "Eventos de teste" e NÃO contam para as campanhas</label>
              <input v-model="form.capi_test_code" placeholder="TEST12345 (deixe vazio para valer de verdade)" :style="campo">
            </div>
          </div>
        </div>

        <!-- mesmo caso do "Último erro": o texto da Meta traz trace_id e URL colados -->
        <div v-if="resultado" class="r-break" :style="{ marginTop: '14px', padding: '10px 12px', borderRadius: '10px', fontSize: '12px', whiteSpace: 'pre-wrap', background: resultado.ok ? 'rgba(35,197,98,.08)' : 'rgba(255,77,77,.08)', border: '1px solid ' + (resultado.ok ? 'rgba(35,197,98,.3)' : 'rgba(255,77,77,.3)'), color: resultado.ok ? 'var(--c-text-secondary)' : 'var(--c-danger-soft)' }">
          {{ resultado.ok ? '✓ ' : '✗ ' }}{{ resultado.texto }}
        </div>

        <!-- os dois botões somavam ~270px nos 312px de 360, sem folga de toque e com ~37px
             de altura; no celular empilham e ficam com o alvo de 44px -->
        <div class="r-xs-stack r-xs-gap-sm" style="display:flex;gap:10px;margin-top:16px;">
          <button :disabled="salvando" class="r-tap" :style="{ background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontWeight: 700, fontSize: '13px', padding: '10px 18px', borderRadius: '10px', cursor: salvando ? 'default' : 'pointer', opacity: salvando ? .6 : 1 }" @click="salvar">{{ salvando ? 'Salvando…' : 'Salvar e testar' }}</button>
          <button :disabled="testando || !status?.tem_token" class="r-tap" style="background:none;border:1px solid var(--c-surface-3);color:var(--c-text-secondary);font-family:inherit;font-size:13px;padding:10px 16px;border-radius:10px;cursor:pointer;" @click="testar">{{ testando ? 'Testando…' : 'Testar conexão' }}</button>
        </div>

        <div style="margin-top:22px;font-size:11.5px;color:var(--c-text-faint);line-height:1.6;">
          O token precisa dos escopos <code>ads_management</code> e <code>ads_read</code>, e a conta de anúncios tem que estar
          no mesmo Business Manager do app. Um token de usuário do sistema (System User) não expira — é o recomendado aqui.
        </div>
      </div>
    </div>
  </div>
</template>
