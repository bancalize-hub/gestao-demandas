<script setup lang="ts">
import { type BoardCard, prazoMeta, useBoardStore, usePrioMeta } from '~/stores/board'

/**
 * Cartão do quadro. Mostra de relance o que o Trello mostra: etiquetas, título,
 * prazo, progresso do checklist, quantos comentários e anexos tem e quem é o
 * responsável. O resto abre no modal.
 */
const props = defineProps<{ card: BoardCard, arrastando?: boolean }>()

const board = useBoardStore()

const etiquetas = computed(() => props.card.label_ids
  .map(id => board.labelById(id))
  .filter(Boolean) as { id: number, name: string, color: string }[])

const prazo = computed(() => prazoMeta(props.card))
const prio = computed(() => usePrioMeta(props.card.priority))

/** Iniciais para o avatar — duas letras dão para distinguir o time inteiro. */
function iniciais(nome: string) {
  const partes = nome.trim().split(/\s+/).filter(Boolean)
  if (!partes.length) return '?'
  return (partes[0][0] + (partes.length > 1 ? partes[partes.length - 1][0] : '')).toUpperCase()
}

/** Cor estável por pessoa: o mesmo nome cai sempre no mesmo tom. */
function corDe(id: number) {
  const cores = ['#53bdeb', '#25D366', '#ffb443', '#a78bfa', '#ff6b6b', '#4fd1c5']
  return cores[id % cores.length]
}
</script>

<template>
  <div
    class="bcard" :style="{ opacity: arrastando ? 0.4 : 1 }"
  >
    <div v-if="etiquetas.length" style="display:flex;flex-wrap:wrap;gap:4px;margin-bottom:7px;">
      <span
        v-for="l in etiquetas" :key="l.id" :title="l.name || 'Etiqueta'"
        :style="{ background: l.color, height: l.name ? 'auto' : '7px', minWidth: '30px', borderRadius: '5px', padding: l.name ? '1px 7px' : '0', fontSize: '10.5px', fontWeight: 700, color: '#10231c' }"
      >{{ l.name }}</span>
    </div>

    <div class="r-break" style="font-weight:650;font-size:13.5px;line-height:1.38;">{{ card.title }}</div>

    <div v-if="card.client" class="r-ellipsis" style="font-size:11.5px;color:var(--c-text-muted);margin-top:5px;">{{ card.client }}</div>

    <div style="display:flex;align-items:center;flex-wrap:wrap;gap:7px;margin-top:9px;">
      <span
        v-if="prazo"
        :style="{ fontSize: '10.5px', fontWeight: 700, color: prazo.color, background: prazo.bg, padding: '2px 7px', borderRadius: '6px', display: 'inline-flex', alignItems: 'center', gap: '4px' }"
      >
        <svg width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" stroke-linecap="round" /></svg>
        {{ prazo.label }}
      </span>

      <span v-if="card.priority !== 'media'" :style="{ fontSize: '10px', fontWeight: 700, color: prio.color, background: `${prio.color}22`, padding: '2px 7px', borderRadius: '6px' }">{{ prio.label }}</span>

      <span v-if="card.type" style="font-size:10px;color:var(--c-text-muted);background:var(--c-bg-deep);padding:2px 7px;border-radius:6px;">{{ card.type }}</span>

      <span
        v-if="card.checklist_total"
        :style="{ fontSize: '10.5px', fontWeight: 600, display: 'inline-flex', alignItems: 'center', gap: '3px', color: card.checklist_done === card.checklist_total ? '#25D366' : 'var(--c-text-muted)' }"
      >
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><rect x="4" y="4" width="16" height="16" rx="3.5" /><path d="m8 12.2 2.4 2.4L16 9" stroke-linecap="round" stroke-linejoin="round" /></svg>
        {{ card.checklist_done }}/{{ card.checklist_total }}
      </span>

      <span v-if="card.description" title="Tem descrição" style="color:var(--c-text-faint);display:inline-flex;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M5 7h14M5 12h14M5 17h8" stroke-linecap="round" /></svg>
      </span>

      <span v-if="card.comments_count" style="font-size:10.5px;color:var(--c-text-muted);display:inline-flex;align-items:center;gap:3px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M20 12a7.5 7.5 0 0 1-10.9 6.7L4 20l1.4-4.6A7.5 7.5 0 1 1 20 12Z" stroke-linejoin="round" /></svg>
        {{ card.comments_count }}
      </span>

      <span v-if="card.attachments_count" style="font-size:10.5px;color:var(--c-text-muted);display:inline-flex;align-items:center;gap:3px;">
        <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M18 8.5 10.2 16.3a3 3 0 0 1-4.3-4.2l8.2-8.2a4.6 4.6 0 0 1 6.5 6.5l-8.2 8.2a6.2 6.2 0 0 1-8.8-8.8l7.4-7.4" stroke-linecap="round" stroke-linejoin="round" /></svg>
        {{ card.attachments_count }}
      </span>

      <span v-if="card.members.length" style="margin-left:auto;display:flex;align-items:center;">
        <span
          v-for="(m, i) in card.members.slice(0, 3)" :key="m.id" :title="m.name"
          :style="{ width: '22px', height: '22px', borderRadius: '50%', background: corDe(m.id), color: '#10231c', fontSize: '9.5px', fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center', marginLeft: i ? '-6px' : '0', border: '2px solid var(--c-surface-1)' }"
        >{{ iniciais(m.name) }}</span>
        <span v-if="card.members.length > 3" style="font-size:10px;color:var(--c-text-muted);margin-left:4px;">+{{ card.members.length - 3 }}</span>
      </span>
    </div>
  </div>
</template>

<style scoped>
.bcard {
  background: var(--c-surface-1);
  border-radius: 11px;
  padding: 11px 12px;
  cursor: pointer;
  border: 1px solid transparent;
  transition: border-color .12s, filter .12s;
}
.bcard:hover { border-color: var(--c-surface-3); filter: brightness(1.06); }
</style>
