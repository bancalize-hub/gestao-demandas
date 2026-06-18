<script setup lang="ts">
interface Chunk { id: number, kind: string, gatilho: string, conteudo: string, keywords: string }

const api = useApi()

const chunks = ref<Chunk[]>([])
const style = ref('')
const samples = ref<{ id: number, text: string }[]>([])
const rules = ref<{ id: number, rule: string }[]>([])
const loading = ref(true)
const savingStyle = ref(false)
const styleSaved = ref(false)

const kinds = ['faq', 'preco', 'objecao', 'procedimento', 'fato']
const editingId = ref<number | null>(null) // null = nenhum, 0 = novo, >0 = editando
const form = reactive({ kind: 'faq', gatilho: '', conteudo: '', keywords: '' })
const savingChunk = ref(false)
const chunkError = ref('')

function startAdd() {
  editingId.value = 0
  Object.assign(form, { kind: 'faq', gatilho: '', conteudo: '', keywords: '' })
  chunkError.value = ''
}

function startEdit(c: Chunk) {
  editingId.value = c.id
  Object.assign(form, { kind: c.kind, gatilho: c.gatilho, conteudo: c.conteudo, keywords: c.keywords || '' })
  chunkError.value = ''
}

function cancelEdit() {
  editingId.value = null
  chunkError.value = ''
}

async function saveChunk() {
  if (savingChunk.value) return
  if (!form.gatilho.trim() || !form.conteudo.trim()) {
    chunkError.value = 'Preencha o gatilho e o conteúdo.'
    return
  }
  savingChunk.value = true
  chunkError.value = ''
  try {
    if (editingId.value === 0) {
      const c = await api<Chunk>('/api/memory/chunks', { method: 'POST', body: { ...form } })
      chunks.value.unshift(c)
    }
    else {
      const id = editingId.value!
      const c = await api<Chunk>(`/api/memory/chunks/${id}`, { method: 'PATCH', body: { ...form } })
      const i = chunks.value.findIndex(x => x.id === id)
      if (i !== -1) chunks.value[i] = c
    }
    editingId.value = null
  }
  catch (e: any) {
    const errs = e?.response?._data?.errors
    chunkError.value = errs ? Object.values(errs).flat().join(' ') : (e?.response?._data?.message || 'Erro ao salvar.')
  }
  finally { savingChunk.value = false }
}

async function load() {
  loading.value = true
  try {
    const r = await api<{ chunks: Chunk[], style: string, samples: { id: number, text: string }[], rules: { id: number, rule: string }[] }>('/api/memory')
    chunks.value = r.chunks
    style.value = r.style
    samples.value = r.samples
    rules.value = r.rules
  }
  catch { /* */ }
  finally { loading.value = false }
}

async function saveStyle() {
  savingStyle.value = true
  styleSaved.value = false
  try {
    await api('/api/memory/style', { method: 'PUT', body: { summary: style.value } })
    styleSaved.value = true
    setTimeout(() => { styleSaved.value = false }, 2500)
  }
  catch { /* */ }
  finally { savingStyle.value = false }
}

async function delChunk(id: number) {
  if (!confirm('Excluir este conhecimento da memória?')) return
  chunks.value = chunks.value.filter(c => c.id !== id)
  try { await api(`/api/memory/chunks/${id}`, { method: 'DELETE' }) }
  catch { /* */ }
}

async function delRule(id: number) {
  rules.value = rules.value.filter(r => r.id !== id)
  try { await api(`/api/memory/rules/${id}`, { method: 'DELETE' }) }
  catch { /* */ }
}

function kindColor(k: string) {
  return ({ preco: '#25D366', faq: '#53bdeb', objecao: '#ff6b6b', procedimento: '#ffb443', fato: '#a89bf9' } as Record<string, string>)[k] || '#8696a0'
}

onMounted(load)
</script>

<template>
  <div style="flex:1;min-width:0;background:#0b141a;display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid #1c2730;display:flex;align-items:center;gap:11px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:#7c6cf5;display:flex;align-items:center;justify-content:center;font-size:17px;">🧠</div>
      <div>
        <div style="font-weight:800;font-size:15px;">Memória da IA</div>
        <div style="font-size:12px;color:#8696a0;">Base de conhecimento + seu jeito de falar · usados para sugerir respostas</div>
      </div>
    </div>

    <div style="flex:1;display:flex;justify-content:center;padding:30px 24px 60px;">
      <div style="width:780px;max-width:100%;display:flex;flex-direction:column;gap:24px;">
        <div v-if="loading" style="color:#8696a0;font-size:14px;text-align:center;padding:40px;">Carregando…</div>

        <template v-else>
          <!-- perfil de voz -->
          <div style="background:#111b21;border:1px solid #1c2730;border-radius:16px;padding:24px;">
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div>
                <div style="font-size:16px;font-weight:800;">Seu jeito de falar (voz)</div>
                <div style="font-size:12.5px;color:#8696a0;margin-top:3px;">Refinado a cada conversa adicionada. Pode editar à mão.</div>
              </div>
              <button :disabled="savingStyle" :style="{ background: '#7c6cf5', border: 'none', color: '#fff', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '9px 16px', borderRadius: '10px', cursor: 'pointer', opacity: savingStyle ? 0.7 : 1 }" @click="saveStyle">{{ savingStyle ? 'Salvando…' : (styleSaved ? 'Salvo ✓' : 'Salvar') }}</button>
            </div>
            <textarea v-model="style" rows="8" placeholder="Ainda vazio — adicione conversas à memória pra começar." style="width:100%;margin-top:14px;background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:12px 13px;color:#e9edef;font-family:inherit;font-size:13px;line-height:1.55;outline:none;resize:vertical;" />
            <div v-if="samples.length" style="margin-top:14px;">
              <div style="font-size:11px;font-weight:700;color:#8696a0;letter-spacing:.4px;margin-bottom:8px;">EXEMPLOS DE MENSAGENS SUAS ({{ samples.length }})</div>
              <div style="display:flex;flex-direction:column;gap:6px;">
                <div v-for="s in samples.slice(0, 6)" :key="s.id" style="font-size:12.5px;color:#aebac1;background:#202c33;border-radius:8px;padding:8px 11px;">“{{ s.text }}”</div>
              </div>
            </div>
          </div>

          <!-- regras de resposta -->
          <div style="background:#111b21;border:1px solid #1c2730;border-radius:16px;padding:24px;">
            <div style="font-size:16px;font-weight:800;margin-bottom:4px;">Regras de resposta ({{ rules.length }})</div>
            <div style="font-size:12.5px;color:#8696a0;margin-bottom:16px;">Correções que você salvou — a IA segue todas ao sugerir. Crie pelo botão "Salvar correção" no chat.</div>
            <div v-if="!rules.length" style="font-size:13px;color:#5f6f78;">Nenhuma regra ainda.</div>
            <div v-else style="display:flex;flex-direction:column;gap:8px;">
              <div v-for="r in rules" :key="r.id" style="background:#202c33;border-radius:10px;padding:11px 14px;display:flex;gap:12px;align-items:center;">
                <span style="color:#7ee6a8;flex-shrink:0;">✓</span>
                <div style="flex:1;font-size:13px;">{{ r.rule }}</div>
                <button title="Excluir" class="delk" style="background:none;border:none;color:#5a6b73;cursor:pointer;flex-shrink:0;display:flex;" @click="delRule(r.id)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              </div>
            </div>
          </div>

          <!-- base de conhecimento -->
          <div style="background:#111b21;border:1px solid #1c2730;border-radius:16px;padding:24px;">
            <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:16px;">
              <div>
                <div style="font-size:16px;font-weight:800;margin-bottom:4px;">Base de conhecimento ({{ chunks.length }})</div>
                <div style="font-size:12.5px;color:#8696a0;">O que a IA "sabe" para responder os clientes. Adicione, edite ou exclua.</div>
              </div>
              <button v-if="editingId !== 0" style="background:#7c6cf5;border:none;color:#fff;font-family:inherit;font-size:13px;font-weight:700;padding:9px 14px;border-radius:10px;cursor:pointer;flex-shrink:0;white-space:nowrap;" @click="startAdd">+ Adicionar</button>
            </div>

            <!-- formulário de novo conhecimento -->
            <div v-if="editingId === 0" style="margin-bottom:12px;">
              <div style="background:#202c33;border:1px solid #2a3942;border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px;">
                <div style="display:flex;gap:10px;flex-wrap:wrap;">
                  <select v-model="form.kind" style="background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;cursor:pointer;">
                    <option v-for="k in kinds" :key="k" :value="k">{{ k }}</option>
                  </select>
                  <input v-model="form.gatilho" placeholder="Gatilho — tema ou pergunta curta" style="flex:1;min-width:200px;background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
                </div>
                <textarea v-model="form.conteudo" rows="3" placeholder="Conteúdo — a resposta em 1 a 3 frases" style="width:100%;background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:10px 11px;color:#e9edef;font-family:inherit;font-size:13px;line-height:1.5;outline:none;resize:vertical;" />
                <input v-model="form.keywords" placeholder="Palavras-chave separadas por vírgula (opcional)" style="background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
                <div v-if="chunkError" style="color:#ff6b6b;font-size:12px;">{{ chunkError }}</div>
                <div style="display:flex;gap:8px;justify-content:flex-end;">
                  <button style="background:none;border:1px solid #2a3942;color:#aebac1;font-family:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px;cursor:pointer;" @click="cancelEdit">Cancelar</button>
                  <button :disabled="savingChunk" :style="{ background: '#25D366', border: 'none', color: '#06281a', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '8px 16px', borderRadius: '9px', cursor: 'pointer', opacity: savingChunk ? 0.7 : 1 }" @click="saveChunk">{{ savingChunk ? 'Salvando…' : 'Salvar' }}</button>
                </div>
              </div>
            </div>

            <div v-if="!chunks.length && editingId !== 0" style="font-size:13px;color:#5f6f78;padding:14px 0;">Nada ainda. Clique em "+ Adicionar" ou vá no chat → menu ⋮ da conversa → "Adicionar à memória".</div>
            <div v-else-if="chunks.length" style="display:flex;flex-direction:column;gap:10px;">
              <template v-for="c in chunks" :key="c.id">
                <!-- formulário de edição -->
                <div v-if="editingId === c.id" style="background:#202c33;border:1px solid #7c6cf5;border-radius:12px;padding:14px;display:flex;flex-direction:column;gap:10px;">
                  <div style="display:flex;gap:10px;flex-wrap:wrap;">
                    <select v-model="form.kind" style="background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;cursor:pointer;">
                      <option v-for="k in kinds" :key="k" :value="k">{{ k }}</option>
                    </select>
                    <input v-model="form.gatilho" placeholder="Gatilho — tema ou pergunta curta" style="flex:1;min-width:200px;background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
                  </div>
                  <textarea v-model="form.conteudo" rows="3" placeholder="Conteúdo — a resposta em 1 a 3 frases" style="width:100%;background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:10px 11px;color:#e9edef;font-family:inherit;font-size:13px;line-height:1.5;outline:none;resize:vertical;" />
                  <input v-model="form.keywords" placeholder="Palavras-chave separadas por vírgula (opcional)" style="background:#0b141a;border:1px solid #2a3942;border-radius:9px;padding:9px 11px;color:#e9edef;font-family:inherit;font-size:13px;outline:none;">
                  <div v-if="chunkError" style="color:#ff6b6b;font-size:12px;">{{ chunkError }}</div>
                  <div style="display:flex;gap:8px;justify-content:flex-end;">
                    <button style="background:none;border:1px solid #2a3942;color:#aebac1;font-family:inherit;font-size:13px;font-weight:600;padding:8px 14px;border-radius:9px;cursor:pointer;" @click="cancelEdit">Cancelar</button>
                    <button :disabled="savingChunk" :style="{ background: '#25D366', border: 'none', color: '#06281a', fontFamily: 'inherit', fontSize: '13px', fontWeight: 700, padding: '8px 16px', borderRadius: '9px', cursor: 'pointer', opacity: savingChunk ? 0.7 : 1 }" @click="saveChunk">{{ savingChunk ? 'Salvando…' : 'Salvar' }}</button>
                  </div>
                </div>
                <!-- card normal -->
                <div v-else style="background:#202c33;border-radius:12px;padding:13px 15px;display:flex;gap:12px;align-items:flex-start;">
                  <span :style="{ fontSize: '10px', fontWeight: 700, color: kindColor(c.kind), background: `${kindColor(c.kind)}22`, padding: '3px 8px', borderRadius: '6px', flexShrink: 0, marginTop: '2px' }">{{ c.kind }}</span>
                  <div style="flex:1;min-width:0;">
                    <div style="font-size:13.5px;font-weight:700;">{{ c.gatilho }}</div>
                    <div style="font-size:13px;color:#aebac1;margin-top:3px;line-height:1.45;">{{ c.conteudo }}</div>
                    <div v-if="c.keywords" style="font-size:11px;color:#5f6f78;margin-top:5px;">{{ c.keywords }}</div>
                  </div>
                  <button title="Editar" style="background:none;border:none;color:#5a6b73;cursor:pointer;flex-shrink:0;display:flex;" class="editk" @click="startEdit(c)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                  <button title="Excluir" style="background:none;border:none;color:#5a6b73;cursor:pointer;flex-shrink:0;display:flex;" class="delk" @click="delChunk(c.id)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
                </div>
              </template>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.delk:hover { color: #ff6b6b !important; }
.editk:hover { color: #7c6cf5 !important; }
</style>
