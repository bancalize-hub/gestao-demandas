<script setup lang="ts">
/**
 * Memória de marketing: o que já se aprendeu sobre os anúncios.
 *
 * Separada da memória da IA de vendas (Admin → Memória) de propósito: "o Criativo 3 traz
 * quem quer empréstimo" é verdade sobre o ANÚNCIO, não sobre o produto — na base da IA de
 * vendas isso viraria coisa dita para o cliente.
 *
 * O que justifica a tela existir é o esquecimento: toda análise de campanha refaz o mesmo
 * caminho (puxar gasto, cruzar com o CRM, achar o criativo ruim) e joga a conclusão fora
 * no fim da conversa.
 */
const api = useApi()

interface Memoria {
  id: number
  categoria: string
  titulo: string
  conteudo: string
  periodo: string | null
  confianca: 'medido' | 'hipotese'
  fixado: boolean
  updated_at: string
}

const memorias = ref<Memoria[]>([])
const carregando = ref(true)
const salvando = ref(false)
const erro = ref('')
const editando = ref<number | null>(null)

const CATS: Record<string, { rotulo: string, icone: string }> = {
  criativo: { rotulo: 'Criativo', icone: '🖼️' },
  publico: { rotulo: 'Público', icone: '🎯' },
  orcamento: { rotulo: 'Orçamento', icone: '💰' },
  metrica: { rotulo: 'Métrica', icone: '📊' },
  aprendizado: { rotulo: 'Aprendizado', icone: '💡' },
}

function vazio(): Omit<Memoria, 'id' | 'updated_at'> {
  return { categoria: 'aprendizado', titulo: '', conteudo: '', periodo: '', confianca: 'medido', fixado: false }
}
const form = ref(vazio())

async function carregar() {
  carregando.value = true
  try {
    const r = await api<{ memorias: Memoria[] }>('/api/marketing/memorias')
    memorias.value = r.memorias || []
  }
  catch (e: any) { erro.value = e?.message || 'Não consegui carregar.' }
  finally { carregando.value = false }
}
onMounted(carregar)

function editar(m: Memoria) {
  editando.value = m.id
  form.value = { categoria: m.categoria, titulo: m.titulo, conteudo: m.conteudo, periodo: m.periodo || '', confianca: m.confianca, fixado: m.fixado }
}
function cancelar() { editando.value = null; form.value = vazio() }

async function salvar() {
  if (!form.value.titulo.trim() || !form.value.conteudo.trim()) { erro.value = 'Título e conteúdo são obrigatórios.'; return }
  salvando.value = true
  erro.value = ''
  try {
    const rota = editando.value ? `/api/marketing/memorias/${editando.value}` : '/api/marketing/memorias'
    await api(rota, { method: editando.value ? 'PATCH' : 'POST', body: form.value })
    cancelar()
    await carregar()
  }
  catch (e: any) { erro.value = e?.response?._data?.message || e?.message || 'Erro ao salvar.' }
  finally { salvando.value = false }
}

async function apagar(m: Memoria) {
  if (!confirm(`Apagar "${m.titulo}"?`)) return
  try { await api(`/api/marketing/memorias/${m.id}`, { method: 'DELETE' }); await carregar() }
  catch (e: any) { erro.value = e?.message || 'Erro ao apagar.' }
}

async function fixar(m: Memoria) {
  try {
    await api(`/api/marketing/memorias/${m.id}`, {
      method: 'PATCH',
      body: { categoria: m.categoria, titulo: m.titulo, conteudo: m.conteudo, periodo: m.periodo, confianca: m.confianca, fixado: !m.fixado },
    })
    await carregar()
  }
  catch (e: any) { erro.value = e?.message || 'Erro ao fixar.' }
}

function quando(iso: string) {
  return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <!-- r-wrap: em 360px o link "← Gerenciador de anúncios" era espremido e quebrava em três linhas dentro do próprio botão -->
    <div class="r-wrap r-pad" style="padding-block:16px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px;">🧠</div>
      <div style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Memória de marketing</div>
        <div style="font-size:12px;color:var(--c-text-muted);">O que já se aprendeu sobre os anúncios — para não redescobrir toda vez</div>
      </div>
      <!-- o espaçador vira a quebra de linha no celular: solto numa barra que quebra, ele engolia a fileira inteira -->
      <div class="r-break-line" style="flex:1;" />
      <NuxtLink to="/marketing" class="r-tap r-xs-full" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">← Gerenciador de anúncios</NuxtLink>
    </div>

    <!--
      A coluna tinha teto de 940px mas nenhuma centralização: em 1600px+ ficava colada na
      borda esquerda com metade da tela vazia. O r-safe-pad-bottom é porque esta tela rola
      inteira e o último cartão terminava debaixo da barra inferior do celular.
    -->
    <div class="r-page r-pad r-safe-pad-bottom" style="--r-page:940px;--r-pb:24px;padding-top:20px;">
      <div v-if="erro" style="background:var(--c-danger-soft, #3a1f1f);color:var(--c-danger, #ff8a8a);padding:9px 12px;border-radius:8px;font-size:12px;margin-bottom:14px;">{{ erro }}</div>

      <!-- formulário -->
      <div style="background:var(--c-surface-0);border:1px solid var(--c-surface-2);border-radius:12px;padding:14px;margin-bottom:22px;">
        <div style="font-weight:700;font-size:13px;margin-bottom:10px;">{{ editando ? 'Editando memória' : 'Nova memória' }}</div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:9px;">
          <select v-model="form.categoria" style="background:var(--c-surface-1);border:1px solid var(--c-surface-3);border-radius:7px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:12.5px;">
            <option v-for="(c, k) in CATS" :key="k" :value="k">{{ c.icone }} {{ c.rotulo }}</option>
          </select>
          <select v-model="form.confianca" title="Medido saiu de número apurado. Hipótese ainda não foi confirmada." style="background:var(--c-surface-1);border:1px solid var(--c-surface-3);border-radius:7px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:12.5px;">
            <option value="medido">✓ Medido</option>
            <option value="hipotese">? Hipótese</option>
          </select>
          <input v-model="form.periodo" placeholder="Período (ex.: 04–06/08/2026)" style="flex:1;min-width:180px;background:var(--c-surface-1);border:1px solid var(--c-surface-3);border-radius:7px;padding:7px 9px;color:var(--c-text);font-family:inherit;font-size:12.5px;">
        </div>
        <input v-model="form.titulo" placeholder="Título — a conclusão em uma linha" style="width:100%;background:var(--c-surface-1);border:1px solid var(--c-surface-3);border-radius:7px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:13px;margin-bottom:9px;">
        <textarea v-model="form.conteudo" rows="4" placeholder="O que foi medido, com os números. Quem ler daqui a um mês precisa entender sem você por perto." style="width:100%;background:var(--c-surface-1);border:1px solid var(--c-surface-3);border-radius:7px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:12.5px;line-height:1.55;resize:vertical;" />
        <div class="r-xs-wrap" style="display:flex;align-items:center;gap:10px;margin-top:9px;">
          <button :disabled="salvando" class="r-tap r-xs-full" style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:8px;cursor:pointer;" @click="salvar()">{{ salvando ? 'Salvando…' : (editando ? 'Salvar' : 'Adicionar') }}</button>
          <button v-if="editando" class="r-tap" style="background:none;border:none;color:var(--c-text-faint);font-family:inherit;font-size:12.5px;cursor:pointer;" @click="cancelar()">cancelar</button>
        </div>
      </div>

      <div v-if="carregando" style="color:var(--c-text-faint);font-size:12.5px;">Carregando…</div>
      <div v-else-if="!memorias.length" style="color:var(--c-text-faint);font-size:12.5px;line-height:1.6;">
        Nenhuma memória ainda. Toda conclusão que você quiser reaproveitar na próxima análise entra aqui —
        qual criativo traz gente boa, qual público desperdiça, o que já foi testado e não deu certo.
      </div>

      <div v-for="m in memorias" :key="m.id" style="background:var(--c-surface-0);border:1px solid var(--c-surface-2);border-radius:11px;padding:13px 14px;margin-bottom:10px;">
        <div style="display:flex;align-items:center;gap:8px;margin-bottom:5px;flex-wrap:wrap;">
          <span style="font-size:11px;font-weight:700;color:var(--c-text-faint);">{{ CATS[m.categoria]?.icone }} {{ CATS[m.categoria]?.rotulo || m.categoria }}</span>
          <span v-if="m.confianca === 'hipotese'" title="Ainda não confirmado por número" style="font-size:10px;font-weight:700;color:var(--c-warn);border:1px solid var(--c-warn);border-radius:5px;padding:1px 5px;">HIPÓTESE</span>
          <span v-if="m.periodo" style="font-size:10.5px;color:var(--c-text-faint);">{{ m.periodo }}</span>
          <!-- espaçador vira quebra: quando os chips enchiam a primeira fileira ele consumia a segunda e deixava as ações soltas no meio do cartão -->
          <div class="r-break-line" style="flex:1;" />
          <button :title="m.fixado ? 'Desafixar' : 'Fixar no topo'" class="r-tap" style="background:none;border:none;font-size:12px;cursor:pointer;padding:2px;" :style="{ opacity: m.fixado ? 1 : .35 }" @click="fixar(m)">📌</button>
          <button class="r-tap" style="background:none;border:none;color:var(--c-text-faint);font-family:inherit;font-size:11px;cursor:pointer;" @click="editar(m)">editar</button>
          <button class="r-tap" style="background:none;border:none;color:var(--c-text-faint);font-family:inherit;font-size:11px;cursor:pointer;" @click="apagar(m)">apagar</button>
        </div>
        <div style="font-weight:700;font-size:13.5px;margin-bottom:4px;">{{ m.titulo }}</div>
        <!-- r-break: ID de campanha e URL de criativo não quebram sozinhos e criavam rolagem horizontal na tela toda -->
        <div class="r-break" style="font-size:12.5px;color:var(--c-text-secondary);line-height:1.6;white-space:pre-wrap;">{{ m.conteudo }}</div>
        <div style="font-size:10.5px;color:var(--c-text-faint);margin-top:6px;">atualizada em {{ quando(m.updated_at) }}</div>
      </div>
    </div>
  </div>
</template>
