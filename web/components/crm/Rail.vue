<script setup lang="ts">
import { SCREEN_ROUTES, useCrmStore, type Screen } from '~/stores/crm'

const crm = useCrmStore()
const route = useRoute()
const { user, logout } = useAuth()

function isActive(s: Screen) {
  return route.path === SCREEN_ROUTES[s]
}

function nav(active: boolean) {
  return {
    width: '46px', height: '46px', borderRadius: '14px', display: 'flex',
    alignItems: 'center', justifyContent: 'center', border: 'none', cursor: 'pointer',
    transition: 'all .15s', background: active ? 'rgba(37,211,102,0.14)' : 'transparent',
    color: active ? '#25D366' : '#8696a0',
  }
}

const initials = computed(() => {
  const n = user.value?.name?.trim() || ''
  const parts = n.split(/\s+/).filter(Boolean)
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || 'U'
})
</script>

<template>
  <nav style="width:76px;flex-shrink:0;background:#0a0f12;border-right:1px solid #1c2730;display:flex;flex-direction:column;align-items:center;padding:18px 0;gap:6px;">
    <div style="width:42px;height:42px;border-radius:13px;background:#25D366;display:flex;align-items:center;justify-content:center;margin-bottom:14px;box-shadow:0 6px 16px rgba(37,211,102,.35);">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" fill="#0a0f12" /></svg>
    </div>

    <button class="navbtn" :style="nav(isActive('chat'))" title="Chat" @click="crm.go('chat')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.34 9 9 0 0 1-3.9-.9L3 21l1.06-4.1A8.38 8.38 0 0 1 3 11.5 8.5 8.5 0 0 1 21 11.5Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <button class="navbtn" :style="nav(isActive('pipeline'))" title="Funil" @click="crm.go('pipeline')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="3" width="5" height="18" rx="1.5" /><rect x="9.5" y="3" width="5" height="12" rx="1.5" /><rect x="16" y="3" width="5" height="15" rx="1.5" /></svg>
    </button>
    <button class="navbtn" :style="nav(isActive('tasks'))" title="Tarefas" @click="crm.go('tasks')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="3.5" /><path d="m8 12.2 2.4 2.4L16 9" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <button class="navbtn" :style="nav(isActive('agenda'))" title="Agenda" @click="crm.go('agenda')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="3" y="4.5" width="18" height="16" rx="2.5" /><path d="M3 9h18M8 2.5v4M16 2.5v4" stroke-linecap="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path.startsWith('/admin'))" title="Usuários" @click="navigateTo('/admin/usuarios')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0M17 5.5a3 3 0 0 1 0 5.4M21 20a5.5 5.5 0 0 0-3.5-5.1" stroke-linecap="round" /></svg>
    </button>

    <div style="flex:1;" />

    <button class="navbtn" title="Sair" :style="nav(false)" @click="logout()">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 12H4m0 0 3.5-3.5M4 12l3.5 3.5M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <div :title="user?.name || ''" style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,#25D366,#0e8a4f);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:#062014;margin-top:4px;">
      {{ initials }}
    </div>
  </nav>
</template>

<style scoped>
.navbtn:hover {
  background: rgba(37, 211, 102, 0.1) !important;
}
</style>
