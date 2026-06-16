<script setup lang="ts">
import { useCrmStore, sumCol } from '~/stores/crm'

const crm = useCrmStore()

const cols = computed(() => crm.pipeline.map((col) => {
  const key = `pipeline:${col.id}`
  const over = crm.dragOverCol === key
  return {
    id: col.id, title: col.title, dot: col.dot, count: col.cards.length, sum: sumCol(col),
    bodyStyle: { display: 'flex', flexDirection: 'column', gap: '10px', overflowY: 'auto', flex: 1, borderRadius: '10px', minHeight: '70px', transition: 'background .15s', background: over ? 'rgba(37,211,102,.08)' : 'transparent', outline: over ? '2px dashed rgba(37,211,102,.45)' : '2px dashed transparent', outlineOffset: '-2px' },
    cards: col.cards.map(card => ({
      ...card,
      cardStyle: { background: card.won ? '#15281f' : (card.hot ? '#202c33' : '#1a262e'), borderRadius: '12px', padding: '13px', cursor: 'grab', borderLeft: `3px solid ${col.dot}` },
      tagStyle: card.tagStrong
        ? { fontSize: '10.5px', fontWeight: 700, color: '#062014', background: '#25D366', padding: '3px 8px', borderRadius: '6px' }
        : { fontSize: '10.5px', color: '#8696a0', background: '#0b141a', padding: '3px 8px', borderRadius: '6px' },
    })),
  }
}))
</script>

<template>
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;background:#0b141a;">
    <div style="padding:22px 30px 0;">
      <div style="display:flex;align-items:center;justify-content:space-between;">
        <div>
          <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Funil de vendas</div>
          <div style="font-size:13.5px;color:#8696a0;margin-top:3px;">Arraste os cards entre as etapas · 10 negócios ativos</div>
        </div>
        <div style="display:flex;gap:10px;align-items:center;">
          <div style="display:flex;align-items:center;gap:8px;background:#202c33;border-radius:11px;padding:9px 13px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg><input placeholder="Buscar negócio" style="background:transparent;border:none;outline:none;color:#e9edef;font-family:inherit;font-size:13px;width:130px;"></div>
          <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 17px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Novo negócio</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:22px;">
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Em negociação</div><div style="font-size:25px;font-weight:800;margin-top:5px;">R$ 89,4 mil</div><div style="font-size:11.5px;color:#25D366;margin-top:4px;font-weight:600;">▲ 12% vs. mês anterior</div></div>
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Taxa de conversão</div><div style="font-size:25px;font-weight:800;margin-top:5px;">34%</div><div style="font-size:11.5px;color:#25D366;margin-top:4px;font-weight:600;">▲ 5 p.p.</div></div>
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Conversas abertas</div><div style="font-size:25px;font-weight:800;margin-top:5px;">27</div><div style="font-size:11.5px;color:#ffb443;margin-top:4px;font-weight:600;">3 não respondidas</div></div>
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Reuniões hoje</div><div style="font-size:25px;font-weight:800;margin-top:5px;">4</div><div style="font-size:11.5px;color:#7c6cf5;margin-top:4px;font-weight:600;">Próxima às 14:00</div></div>
      </div>
    </div>

    <div style="flex:1;overflow-x:auto;overflow-y:hidden;padding:22px 30px 26px;">
      <div style="display:flex;gap:16px;height:100%;min-width:max-content;">
        <div v-for="col in cols" :key="col.id" style="width:280px;display:flex;flex-direction:column;background:#0f181e;border-radius:14px;padding:13px;flex-shrink:0;">
          <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:13px;padding:0 3px;">
            <div style="display:flex;align-items:center;gap:8px;"><span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: col.dot }" /><span style="font-weight:700;font-size:13.5px;">{{ col.title }}</span><span style="font-size:12px;color:#8696a0;">{{ col.count }}</span></div>
            <span style="font-size:12px;color:#8696a0;font-weight:600;">{{ col.sum }}</span>
          </div>
          <div
            :style="col.bodyStyle"
            @dragover.prevent="crm.setDragOver(`pipeline:${col.id}`)"
            @drop.prevent="crm.dropTo('pipeline', col.id)"
          >
            <div
              v-for="card in col.cards" :key="card.id"
              draggable="true" :style="card.cardStyle" class="card"
              @dragstart="crm.setDrag('pipeline', col.id, card.id)"
              @dragend="crm.setDragOver(null)"
            >
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div style="font-weight:700;font-size:14px;">{{ card.name }}</div>
                <span v-if="card.hot" style="font-size:10px;font-weight:700;color:#ff7a45;background:rgba(255,122,69,.15);padding:2px 7px;border-radius:6px;flex-shrink:0;">🔥</span>
                <svg v-if="card.won" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.5" style="flex-shrink:0;"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </div>
              <div style="font-size:12px;color:#8696a0;margin-top:2px;">{{ card.sub }}</div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:11px;"><span style="font-size:13.5px;font-weight:700;color:#25D366;">{{ card.value }}</span><span :style="card.tagStyle">{{ card.tag }}</span></div>
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
