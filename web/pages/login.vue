<script setup lang="ts">
definePageMeta({ layout: 'blank' })

const { login } = useAuth()

// Marca da empresa dona da instância (logo + cor) p/ personalizar o login.
const brand = ref<{ name?: string, brand_color?: string | null, logo_light_url?: string | null, logo_dark_url?: string | null } | null>(null)
onMounted(async () => {
  try {
    brand.value = await useApi()('/api/public/branding')
    applyBrand(brand.value?.brand_color)
    setFavicon(brand.value?.logo_light_url || brand.value?.logo_dark_url)
  }
  catch {}
})

const email = ref('')
const password = ref('')
const remember = ref(false)
const error = ref('')
const loading = ref(false)

async function submit() {
  if (loading.value) return
  error.value = ''
  loading.value = true
  try {
    await login({ email: email.value, password: password.value, remember: remember.value })
    await navigateTo('/', { replace: true })
  }
  catch (e: any) {
    error.value = e?.response?._data?.message || 'E-mail ou senha inválidos.'
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:24px;background-image:radial-gradient(circle at 20% 30%,rgba(var(--accent-rgb),.05),transparent 42%),radial-gradient(circle at 80% 70%,rgba(124,108,245,.05),transparent 42%);">
    <div style="width:400px;max-width:100%;">
      <div style="display:flex;justify-content:center;margin-bottom:26px;">
        <BrandLogo :company="brand" fallback-name="Vértice CRM" :height="48" />
      </div>

      <form style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:30px;display:flex;flex-direction:column;gap:18px;" @submit.prevent="submit">
        <div>
          <div style="font-size:21px;font-weight:800;">Entrar</div>
          <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:5px;">Acesse o painel da sua equipe.</div>
        </div>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">E-mail</span>
          <input v-model="email" type="email" autocomplete="email" placeholder="voce@empresa.com" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:12px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;">
        </label>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Senha</span>
          <input v-model="password" type="password" autocomplete="current-password" placeholder="••••••••" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:12px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;">
        </label>

        <label style="display:flex;align-items:center;gap:9px;font-size:13px;color:var(--c-text-secondary);cursor:pointer;">
          <input v-model="remember" type="checkbox" style="width:16px;height:16px;accent-color:var(--accent);cursor:pointer;">
          Manter conectado
        </label>

        <div v-if="error" style="display:flex;align-items:center;gap:8px;background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:var(--c-danger-soft);font-size:13px;font-weight:600;padding:11px 14px;border-radius:11px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16v.5" stroke-linecap="round" /></svg>{{ error }}
        </div>

        <button type="submit" :disabled="loading" :style="{ width: '100%', background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '15px', fontWeight: 700, padding: '14px', borderRadius: '13px', cursor: loading ? 'default' : 'pointer', opacity: loading ? 0.7 : 1, boxShadow: '0 6px 16px rgba(var(--accent-rgb),.28)' }">
          {{ loading ? 'Entrando…' : 'Entrar' }}
        </button>
      </form>

      <div style="text-align:center;font-size:13px;color:var(--c-text-muted);margin-top:18px;">
        Ainda não tem conta?
        <NuxtLink to="/register" style="color:var(--accent);font-weight:700;text-decoration:none;">Cadastre sua empresa</NuxtLink>
      </div>
    </div>
  </div>
</template>
