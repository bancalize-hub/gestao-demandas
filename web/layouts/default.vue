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
    <!--
      Caixa que PRENDE a tela nos dois eixos.

      Sem ela o mobile quebrava inteiro. As telas trazem `flex:1; min-width:0` na raiz —
      `min-width:0` resolve o eixo do desktop, onde este layout é uma LINHA. No celular
      ele vira COLUNA, e aí quem manda é o `min-height`, que por padrão é `auto`: o item
      não encolhe abaixo do próprio conteúdo. A lista de centenas de conversas então
      esticava a tela para milhares de pixels, o `overflow-y:auto` de dentro nunca
      chegava a rolar e a barra de navegação (irmã de baixo, `order:2`) era empurrada
      para fora dos 100dvh e cortada pelo `overflow:hidden` daqui. Resultado: nada rolava
      e não dava para trocar de seção.

      Fica no layout, e não em cada tela, porque o bug é do container — vale para chat,
      funil, agenda, tarefas e contatos igual.
    -->
    <div :style="{ flex: 1, minWidth: 0, minHeight: 0, display: 'flex', overflow: 'hidden' }">
      <slot />
    </div>
  </div>
</template>
