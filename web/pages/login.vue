<script setup lang="ts">
definePageMeta({ layout: 'blank' })

const { login } = useSanctumAuth()

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
    // redireciona via sanctum.redirect.onLogin
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
  <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:24px;background-image:radial-gradient(circle at 20% 30%,rgba(37,211,102,.05),transparent 42%),radial-gradient(circle at 80% 70%,rgba(124,108,245,.05),transparent 42%);">
    <div style="width:400px;max-width:100%;">
      <div style="display:flex;align-items:center;gap:12px;margin-bottom:26px;justify-content:center;">
        <div style="width:46px;height:46px;border-radius:14px;background:#25D366;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(37,211,102,.35);">
          <svg width="24" height="24" viewBox="0 0 24 24" fill="#0b141a"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" /></svg>
        </div>
        <span style="font-size:21px;font-weight:800;letter-spacing:-.3px;">Vértice CRM</span>
      </div>

      <form style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:30px;display:flex;flex-direction:column;gap:18px;" @submit.prevent="submit">
        <div>
          <div style="font-size:21px;font-weight:800;">Entrar</div>
          <div style="font-size:13.5px;color:#8696a0;margin-top:5px;">Acesse o painel da sua equipe.</div>
        </div>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:#aebac1;">E-mail</span>
          <input v-model="email" type="email" autocomplete="email" placeholder="voce@empresa.com" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:12px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;">
        </label>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:#aebac1;">Senha</span>
          <input v-model="password" type="password" autocomplete="current-password" placeholder="••••••••" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:12px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;">
        </label>

        <label style="display:flex;align-items:center;gap:9px;font-size:13px;color:#aebac1;cursor:pointer;">
          <input v-model="remember" type="checkbox" style="width:16px;height:16px;accent-color:#25D366;cursor:pointer;">
          Manter conectado
        </label>

        <div v-if="error" style="display:flex;align-items:center;gap:8px;background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-size:13px;font-weight:600;padding:11px 14px;border-radius:11px;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16v.5" stroke-linecap="round" /></svg>{{ error }}
        </div>

        <button type="submit" :disabled="loading" :style="{ width: '100%', background: '#25D366', border: 'none', color: '#062014', fontFamily: 'inherit', fontSize: '15px', fontWeight: 700, padding: '14px', borderRadius: '13px', cursor: loading ? 'default' : 'pointer', opacity: loading ? 0.7 : 1, boxShadow: '0 6px 16px rgba(37,211,102,.28)' }">
          {{ loading ? 'Entrando…' : 'Entrar' }}
        </button>
      </form>

      <div style="text-align:center;font-size:12.5px;color:#5f6f78;margin-top:18px;">
        Acesso restrito · contate um administrador para criar sua conta.
      </div>
    </div>
  </div>
</template>
