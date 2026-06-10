<script setup lang="ts">
import type { Ticket } from '~/stores/board'

defineProps<{ ticket: Ticket }>()
defineEmits<{ abrir: [ticket: Ticket] }>()

const corPrioridade: Record<string, string> = {
  BAIXA: 'bg-slate-100 text-slate-600',
  MEDIA: 'bg-blue-100 text-blue-700',
  ALTA: 'bg-orange-100 text-orange-700',
  CRITICA: 'bg-red-100 text-red-700',
}

const labelPrioridade: Record<string, string> = {
  BAIXA: 'Baixa',
  MEDIA: 'Média',
  ALTA: 'Alta',
  CRITICA: 'Crítica',
}

function formatarData(iso: string) {
  return new Date(iso).toLocaleDateString('pt-BR')
}
</script>

<template>
  <article
    class="cursor-pointer rounded-xl border bg-white p-3 shadow-sm transition hover:shadow-md"
    :class="ticket.prioridade === 'CRITICA' ? 'border-red-300 ring-1 ring-red-200' : 'border-slate-200'"
    @click="$emit('abrir', ticket)"
  >
    <div class="mb-1.5 flex items-center justify-between gap-2">
      <span class="font-mono text-xs font-bold text-indigo-600">{{ ticket.codigo }}</span>
      <span class="text-sm">{{ ticket.tipo === 'BUG' ? '🐛' : '✨' }}</span>
    </div>

    <h3 class="mb-2 text-sm font-semibold leading-snug text-slate-900">{{ ticket.titulo }}</h3>

    <div class="mb-2 flex flex-wrap items-center gap-1.5">
      <span class="rounded-full px-2 py-0.5 text-[11px] font-semibold" :class="corPrioridade[ticket.prioridade]">
        {{ labelPrioridade[ticket.prioridade] }}
      </span>
      <span class="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-600">{{ ticket.modulo }}</span>
    </div>

    <div class="flex items-center justify-between text-[11px] text-slate-500">
      <span class="truncate">{{ ticket.solicitante_empresa }} · {{ ticket.solicitante_nome }}</span>
      <span class="ml-2 flex shrink-0 items-center gap-1">
        <span v-if="ticket.anexos?.length" title="Possui anexos">📎{{ ticket.anexos.length }}</span>
        {{ formatarData(ticket.data_solicitacao) }}
      </span>
    </div>
  </article>
</template>
