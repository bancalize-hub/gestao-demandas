<script setup lang="ts">
// Biblioteca de criativos. Saiu de dentro do /marketing (que virou só o gerenciador
// de anúncios) e ganhou página própria.
//
// Cada peça carrega um VEREDITO (validado / em teste / reprovado) ao lado do
// desempenho que a Meta reporta. O selo é manual de propósito: CPL alto pode ser
// culpa do público, e lead barato pode não fechar — quem decide é quem cuida da
// conta. O que o selo garante é que a decisão não se perca: criativo reprovado é
// recusado na criação de anúncio, inclusive pela IA.
const api = useApi()
const apiOrigin = useRuntimeConfig().public.apiOrigin as string

type Status = 'testando' | 'validado' | 'reprovado'

interface Criativo {
  id: number; name: string; number: number; original_name: string | null
  mime: string | null; size: number; notes: string | null; no_facebook: boolean; url: string
  status: Status; status_note: string | null
}

interface Desempenho {
  id: number; anuncios: number; ativos: number; gasto: number
  impressoes: number; cliques: number
  leads: number; responderam: number; qualificados: number
  reunioes: number; realizadas: number; vendas: number
  cpl: number | null; cpq: number | null
}

const SELOS: Record<Status, { rotulo: string; icone: string; cor: string; curto: string }> = {
  validado: { rotulo: 'Validado', icone: '✅', cor: 'var(--c-teal)', curto: 'Validados' },
  testando: { rotulo: 'Em teste', icone: '🧪', cor: 'var(--c-warn)', curto: 'Em teste' },
  reprovado: { rotulo: 'Reprovado', icone: '⛔', cor: 'var(--c-danger)', curto: 'Reprovados' },
}
const ORDEM_SELOS: Status[] = ['validado', 'testando', 'reprovado']

// Peça que nunca virou anúncio não está "em teste" — não está em lugar nenhum. Este
// quarto estado NÃO existe no banco: ele é derivado de ter ou não anúncio, porque é um
// fato sobre a conta de anúncios, não um julgamento de ninguém. Assim ele se corrige
// sozinho no dia em que a peça sobe, sem precisar que alguém lembre de mudar o selo.
const NAO_SUBIU = { rotulo: 'Não subiu', icone: '⏳', cor: 'var(--c-text-faint)', curto: 'Não subiram' }

/**
 * Já foi AO AR? Só dá para afirmar depois que o desempenho chega.
 *
 * Existir anúncio não basta: peça montada e deixada pausada nunca foi entregue a
 * ninguém, e chamar isso de "em teste" faria a tela dizer que dez criativos estão
 * sendo testados enquanto nenhum apareceu para uma única pessoa. O que prova a ida ao
 * ar é entrega — estar ativo agora ou ter tido impressão no período.
 */
function subiu(c: Criativo): boolean | null {
  const d = desempenho.value[c.id]
  return d ? d.ativos > 0 || d.impressoes > 0 : null
}

/** O selo COMO A TELA MOSTRA — "em teste" vira "não subiu" quando nunca virou anúncio. */
function selo(c: Criativo) {
  return c.status === 'testando' && subiu(c) === false ? NAO_SUBIU : SELOS[c.status]
}

const criativos = ref<Criativo[]>([])
const desempenho = ref<Record<number, Desempenho>>({})
const erroDesempenho = ref('')
const desempenhoDe = ref<string | null>(null)
const subindo = ref(false)
const carregando = ref(true)
const carregandoDesempenho = ref(false)
const erroUpload = ref('')
const fileInput = ref<HTMLInputElement | null>(null)
// A biblioteca é o que ainda pode ir para anúncio. Reprovado sai da grade e vai para
// "Arquivados": ele não é para ser escolhido, e deixá-lo no meio dos outros faz olhar
// de novo, toda vez, para a peça que já foi decidida.
const filtro = ref<Status | 'biblioteca' | 'naosubiu'>('biblioteca')
const arquivadoAgora = ref('')
const periodo = ref('last_30d')

const periodos = [
  { label: '7 dias', value: 'last_7d' },
  { label: '30 dias', value: 'last_30d' },
  { label: 'Este mês', value: 'this_month' },
]

async function carregar() {
  carregando.value = true
  try { criativos.value = await api<Criativo[]>('/api/marketing/creatives') }
  catch { criativos.value = [] }
  finally { carregando.value = false }
}

/**
 * Os números vêm em uma segunda chamada: dependem de duas requisições à Graph API e
 * a biblioteca não pode ficar esperando o Facebook para aparecer na tela.
 */
async function carregarDesempenho() {
  carregandoDesempenho.value = true
  erroDesempenho.value = ''
  try {
    const r = await api<{ desempenho: Desempenho[]; erro: string | null; de: string | null }>(
      `/api/marketing/creatives-desempenho?periodo=${periodo.value}`,
    )
    desempenho.value = Object.fromEntries(r.desempenho.map(d => [d.id, d]))
    erroDesempenho.value = r.erro || ''
    desempenhoDe.value = r.de
  }
  catch (e: any) { erroDesempenho.value = e?.message || 'Não consegui buscar o desempenho.' }
  finally { carregandoDesempenho.value = false }
}

onMounted(async () => {
  await carregar()
  carregarDesempenho()
})
watch(periodo, carregarDesempenho)

const contagem = computed(() => {
  const c = { validado: 0, testando: 0, naosubiu: 0, reprovado: 0 }
  for (const x of criativos.value) {
    if (x.status === 'testando' && subiu(x) === false) c.naosubiu++
    else c[x.status]++
  }
  return c
})

const visiveis = computed(() => {
  if (filtro.value === 'biblioteca') return criativos.value.filter(c => c.status !== 'reprovado')
  if (filtro.value === 'naosubiu') return criativos.value.filter(c => c.status === 'testando' && subiu(c) === false)
  if (filtro.value === 'testando') return criativos.value.filter(c => c.status === 'testando' && subiu(c) !== false)
  return criativos.value.filter(c => c.status === filtro.value)
})

async function subir(files: FileList | File[] | null) {
  const imagens = Array.from(files || []).filter(f => f.type.startsWith('image/'))
  if (!imagens.length) {
    if (files?.length) erroUpload.value = 'Só entram imagens (JPG, PNG, GIF ou WebP).'
    return
  }
  subindo.value = true
  erroUpload.value = ''
  try {
    // Um POST por arquivo: o nome (Criativo N) é sequencial e o servidor trava a
    // numeração por upload — mandar tudo junto viraria dois "Criativo 4".
    for (const f of imagens) {
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

// Arrastar e soltar. O contador existe porque `dragleave` dispara ao passar por cima
// de qualquer filho da área — sem ele o destaque pisca enquanto o arquivo é arrastado.
const arrastando = ref(0)
function aoSoltar(e: DragEvent) {
  arrastando.value = 0
  subir(e.dataTransfer?.files || null)
}

async function salvar(c: Criativo, campos: Partial<Criativo>) {
  try { await api(`/api/marketing/creatives/${c.id}`, { method: 'PATCH', body: campos }) }
  catch (e: any) { alert(e?.response?._data?.message || e?.message || 'Não consegui salvar.') }
}

function salvarNota(c: Criativo) {
  // A observação é auxiliar; falhar aqui não pode travar a tela.
  api(`/api/marketing/creatives/${c.id}`, { method: 'PATCH', body: { notes: c.notes } }).catch(() => {})
}

async function definirStatus(c: Criativo, status: Status) {
  const antes = c.status
  if (antes === status) return
  c.status = status // otimista: o clique tem que responder na hora
  // Reprovar some com o card da grade (ele foi para os arquivados). Sem dizer isso, a
  // peça simplesmente desaparece embaixo do cursor e parece que foi apagada.
  arquivadoAgora.value = status === 'reprovado' && filtro.value === 'biblioteca' ? c.name : ''
  try { await api(`/api/marketing/creatives/${c.id}`, { method: 'PATCH', body: { status } }) }
  catch (e: any) {
    c.status = antes
    arquivadoAgora.value = ''
    alert(e?.response?._data?.message || e?.message || 'Não consegui mudar o status.')
  }
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

function dinheiro(v: number | null) {
  if (v === null || v === undefined) return '—'
  return v.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL', maximumFractionDigits: 2 })
}

const campo = 'width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;'

function estiloCard(c: Criativo) {
  const cor = SELOS[c.status].cor
  const base = 'border-radius:12px;overflow:hidden;background:var(--c-bg-deepest);display:flex;flex-direction:column;'
  if (c.status === 'testando') return `${base}border:1px solid var(--c-surface-1);`
  return `${base}border:1px solid ${cor};box-shadow:inset 0 2px 0 0 ${cor};`
}
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
      <!-- Contadores: também são o filtro. O número e o recorte são a mesma pergunta
           ("quantos validados eu tenho?" / "me mostra os validados"). O arquivo fica
           depois de um respiro, porque não é lugar de onde se escolhe peça. -->
      <div style="display:flex;flex-wrap:wrap;align-items:center;gap:8px;margin-bottom:6px;">
        <button
          :style="{ background: filtro === 'biblioteca' ? 'var(--c-surface-2)' : 'var(--c-bg-deepest)', border: '1px solid ' + (filtro === 'biblioteca' ? 'var(--c-surface-3)' : 'var(--c-surface-1)'), borderRadius: '10px', padding: '8px 14px', cursor: 'pointer', fontFamily: 'inherit', color: 'var(--c-text)', display: 'flex', alignItems: 'baseline', gap: '7px' }"
          @click="filtro = 'biblioteca'"
        >
          <strong style="font-size:16px;">{{ contagem.validado + contagem.testando }}</strong>
          <span style="font-size:11.5px;color:var(--c-text-muted);font-weight:700;">na biblioteca</span>
        </button>
        <button
          v-for="s in [
            { k: 'validado', selo: SELOS.validado },
            { k: 'testando', selo: SELOS.testando },
            { k: 'naosubiu', selo: NAO_SUBIU },
          ]" :key="s.k"
          :title="s.k === 'naosubiu' ? 'Nunca viraram anúncio — não estão em teste, estão parados' : ''"
          :style="{ background: filtro === s.k ? 'var(--c-surface-2)' : 'var(--c-bg-deepest)', border: '1px solid ' + (filtro === s.k ? s.selo.cor : 'var(--c-surface-1)'), borderRadius: '10px', padding: '8px 14px', cursor: 'pointer', fontFamily: 'inherit', color: 'var(--c-text)', display: 'flex', alignItems: 'baseline', gap: '7px' }"
          @click="filtro = filtro === s.k ? 'biblioteca' : (s.k as any)"
        >
          <strong :style="{ fontSize: '16px', color: s.selo.cor }">{{ (contagem as any)[s.k] }}</strong>
          <span style="font-size:11.5px;color:var(--c-text-muted);font-weight:700;">{{ s.selo.icone }} {{ s.selo.curto }}</span>
        </button>

        <div style="width:1px;height:26px;background:var(--c-surface-1);margin:0 3px;" />

        <!-- Diz "Reprovados", e não "Arquivados": é a MESMA palavra do selo do card e do
             botão que reprova. Com dois nomes para o mesmo estado, quem procura pelo
             veredito que acabou de dar não encontra o recorte — o arquivamento é a
             consequência, não o nome do estado. -->
        <button
          title="Reprovados — saem da biblioteca e são recusados na criação de anúncio, inclusive pela IA"
          :style="{ background: filtro === 'reprovado' ? 'var(--c-surface-2)' : 'var(--c-bg-deepest)', border: '1px solid ' + (filtro === 'reprovado' ? SELOS.reprovado.cor : 'var(--c-surface-1)'), borderRadius: '10px', padding: '8px 14px', cursor: 'pointer', fontFamily: 'inherit', color: 'var(--c-text)', display: 'flex', alignItems: 'baseline', gap: '7px' }"
          @click="filtro = filtro === 'reprovado' ? 'biblioteca' : 'reprovado'"
        >
          <strong :style="{ fontSize: '16px', color: SELOS.reprovado.cor }">{{ contagem.reprovado }}</strong>
          <span style="font-size:11.5px;color:var(--c-text-muted);font-weight:700;">{{ SELOS.reprovado.icone }} {{ SELOS.reprovado.curto }}</span>
        </button>

        <div style="flex:1;" />

        <div style="display:flex;align-items:center;gap:4px;background:var(--c-bg-deepest);border:1px solid var(--c-surface-1);border-radius:10px;padding:4px;">
          <button
            v-for="p in periodos" :key="p.value"
            :style="{ background: periodo === p.value ? 'var(--c-surface-2)' : 'transparent', border: 'none', color: periodo === p.value ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 10px', borderRadius: '7px', cursor: 'pointer' }"
            @click="periodo = p.value"
          >{{ p.label }}</button>
        </div>
      </div>

      <div style="font-size:11px;color:var(--c-text-faint);margin-bottom:16px;">{{ criativos.length }} criativo{{ criativos.length === 1 ? '' : 's' }} no total</div>

      <div v-if="arquivadoAgora" style="background:var(--c-surface-1);border:1px solid var(--c-surface-3);border-radius:10px;padding:9px 12px;font-size:12px;margin-bottom:14px;display:flex;align-items:center;gap:10px;">
        <span>⛔ <strong>{{ arquivadoAgora }}</strong> foi reprovado e saiu da biblioteca.</span>
        <button style="background:none;border:none;color:var(--c-text-secondary);font-family:inherit;font-size:12px;font-weight:700;cursor:pointer;text-decoration:underline;" @click="filtro = 'reprovado'; arquivadoAgora = ''">Ver reprovados</button>
        <button style="margin-left:auto;background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:15px;" @click="arquivadoAgora = ''">×</button>
      </div>

      <div v-if="filtro === 'reprovado'" style="background:rgba(255,77,77,.06);border:1px solid rgba(255,77,77,.25);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-text-secondary);margin-bottom:16px;line-height:1.5;">
        <strong>Reprovados.</strong> Estas peças deram ruim e ficam fora da biblioteca — a criação de anúncio recusa cada uma delas, inclusive quando quem pede é a IA.
        Para trazer uma de volta, mude o selo dela para <strong>Em teste</strong> ou <strong>Validado</strong>.
      </div>

      <div v-else style="font-size:12px;color:var(--c-text-muted);margin-bottom:16px;line-height:1.5;">
        Cada imagem enviada vira <strong>Criativo 1</strong>, <strong>Criativo 2</strong>… É por esse nome que a peça é identificada na criação do anúncio.
        A observação é lida pela IA — descreva o que a peça mostra e para quem serve.
        <strong>Criativo reprovado é recusado na criação de anúncio</strong>, inclusive quando quem pede é a IA.
      </div>

      <label
        v-if="filtro !== 'reprovado'"
        :style="`display:block;border:1.5px dashed ${arrastando ? 'var(--accent)' : 'var(--c-surface-3)'};border-radius:12px;padding:22px;text-align:center;cursor:pointer;margin-bottom:18px;background:${arrastando ? 'var(--c-surface-1)' : 'var(--c-bg-deepest)'};transition:background .12s,border-color .12s;`"
        @dragenter.prevent="arrastando++"
        @dragover.prevent
        @dragleave.prevent="arrastando = Math.max(0, arrastando - 1)"
        @drop.prevent="aoSoltar"
      >
        <input ref="fileInput" type="file" accept="image/*" multiple hidden @change="(e: any) => subir(e.target.files)">
        <div style="font-size:26px;margin-bottom:6px;">{{ arrastando ? '📥' : '🖼️' }}</div>
        <div style="font-size:13px;font-weight:700;">
          {{ subindo ? 'Enviando…' : arrastando ? 'Solte aqui' : 'Arraste as imagens aqui ou clique para escolher' }}
        </div>
        <div style="font-size:11px;color:var(--c-text-faint);margin-top:4px;">JPG, PNG, GIF ou WebP · até 30 MB cada · pode soltar várias de uma vez</div>
      </label>

      <div v-if="erroUpload" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-danger-soft);margin-bottom:14px;">{{ erroUpload }}</div>

      <div v-if="erroDesempenho" style="background:var(--c-warn-bg);border:1px solid rgba(var(--c-warn-rgb),.4);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-warn-hi);margin-bottom:14px;">
        Os números do Facebook não vieram agora: {{ erroDesempenho }}
        <template v-if="desempenhoDe"> Mostrando o último resultado bom.</template>
      </div>

      <div v-if="carregando" style="font-size:12.5px;color:var(--c-text-faint);">Carregando…</div>
      <div v-else-if="!criativos.length" style="font-size:12.5px;color:var(--c-text-faint);">Nenhum criativo ainda.</div>
      <div v-else-if="!visiveis.length" style="font-size:12.5px;color:var(--c-text-faint);">
        {{ filtro === 'biblioteca' ? 'Nenhum criativo na biblioteca — todos foram reprovados.'
          : filtro === 'reprovado' ? 'Nenhum criativo reprovado.'
            : filtro === 'naosubiu' ? 'Todos os criativos da biblioteca já viraram anúncio.'
              : filtro === 'testando' ? 'Nenhum criativo em teste — nenhum dos não-julgados está no ar.'
                : 'Nenhum criativo validado.' }}
      </div>

      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(250px,100%),1fr));gap:14px;">
        <div v-for="c in visiveis" :key="c.id" :style="estiloCard(c)">
          <img
            :src="apiOrigin + c.url" :alt="c.name"
            :style="`width:100%;aspect-ratio:1;object-fit:cover;background:var(--c-bg-deep);${c.status === 'reprovado' ? 'filter:grayscale(.85);opacity:.6;' : ''}`"
          >
          <div style="padding:10px 12px;display:flex;flex-direction:column;gap:8px;">
            <div style="display:flex;align-items:center;gap:6px;">
              <strong style="font-size:13px;">{{ c.name }}</strong>
              <span :style="{ fontSize: '10.5px', fontWeight: 800, color: selo(c).cor }">{{ selo(c).icone }} {{ selo(c).rotulo }}</span>
              <button title="Apagar" style="margin-left:auto;background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:15px;" @click="apagar(c)">×</button>
            </div>

            <!-- Veredito. Três botões e não um menu: a decisão é de um clique e precisa
                 ser lida sem abrir nada. -->
            <div style="display:flex;gap:4px;background:var(--c-bg-deep);border-radius:9px;padding:3px;">
              <button
                v-for="s in ORDEM_SELOS" :key="s"
                :title="s === 'reprovado' ? 'Não poderá ser usado em anúncio novo' : s === 'testando' && subiu(c) === false ? 'Esta peça ainda não virou anúncio — só entra em teste quando subir' : ''"
                :style="{ flex: 1, background: c.status === s ? selo(c).cor : 'transparent', color: c.status === s ? 'var(--c-on-accent)' : 'var(--c-text-faint)', border: 'none', borderRadius: '7px', padding: '5px 4px', fontFamily: 'inherit', fontSize: '10.5px', fontWeight: 800, cursor: 'pointer' }"
                @click="definirStatus(c, s)"
              >{{ s === 'testando' && subiu(c) === false ? `${NAO_SUBIU.icone} ${NAO_SUBIU.rotulo}` : `${SELOS[s].icone} ${SELOS[s].rotulo}` }}</button>
            </div>

            <!-- Desempenho real: é o que sustenta o veredito. -->
            <div v-if="desempenho[c.id]" style="font-size:10.5px;line-height:1.6;">
              <template v-if="desempenho[c.id].anuncios">
                <div style="display:flex;flex-wrap:wrap;gap:2px 10px;color:var(--c-text-secondary);">
                  <span>Gasto <strong style="color:var(--c-text);">{{ dinheiro(desempenho[c.id].gasto) }}</strong></span>
                  <span>Leads <strong style="color:var(--c-text);">{{ desempenho[c.id].leads }}</strong></span>
                  <span>Qualif. <strong style="color:var(--c-text);">{{ desempenho[c.id].qualificados }}</strong></span>
                  <span>Vendas <strong style="color:var(--c-text);">{{ desempenho[c.id].vendas }}</strong></span>
                </div>
                <div style="color:var(--c-text-faint);">
                  CPL {{ dinheiro(desempenho[c.id].cpl) }} · Custo/qualificado {{ dinheiro(desempenho[c.id].cpq) }}
                  · {{ desempenho[c.id].anuncios }} anúncio{{ desempenho[c.id].anuncios > 1 ? 's' : '' }}<template v-if="desempenho[c.id].ativos">, {{ desempenho[c.id].ativos }} no ar</template>
                </div>
              </template>
              <div v-else style="color:var(--c-text-faint);">Nunca rodou em anúncio — sem números para julgar.</div>
            </div>
            <div v-else-if="carregandoDesempenho" style="font-size:10.5px;color:var(--c-text-faint);">Buscando desempenho…</div>

            <div style="font-size:10.5px;color:var(--c-text-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.original_name }} · {{ tamanho(c.size) }}<span v-if="c.no_facebook"> · no FB</span></div>

            <!-- O motivo só aparece depois do martelo batido: em teste não há o que
                 justificar, e o campo vazio só ocuparia espaço em toda a grade. -->
            <input
              v-if="c.status !== 'testando'"
              v-model="c.status_note"
              :placeholder="c.status === 'reprovado' ? 'Por que deu ruim?' : 'Por que funcionou?'"
              :style="campo + 'font-size:11px;padding:6px 9px;'"
              @blur="salvar(c, { status_note: c.status_note })"
            >

            <textarea v-model="c.notes" rows="2" placeholder="O que essa peça mostra? (a IA lê isto)" :style="campo + 'resize:vertical;font-size:11.5px;padding:7px 9px;'" @blur="salvarNota(c)" />
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
