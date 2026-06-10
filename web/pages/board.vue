<script setup lang="ts">
import draggable from 'vuedraggable'
import type { Status, Ticket } from '~/stores/board'
import { LABEL_STATUS, STATUSES, useBoardStore } from '~/stores/board'

definePageMeta({ middleware: 'auth' })

const board = useBoardStore()
const { usuario, checar, logout } = useAuth()
const ticketAberto = ref<Ticket | null>(null)

const filtros = reactive({ busca: '', tipo: '', prioridade: '' })
const filtroAtivo = computed(() => !!(filtros.busca || filtros.tipo || filtros.prioridade))

onMounted(async () => {
  // No primeiro acesso (SSR) o middleware não roda no cliente — revalida aqui.
  if (!(await checar())) return navigateTo('/login')
  board.carregar()
})

function visiveis(status: Status) {
  return board.colunas[status].filter((t) => {
    if (filtros.tipo && t.tipo !== filtros.tipo) return false
    if (filtros.prioridade && t.prioridade !== filtros.prioridade) return false
    if (filtros.busca) {
      const alvo = `${t.codigo} ${t.titulo} ${t.descricao} ${t.solicitante_empresa} ${t.modulo}`.toLowerCase()
      if (!alvo.includes(filtros.busca.toLowerCase())) return false
    }
    return true
  })
}

// Calcula a nova ordem como ponto médio entre os vizinhos da posição de destino.
function ordemNaPosicao(lista: Ticket[], indice: number) {
  const anterior = lista[indice - 1]?.ordem
  const proximo = lista[indice + 1]?.ordem
  if (anterior !== undefined && proximo !== undefined) return (anterior + proximo) / 2
  if (anterior !== undefined) return anterior + 1
  if (proximo !== undefined) return proximo - 1
  return 0
}

function aoMudar(status: Status, evento: any) {
  const mudanca = evento.added ?? evento.moved
  if (!mudanca) return
  const lista = board.colunas[status]
  board.mover(mudanca.element, status, ordemNaPosicao(lista, mudanca.newIndex))
}

const corColuna: Record<Status, string> = {
  TRIAGEM: 'border-t-violet-400',
  A_FAZER: 'border-t-slate-400',
  EM_ANDAMENTO: 'border-t-blue-400',
  EM_REVISAO: 'border-t-amber-400',
  CONCLUIDO: 'border-t-emerald-400',
}
</script>

<template>
  <main class="flex h-screen flex-col">
    <!-- Barra superior -->
    <header class="flex flex-wrap items-center gap-3 border-b border-slate-200 bg-white px-4 py-3">
      <h1 class="mr-auto text-lg font-bold text-slate-900">Gestão de Demandas</h1>

      <input
        v-model="filtros.busca"
        type="search"
        placeholder="Buscar por código, título, empresa..."
        class="w-64 rounded-lg border border-slate-300 px-3 py-1.5 text-sm outline-none focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
      >
      <select v-model="filtros.tipo" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        <option value="">Todos os tipos</option>
        <option value="BUG">🐛 Bug</option>
        <option value="FEATURE">✨ Feature</option>
      </select>
      <select v-model="filtros.prioridade" class="rounded-lg border border-slate-300 px-2 py-1.5 text-sm">
        <option value="">Todas as prioridades</option>
        <option value="CRITICA">Crítica</option>
        <option value="ALTA">Alta</option>
        <option value="MEDIA">Média</option>
        <option value="BAIXA">Baixa</option>
      </select>

      <button
        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50"
        title="Recarregar"
        @click="board.carregar()"
      >
        ↻
      </button>
      <NuxtLink to="/solicitar" class="rounded-lg bg-indigo-600 px-4 py-1.5 text-sm font-semibold text-white hover:bg-indigo-700">
        Nova Demanda
      </NuxtLink>
      <button
        v-if="usuario"
        class="rounded-lg border border-slate-300 px-3 py-1.5 text-sm text-slate-600 transition hover:bg-slate-50"
        :title="`Sair (${usuario.email})`"
        @click="logout()"
      >
        Sair
      </button>
    </header>

    <p v-if="board.erro" class="bg-red-50 px-4 py-2 text-sm text-red-700">{{ board.erro }}</p>
    <p v-if="filtroAtivo" class="bg-amber-50 px-4 py-1.5 text-xs text-amber-700">
      Filtros ativos — o arrastar de cards fica desabilitado enquanto houver filtro.
    </p>

    <!-- Colunas -->
    <div class="flex flex-1 gap-4 overflow-x-auto bg-slate-100 p-4">
      <section
        v-for="status in STATUSES"
        :key="status"
        class="flex w-72 shrink-0 flex-col rounded-xl border-t-4 bg-slate-50 shadow-sm"
        :class="corColuna[status]"
      >
        <header class="flex items-center justify-between px-3 py-2.5">
          <h2 class="text-sm font-bold text-slate-700">{{ LABEL_STATUS[status] }}</h2>
          <span class="rounded-full bg-slate-200 px-2 py-0.5 text-xs font-semibold text-slate-600">
            {{ visiveis(status).length }}
          </span>
        </header>

        <draggable
          v-if="!filtroAtivo"
          v-model="board.colunas[status]"
          group="tickets"
          item-key="id"
          class="flex-1 space-y-2 overflow-y-auto px-2 pb-2"
          ghost-class="opacity-40"
          @change="aoMudar(status, $event)"
        >
          <template #item="{ element }">
            <BoardCardDemanda :ticket="element" @abrir="ticketAberto = $event" />
          </template>
        </draggable>

        <!-- Com filtro ativo, lista somente leitura -->
        <div v-else class="flex-1 space-y-2 overflow-y-auto px-2 pb-2">
          <BoardCardDemanda
            v-for="t in visiveis(status)"
            :key="t.id"
            :ticket="t"
            @abrir="ticketAberto = $event"
          />
        </div>
      </section>
    </div>

    <BoardCardDetalheModal v-if="ticketAberto" :ticket="ticketAberto" @fechar="ticketAberto = null" />
  </main>
</template>
