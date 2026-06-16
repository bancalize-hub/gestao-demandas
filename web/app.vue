<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()

onMounted(() => crm.init())
</script>

<template>
  <div style="display:flex;width:100%;height:100vh;min-height:760px;background:#0b141a;color:#e9edef;font-family:'Manrope',sans-serif;overflow:hidden;">
    <CrmRail />
    <CrmScreenChat v-if="crm.screen === 'chat'" />
    <CrmScreenPipeline v-else-if="crm.screen === 'pipeline'" />
    <CrmScreenTasks v-else-if="crm.screen === 'tasks'" />
    <CrmScreenAgenda v-else-if="crm.screen === 'agenda'" />
    <CrmScreenMeeting v-else-if="crm.screen === 'meeting'" />
    <CrmScreenContact v-else-if="crm.screen === 'contact'" />
    <CrmScreenForm v-else-if="crm.screen === 'form'" />
    <CrmScreenMobile v-else-if="crm.screen === 'mobile'" />
  </div>
</template>
