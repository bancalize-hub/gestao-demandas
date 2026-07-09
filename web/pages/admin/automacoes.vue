<script setup lang="ts">
interface Stage { id: number, key: string, name: string, color: string }
interface Step {
  id?: number
  type: 'text' | 'media'
  delay_minutes: number
  text: string
  asset_filename?: string | null
}
interface Automation {
  id: number
  stage_key: string
  name: string
  enabled: boolean
  steps: Step[]
}

const api = useApi()

const stages = ref<Stage[]>([])
const automations = ref<Automation[]>([])
const loading = ref(true)
const activeKey = ref<string>('')

// Estado editável da etapa selecionada
const name = ref('')
const enabled = ref(true)
const steps = ref<Step[]>([])
const saving = ref(false)
const savedMsg = ref('')

const activeStage = computed(() => stages.value.find(s => s.key === activeKey.value))
const currentAutomation = computed(() => automations.value.find(a => a.stage_key === activeKey.value))

async function load() {
  try {
    const [st, au] = await Promise.all([
      api<Stage[]>('/api/stages'),
      api<Automation[]>('/api/stage-automations'),
    ])
    stages.value = st
    automations.value = au
    if (!activeKey.value && stages.value.length) {
      // Abre na etapa que já tem playbook, senão na primeira.
      activeKey.value = au[0]?.stage_key || stages.value[0].key
    }
    syncForm()
  }
  catch { /* */ }
  finally { loading.value = false }
}

function syncForm() {
  const a = currentAutomation.value
  name.value = a?.name || (activeStage.value ? `Playbook — ${activeStage.value.name}` : '')
  enabled.value = a ? a.enabled : true
  steps.value = a ? a.steps.map(s => ({ ...s })) : []
}

function selectStage(key: string) {
  activeKey.value = key
  savedMsg.value = ''
  syncForm()
}

// ---- Edição de passos ----
function addStep(type: 'text' | 'media') {
  steps.value.push({ type, delay_minutes: type === 'media' ? 0 : 2880, text: '' })
}
function removeStep(i: number) {
  steps.value.splice(i, 1)
}
function moveStep(i: number, dir: -1 | 1) {
  const j = i + dir
  if (j < 0 || j >= steps.value.length) return
  const arr = steps.value
  ;[arr[i], arr[j]] = [arr[j], arr[i]]
}

// Atraso amigável: valor + unidade ⇄ minutos
function delayValue(s: Step) {
  const m = s.delay_minutes
  if (m === 0) return 0
  if (m % 1440 === 0) return m / 1440
  if (m % 60 === 0) return m / 60
  return m
}
function delayUnit(s: Step) {
  const m = s.delay_minutes
  if (m === 0) return 'min'
  if (m % 1440 === 0) return 'dias'
  if (m % 60 === 0) return 'horas'
  return 'min'
}
function setDelay(s: Step, value: number, unit: string) {
  const v = Math.max(0, Math.floor(value || 0))
  s.delay_minutes = unit === 'dias' ? v * 1440 : unit === 'horas' ? v * 60 : v
}

async function save() {
  if (!activeKey.value || saving.value) return
  saving.value = true
  savedMsg.value = ''
  try {
    await api('/api/stage-automations', {
      method: 'POST',
      body: {
        stage_key: activeKey.value,
        name: name.value.trim() || `Playbook — ${activeStage.value?.name}`,
        enabled: enabled.value,
        steps: steps.value.map(s => ({ id: s.id, type: s.type, delay_minutes: s.delay_minutes, text: s.text })),
      },
    })
    savedMsg.value = 'Salvo ✓'
    await load() // recarrega para obter ids dos passos novos (necessários p/ anexar PDF)
  }
  catch (e: any) { savedMsg.value = e?.response?._data?.message || 'Falha ao salvar.' }
  finally { saving.value = false }
}

// ---- Upload de PDF de um passo de mídia ----
const uploadingIdx = ref<number | null>(null)
async function uploadPdf(i: number, e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  const step = steps.value[i]
  if (!step.id) { alert('Salve a sequência primeiro para depois anexar o PDF.'); input.value = ''; return }
  uploadingIdx.value = i
  try {
    const fd = new FormData()
    fd.append('file', file)
    const r = await api<Step>(`/api/stage-automations/steps/${step.id}/asset`, { method: 'POST', body: fd })
    step.asset_filename = r.asset_filename
  }
  catch (e: any) { alert(e?.response?._data?.message || 'Falha ao enviar o PDF (use um arquivo .pdf de até 30MB).') }
  finally { uploadingIdx.value = null; input.value = '' }
}

onMounted(load)
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="var(--accent-ink)" stroke-width="2"><path d="M4 6h10M4 12h7M4 18h12" stroke-linecap="round" /><path d="m16 8 3 3-3 3" stroke-linecap="round" stroke-linejoin="round" /></svg>
      </div>
      <div style="flex:1;">
        <div style="font-weight:800;font-size:15px;">Automações de etapa</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Mensagens automáticas (PDF/follow-up) quando o lead entra numa etapa do funil · 1× por conversa</div>
      </div>
    </div>

    <div style="flex:1;display:flex;align-items:flex-start;justify-content:center;padding:24px;">
      <div style="width:700px;max-width:100%;display:flex;flex-direction:column;gap:16px;">
        <div v-if="loading" style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:40px;text-align:center;color:var(--c-text-muted);">Carregando…</div>

        <template v-else>
          <!-- seletor de etapa -->
          <div style="display:flex;gap:8px;flex-wrap:wrap;">
            <button
              v-for="s in stages" :key="s.key"
              :style="{ display: 'flex', alignItems: 'center', gap: '7px', background: activeKey === s.key ? 'var(--c-surface-2)' : 'var(--c-bg)', border: '1px solid ' + (activeKey === s.key ? 'var(--c-surface-3)' : 'var(--c-surface-1)'), color: 'var(--c-text)', fontFamily: 'inherit', fontSize: '12.5px', fontWeight: 700, padding: '8px 13px', borderRadius: '10px', cursor: 'pointer' }"
              @click="selectStage(s.key)"
            >
              <span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: s.color }" />
              {{ s.name }}
              <span v-if="automations.find(a => a.stage_key === s.key && a.steps.length)" style="font-size:10px;color:var(--accent);">●</span>
            </button>
          </div>

          <!-- editor da etapa selecionada -->
          <div style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px;">
            <div style="display:flex;align-items:center;gap:10px;margin-bottom:6px;">
              <div style="flex:1;">
                <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:5px;">Nome do playbook</div>
                <input v-model="name" placeholder="Ex.: Pós-reunião — proposta e follow-up" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;">
              </div>
              <label style="display:flex;align-items:center;gap:7px;font-size:12.5px;color:var(--c-text-secondary);cursor:pointer;align-self:flex-end;padding-bottom:9px;">
                <input v-model="enabled" type="checkbox" style="accent-color:var(--accent);width:16px;height:16px;"> Ativo
              </label>
            </div>

            <div style="font-size:11.5px;color:var(--c-text-faint);margin:12px 0;line-height:1.5;">
              Variáveis disponíveis no texto: <b style="color:var(--c-ai-soft);">{nome}</b> · <b style="color:var(--c-ai-soft);">{empresa}</b> · <b style="color:var(--c-ai-soft);">{responsavel}</b>
            </div>

            <!-- passos -->
            <div v-for="(s, i) in steps" :key="i" style="background:var(--c-bg-deep);border:1px solid var(--c-surface-1);border-radius:14px;padding:15px;margin-bottom:11px;">
              <div style="display:flex;align-items:center;gap:9px;margin-bottom:10px;">
                <span style="background:rgba(var(--accent-rgb),.13);color:var(--accent);font-size:11px;font-weight:800;width:22px;height:22px;border-radius:50%;display:flex;align-items:center;justify-content:center;">{{ i + 1 }}</span>
                <span style="font-size:12.5px;font-weight:700;color:var(--c-text);">{{ s.type === 'media' ? '📄 Documento (PDF)' : '💬 Texto' }}</span>
                <div style="flex:1;" />
                <button title="Subir" :disabled="i === 0" :style="{ background: 'none', border: 'none', color: i === 0 ? 'var(--c-border-strong)' : 'var(--c-text-muted)', cursor: i === 0 ? 'default' : 'pointer', fontSize: '15px' }" @click="moveStep(i, -1)">↑</button>
                <button title="Descer" :disabled="i === steps.length - 1" :style="{ background: 'none', border: 'none', color: i === steps.length - 1 ? 'var(--c-border-strong)' : 'var(--c-text-muted)', cursor: i === steps.length - 1 ? 'default' : 'pointer', fontSize: '15px' }" @click="moveStep(i, 1)">↓</button>
                <button title="Remover passo" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:14px;" @click="removeStep(i)">✕</button>
              </div>

              <!-- atraso -->
              <div style="display:flex;align-items:center;gap:7px;margin-bottom:10px;">
                <span style="font-size:12px;color:var(--c-text-muted);">Disparar</span>
                <input
                  type="number" min="0" :value="delayValue(s)"
                  style="width:64px;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;"
                  @input="(e) => setDelay(s, +(e.target as HTMLInputElement).value, delayUnit(s))"
                >
                <select
                  :value="delayUnit(s)"
                  style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;"
                  @change="(e) => setDelay(s, delayValue(s), (e.target as HTMLSelectElement).value)"
                >
                  <option value="min">minutos</option>
                  <option value="horas">horas</option>
                  <option value="dias">dias</option>
                </select>
                <span style="font-size:12px;color:var(--c-text-muted);">após entrar na etapa</span>
              </div>

              <textarea v-model="s.text" rows="3" :placeholder="s.type === 'media' ? 'Legenda enviada junto com o PDF…' : 'Mensagem de texto…'" style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;box-sizing:border-box;resize:vertical;" />

              <!-- anexo PDF (passos de mídia) -->
              <div v-if="s.type === 'media'" style="margin-top:10px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                <label :style="{ background: 'var(--c-surface-2)', color: 'var(--c-text)', fontSize: '12px', fontWeight: 700, padding: '8px 13px', borderRadius: '9px', cursor: 'pointer' }">
                  {{ uploadingIdx === i ? 'Enviando…' : (s.asset_filename ? '↻ Trocar PDF' : '⬆ Anexar PDF') }}
                  <input type="file" accept="application/pdf,.pdf" style="display:none;" :disabled="uploadingIdx === i" @change="(e) => uploadPdf(i, e)">
                </label>
                <span v-if="s.asset_filename" style="font-size:12px;color:var(--c-ai-soft);">📄 {{ s.asset_filename }}</span>
                <span v-else style="font-size:11.5px;color:var(--c-warn);">Sem PDF — este passo não dispara até você anexar um arquivo.</span>
                <span v-if="!s.id" style="font-size:11px;color:var(--c-text-faint);flex-basis:100%;">Salve a sequência para liberar o anexo.</span>
              </div>
            </div>

            <!-- adicionar passo -->
            <div style="display:flex;gap:8px;margin-top:4px;">
              <button style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);color:var(--c-text);font-family:inherit;font-size:12.5px;font-weight:700;padding:9px 14px;border-radius:10px;cursor:pointer;" @click="addStep('text')">+ Texto</button>
              <button style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);color:var(--c-text);font-family:inherit;font-size:12.5px;font-weight:700;padding:9px 14px;border-radius:10px;cursor:pointer;" @click="addStep('media')">+ Documento (PDF)</button>
            </div>

            <div style="display:flex;align-items:center;gap:12px;margin-top:18px;border-top:1px solid var(--c-surface-1);padding-top:16px;">
              <button :disabled="saving" :style="{ background: 'var(--accent)', border: 'none', color: 'var(--accent-ink)', fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '11px 20px', borderRadius: '11px', cursor: saving ? 'default' : 'pointer', opacity: saving ? 0.6 : 1 }" @click="save">{{ saving ? 'Salvando…' : 'Salvar sequência' }}</button>
              <span v-if="savedMsg" style="font-size:12.5px;color:var(--c-ai-soft);">{{ savedMsg }}</span>
            </div>
          </div>

          <div style="font-size:11.5px;color:var(--c-text-faint);line-height:1.6;padding:0 4px;">
            As mensagens disparam <b>uma única vez por conversa</b> ao entrar na etapa (mover de volta não reenvia). Só conversas com WhatsApp vinculado recebem. O envio respeita a ordem e o atraso configurados.
          </div>
        </template>
      </div>
    </div>
  </div>
</template>
