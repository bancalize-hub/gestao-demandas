<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()

// Carrega os dados do CRM no cliente (rotas autenticadas).
onMounted(() => crm.init())
</script>

<template>
  <div style="display:flex;width:100%;height:100vh;min-height:760px;background:#0b141a;color:#e9edef;font-family:'Manrope',sans-serif;overflow:hidden;">
    <CrmRail />
    <slot />
  </div>
</template>
