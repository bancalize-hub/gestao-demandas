<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

// Trocar de /chat-2 para /chat-3 é a MESMA rota dinâmica: sem esta key o Nuxt
// reaproveita a página montada e o chat continuaria com a instância (e os filtros)
// antiga — era o bug de "as tags vão junto" ao trocar de balão.
definePageMeta({ key: route => route.fullPath })

const route = useRoute()
// /chat-2, /chat-3, … — o número da URL é a instância do chat (limites do useChats).
const n = Math.max(2, Math.min(MAX_CHATS, Number.parseInt(String(route.params.n), 10) || 2))

// Mesmo comportamento de "estar no chat" (não-lidas, tempo real) da página principal.
useCrmStore().screen = 'chat'
</script>

<template>
  <CrmScreenChat :instance="n" />
</template>
