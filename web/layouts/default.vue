<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
const isMobile = useIsMobile()

// Carrega os dados do CRM. O tempo real vem do WebSocket (plugins/echo.client.ts);
// o polling abaixo é só um fallback de segurança caso o WS caia.
let poll: ReturnType<typeof setInterval> | null = null
onMounted(async () => {
  await crm.init()
  // Só rede de segurança: o tempo real de verdade chega pelo WebSocket com payload
  // (message.new/message.patch). 30s de polling dobrava a carga à toa.
  poll = setInterval(() => crm.refreshBoards(), 60000)
})
onBeforeUnmount(() => { if (poll) clearInterval(poll) })
</script>

<template>
  <div :style="{ display: 'flex', flexDirection: isMobile ? 'column' : 'row', width: '100%', height: '100dvh', background: 'var(--c-bg-deep)', color: 'var(--c-text)', fontFamily: 'Manrope, sans-serif', overflow: 'hidden' }">
    <CrmRail />
    <slot />
  </div>
</template>
