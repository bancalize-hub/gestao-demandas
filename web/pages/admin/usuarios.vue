<script setup lang="ts">
interface U { id: number, name: string, email: string, is_admin: boolean }

const api = useApi()

const users = ref<U[]>([])
const loading = ref(true)

const form = reactive({ name: '', email: '', password: '', is_admin: false })
const saving = ref(false)
const error = ref('')
const ok = ref(false)

async function load() {
  loading.value = true
  try {
    const r = await api<{ users: U[] }>('/api/users')
    users.value = r.users
  }
  catch { /* */ }
  finally {
    loading.value = false
  }
}

async function create() {
  if (saving.value) return
  error.value = ''
  ok.value = false
  saving.value = true
  try {
    await api('/api/users', { method: 'POST', body: { ...form } })
    form.name = ''
    form.email = ''
    form.password = ''
    form.is_admin = false
    ok.value = true
    await load()
  }
  catch (e: any) {
    const errs = e?.response?._data?.errors
    error.value = errs ? Object.values(errs).flat().join(' ') : (e?.response?._data?.message || 'Erro ao criar usuário.')
  }
  finally {
    saving.value = false
  }
}

function initials(name: string) {
  const p = name.trim().split(/\s+/).filter(Boolean)
  return ((p[0]?.[0] ?? '') + (p[1]?.[0] ?? '')).toUpperCase() || 'U'
}

onMounted(load)
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--c-ai);display:flex;align-items:center;justify-content:center;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--c-on-accent)" stroke-width="2"><circle cx="9" cy="8" r="3.2" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0M17 5.5a3 3 0 0 1 0 5.4M21 20a5.5 5.5 0 0 0-3.5-5.1" stroke-linecap="round" /></svg>
      </div>
      <div>
        <div style="font-weight:800;font-size:15px;">Usuários</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Acesso ao painel · somente administradores</div>
      </div>
    </div>

    <div style="flex:1;display:flex;align-items:flex-start;justify-content:center;padding:34px 24px 60px;">
      <div style="width:760px;max-width:100%;display:flex;flex-direction:column;gap:24px;">
        <!-- novo usuário -->
        <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
          <div style="font-size:17px;font-weight:800;">Novo usuário</div>
          <div style="font-size:13px;color:var(--c-text-muted);margin-top:4px;">Crie um acesso para um membro da equipe.</div>

          <div style="margin-top:20px;display:flex;flex-direction:column;gap:16px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Nome</span><input v-model="form.name" placeholder="Nome completo" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"></label>
              <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">E-mail</span><input v-model="form.email" type="email" placeholder="email@empresa.com" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"></label>
            </div>
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:end;">
              <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Senha (mín. 8)</span><input v-model="form.password" type="password" placeholder="••••••••" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:11px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"></label>
              <label style="display:flex;align-items:center;gap:9px;font-size:13.5px;color:var(--c-text-secondary);cursor:pointer;padding:11px 0;"><input v-model="form.is_admin" type="checkbox" style="width:16px;height:16px;accent-color:var(--c-ai);cursor:pointer;">Administrador</label>
            </div>

            <div v-if="error" style="display:flex;align-items:center;gap:8px;background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:var(--c-danger-soft);font-size:13px;font-weight:600;padding:11px 14px;border-radius:11px;">{{ error }}</div>
            <div v-if="ok" style="display:flex;align-items:center;gap:8px;background:rgba(var(--accent-rgb),.1);border:1px solid rgba(var(--accent-rgb),.3);color:var(--accent-soft);font-size:13px;font-weight:600;padding:11px 14px;border-radius:11px;">Usuário criado com sucesso.</div>

            <button :disabled="saving" :style="{ alignSelf: 'flex-start', background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '14px', fontWeight: 700, padding: '12px 22px', borderRadius: '12px', cursor: saving ? 'default' : 'pointer', opacity: saving ? 0.7 : 1 }" @click="create">
              {{ saving ? 'Criando…' : 'Criar usuário' }}
            </button>
          </div>
        </div>

        <!-- lista -->
        <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:24px;">
          <div style="font-size:15px;font-weight:800;margin-bottom:16px;">Equipe ({{ users.length }})</div>
          <div v-if="loading" style="font-size:13px;color:var(--c-text-muted);">Carregando…</div>
          <div v-else style="display:flex;flex-direction:column;gap:10px;">
            <div v-for="u in users" :key="u.id" style="display:flex;align-items:center;gap:13px;background:var(--c-surface-2);border-radius:12px;padding:12px 15px;">
              <div style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-deep));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--accent-ink);flex-shrink:0;">{{ initials(u.name) }}</div>
              <div style="flex:1;min-width:0;">
                <div style="font-weight:700;font-size:14px;">{{ u.name }}</div>
                <div style="font-size:12.5px;color:var(--c-text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ u.email }}</div>
              </div>
              <span v-if="u.is_admin" style="font-size:11px;font-weight:700;color:var(--c-ai-soft);background:rgba(124,108,245,.16);padding:4px 10px;border-radius:7px;flex-shrink:0;">Admin</span>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
