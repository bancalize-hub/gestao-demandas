<script setup lang="ts">
// Biblioteca de criativos. Saiu de dentro do /marketing (que virou só o gerenciador
// de anúncios) e ganhou página própria.
const api = useApi()
const apiOrigin = useRuntimeConfig().public.apiOrigin as string

interface Criativo {
  id: number; name: string; number: number; original_name: string | null
  mime: string | null; size: number; notes: string | null; no_facebook: boolean; url: string
}

const criativos = ref<Criativo[]>([])
const subindo = ref(false)
const carregando = ref(true)
const erroUpload = ref('')
const fileInput = ref<HTMLInputElement | null>(null)

async function carregar() {
  carregando.value = true
  try { criativos.value = await api<Criativo[]>('/api/marketing/creatives') }
  catch { criativos.value = [] }
  finally { carregando.value = false }
}
onMounted(carregar)

async function subir(files: FileList | null) {
  if (!files?.length) return
  subindo.value = true
  erroUpload.value = ''
  try {
    // Um POST por arquivo: o nome (Criativo N) é sequencial e o servidor trava a
    // numeração por upload — mandar tudo junto viraria dois "Criativo 4".
    for (const f of Array.from(files)) {
      const fd = new FormData()
      fd.append('file', f)
      await api('/api/marketing/creatives', { method: 'POST', body: fd })
    }
    await carregar()
  }
  catch (e: any) { erroUpload.value = e?.response?._data?.message || e?.message || 'Erro ao enviar.' }
  finally {
    subindo.value = false
    if (fileInput.value) fileInput.value.value = ''
  }
}

async function salvarNota(c: Criativo) {
  try { await api(`/api/marketing/creatives/${c.id}`, { method: 'PATCH', body: { notes: c.notes } }) }
  catch { /* a observação é auxiliar; falhar aqui não pode travar a tela */ }
}

async function apagar(c: Criativo) {
  if (!confirm(`Apagar ${c.name}?`)) return
  try {
    await api(`/api/marketing/creatives/${c.id}`, { method: 'DELETE' })
    criativos.value = criativos.value.filter(x => x.id !== c.id)
  }
  catch (e: any) { alert(e?.message || 'Erro ao apagar.') }
}

function tamanho(bytes: number) {
  if (bytes < 1024) return `${bytes} B`
  if (bytes < 1024 * 1024) return `${Math.round(bytes / 1024)} KB`
  return `${(bytes / 1024 / 1024).toFixed(1)} MB`
}

const campo = 'width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;'
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 24px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px;">🖼️</div>
      <div style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Criativos</div>
        <div style="font-size:12px;color:var(--c-text-muted);">As peças que vão para os anúncios do Facebook</div>
      </div>
      <div style="flex:1;" />
      <NuxtLink to="/marketing" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">← Gerenciador de anúncios</NuxtLink>
    </div>

    <div style="padding:20px 24px;max-width:1100px;">
      <div style="font-size:12px;color:var(--c-text-muted);margin-bottom:16px;line-height:1.5;">
        Cada imagem enviada vira <strong>Criativo 1</strong>, <strong>Criativo 2</strong>… É por esse nome que a peça é identificada na criação do anúncio.
        A observação é lida pela IA — descreva o que a peça mostra e para quem serve.
      </div>

      <label style="display:block;border:1.5px dashed var(--c-surface-3);border-radius:12px;padding:22px;text-align:center;cursor:pointer;margin-bottom:18px;background:var(--c-bg-deepest);">
        <input ref="fileInput" type="file" accept="image/*" multiple hidden @change="(e: any) => subir(e.target.files)">
        <div style="font-size:26px;margin-bottom:6px;">🖼️</div>
        <div style="font-size:13px;font-weight:700;">{{ subindo ? 'Enviando…' : 'Clique para enviar criativos' }}</div>
        <div style="font-size:11px;color:var(--c-text-faint);margin-top:4px;">JPG, PNG, GIF ou WebP · até 30 MB cada · pode selecionar vários</div>
      </label>

      <div v-if="erroUpload" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-danger-soft);margin-bottom:14px;">{{ erroUpload }}</div>

      <div v-if="carregando" style="font-size:12.5px;color:var(--c-text-faint);">Carregando…</div>
      <div v-else-if="!criativos.length" style="font-size:12.5px;color:var(--c-text-faint);">Nenhum criativo ainda.</div>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:14px;">
        <div v-for="c in criativos" :key="c.id" style="border:1px solid var(--c-surface-1);border-radius:12px;overflow:hidden;background:var(--c-bg-deepest);display:flex;flex-direction:column;">
          <img :src="apiOrigin + c.url" :alt="c.name" style="width:100%;aspect-ratio:1;object-fit:cover;background:var(--c-bg-deep);">
          <div style="padding:10px 12px;display:flex;flex-direction:column;gap:7px;">
            <div style="display:flex;align-items:center;gap:6px;">
              <strong style="font-size:13px;">{{ c.name }}</strong>
              <span v-if="c.no_facebook" title="Já enviado ao Facebook" style="font-size:10px;color:var(--c-ai-soft);">● no FB</span>
              <button title="Apagar" style="margin-left:auto;background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:15px;" @click="apagar(c)">×</button>
            </div>
            <div style="font-size:10.5px;color:var(--c-text-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.original_name }} · {{ tamanho(c.size) }}</div>
            <textarea v-model="c.notes" rows="2" placeholder="O que essa peça mostra? (a IA lê isto)" :style="campo + 'resize:vertical;font-size:11.5px;padding:7px 9px;'" @blur="salvarNota(c)" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
