<script setup lang="ts">
definePageMeta({ layout: 'blank' })

const { register } = useAuth()

// Marca da empresa dona da instância (logo + cor) p/ personalizar o cadastro.
const brand = ref<{ name?: string, brand_color?: string | null, logo_light_url?: string | null, logo_dark_url?: string | null } | null>(null)
onMounted(async () => {
  try {
    brand.value = await useApi()('/api/public/branding')
    applyBrand(brand.value?.brand_color)
    setFavicon(brand.value?.logo_light_url || brand.value?.logo_dark_url)
  }
  catch {}
})

const company = ref('')
const name = ref('')
const email = ref('')
const password = ref('')
const error = ref('')
const loading = ref(false)

async function submit() {
  if (loading.value) return
  error.value = ''
  loading.value = true
  try {
    await register({ company: company.value, name: name.value, email: email.value, password: password.value })
    await navigateTo('/', { replace: true })
  }
  catch (e: any) {
    const data = e?.response?._data
    error.value = data?.message
      ? (data.errors ? Object.values(data.errors).flat()[0] as string : data.message)
      : 'Não foi possível criar a conta. Verifique os dados.'
  }
  finally {
    loading.value = false
  }
}
</script>

<template>
  <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:clamp(16px,5vw,24px);background-image:radial-gradient(circle at 20% 30%,rgba(var(--accent-rgb),.05),transparent 42%),radial-gradient(circle at 80% 70%,rgba(124,108,245,.05),transparent 42%);">
    <div style="width:400px;max-width:100%;">
      <div style="display:flex;justify-content:center;margin-bottom:26px;">
        <CrmBrandLogo :company="brand" fallback-name="Vértice CRM" :height="48" />
      </div>

      <form style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:clamp(20px,6vw,30px);display:flex;flex-direction:column;gap:16px;" @submit.prevent="submit">
        <div>
          <div style="font-size:21px;font-weight:800;">Criar conta da empresa</div>
          <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:5px;">Atenda seus clientes com seu próprio WhatsApp, isolado.</div>
        </div>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Nome da empresa</span>
          <input v-model="company" type="text" autocomplete="organization" placeholder="Minha Empresa Ltda" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:12px 13px;color:var(--c-text);font-family:inherit;font-size:16px;outline:none;">
        </label>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Seu nome</span>
          <input v-model="name" type="text" autocomplete="name" placeholder="Seu nome" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:12px 13px;color:var(--c-text);font-family:inherit;font-size:16px;outline:none;">
        </label>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">E-mail</span>
          <input v-model="email" type="email" autocomplete="email" placeholder="voce@empresa.com" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:12px 13px;color:var(--c-text);font-family:inherit;font-size:16px;outline:none;">
        </label>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Senha</span>
          <CrmPasswordField v-model="password" autocomplete="new-password" placeholder="mínimo 8 caracteres" />
        </label>

        <div v-if="error" style="display:flex;align-items:center;gap:8px;background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:var(--c-danger-soft);font-size:13px;font-weight:600;padding:11px 14px;border-radius:11px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16v.5" stroke-linecap="round" /></svg>{{ error }}
        </div>

        <button type="submit" :disabled="loading" :style="{ width: '100%', background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '15px', fontWeight: 700, padding: '14px', borderRadius: '13px', cursor: loading ? 'default' : 'pointer', opacity: loading ? 0.7 : 1, boxShadow: '0 6px 16px rgba(var(--accent-rgb),.28)' }">
          {{ loading ? 'Criando…' : 'Criar conta' }}
        </button>
      </form>

      <div style="text-align:center;font-size:13px;color:var(--c-text-muted);margin-top:18px;">
        Já tem conta?
        <NuxtLink to="/login" style="color:var(--accent);font-weight:700;text-decoration:none;">Entrar</NuxtLink>
      </div>
    </div>
  </div>
</template>
