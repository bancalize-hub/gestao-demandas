<script setup lang="ts">
// Marca da empresa (admin): cor de destaque + logos clara/escura.
const api = useApi()
const { fetchUser } = useAuth()

interface Branding { brand_color: string | null, logo_light_url: string | null, logo_dark_url: string | null }
const branding = ref<Branding>({ brand_color: null, logo_light_url: null, logo_dark_url: null })
const DEFAULT = '#25D366' // valor hex real do acento padrão (dado do <input type=color>)
const color = ref(DEFAULT)
const saving = ref(false)
const savedMsg = ref('')

onMounted(async () => {
  try {
    branding.value = await api<Branding>('/api/branding')
    color.value = branding.value.brand_color || DEFAULT
    applyBrand(color.value) // preview imediato
  }
  catch {}
})

// Preview ao vivo: aplica a cor enquanto o admin ajusta.
watch(color, v => applyBrand(v))

async function saveColor(reset = false) {
  saving.value = true
  savedMsg.value = ''
  try {
    const body = { brand_color: reset ? null : color.value }
    branding.value = await api<Branding>('/api/branding', { method: 'PATCH', body })
    if (reset) color.value = DEFAULT
    applyBrand(branding.value.brand_color)
    await fetchUser() // propaga a marca p/ todo o app (Rail, favicon)
    savedMsg.value = 'Cor salva.'
  }
  catch { savedMsg.value = 'Falha ao salvar.' }
  finally { saving.value = false }
}

async function onLogo(e: Event, variant: 'light' | 'dark') {
  const file = (e.target as HTMLInputElement).files?.[0]
  if (!file) return
  const fd = new FormData()
  fd.append('variant', variant)
  fd.append('logo', file)
  try {
    branding.value = await api<Branding>('/api/branding/logo', { method: 'POST', body: fd })
    await fetchUser()
    savedMsg.value = 'Logo enviada.'
  }
  catch { savedMsg.value = 'Falha no upload (imagem até 2MB).' }
}

async function removeLogo(variant: 'light' | 'dark') {
  try {
    branding.value = await api<Branding>('/api/branding/logo', { method: 'DELETE', body: { variant } })
    await fetchUser()
  }
  catch {}
}

const box = { background: 'var(--c-surface-2)', border: '1px solid var(--c-border)', borderRadius: '16px', padding: '22px' }
</script>

<template>
  <div style="flex:1;min-width:0;overflow-y:auto;padding:32px clamp(12px,4vw,30px);background:var(--c-bg);color:var(--c-text);">
    <div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:20px;">
      <div>
        <div style="font-size:22px;font-weight:800;letter-spacing:-.3px;">Marca da empresa</div>
        <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:4px;">Personalize a cor de destaque e as logos. Valem para toda a equipe.</div>
      </div>

      <!-- Cor de destaque -->
      <div class="r-xs-pad-sm" :style="box">
        <div style="font-size:15px;font-weight:700;margin-bottom:14px;">Cor de destaque</div>
        <div style="display:flex;align-items:center;gap:16px;flex-wrap:wrap;">
          <input v-model="color" type="color" style="width:56px;height:56px;border:none;border-radius:12px;background:none;cursor:pointer;padding:0;">
          <input v-model="color" type="text" placeholder="#25D366" style="width:130px;background:var(--c-surface-3);border:1px solid var(--c-border-strong);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;">
          <button :style="{ background: 'var(--accent)', color: 'var(--accent-ink)', border: 'none', fontFamily: 'inherit', fontSize: '14px', fontWeight: 700, padding: '12px 20px', borderRadius: '11px', cursor: 'pointer', opacity: saving ? 0.7 : 1 }" :disabled="saving" @click="saveColor(false)">
            {{ saving ? 'Salvando…' : 'Salvar cor' }}
          </button>
          <button style="background:var(--c-surface-3);color:var(--c-text-secondary);border:none;font-family:inherit;font-size:14px;font-weight:600;padding:12px 18px;border-radius:11px;cursor:pointer;" @click="saveColor(true)">
            Restaurar padrão
          </button>
        </div>
        <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:16px;">
          <div :style="{ background: 'var(--accent)', color: 'var(--accent-ink)', padding: '9px 16px', borderRadius: '10px', fontWeight: 700, fontSize: '13px' }">Botão</div>
          <div :style="{ background: 'rgba(var(--accent-rgb),.14)', color: 'var(--accent)', padding: '9px 16px', borderRadius: '10px', fontWeight: 700, fontSize: '13px' }">Destaque suave</div>
        </div>
      </div>

      <!-- Logos -->
      <div class="r-xs-pad-sm" :style="box">
        <div style="font-size:15px;font-weight:700;margin-bottom:4px;">Logos</div>
        <div style="font-size:12.5px;color:var(--c-text-muted);margin-bottom:16px;">PNG/SVG/JPG até 2MB. A logo clara é usada no tema claro; a escura, no tema escuro.</div>
        <div class="r-col-1" style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div v-for="v in (['light', 'dark'] as const)" :key="v" style="border:1px solid var(--c-border);border-radius:12px;padding:16px;display:flex;flex-direction:column;gap:12px;">
            <div style="font-size:13px;font-weight:700;color:var(--c-text-secondary);">Logo — tema {{ v === 'light' ? 'claro' : 'escuro' }}</div>
            <div :style="{ height: '76px', borderRadius: '10px', display: 'flex', alignItems: 'center', justifyContent: 'center', background: v === 'light' ? '#f0f2f5' : 'var(--c-bg-deep)', border: '1px solid var(--c-border)' }">
              <img v-if="v === 'light' ? branding.logo_light_url : branding.logo_dark_url" :src="(v === 'light' ? branding.logo_light_url : branding.logo_dark_url) as string" style="max-height:56px;max-width:80%;object-fit:contain;">
              <span v-else style="font-size:12px;color:var(--c-text-muted);">sem logo</span>
            </div>
            <div style="display:flex;gap:8px;flex-wrap:wrap;">
              <label style="flex:1;text-align:center;background:var(--accent);color:var(--accent-ink);font-weight:700;font-size:13px;padding:9px;border-radius:9px;cursor:pointer;">
                Enviar
                <input type="file" accept="image/*" style="display:none;" @change="onLogo($event, v)">
              </label>
              <button v-if="v === 'light' ? branding.logo_light_url : branding.logo_dark_url" style="background:var(--c-surface-3);color:var(--c-danger);border:none;font-family:inherit;font-size:13px;font-weight:600;padding:9px 12px;border-radius:9px;cursor:pointer;" @click="removeLogo(v)">Remover</button>
            </div>
          </div>
        </div>
      </div>

      <div v-if="savedMsg" style="font-size:13px;color:var(--accent);font-weight:600;">{{ savedMsg }}</div>
    </div>
  </div>
</template>
