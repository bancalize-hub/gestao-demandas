<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
const c = computed(() => crm.activeConv)

// Etapas reais do funil (acompanham "Editar etapas" — não são mais fixas no código).
const STAGES = computed(() => crm.stages)
const stageIndex = computed(() => {
  const i = crm.stages.findIndex(s => s.key === c.value?.stage)
  return i < 0 ? 0 : i
})
function barStyle(i: number) {
  const idx = stageIndex.value
  const col = crm.stages[i]?.color ?? 'var(--c-warn)'
  if (i < idx) return { height: '6px', borderRadius: '4px', background: 'var(--accent)' }
  if (i === idx) return { height: '6px', borderRadius: '4px', background: `linear-gradient(90deg,var(--accent),${col})` }
  return { height: '6px', borderRadius: '4px', background: 'var(--c-surface-2)' }
}
function labelStyle(i: number) {
  const idx = stageIndex.value
  if (i < idx) return { fontSize: '11px', color: 'var(--accent)', fontWeight: 600, marginTop: '7px' }
  if (i === idx) return { fontSize: '11px', color: crm.stages[i]?.color ?? 'var(--c-warn)', fontWeight: 700, marginTop: '7px' }
  return { fontSize: '11px', color: 'var(--c-text-muted)', fontWeight: 600, marginTop: '7px' }
}
// Clicar numa etapa move o lead — mesma sincronização do funil/etiqueta (stage+cor+tag+WhatsApp).
function setStage(key: string) {
  if (c.value && c.value.stage !== key) crm.setConvStage(c.value.id, key)
}

// --- Ficha editável (draft local) ---
// Bufferiza os campos localmente; o refetch do Reverb faz Object.assign no store e
// apagaria um input controlado direto. Só re-sincronizamos quando troca o lead (id).
const form = reactive({ email: '', company: '', origin: '', segmento: '', responsible: '', role: '', dealValue: '', prob: 0, notes: '' })
watch(() => c.value?.id, () => {
  const v = c.value
  if (!v) return
  Object.assign(form, {
    email: v.email, company: v.company, origin: v.origin, segmento: v.segmento,
    responsible: v.responsible, role: v.role, dealValue: v.dealValue, prob: v.prob, notes: v.notes,
  })
}, { immediate: true })

function save(field: keyof typeof form) {
  const v = c.value
  if (!v) return
  let val: any = form[field]
  if (field === 'prob') val = Math.max(0, Math.min(100, Number.parseInt(String(val), 10) || 0))
  if ((v as any)[field] === val) return
  crm.patchConvFields(v.id, { [field]: val } as any)
}

const inputStyle = 'background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:8px 11px;color:var(--c-text);font-family:inherit;font-size:13.5px;font-weight:600;outline:none;text-align:right;width:170px;'

// --- Linha do tempo de atividades (real) ---
const ACT_COLOR: Record<string, string> = { nota: 'var(--c-info)', etapa: 'var(--c-ai)', reuniao: 'var(--accent)', followup: 'var(--c-warn)', whatsapp: 'var(--accent)', nudge: 'var(--c-ai)' }
function actColor(t: string) { return ACT_COLOR[t] ?? 'var(--c-text-muted)' }
function fmtWhen(iso: string) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleString('pt-BR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function actMeta(a: { occurred_at: string, user?: { name: string } | null }) {
  const when = fmtWhen(a.occurred_at)
  return a.user?.name ? `${when} · ${a.user.name}` : when
}

// Carrega atividades + follow-ups sempre que troca o lead aberto.
watch(() => c.value?.id, (id) => { if (id) { crm.loadActivities(id); crm.loadFollowups(id) } }, { immediate: true })

// --- Follow-ups (acompanhamento) ---
const fuNote = ref('')
const fuWhen = ref('')
const savingFu = ref(false)
const pendingFu = computed(() => crm.followups.filter(f => f.column !== 'done'))
function fmtDue(iso: string | null) {
  if (!iso) return ''
  return new Date(iso).toLocaleString('pt-BR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}
function isOverdue(iso: string | null) {
  return !!iso && new Date(iso).getTime() <= Date.now()
}
async function addFollowup() {
  const v = c.value
  const note = fuNote.value.trim()
  if (!v || !note || !fuWhen.value || savingFu.value) return
  savingFu.value = true
  try {
    // datetime-local vem sem timezone; envia ISO local que o Laravel interpreta no fuso do app.
    await crm.createFollowup(v.id, { title: note.slice(0, 200), starts_at: fuWhen.value.replace('T', ' ') + ':00' })
    fuNote.value = ''
    fuWhen.value = ''
  }
  catch { /* silencioso */ }
  finally { savingFu.value = false }
}

const noteText = ref('')
const savingNote = ref(false)
async function addNote() {
  const v = c.value
  const txt = noteText.value.trim()
  if (!v || !txt || savingNote.value) return
  savingNote.value = true
  try {
    // Nota curta vira o título; nota longa (>200 do backend) vai pro corpo,
    // com um título resumido — o timeline mostra título + corpo abaixo.
    const payload = txt.length <= 180
      ? { type: 'nota', title: txt }
      : { type: 'nota', title: txt.replace(/\s+/g, ' ').slice(0, 120).trim() + '…', body: txt }
    await crm.addActivity(v.id, payload)
    noteText.value = ''
  }
  catch { /* silencioso */ }
  finally { savingNote.value = false }
}
</script>

<template>
  <div style="flex:1;display:flex;min-width:0;background:var(--c-bg-deep);overflow-y:auto;">
    <div style="flex:1;min-width:0;padding:26px 32px;">
      <div style="display:flex;align-items:center;gap:8px;font-size:13px;color:var(--c-text-muted);margin-bottom:18px;"><span class="bc" style="cursor:pointer;" @click="crm.go('pipeline')">Funil</span><span>›</span><span style="color:var(--c-text);">{{ c?.name }}</span></div>

      <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:24px;display:flex;align-items:center;gap:20px;">
        <div :style="{ width: '84px', height: '84px', borderRadius: '50%', background: c?.color, display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '30px', flexShrink: 0 }">{{ c?.initials }}</div>
        <div style="flex:1;">
          <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;"><span style="font-size:22px;font-weight:800;">{{ c?.name }}</span><span v-if="c?.hot" style="font-size:11px;font-weight:700;color:var(--c-orange);background:rgba(255,122,69,.13);padding:3px 10px;border-radius:7px;">🔥 Lead quente</span><span v-for="(t, i) in c?.tags" :key="i" :style="{ fontSize: '11px', fontWeight: 700, color: t.color, background: `${t.color}22`, padding: '3px 10px', borderRadius: '7px' }">{{ t.label }}</span></div>
          <input v-model="form.role" placeholder="Cargo / função" style="margin-top:6px;background:transparent;border:none;outline:none;color:var(--c-text-muted);font-family:inherit;font-size:14px;width:100%;" @blur="save('role')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()">
        </div>
        <div style="display:flex;gap:9px;">
          <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:11px 17px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="crm.go('chat')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 11.5a8.38 8.38 0 0 1-9 8.34 9 9 0 0 1-3.9-.9L3 21l1.06-4.1A8.38 8.38 0 0 1 3 11.5 8.5 8.5 0 0 1 21 11.5Z" stroke-linecap="round" stroke-linejoin="round" /></svg>Mensagem</button>
        </div>
      </div>

      <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px 24px;margin-top:18px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;"><span style="font-size:15px;font-weight:700;">Progresso do negócio</span><div style="display:flex;align-items:center;gap:4px;"><span style="font-size:13px;color:var(--c-text-muted);font-weight:600;">R$</span><input v-model="form.dealValue" placeholder="0" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:7px 11px;color:var(--accent);font-family:inherit;font-size:18px;font-weight:800;outline:none;width:120px;text-align:right;" @blur="save('dealValue')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()"></div></div>
        <div style="display:flex;align-items:center;gap:6px;">
          <div v-for="(s, i) in STAGES" :key="s.key" class="stagestep" style="flex:1;text-align:center;cursor:pointer;" :title="`Mover para “${s.name}”`" @click="setStage(s.key)"><div :style="barStyle(i)" /><div :style="labelStyle(i)">{{ s.name }}</div></div>
        </div>
      </div>

      <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px 24px;margin-top:18px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;"><span style="font-size:15px;font-weight:700;">Próximos passos · acompanhamento</span><span style="font-size:12px;color:var(--c-text-muted);">{{ pendingFu.length }} pendente(s)</span></div>

        <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
          <input v-model="fuNote" placeholder="O que fazer (ex.: cobrar resposta da proposta)" style="flex:1;min-width:200px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;" @keydown.enter="addFollowup">
          <input v-model="fuWhen" type="datetime-local" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;color-scheme:dark;">
          <button :disabled="savingFu || !fuNote.trim() || !fuWhen" :style="{ background: 'var(--c-ai)', border: 'none', color: 'var(--c-on-accent)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '0 16px', borderRadius: '10px', cursor: (savingFu || !fuNote.trim() || !fuWhen) ? 'default' : 'pointer', opacity: (savingFu || !fuNote.trim() || !fuWhen) ? 0.6 : 1 }" @click="addFollowup">Agendar</button>
        </div>

        <div v-if="crm.followups.length" style="display:flex;flex-direction:column;gap:9px;">
          <div v-for="f in crm.followups" :key="f.id" :style="{ display: 'flex', alignItems: 'flex-start', gap: '11px', background: 'var(--c-bg-deep)', borderRadius: '11px', padding: '11px 13px', opacity: f.column === 'done' ? 0.55 : 1, borderLeft: `3px solid ${f.column === 'done' ? 'var(--accent)' : (isOverdue(f.starts_at) ? 'var(--c-orange)' : 'var(--c-ai)')}` }">
            <div style="flex:1;min-width:0;">
              <div style="font-size:13.5px;font-weight:600;word-break:break-word;" :style="{ textDecoration: f.column === 'done' ? 'line-through' : 'none' }">{{ f.title }}</div>
              <div style="font-size:12px;margin-top:3px;" :style="{ color: (f.column !== 'done' && isOverdue(f.starts_at)) ? 'var(--c-orange-soft)' : 'var(--c-text-muted)' }">
                <span v-if="f.column === 'done'">Concluído</span>
                <span v-else>{{ isOverdue(f.starts_at) ? 'Venceu' : 'Agendado' }} · {{ fmtDue(f.starts_at) }}</span>
              </div>
              <div v-if="f.ai_draft" style="margin-top:9px;background:var(--c-surface-0);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;">
                <div style="font-size:11px;font-weight:700;color:var(--c-ai-soft);margin-bottom:4px;">✨ Rascunho da IA</div>
                <div style="font-size:12.5px;color:var(--c-ai-faint);white-space:pre-wrap;word-break:break-word;line-height:1.45;">{{ f.ai_draft }}</div>
                <button class="aibtn" style="margin-top:8px;font-size:12px;font-weight:700;color:var(--c-on-accent);background:var(--c-ai);border:none;padding:7px 13px;border-radius:8px;cursor:pointer;" @click="c && crm.useFollowupDraft(c.id, f.ai_draft!)">Abrir no chat com o rascunho</button>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:6px;flex-shrink:0;">
              <button v-if="f.column !== 'done'" title="Concluir" style="background:rgba(var(--accent-rgb),.14);border:none;color:var(--accent);cursor:pointer;padding:6px 8px;border-radius:8px;display:flex;" @click="c && crm.completeFollowup(c.id, f.id)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              <button title="Excluir" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;padding:6px 8px;display:flex;" @click="crm.removeFollowup(f.id)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
            </div>
          </div>
        </div>
        <div v-else style="font-size:13px;color:var(--c-text-muted);">Nenhum follow-up agendado. Crie um para não perder o lead de vista.</div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1.2fr;gap:18px;margin-top:18px;">
        <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px 24px;">
          <div style="font-size:15px;font-weight:700;margin-bottom:16px;">Detalhes</div>
          <div style="display:flex;flex-direction:column;gap:13px;">
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span style="font-size:13px;color:var(--c-text-muted);flex-shrink:0;">Telefone</span><span style="font-size:13.5px;font-weight:600;">{{ c?.phone || '—' }}</span></div>
            <div style="height:1px;background:var(--c-surface-1);" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span style="font-size:13px;color:var(--c-text-muted);flex-shrink:0;">E-mail</span><input v-model="form.email" placeholder="—" :style="inputStyle" @blur="save('email')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()"></div>
            <div style="height:1px;background:var(--c-surface-1);" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span style="font-size:13px;color:var(--c-text-muted);flex-shrink:0;">Empresa</span><input v-model="form.company" placeholder="—" :style="inputStyle" @blur="save('company')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()"></div>
            <div style="height:1px;background:var(--c-surface-1);" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span style="font-size:13px;color:var(--c-text-muted);flex-shrink:0;">Segmento</span><input v-model="form.segmento" placeholder="—" :style="inputStyle" @blur="save('segmento')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()"></div>
            <div style="height:1px;background:var(--c-surface-1);" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span style="font-size:13px;color:var(--c-text-muted);flex-shrink:0;">Origem</span><input v-model="form.origin" placeholder="—" :style="inputStyle" @blur="save('origin')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()"></div>
            <div style="height:1px;background:var(--c-surface-1);" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span style="font-size:13px;color:var(--c-text-muted);flex-shrink:0;">Responsável</span><input v-model="form.responsible" placeholder="—" :style="inputStyle" @blur="save('responsible')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()"></div>
            <div style="height:1px;background:var(--c-surface-1);" />
            <div style="display:flex;justify-content:space-between;align-items:center;gap:10px;"><span style="font-size:13px;color:var(--c-text-muted);flex-shrink:0;">Probabilidade</span><div style="display:flex;align-items:center;gap:4px;"><input v-model="form.prob" type="number" min="0" max="100" placeholder="0" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:8px 11px;color:var(--accent);font-family:inherit;font-size:13.5px;font-weight:700;outline:none;width:64px;text-align:right;" @blur="save('prob')" @keydown.enter="(e) => (e.target as HTMLInputElement).blur()"><span style="font-size:13.5px;font-weight:700;color:var(--accent);">%</span></div></div>
          </div>

          <div style="font-size:13px;color:var(--c-text-muted);margin:18px 0 8px;">Observações</div>
          <textarea v-model="form.notes" rows="4" placeholder="Anotações sobre o lead, necessidades, contexto…" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;resize:vertical;line-height:1.5;box-sizing:border-box;" @blur="save('notes')" />
        </div>

        <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px 24px;">
          <div style="font-size:15px;font-weight:700;margin-bottom:14px;">Histórico de interações</div>

          <div style="display:flex;gap:8px;margin-bottom:18px;">
            <input v-model="noteText" placeholder="Registrar uma nota / interação…" style="flex:1;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;" @keydown.enter="addNote">
            <button :disabled="savingNote || !noteText.trim()" :style="{ background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '0 16px', borderRadius: '10px', cursor: (savingNote || !noteText.trim()) ? 'default' : 'pointer', opacity: (savingNote || !noteText.trim()) ? 0.6 : 1 }" @click="addNote">Adicionar</button>
          </div>

          <div v-if="crm.activities.length" style="display:flex;flex-direction:column;gap:0;">
            <div v-for="(a, i) in crm.activities" :key="a.id" class="act" style="display:flex;gap:13px;">
              <div style="display:flex;flex-direction:column;align-items:center;">
                <div :style="{ width: '32px', height: '32px', borderRadius: '50%', background: `${actColor(a.type)}26`, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }"><span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: actColor(a.type), display: 'block' }" /></div>
                <div v-if="i < crm.activities.length - 1" style="width:2px;flex:1;background:var(--c-surface-1);margin:4px 0;" />
              </div>
              <div :style="{ paddingBottom: i < crm.activities.length - 1 ? '18px' : '0', flex: 1, minWidth: 0 }">
                <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:8px;">
                  <div style="font-size:13.5px;font-weight:600;word-break:break-word;">{{ a.title }}</div>
                  <button class="delact" title="Excluir" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;padding:0;flex-shrink:0;display:flex;" @click="c && crm.removeActivity(c.id, a.id)"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                </div>
                <div v-if="a.body" style="font-size:12.5px;color:var(--c-text-secondary);margin-top:3px;white-space:pre-wrap;word-break:break-word;">{{ a.body }}</div>
                <div style="font-size:12px;color:var(--c-text-muted);margin-top:2px;">{{ actMeta(a) }}</div>
              </div>
            </div>
          </div>
          <div v-else-if="!crm.activitiesLoading" style="font-size:13px;color:var(--c-text-muted);">Sem interações registradas ainda.</div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: var(--accent-hi) !important; }
.ghost:hover { background: var(--c-surface-3) !important; }
.bc:hover { color: var(--c-text) !important; }
.act .delact { opacity: 0; transition: opacity .15s; }
.act:hover .delact { opacity: 1; }
.delact:hover { color: var(--c-danger) !important; }
.stagestep:hover { opacity: .8; }
.stagestep:hover > div:first-child { filter: brightness(1.4); }
</style>
