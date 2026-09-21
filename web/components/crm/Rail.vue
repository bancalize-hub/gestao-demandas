<script setup lang="ts">
import { SCREEN_ROUTES, useCrmStore, type Screen } from '~/stores/crm'

const crm = useCrmStore()
const route = useRoute()
const { user, logout } = useAuth()
const { isDark, toggle } = useTheme()
const isMobile = useIsMobile()
// Chats duplicados: um balão por chat, cada um abrindo sua página (tab e filtros próprios).
// Excluir um chat é só DENTRO da página dele (× no topo da lista) — o × que ficava aqui
// no balão saiu porque era fácil excluir sem querer.
const { chats, order, setOrder } = useChats()
function chatPath(i: number) { return i === 1 ? '/' : `/chat-${i}` }
function goChat(i: number) {
  if (i === 1) return crm.go('chat')
  crm.screen = 'chat'
  navigateTo(chatPath(i))
}
// Arrastar um balão sobre outro reordena (a hierarquia que o usuário quiser). O balão
// mantém o número dele — muda só a posição na barra.
const dragChat = ref<number | null>(null)
function dropChat(alvo: number) {
  const de = dragChat.value
  dragChat.value = null
  if (!de || de === alvo) return
  const o = order.value.filter(x => x !== de)
  o.splice(o.indexOf(alvo), 0, de)
  setOrder(o)
}

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
const hideOnMobile = computed(() => isMobile.value && (isActive('chat') || route.path.startsWith('/chat-')) && crm.chatOpen)
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

// Mini menu do avatar (sair, tema, usuários, WhatsApp). Fica fora da Rail (Teleport)
// porque a barra do celular tem overflow-x: auto e cortaria o popover.
const MENU_W = 236
const menuOpen = ref(false)
const avatarEl = ref<HTMLElement | null>(null)
const menuStyle = ref<Record<string, string>>({})

function placeMenu() {
  const r = avatarEl.value?.getBoundingClientRect()
  if (!r) return
  const base = { position: 'fixed', width: `${MENU_W}px`, zIndex: '3000' }
  if (isMobile.value) {
    const left = Math.min(Math.max(12, r.left + r.width / 2 - MENU_W / 2), window.innerWidth - MENU_W - 12)
    menuStyle.value = { ...base, left: `${left}px`, bottom: `${window.innerHeight - r.top + 10}px` }
  }
  else {
    menuStyle.value = { ...base, left: `${r.right + 10}px`, bottom: `${Math.max(12, window.innerHeight - r.bottom)}px` }
  }
}

function toggleMenu() {
  menuOpen.value = !menuOpen.value
  if (menuOpen.value) nextTick(placeMenu)
}

function goFromMenu(path: string) {
  menuOpen.value = false
  navigateTo(path)
}

// Fecha ao trocar de tela; o toggle de tema mantém o menu aberto de propósito.
watch(() => route.path, () => { menuOpen.value = false })

function onKeydown(e: KeyboardEvent) {
  if (e.key === 'Escape') menuOpen.value = false
}
onMounted(() => {
  window.addEventListener('keydown', onKeydown)
  window.addEventListener('resize', placeMenu)
})
onBeforeUnmount(() => {
  window.removeEventListener('keydown', onKeydown)
  window.removeEventListener('resize', placeMenu)
})
</script>

<template>
  <nav :style="railStyle">
    <img v-if="!isMobile && companyLogo" :src="companyLogo" alt="Logo" style="width:42px;height:42px;border-radius:13px;object-fit:contain;margin-bottom:14px;">
    <div v-else-if="!isMobile" style="width:42px;height:42px;border-radius:13px;background:var(--accent);display:flex;align-items:center;justify-content:center;margin-bottom:14px;box-shadow:0 6px 16px rgba(var(--accent-rgb),.35);">
      <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" fill="var(--accent-ink)" /></svg>
    </div>

    <button v-for="i in order" :key="i" class="navbtn" :style="[nav(route.path === chatPath(i)), chats > 1 ? { position: 'relative' } : {}]" :title="chats > 1 ? `Chat ${i} — arraste para reordenar` : 'Chat'" :draggable="!isMobile && chats > 1" @click="goChat(i)" @dragstart="dragChat = i" @dragover.prevent @drop.prevent="dropChat(i)">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.34 9 9 0 0 1-3.9-.9L3 21l1.06-4.1A8.38 8.38 0 0 1 3 11.5 8.5 8.5 0 0 1 21 11.5Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
      <span v-if="chats > 1" class="chatbadge">{{ i }}</span>
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
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/campanhas')" title="Campanhas (prospecção)" @click="navigateTo('/admin/campanhas')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M21.5 3.2 2.8 10.4a.6.6 0 0 0 .05 1.13l4.9 1.6 1.6 4.9a.6.6 0 0 0 1.13.05Z" stroke-linejoin="round" /><path d="m21.5 3.2-13.75 9.93" stroke-linecap="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path.startsWith('/marketing'))" title="Gerenciador de anúncios (Facebook Ads)" @click="navigateTo('/marketing')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11v2a1 1 0 0 0 1 1h2v4h2v-4l10 4.5v-15L8 8H4a1 1 0 0 0-1 1Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
    </button>
    <button v-if="user?.is_admin" class="navbtn" :style="nav(route.path === '/admin/memoria')" title="Memória da IA" @click="navigateTo('/admin/memoria')">
      <svg width="23" height="23" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M9 3a3 3 0 0 0-3 3 3 3 0 0 0-1 5.8V15a3 3 0 0 0 3 3 2.5 2.5 0 0 0 5 0V5.5A2.5 2.5 0 0 0 9.5 3Z" /><path d="M15 3a3 3 0 0 1 3 3 3 3 0 0 1 1 5.8V15a3 3 0 0 1-3 3" /></svg>
    </button>
    <div v-if="!isMobile" style="flex:1;" />

    <button
      ref="avatarEl" class="avatar" :title="user?.name || ''" aria-haspopup="menu" :aria-expanded="menuOpen"
      :style="{ width: '40px', height: '40px', borderRadius: '50%', border: 'none', cursor: 'pointer', background: 'linear-gradient(135deg,var(--accent),var(--accent-deep))', display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '14px', color: 'var(--accent-ink)', marginTop: isMobile ? '0' : '4px', flexShrink: 0, outline: menuOpen ? '2px solid var(--accent)' : 'none', outlineOffset: '2px' }"
      @click="toggleMenu()"
    >
      {{ initials }}
    </button>

    <Teleport to="body">
      <div v-if="menuOpen" style="position:fixed;inset:0;z-index:2999;" @click="menuOpen = false" />
      <div v-if="menuOpen" class="usermenu" role="menu" :style="menuStyle">
        <div class="usermenu-head">
          <div class="usermenu-name">{{ user?.name }}</div>
          <div class="usermenu-mail">{{ user?.email }}</div>
        </div>

        <button v-if="user?.is_admin" class="usermenu-item" :class="{ active: route.path === '/admin/usuarios' }" role="menuitem" @click="goFromMenu('/admin/usuarios')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="9" cy="8" r="3.2" /><path d="M3.5 20a5.5 5.5 0 0 1 11 0M17 5.5a3 3 0 0 1 0 5.4M21 20a5.5 5.5 0 0 0-3.5-5.1" stroke-linecap="round" /></svg>
          Usuários
        </button>
        <button v-if="user?.is_admin" class="usermenu-item" :class="{ active: route.path === '/admin/whatsapp' }" role="menuitem" @click="goFromMenu('/admin/whatsapp')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
          Conexão WhatsApp
        </button>

        <button v-if="user?.is_admin" class="usermenu-item" :class="{ active: route.path === '/admin/atendimento' }" role="menuitem" @click="goFromMenu('/admin/atendimento')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="8.5" /><path d="M12 7v5l3.2 2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          Horário de atendimento
        </button>
        <button v-if="user?.is_admin" class="usermenu-item" :class="{ active: route.path === '/admin/marca' }" role="menuitem" @click="goFromMenu('/admin/marca')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 3a9 9 0 1 0 0 18c1.4 0 2.2-.9 2.2-2 0-1-.8-1.6-.8-2.4 0-.7.6-1.3 1.4-1.3H17a4 4 0 0 0 4-4c0-4.4-4-8.3-9-8.3Z" stroke-linejoin="round" /><circle cx="7.5" cy="12" r="1.1" fill="currentColor" stroke="none" /><circle cx="10" cy="7.8" r="1.1" fill="currentColor" stroke="none" /><circle cx="14.5" cy="7.8" r="1.1" fill="currentColor" stroke="none" /></svg>
          Marca (cores e logo)
        </button>

        <button v-if="user?.is_super_admin" class="usermenu-item" :class="{ active: route.path === '/super' }" role="menuitem" @click="goFromMenu('/super')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M12 2.8 4.5 6v5.5c0 4.4 3.1 8.2 7.5 9.7 4.4-1.5 7.5-5.3 7.5-9.7V6L12 2.8Z" stroke-linejoin="round" /><path d="M9.2 12.2 11 14l4-4.2" stroke-linecap="round" stroke-linejoin="round" /></svg>
          Plataforma (todas as empresas)
        </button>

        <button v-if="user?.is_super_admin" class="usermenu-item" :class="{ active: route.path === '/agente' }" role="menuitem" @click="goFromMenu('/agente')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="6" width="16" height="13" rx="2.5" /><path d="M9 2.5v3.5M15 2.5v3.5M9.5 12h.01M14.5 12h.01M9 16h6" stroke-linecap="round" /></svg>
          Agente (opera a VPS)
        </button>

        <div v-if="user?.is_admin || user?.is_super_admin" class="usermenu-sep" />

        <button class="usermenu-item" role="menuitem" @click="toggle()">
          <svg v-if="isDark" width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="12" cy="12" r="4.2" /><path d="M12 2.5v2.2M12 19.3v2.2M4.6 4.6l1.6 1.6M17.8 17.8l1.6 1.6M2.5 12h2.2M19.3 12h2.2M4.6 19.4l1.6-1.6M17.8 6.2l1.6-1.6" stroke-linecap="round" /></svg>
          <svg v-else width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M20 14.5A8 8 0 0 1 9.5 4a7 7 0 1 0 10.5 10.5Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
          {{ isDark ? 'Tema claro' : 'Tema escuro' }}
        </button>

        <button v-if="user?.is_admin" class="usermenu-item" :class="{ active: route.path === '/admin/meta-ads' }" role="menuitem" @click="goFromMenu('/admin/meta-ads')">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 11v2a1 1 0 0 0 1 1h2v4h2v-4l10 4.5v-15L8 8H4a1 1 0 0 0-1 1Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
          Conexão Facebook Ads
        </button>

        <div class="usermenu-sep" />

        <button class="usermenu-item danger" role="menuitem" @click="logout()">
          <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 12H4m0 0 3.5-3.5M4 12l3.5 3.5M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
          Sair
        </button>
      </div>
    </Teleport>
  </nav>
</template>

<style scoped>
.navbtn:hover {
  background: rgba(var(--accent-rgb),0.1) !important;
}
/* Numerinho que separa os balões de chat quando o chat está duplicado. */
.chatbadge {
  position: absolute;
  top: 5px;
  right: 5px;
  min-width: 14px;
  height: 14px;
  border-radius: 7px;
  background: var(--accent);
  color: var(--accent-ink);
  font-size: 9px;
  font-weight: 800;
  display: flex;
  align-items: center;
  justify-content: center;
  padding: 0 2px;
}
.avatar:hover {
  filter: brightness(1.08);
}

.usermenu {
  background: var(--c-surface-2);
  border: 1px solid var(--c-border);
  border-radius: 14px;
  box-shadow: 0 16px 40px rgba(0, 0, 0, .28);
  padding: 6px;
  font-family: Manrope, sans-serif;
  color: var(--c-text);
}
.usermenu-head {
  padding: 8px 10px 10px;
  border-bottom: 1px solid var(--c-border);
  margin-bottom: 6px;
}
.usermenu-name {
  font-weight: 700;
  font-size: 13.5px;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.usermenu-mail {
  font-size: 11.5px;
  color: var(--c-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}
.usermenu-item {
  width: 100%;
  display: flex;
  align-items: center;
  gap: 10px;
  padding: 9px 10px;
  border: none;
  border-radius: 10px;
  background: transparent;
  color: var(--c-text);
  font: inherit;
  font-size: 13.5px;
  text-align: left;
  cursor: pointer;
}
.usermenu-item.active {
  color: var(--accent);
  background: rgba(var(--accent-rgb), .1);
}
.usermenu-item:hover {
  background: rgba(var(--accent-rgb), .12);
  color: var(--accent);
}
.usermenu-item.danger:hover {
  background: rgba(239, 68, 68, .14);
  color: #ef4444;
}
.usermenu-sep {
  height: 1px;
  background: var(--c-border);
  margin: 6px 4px;
}
</style>
