<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
const isMobile = useIsMobile()

// Carrega os dados do CRM. O tempo real vem do WebSocket (plugins/echo.client.ts);
// o polling abaixo é só um fallback de segurança caso o WS caia.
let poll: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  await crm.init()
  poll = setInterval(() => crm.refreshBoards(), 30000)
})
onBeforeUnmount(() => { if (poll) clearInterval(poll) })
</script>

<template>
  <div :style="{ display: 'flex', flexDirection: isMobile ? 'column' : 'row', width: '100%', height: '100dvh', background: '#0b141a', color: '#e9edef', fontFamily: 'Manrope, sans-serif', overflow: 'hidden' }">
    <CrmRail />
    <slot />
  </div>
</template>
