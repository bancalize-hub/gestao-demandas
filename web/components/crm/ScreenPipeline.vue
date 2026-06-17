<script setup lang="ts">
import { useCrmStore, sumCol } from '~/stores/crm'

const crm = useCrmStore()

// --- Modal de negócio (criar/editar = ficha) ---
const showNew = ref(false)
const saving = ref(false)
const error = ref('')
const editId = ref<number | null>(null)
const form = reactive({ name: '', sub: '', value: '', stage: 'novo', hot: false })

const stages = computed(() => crm.stages.map(s => ({ id: s.key, title: s.name })))

function openNew() {
  editId.value = null
  error.value = ''
  Object.assign(form, { name: '', sub: '', value: '', stage: crm.stages[0]?.key || 'novo', hot: false })
  showNew.value = true
}

function openEdit(card: any) {
  editId.value = card.id
  error.value = ''
  Object.assign(form, { name: card.name, sub: card.sub || '', value: card.value || '', stage: card.stage, hot: !!card.hot })
  showNew.value = true
}

async function submitNew() {
  if (saving.value) return
  if (!form.name.trim()) { error.value = 'Informe o nome do negócio.'; return }
  saving.value = true
  error.value = ''
  try {
    if (editId.value) crm.updateDeal(editId.value, { ...form, name: form.name.trim() })
    else await crm.createDeal({ ...form, name: form.name.trim() })
    showNew.value = false
  }
  catch {
    error.value = 'Não foi possível salvar o negócio.'
  }
  finally {
    saving.value = false
  }
}

// --- Editar etapas do funil ---
const showStages = ref(false)
const newStageName = ref('')
function addStage() {
  const n = newStageName.value.trim()
  if (!n) return
  crm.createStage({ name: n, color: '#8696a0' })
  newStageName.value = ''
}
function moveStage(i: number, dir: number) {
  const j = i + dir
  if (j < 0 || j >= crm.stages.length) return
  const arr = crm.stages as any[]
  ;[arr[i], arr[j]] = [arr[j], arr[i]]
  crm.reorderStages()
}

const stats = computed(() => {
  const ds = crm.dealList
  const val = (v: string) => Number.parseInt(String(v || '').replace(/[^\d]/g, ''), 10) || 0
  const emNeg = ds.filter(d => d.stage === 'negociacao').reduce((a, d) => a + val(d.value), 0)
  const won = ds.filter(d => d.won || d.stage === 'fechado').length
  return {
    emNeg: emNeg >= 1000 ? `R$ ${(emNeg / 1000).toFixed(1).replace('.', ',')} mil` : `R$ ${emNeg.toLocaleString('pt-BR')}`,
    taxa: ds.length ? Math.round((won / ds.length) * 100) : 0,
    conversas: crm.conversations.length,
    ativos: ds.filter(d => d.stage !== 'fechado').length,
  }
})

function delDeal(id: number) {
  if (confirm('Excluir este negócio?')) { crm.removeDeal(id); showNew.value = false }
}

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
          <button style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13px;font-weight:600;padding:10px 14px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="showStages = true"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M3 6h18M3 12h18M3 18h18" stroke-linecap="round" /></svg>Editar etapas</button>
          <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 17px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="openNew"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Novo negócio</button>
        </div>
      </div>

      <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-top:22px;">
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Em negociação</div><div style="font-size:25px;font-weight:800;margin-top:5px;">{{ stats.emNeg }}</div><div style="font-size:11.5px;color:#8696a0;margin-top:4px;font-weight:600;">no estágio Negociação</div></div>
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Taxa de conversão</div><div style="font-size:25px;font-weight:800;margin-top:5px;">{{ stats.taxa }}%</div><div style="font-size:11.5px;color:#8696a0;margin-top:4px;font-weight:600;">negócios fechados / total</div></div>
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Conversas</div><div style="font-size:25px;font-weight:800;margin-top:5px;">{{ stats.conversas }}</div><div style="font-size:11.5px;color:#8696a0;margin-top:4px;font-weight:600;">total no CRM</div></div>
        <div style="background:#111b21;border:1px solid #1c2730;border-radius:14px;padding:16px 18px;"><div style="font-size:12.5px;color:#8696a0;">Negócios ativos</div><div style="font-size:25px;font-weight:800;margin-top:5px;">{{ stats.ativos }}</div><div style="font-size:11.5px;color:#8696a0;margin-top:4px;font-weight:600;">não fechados</div></div>
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
              @click="openEdit(card)"
            >
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div style="font-weight:700;font-size:14px;">{{ card.name }}</div>
                <span v-if="card.hot" style="font-size:10px;font-weight:700;color:#ff7a45;background:rgba(255,122,69,.15);padding:2px 7px;border-radius:6px;flex-shrink:0;">🔥</span>
                <svg v-if="card.won" width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.5" style="flex-shrink:0;"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg>
                <button class="delbtn" title="Excluir" style="background:none;border:none;color:#5a6b73;cursor:pointer;padding:0;flex-shrink:0;display:flex;" @click.stop="delDeal(card.id)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              </div>
              <div style="font-size:12px;color:#8696a0;margin-top:2px;">{{ card.sub }}</div>
              <div style="display:flex;justify-content:space-between;align-items:center;margin-top:11px;"><span style="font-size:13.5px;font-weight:700;color:#25D366;">{{ card.value }}</span><span :style="card.tagStyle">{{ card.tag }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- modal: novo negócio -->
    <div v-if="showNew" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:24px;" @click.self="showNew = false">
      <div style="width:460px;max-width:100%;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:26px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
          <div style="font-size:19px;font-weight:800;">{{ editId ? 'Editar negócio' : 'Novo negócio' }}</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="showNew = false">×</button>
        </div>
        <div style="font-size:13px;color:#8696a0;margin-bottom:20px;">Adicione um negócio ao funil.</div>

        <div style="display:flex;flex-direction:column;gap:15px;">
          <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Nome*</span><input v-model="form.name" placeholder="Ex: Vértice Pro — 12 licenças" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:11px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;"></label>
          <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Cliente / contato</span><input v-model="form.sub" placeholder="Ex: Mariana Costa" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:11px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;"></label>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
            <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Valor</span><input v-model="form.value" placeholder="R$ 4.200" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:11px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;"></label>
            <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Etapa</span><select v-model="form.stage" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:11px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;color-scheme:dark;"><option v-for="s in stages" :key="s.id" :value="s.id">{{ s.title }}</option></select></label>
          </div>
          <label style="display:flex;align-items:center;gap:9px;font-size:13.5px;color:#aebac1;cursor:pointer;"><input v-model="form.hot" type="checkbox" style="width:16px;height:16px;accent-color:#ff7a45;cursor:pointer;">Marcar como lead quente 🔥</label>

          <div v-if="error" style="background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-size:13px;font-weight:600;padding:10px 13px;border-radius:10px;">{{ error }}</div>

          <div style="display:flex;gap:10px;align-items:center;margin-top:4px;">
            <button v-if="editId" style="background:rgba(255,77,77,.12);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-family:inherit;font-size:13px;font-weight:700;padding:11px 16px;border-radius:11px;cursor:pointer;" @click="delDeal(editId)">Excluir</button>
            <div style="flex:1;" />
            <button style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13.5px;font-weight:600;padding:11px 18px;border-radius:11px;cursor:pointer;" @click="showNew = false">Cancelar</button>
            <button :disabled="saving" :style="{ background: '#25D366', border: 'none', color: '#062014', fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '11px 20px', borderRadius: '11px', cursor: saving ? 'default' : 'pointer', opacity: saving ? 0.7 : 1 }" @click="submitNew">{{ saving ? 'Salvando…' : (editId ? 'Salvar' : 'Criar negócio') }}</button>
          </div>
        </div>
      </div>
    </div>

    <!-- modal: editar etapas do funil -->
    <div v-if="showStages" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:24px;" @click.self="showStages = false">
      <div style="width:480px;max-width:100%;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:26px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:4px;">
          <div style="font-size:19px;font-weight:800;">Etapas do funil</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="showStages = false">×</button>
        </div>
        <div style="font-size:13px;color:#8696a0;margin-bottom:18px;">Estas etapas também são as etiquetas das conversas no chat.</div>
        <div style="display:flex;flex-direction:column;gap:8px;">
          <div v-for="(s, i) in crm.stages" :key="s.id || s.key" style="display:flex;align-items:center;gap:8px;background:#202c33;border-radius:10px;padding:8px 10px;">
            <input type="color" :value="s.color" style="width:26px;height:26px;border:none;background:none;cursor:pointer;padding:0;flex-shrink:0;" @input="s.id && crm.updateStage(s.id, { color: ($event.target as HTMLInputElement).value })">
            <input :value="s.name" style="flex:1;background:#111b21;border:1px solid #2a3942;border-radius:8px;padding:8px 10px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;" @change="s.id && crm.updateStage(s.id, { name: ($event.target as HTMLInputElement).value })">
            <button title="Subir" :disabled="i === 0" style="background:none;border:none;color:#8696a0;cursor:pointer;padding:2px 4px;" @click="moveStage(i, -1)">▲</button>
            <button title="Descer" :disabled="i === crm.stages.length - 1" style="background:none;border:none;color:#8696a0;cursor:pointer;padding:2px 4px;" @click="moveStage(i, 1)">▼</button>
            <button title="Excluir" style="background:none;border:none;color:#ff6b6b;cursor:pointer;padding:2px 4px;" @click="s.id && confirm('Excluir a etapa? Os negócios dela vão para a primeira etapa.') && crm.removeStage(s.id)">✕</button>
          </div>
        </div>
        <div style="display:flex;gap:8px;margin-top:14px;">
          <input v-model="newStageName" placeholder="Nova etapa" style="flex:1;background:#202c33;border:1px solid #2a3942;border-radius:10px;padding:10px 12px;color:#e9edef;font-family:inherit;font-size:13.5px;outline:none;" @keydown.enter="addStage">
          <button style="background:#7c6cf5;border:none;color:#fff;font-family:inherit;font-size:13px;font-weight:700;padding:0 18px;border-radius:10px;cursor:pointer;" @click="addStage">Adicionar</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: #2ee070 !important; }
.card:hover { filter: brightness(1.12); }
.card .delbtn { opacity: 0; transition: opacity .15s; }
.card:hover .delbtn { opacity: 1; }
.delbtn:hover { color: #ff6b6b !important; }
</style>
