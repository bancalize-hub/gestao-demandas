<script setup lang="ts">
import { type BoardCard, useBoardStore } from '~/stores/board'

/**
 * Uma lista do quadro: cabeçalho (nome, cor, menu), os cartões e o compositor de
 * "adicionar cartão" no pé.
 *
 * O arrasto é o HTML5 nativo, igual ao resto do CRM. A ordem muda enquanto o cartão
 * passa por cima dos outros (como no Trello) e só é gravada quando ele é solto —
 * `dragover` dispara dezenas de vezes por segundo e não pode virar requisição.
 */
const props = defineProps<{
  coluna: { id: number, name: string, color: string, position: number, cards: BoardCard[], total: number }
  primeira: boolean
  ultima: boolean
}>()
const emit = defineEmits<{ (e: 'abrir', id: number): void }>()

const board = useBoardStore()

const CORES = ['#8696a0', '#53bdeb', '#25D366', '#ffb443', '#ff6b6b', '#a78bfa', '#4fd1c5', '#f472b6']

const menu = ref(false)
const renomeando = ref(false)
const nome = ref(props.coluna.name)
const compondo = ref(false)
const novoTitulo = ref('')
const inputNovo = ref<HTMLTextAreaElement | null>(null)

watch(() => props.coluna.name, v => { nome.value = v })

function abrirRenome() {
  menu.value = false
  renomeando.value = true
  nextTick(() => document.getElementById(`lista-nome-${props.coluna.id}`)?.focus())
}
function salvarNome() {
  renomeando.value = false
  const v = nome.value.trim()
  if (!v || v === props.coluna.name) { nome.value = props.coluna.name; return }
  board.atualizarLista(props.coluna.id, { name: v })
}

function pintar(cor: string) {
  menu.value = false
  board.atualizarLista(props.coluna.id, { color: cor })
}

function arquivar() {
  menu.value = false
  const quantos = props.coluna.total
  const aviso = quantos
    ? `Arquivar "${props.coluna.name}" e os ${quantos} cartões dela? Dá para restaurar tudo pelo Arquivo.`
    : `Arquivar a lista "${props.coluna.name}"?`
  if (confirm(aviso)) board.arquivarLista(props.coluna.id)
}

async function abrirCompositor() {
  menu.value = false
  compondo.value = true
  await nextTick()
  inputNovo.value?.focus()
}
async function criar() {
  const t = novoTitulo.value
  novoTitulo.value = ''
  if (!t.trim()) { compondo.value = false; return }
  await board.criarCartao(props.coluna.id, t)
  // Continua aberto: quem está esvaziando a cabeça cria vários cartões seguidos.
  await nextTick()
  inputNovo.value?.focus()
}

// O botão "Nova tarefa" do topo abre o compositor da primeira lista.
watch(() => board.compondoEm, (v) => {
  if (v === props.coluna.id) {
    board.compondoEm = null
    abrirCompositor()
  }
})

// ----- Arrasto -----
function pegarCartao(card: BoardCard, e: DragEvent) {
  board.dragCardId = card.id
  e.dataTransfer?.setData('text/plain', String(card.id))
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
}

async function soltarCartao() {
  const id = board.dragCardId
  board.dragCardId = null
  board.dragOverListId = null
  if (id) await board.soltarCartao(id)
}

/** Passou por cima de um cartão: entra antes ou depois dele, pela metade da altura. */
function sobreCartao(card: BoardCard, e: DragEvent) {
  if (!board.dragCardId || board.dragCardId === card.id) return
  const alvo = e.currentTarget as HTMLElement
  const r = alvo.getBoundingClientRect()
  const depois = e.clientY > r.top + r.height / 2
  const ordem = props.coluna.cards.filter(c => c.id !== board.dragCardId)
  const i = ordem.findIndex(c => c.id === card.id)
  board.moverCartaoLocal(board.dragCardId, props.coluna.id, i + (depois ? 1 : 0))
}

/** Passou pelo corpo da lista (área vazia): vai para o fim. */
function sobreCorpo() {
  board.dragOverListId = props.coluna.id
  if (board.dragListId) return
  if (!board.dragCardId) return
  const card = board.cards.find(c => c.id === board.dragCardId)
  if (card && card.task_list_id !== props.coluna.id) {
    board.moverCartaoLocal(board.dragCardId, props.coluna.id, props.coluna.cards.length)
  }
}

// Arrasto da lista inteira (pelo cabeçalho).
function pegarLista(e: DragEvent) {
  board.dragListId = props.coluna.id
  if (e.dataTransfer) e.dataTransfer.effectAllowed = 'move'
}
function sobreLista() {
  const arrastada = board.dragListId
  if (!arrastada || arrastada === props.coluna.id) return
  const ordenadas = [...board.lists].sort((a, b) => a.position - b.position)
  const de = ordenadas.findIndex(l => l.id === arrastada)
  const para = ordenadas.findIndex(l => l.id === props.coluna.id)
  if (de < 0 || para < 0) return
  const [l] = ordenadas.splice(de, 1)
  ordenadas.splice(para, 0, l)
  ordenadas.forEach((x, i) => { x.position = i })
  board.lists = ordenadas
}
async function largarLista() {
  if (!board.dragListId) return
  board.dragListId = null
  await board.salvarOrdemListas()
}

const realce = computed(() => board.dragOverListId === props.coluna.id && !!board.dragCardId)
</script>

<template>
  <div
    class="r-kanban-col bcol"
    :style="{ outline: board.dragListId === coluna.id ? '2px dashed rgba(var(--accent-rgb),.5)' : 'none' }"
    @dragover.prevent="sobreLista"
    @drop.prevent="largarLista"
  >
    <!-- Cabeçalho -->
    <div
      draggable="true" style="display:flex;align-items:center;gap:8px;padding:2px 3px 11px;cursor:grab;"
      @dragstart="pegarLista" @dragend="largarLista"
    >
      <span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: coluna.color, flexShrink: 0 }" />

      <input
        v-if="renomeando" :id="`lista-nome-${coluna.id}`" v-model="nome"
        style="flex:1;min-width:0;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:5px 8px;color:var(--c-text);font-family:inherit;font-size:13px;font-weight:700;outline:none;"
        @keyup.enter="salvarNome" @keyup.esc="renomeando = false" @blur="salvarNome"
      >
      <span v-else class="r-ellipsis" style="font-weight:700;font-size:13.5px;flex:1;min-width:0;" @dblclick="abrirRenome">{{ coluna.name }}</span>

      <span style="font-size:12px;color:var(--c-text-muted);background:var(--c-surface-1);min-width:20px;height:20px;border-radius:6px;display:flex;align-items:center;justify-content:center;padding:0 6px;">{{ coluna.cards.length }}</span>

      <button class="ico r-tap" title="Opções da lista" style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;padding:2px;display:flex;" @click.stop="menu = !menu">
        <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><circle cx="5" cy="12" r="1.7" /><circle cx="12" cy="12" r="1.7" /><circle cx="19" cy="12" r="1.7" /></svg>
      </button>
    </div>

    <!-- Menu da lista -->
    <div v-if="menu" class="menu-fundo" @click="menu = false" />
    <div v-if="menu" class="menu" style="position:absolute;z-index:31;top:38px;right:8px;width:212px;background:var(--c-bg);border:1px solid var(--c-surface-2);border-radius:12px;padding:7px;box-shadow:0 18px 38px rgba(0,0,0,.4);">
      <button class="mitem" @click="abrirCompositor">Adicionar cartão</button>
      <button class="mitem" @click="abrirRenome">Renomear lista</button>
      <div style="display:flex;gap:5px;flex-wrap:wrap;padding:8px 9px 9px;">
        <span
          v-for="c in CORES" :key="c" :title="c"
          :style="{ width: '20px', height: '20px', borderRadius: '6px', background: c, cursor: 'pointer', border: coluna.color === c ? '2px solid var(--c-text)' : '2px solid transparent' }"
          @click="pintar(c)"
        />
      </div>
      <button class="mitem" :disabled="primeira" @click="menu = false; board.moverLista(coluna.id, -1)">Mover para a esquerda</button>
      <button class="mitem" :disabled="ultima" @click="menu = false; board.moverLista(coluna.id, 1)">Mover para a direita</button>
      <button class="mitem perigo" @click="arquivar">Arquivar lista</button>
    </div>

    <!-- Cartões -->
    <div
      class="r-kanban-body bbody"
      :style="{ background: realce ? 'rgba(var(--accent-rgb),.07)' : 'transparent', outline: realce ? '2px dashed rgba(var(--accent-rgb),.4)' : '2px dashed transparent' }"
      @dragover.prevent="sobreCorpo"
      @drop.prevent="soltarCartao"
    >
      <div
        v-for="card in coluna.cards" :key="card.id"
        draggable="true"
        @dragstart="pegarCartao(card, $event)"
        @dragend="soltarCartao"
        @dragover.prevent.stop="sobreCartao(card, $event)"
        @drop.prevent.stop="soltarCartao"
        @click="emit('abrir', card.id)"
      >
        <BoardCard :card="card" :arrastando="board.dragCardId === card.id" />
      </div>

      <div v-if="!coluna.cards.length && !compondo" style="font-size:12px;color:var(--c-text-faint);padding:10px 4px;">
        {{ coluna.total ? 'Nenhum cartão bate com o filtro.' : 'Lista vazia.' }}
      </div>

      <!-- Compositor -->
      <div v-if="compondo" style="background:var(--c-surface-1);border-radius:11px;padding:9px;">
        <textarea
          ref="inputNovo" v-model="novoTitulo" rows="2" placeholder="Título do cartão…"
          style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:8px 9px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;resize:vertical;"
          @keydown.enter.exact.prevent="criar" @keyup.esc="compondo = false"
        />
        <div style="display:flex;gap:7px;margin-top:8px;">
          <button class="wabtn r-tap" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:12.5px;font-weight:700;padding:7px 13px;border-radius:9px;cursor:pointer;" @click="criar">Adicionar</button>
          <button class="ghost r-tap" style="background:none;border:none;color:var(--c-text-muted);font-family:inherit;font-size:12.5px;padding:7px 9px;border-radius:9px;cursor:pointer;" @click="compondo = false">Cancelar</button>
        </div>
      </div>
    </div>

    <button v-if="!compondo" class="addcard r-tap" @click="abrirCompositor">
      <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
      Adicionar cartão
    </button>
  </div>
</template>

<style scoped>
.bcol {
  position: relative;
  width: 292px;
  display: flex;
  flex-direction: column;
  background: var(--c-bg-deep);
  border-radius: 14px;
  padding: 12px;
  flex-shrink: 0;
  max-height: 100%;
}
.bbody {
  display: flex;
  flex-direction: column;
  gap: 9px;
  overflow-y: auto;
  flex: 1;
  min-height: 60px;
  border-radius: 10px;
  outline-offset: -2px;
  transition: background .15s;
}
.addcard {
  margin-top: 9px;
  background: none;
  border: none;
  color: var(--c-text-muted);
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  padding: 8px 9px;
  border-radius: 9px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 6px;
  text-align: left;
}
.addcard:hover { background: var(--c-surface-1); color: var(--c-text); }
.ico:hover { color: var(--c-text) !important; }
.menu-fundo { position: fixed; inset: 0; z-index: 30; }
.mitem {
  display: block;
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  color: var(--c-text);
  font-family: inherit;
  font-size: 13px;
  padding: 9px;
  border-radius: 9px;
  cursor: pointer;
}
.mitem:hover:not(:disabled) { background: var(--c-surface-2); }
.mitem:disabled { color: var(--c-text-faint); cursor: default; }
.mitem.perigo { color: var(--c-danger-soft, #ff9a9a); }
.wabtn:hover { background: var(--accent-hi) !important; }
.ghost:hover { color: var(--c-text) !important; }
</style>
