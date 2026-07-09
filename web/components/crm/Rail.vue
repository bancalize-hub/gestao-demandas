<script setup lang="ts">
import { SCREEN_ROUTES, useCrmStore, type Screen } from '~/stores/crm'

const crm = useCrmStore()
const route = useRoute()
const { user, logout } = useAuth()
const { isDark, toggle } = useTheme()
const isMobile = useIsMobile()

// Logo da empresa para o topo do Rail (variante conforme o tema), se houver.
const companyLogo = computed(() => {
  const c = user.value?.company
  if (!c) return null
  return (isDark.value ? c.logo_dark_url : c.logo_light_url) || c.logo_light_url || c.logo_dark_url || null
})

function isActive(s: Screen) {
  return route.path === SCREEN_ROUTES[s]
}

// No celular a Rail vira uma barra inferior horizontal. Ela some quando uma conversa
// está aberta no chat, para o teclado/composer ocuparem a tela toda.
const hideOnMobile = computed(() => isMobile.value && isActive('chat') && crm.chatOpen)
const railStyle = computed(() => isMobile.value
  ? { order: 2, width: '100%', height: '58px', flexShrink: 0, background: 'var(--c-surface-2)', borderTop: '1px solid var(--c-border)', display: hideOnMobile.value ? 'none' : 'flex', flexDirection: 'row', alignItems: 'center', justifyContent: 'space-around', padding: '0 4px', gap: '2px', overflowX: 'auto' }
  : { width: '76px', flexShrink: 0, background: 'var(--c-surface-2)', borderRight: '1px solid var(--c-border)', display: 'flex', flexDirection: 'column', alignItems: 'center', padding: '18px 0', gap: '6px' })

function nav(active: boolean) {
  return {
    width: '46px', height: '46px', borderRadius: '14px', display: 'flex',
    alignItems: 'center', justifyContent: 'center', border: 'none', cursor: 'pointer',
    transition: 'all .15s', background: active ? 'rgba(var(--accent-rgb),0.14)' : 'transparent',
    color: active ? 'var(--accent)' : 'var(--c-text-muted)',
  }
}

const initials = computed(() => {
  const n = user.value?.name?.trim() || ''
  const parts = n.split(/\s+/).filter(Boolean)
  return ((parts[0]?.[0] ?? '') + (parts[1]?.[0] ?? '')).toUpperCase() || 'U'
})
</script>

<template>
  <nav :style="railStyle">
    <img v-if="!isMobile && companyLogo" :src="companyLogo" alt="Logo" style="width:42px;height:42px;border-radius:13px;object-fit:contain;margin-bottom:14px;">
    <div v-else-if="!isMobile" style="width:42px;height:42px;border-radius:13px;background:var(--accent);display:flex;align-items:center;justify-content:center;margin-bottom:14px;box-shadow:0 6px 16px rgba(var(--accent-rgb),.35);">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" fill="var(--accent-ink)" /></svg>
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
    <button class="navbtn" :style="nav(isActive('contacts'))" title="Contatos" @click="crm.go('contacts')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="8" r="3.4" /><path d="M5.5 20a6.5 6.5 0 0 1 13 0" stroke-linecap="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/usuarios')" title="Usuários" @click="navigateTo('/admin/usuarios')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0M17 5.5a3 3 0 0 1 0 5.4M21 20a5.5 5.5 0 0 0-3.5-5.1" stroke-linecap="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/whatsapp')" title="WhatsApp" @click="navigateTo('/admin/whatsapp')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/campanhas')" title="Campanhas (prospecção)" @click="navigateTo('/admin/campanhas')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11v2a1 1 0 0 0 1 1h2l3.5 3.5V7.5L6 11H4a1 1 0 0 0-1 0Z" stroke-linecap="round" stroke-linejoin="round" /><path d="m9.5 7.5 9-4v17l-9-4M18.5 9.5a3 3 0 0 1 0 5" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/memoria')" title="Memória da IA" @click="navigateTo('/admin/memoria')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 3a3 3 0 0 0-3 3 3 3 0 0 0-1 5.8V15a3 3 0 0 0 3 3 2.5 2.5 0 0 0 5 0V5.5A2.5 2.5 0 0 0 9.5 3Z" /><path d="M15 3a3 3 0 0 1 3 3 3 3 0 0 1 1 5.8V15a3 3 0 0 1-3 3" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/automacoes')" title="Automações de etapa" @click="navigateTo('/admin/automacoes')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M4 6h9M4 12h6M4 18h10" stroke-linecap="round" /><path d="m16 8 3.2 3.2L16 14.5" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/marca')" title="Marca (cores e logo)" @click="navigateTo('/admin/marca')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3a9 9 0 1 0 0 18c1.4 0 2.2-.9 2.2-2 0-1-.8-1.6-.8-2.4 0-.7.6-1.3 1.4-1.3H17a4 4 0 0 0 4-4c0-4.4-4-8.3-9-8.3Z" stroke-linejoin="round" /><circle cx="7.5" cy="12" r="1.1" fill="currentColor" stroke="none" /><circle cx="10" cy="7.8" r="1.1" fill="currentColor" stroke="none" /><circle cx="14.5" cy="7.8" r="1.1" fill="currentColor" stroke="none" /></svg>
    </button>

    <button v-if="user?.is_super_admin" class="navbtn" :style="nav(route.path === '/agente')" title="Agente (opera a VPS)" @click="navigateTo('/agente')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="6" width="16" height="13" rx="2.5" /><path d="M9 2.5v3.5M15 2.5v3.5M9.5 12h.01M14.5 12h.01M9 16h6" stroke-linecap="round" /></svg>
    </button>

    <div v-if="!isMobile" style="flex:1;" />

    <button class="navbtn" :title="isDark ? 'Tema claro' : 'Tema escuro'" :style="nav(false)" @click="toggle()">
      <svg v-if="isDark" width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4.2" /><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6" stroke-linecap="round" /></svg>
      <svg v-else width="21" height="21" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 14.5A8 8 0 0 1 9.5 4a7 7 0 1 0 10.5 10.5Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>

    <button class="navbtn" title="Sair" :style="nav(false)" @click="logout()">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 12H4m0 0 3.5-3.5M4 12l3.5 3.5M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <div v-if="!isMobile" :title="user?.name || ''" style="width:40px;height:40px;border-radius:50%;background:linear-gradient(135deg,var(--accent),var(--accent-deep));display:flex;align-items:center;justify-content:center;font-weight:700;font-size:14px;color:var(--accent-ink);margin-top:4px;">
      {{ initials }}
    </div>
  </nav>
</template>

<style scoped>
.navbtn:hover {
  background: rgba(var(--accent-rgb),0.1) !important;
}
</style>
