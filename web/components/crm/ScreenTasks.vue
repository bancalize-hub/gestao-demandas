<script setup lang="ts">
import { useCrmStore, prioMeta } from '~/stores/crm'

const crm = useCrmStore()

const cols = computed(() => crm.tasks.map((col) => {
  const key = `tasks:${col.id}`
  const over = crm.dragOverCol === key
  return {
    id: col.id, title: col.title, dot: col.dot, count: col.cards.length,
    bodyStyle: { display: 'flex', flexDirection: 'column', gap: '10px', overflowY: 'auto', flex: 1, borderRadius: '10px', minHeight: '70px', transition: 'background .15s', background: over ? 'rgba(37,211,102,.08)' : 'transparent', outline: over ? '2px dashed rgba(37,211,102,.45)' : '2px dashed transparent', outlineOffset: '-2px' },
    cards: col.cards.map((card) => {
      const p = prioMeta(card.priority)
      return {
        ...card, prioLabel: p.label,
        prioStyle: { fontSize: '10px', fontWeight: 700, color: p.color, background: `${p.color}22`, padding: '2px 8px', borderRadius: '6px' },
        cardStyle: { background: '#1a262e', borderRadius: '12px', padding: '13px', cursor: 'grab', borderLeft: `3px solid ${col.dot}` },
      }
    }),
  }
}))
</script>

<template>
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;background:#0b141a;">
    <div style="padding:22px 30px 0;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Tarefas</div>
        <div style="font-size:13.5px;color:#8696a0;margin-top:3px;">Arraste para mover entre as etapas · solicitações dos clientes chegam em "A fazer"</div>
      </div>
      <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 17px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="crm.go('form')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Nova tarefa</button>
    </div>
    <div style="flex:1;overflow-x:auto;overflow-y:hidden;padding:22px 30px 26px;">
      <div style="display:flex;gap:16px;height:100%;min-width:max-content;">
        <div v-for="col in cols" :key="col.id" style="width:288px;display:flex;flex-direction:column;background:#0f181e;border-radius:14px;padding:13px;flex-shrink:0;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:13px;padding:0 3px;"><span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: col.dot }" /><span style="font-weight:700;font-size:13.5px;">{{ col.title }}</span><span style="font-size:12px;color:#8696a0;background:#1a262e;min-width:20px;height:20px;border-radius:6px;display:flex;align-items:center;justify-content:center;padding:0 6px;">{{ col.count }}</span></div>
          <div
            :style="col.bodyStyle"
            @dragover.prevent="crm.setDragOver(`tasks:${col.id}`)"
            @drop.prevent="crm.dropTo('tasks', col.id)"
          >
            <div
              v-for="card in col.cards" :key="card.id"
              draggable="true" :style="card.cardStyle" class="card"
              @dragstart="crm.setDrag('tasks', col.id, card.id)"
              @dragend="crm.setDragOver(null)"
            >
              <div style="font-weight:700;font-size:13.5px;line-height:1.35;">{{ card.title }}</div>
              <div style="display:flex;align-items:center;gap:6px;margin-top:9px;"><span :style="card.prioStyle">{{ card.prioLabel }}</span><span style="font-size:10.5px;color:#8696a0;background:#0b141a;padding:2px 8px;border-radius:6px;">{{ card.type }}</span></div>
              <div style="display:flex;align-items:center;justify-content:space-between;margin-top:11px;"><span style="font-size:11.5px;color:#8696a0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;">{{ card.client }}</span><span style="font-size:11px;color:#8696a0;display:flex;align-items:center;gap:4px;flex-shrink:0;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" stroke-linecap="round" /></svg>{{ card.due }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: #2ee070 !important; }
.card:hover { filter: brightness(1.12); }
</style>
