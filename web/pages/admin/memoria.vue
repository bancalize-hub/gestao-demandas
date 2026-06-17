<script setup lang="ts">
interface Chunk { id: number, kind: string, gatilho: string, conteudo: string, keywords: string }

const api = useApi()

const chunks = ref<Chunk[]>([])
const style = ref('')
const samples = ref<{ id: number, text: string }[]>([])
const loading = ref(true)
const savingStyle = ref(false)
const styleSaved = ref(false)

async function load() {
  loading.value = true
  try {
    const r = await api<{ chunks: Chunk[], style: string, samples: { id: number, text: string }[] }>('/api/memory')
    chunks.value = r.chunks
    style.value = r.style
    samples.value = r.samples
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

          <!-- base de conhecimento -->
          <div style="background:#111b21;border:1px solid #1c2730;border-radius:16px;padding:24px;">
            <div style="font-size:16px;font-weight:800;margin-bottom:4px;">Base de conhecimento ({{ chunks.length }})</div>
            <div style="font-size:12.5px;color:#8696a0;margin-bottom:16px;">O que a IA "sabe" para responder os clientes. Exclua o que estiver errado.</div>
            <div v-if="!chunks.length" style="font-size:13px;color:#5f6f78;padding:14px 0;">Nada ainda. Vá no chat → menu ⋮ da conversa → "Adicionar à memória".</div>
            <div v-else style="display:flex;flex-direction:column;gap:10px;">
              <div v-for="c in chunks" :key="c.id" style="background:#202c33;border-radius:12px;padding:13px 15px;display:flex;gap:12px;align-items:flex-start;">
                <span :style="{ fontSize: '10px', fontWeight: 700, color: kindColor(c.kind), background: `${kindColor(c.kind)}22`, padding: '3px 8px', borderRadius: '6px', flexShrink: 0, marginTop: '2px' }">{{ c.kind }}</span>
                <div style="flex:1;min-width:0;">
                  <div style="font-size:13.5px;font-weight:700;">{{ c.gatilho }}</div>
                  <div style="font-size:13px;color:#aebac1;margin-top:3px;line-height:1.45;">{{ c.conteudo }}</div>
                  <div v-if="c.keywords" style="font-size:11px;color:#5f6f78;margin-top:5px;">{{ c.keywords }}</div>
                </div>
                <button title="Excluir" style="background:none;border:none;color:#5a6b73;cursor:pointer;flex-shrink:0;display:flex;" class="delk" @click="delChunk(c.id)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              </div>
            </div>
          </div>
        </template>
      </div>
    </div>
  </div>
</template>

<style scoped>
.delk:hover { color: #ff6b6b !important; }
</style>
