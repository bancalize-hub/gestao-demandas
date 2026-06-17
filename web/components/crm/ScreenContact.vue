<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
const c = computed(() => crm.activeConv)

const STAGES = ['Novo lead', 'Contato', 'Proposta', 'Negociação', 'Fechado']
const stageIndex = computed(() => {
  const map: Record<string, number> = { 'Contato feito': 1, 'Proposta enviada': 2, 'Negociação': 3, 'Fechado': 4 }
  return map[c.value?.stage ?? ''] ?? 0
})
function barStyle(i: number) {
  const idx = stageIndex.value
  if (i < idx) return { height: '6px', borderRadius: '4px', background: '#25D366' }
  if (i === idx) return { height: '6px', borderRadius: '4px', background: `linear-gradient(90deg,#25D366,${c.value?.stageColor ?? '#ffb443'})` }
  return { height: '6px', borderRadius: '4px', background: '#202c33' }
}
function labelStyle(i: number) {
  const idx = stageIndex.value
  if (i < idx) return { fontSize: '11px', color: '#25D366', fontWeight: 600, marginTop: '7px' }
  if (i === idx) return { fontSize: '11px', color: c.value?.stageColor ?? '#ffb443', fontWeight: 700, marginTop: '7px' }
  return { fontSize: '11px', color: '#8696a0', fontWeight: 600, marginTop: '7px' }
}
</script>

<template>
  <div style="flex:1;display:flex;min-width:0;background:#0b141a;overflow-y:auto;">
    <div style="flex:1;min-width:0;padding:26px 32px;">
      <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:#8696a0;margin-bottom:18px;"><span class="bc" style="cursor:pointer;" @click="crm.go('pipeline')">Funil</span><span>›</span><span style="color:#e9edef;">{{ c?.name }}</span></div>

      <div style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:24px;display:flex;align-items:center;gap:20px;">
        <div :style="{ width: '84px', height: '84px', borderRadius: '50%', background: c?.color, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '30px', flexShrink: 0 }">{{ c?.initials }}</div>
        <div style="flex:1;">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"><span style="font-size:22px;font-weight:800;">{{ c?.name }}</span><span v-if="c?.hot" style="font-size:11px;font-weight:700;color:#ff7a45;background:rgba(255,122,69,.13);padding:3px 10px;border-radius:7px;">🔥 Lead quente</span><span v-for="(t, i) in c?.tags" :key="i" :style="{ fontSize: '11px', fontWeight: 700, color: t.color, background: `${t.color}22`, padding: '3px 10px', borderRadius: '7px' }">{{ t.label }}</span></div>
          <div style="font-size:14px;color:#8696a0;margin-top:5px;">{{ c?.role }}</div>
        </div>
        <div style="display:flex;gap:9px;">
          <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:11px 17px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="crm.go('chat')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.34 9 9 0 0 1-3.9-.9L3 21l1.06-4.1A8.38 8.38 0 0 1 3 11.5 8.5 8.5 0 0 1 21 11.5Z" stroke-linecap="round" stroke-linejoin="round" /></svg>Mensagem</button>
          <button class="ghost" style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13px;font-weight:700;padding:11px 15px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="navigateTo('/reuniao/' + (c?.id || 'sala'))"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="2.5" y="6" width="13" height="12" rx="2.5" /><path d="M15.5 10l6-3.2v10.4l-6-3.2" /></svg>Reunião</button>
        </div>
      </div>

      <div style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:22px 24px;margin-top:18px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;"><span style="font-size:15px;font-weight:700;">Progresso do negócio</span><span style="font-size:20px;font-weight:800;color:#25D366;">{{ c?.dealValue }}<span style="font-size:13px;color:#8696a0;font-weight:600;">{{ c?.dealUnit }}</span></span></div>
        <div style="display:flex;align-items:center;gap:6px;">
          <div v-for="(s, i) in STAGES" :key="s" style="flex:1;text-align:center;"><div :style="barStyle(i)" /><div :style="labelStyle(i)">{{ s }}</div></div>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:18px;margin-top:18px;">
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:22px 24px;">
          <div style="font-size:15px;font-weight:700;margin-bottom:16px;">Detalhes</div>
          <div style="display:flex;flex-direction:column;gap:15px;">
            <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:13px;color:#8696a0;">Telefone</span><span style="font-size:13.5px;font-weight:600;">{{ c?.phone || '—' }}</span></div>
            <div style="height:1px;background:#1c2730;" />
            <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:13px;color:#8696a0;">E-mail</span><span style="font-size:13.5px;font-weight:600;">{{ c?.email || '—' }}</span></div>
            <div style="height:1px;background:#1c2730;" />
            <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:13px;color:#8696a0;">Empresa</span><span style="font-size:13.5px;font-weight:600;">{{ c?.company || '—' }}</span></div>
            <div style="height:1px;background:#1c2730;" />
            <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:13px;color:#8696a0;">Origem</span><span style="font-size:13.5px;font-weight:600;">{{ c?.origin || '—' }}</span></div>
            <div style="height:1px;background:#1c2730;" />
            <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:13px;color:#8696a0;">Responsável</span><span style="font-size:13.5px;font-weight:600;display:flex;align-items:center;gap:7px;"><span style="width:22px;height:22px;border-radius:50%;background:linear-gradient(135deg,#25D366,#0e8a4f);display:flex;align-items:center;justify-content:center;font-size:10px;color:#062014;">AB</span>{{ c?.responsible || '—' }}</span></div>
            <div style="height:1px;background:#1c2730;" />
            <div style="display:flex;justify-content:space-between;align-items:center;"><span style="font-size:13px;color:#8696a0;">Probabilidade</span><span style="font-size:13.5px;font-weight:700;color:#25D366;">{{ c?.prob }}%</span></div>
          </div>
        </div>

        <div style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:22px 24px;">
          <div style="font-size:15px;font-weight:700;margin-bottom:18px;">Histórico de interações</div>
          <div v-if="c?.interactions?.length" style="display:flex;flex-direction:column;gap:0;">
            <div v-for="(it, i) in c.interactions" :key="i" style="display:flex;gap:13px;">
              <div style="display:flex;flex-direction:column;align-items:center;">
                <div :style="{ width: '32px', height: '32px', borderRadius: '50%', background: `${it.color}26`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }"><span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: it.color, display: 'block' }" /></div>
                <div v-if="i < c.interactions.length - 1" style="width:2px;flex:1;background:#1c2730;margin:4px 0;" />
              </div>
              <div :style="{ paddingBottom: i < c.interactions.length - 1 ? '20px' : '0' }"><div style="font-size:13.5px;font-weight:600;">{{ it.title }}</div><div style="font-size:12px;color:#8696a0;margin-top:2px;">{{ it.meta }}</div></div>
            </div>
          </div>
          <div v-else style="font-size:13px;color:#8696a0;">Sem interações registradas ainda.</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: #2ee070 !important; }
.ghost:hover { background: #2a3942 !important; }
.bc:hover { color: #e9edef !important; }
</style>
