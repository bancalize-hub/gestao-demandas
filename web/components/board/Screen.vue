<script setup lang="ts">
import { useBoardStore } from '~/stores/board'

/**
 * Tela /tarefas — o quadro. Aqui só entra o que alguém pôs à mão: cartão criado no
 * painel ou solicitação que o cliente mandou pelo formulário. O que os robôs do CRM
 * cobram sozinhos vive na ficha do lead, no chat.
 */
const board = useBoardStore()

const novaLista = ref(false)
const nomeLista = ref('')
const filtrosAbertos = ref(false)

const PRAZOS = [
  { k: 'atrasado', label: 'Atrasados' },
  { k: 'hoje', label: 'Vencem hoje' },
  { k: 'semana', label: 'Próximos 7 dias' },
  { k: 'sem', label: 'Sem prazo' },
] as const

onMounted(() => {
  board.load()
  window.addEventListener('focus', aoVoltar)
  document.addEventListener('visibilitychange', aoVoltar)
})
onUnmounted(() => {
  window.removeEventListener('focus', aoVoltar)
  document.removeEventListener('visibilitychange', aoVoltar)
})

// Voltou para a aba: rebusca. O quadro é de time — alguém pode ter mexido nele.
function aoVoltar() {
  if (document.visibilityState === 'visible') board.refresh()
}

async function criarLista() {
  const nome = nomeLista.value
  nomeLista.value = ''
  novaLista.value = false
  if (nome.trim()) await board.criarLista(nome)
}

function alternar(lista: number[], id: number) {
  const i = lista.indexOf(id)
  i >= 0 ? lista.splice(i, 1) : lista.push(id)
}

function iniciais(nome: string) {
  const p = nome.trim().split(/\s+/).filter(Boolean)
  return p.length ? (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() : '?'
}
function corDe(id: number) {
  return ['#53bdeb', '#25D366', '#ffb443', '#a78bfa', '#ff6b6b', '#4fd1c5'][id % 6]
}

const total = computed(() => board.cards.length)
const mostrando = computed(() => board.filtrados.length)
</script>

<template>
  <div class="r-sm-scroll-y" style="flex:1;display:flex;flex-direction:column;min-width:0;background:var(--c-bg-deep);">
    <!-- Cabeçalho -->
    <div class="r-wrap r-page" style="padding:20px clamp(12px,4vw,30px) 0;display:flex;align-items:center;justify-content:space-between;gap:12px;">
      <div style="min-width:0;">
        <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Tarefas</div>
        <div style="font-size:13px;color:var(--c-text-muted);margin-top:3px;">
          Arraste os cartões entre as listas · clique para abrir
          <span v-if="board.filtroAtivo"> · mostrando {{ mostrando }} de {{ total }}</span>
        </div>
      </div>
      <div style="display:flex;align-items:center;gap:9px;">
        <button class="ghost r-tap" style="background:var(--c-surface-2);border:none;color:var(--c-text-secondary);font-family:inherit;font-size:13px;font-weight:600;padding:9px 14px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="board.abrirArquivo()">
          <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8ZM3 4h18v4H3zM10 12h4" stroke-linejoin="round" /></svg>
          Arquivo
        </button>
        <button
          class="wabtn r-tap" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 16px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;"
          :disabled="!board.colunas.length" @click="board.compondoEm = board.colunas[0]?.id ?? null"
        >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
          Nova tarefa
        </button>
      </div>
    </div>

    <!-- Busca e filtros -->
    <div class="r-wrap" style="padding:14px clamp(12px,4vw,30px) 0;display:flex;align-items:center;gap:9px;">
      <div style="position:relative;flex:1;min-width:180px;max-width:340px;">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" style="position:absolute;left:11px;top:50%;transform:translateY(-50%);color:var(--c-text-faint);"><circle cx="11" cy="11" r="7" /><path d="m20 20-3.2-3.2" stroke-linecap="round" /></svg>
        <input
          v-model="board.filtro.texto" placeholder="Buscar no quadro…"
          style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:9px 11px 9px 32px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;"
        >
      </div>
      <button
        class="ghost r-tap"
        :style="{ background: filtrosAbertos || board.filtroAtivo ? 'var(--c-surface-3)' : 'var(--c-surface-2)', border: 'none', color: 'var(--c-text-secondary)', fontFamily: 'inherit', fontSize: '13px', fontWeight: 600, padding: '9px 14px', borderRadius: '11px', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: '7px' }"
        @click="filtrosAbertos = !filtrosAbertos"
      >
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"><path d="M4 6h16M7 12h10M10 18h4" stroke-linecap="round" /></svg>
        Filtros
      </button>
      <button v-if="board.filtroAtivo" class="link r-tap" style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;" @click="board.limparFiltros()">limpar</button>
    </div>

    <div v-if="filtrosAbertos" class="r-wrap" style="padding:12px clamp(12px,4vw,30px) 0;display:flex;gap:18px;flex-wrap:wrap;">
      <div v-if="board.labels.length">
        <div style="font-size:11px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.4px;">Etiqueta</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;">
          <button
            v-for="l in board.labels" :key="l.id" class="r-tap"
            :style="{ fontSize: '12px', fontWeight: 700, color: '#10231c', background: l.color, opacity: board.filtro.labelIds.includes(l.id) ? 1 : 0.4, padding: '5px 10px', borderRadius: '8px', border: 'none', cursor: 'pointer', fontFamily: 'inherit' }"
            @click="alternar(board.filtro.labelIds, l.id)"
          >{{ l.name || '—' }}</button>
        </div>
      </div>

      <div v-if="board.members.length">
        <div style="font-size:11px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.4px;">Responsável</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;">
          <button
            v-for="m in board.members" :key="m.id" class="r-tap" :title="m.name"
            :style="{ width: '28px', height: '28px', borderRadius: '50%', background: corDe(m.id), color: '#10231c', fontSize: '10.5px', fontWeight: 800, border: board.filtro.memberIds.includes(m.id) ? '2px solid var(--c-text)' : '2px solid transparent', opacity: board.filtro.memberIds.includes(m.id) ? 1 : 0.5, cursor: 'pointer', fontFamily: 'inherit' }"
            @click="alternar(board.filtro.memberIds, m.id)"
          >{{ iniciais(m.name) }}</button>
        </div>
      </div>

      <div>
        <div style="font-size:11px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.4px;">Prazo</div>
        <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:7px;">
          <button
            v-for="p in PRAZOS" :key="p.k" class="chip r-tap"
            :class="{ on: board.filtro.prazo === p.k }"
            @click="board.filtro.prazo = board.filtro.prazo === p.k ? '' : p.k"
          >{{ p.label }}</button>
        </div>
      </div>

      <div>
        <div style="font-size:11px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.4px;">Prioridade</div>
        <div style="display:flex;gap:6px;margin-top:7px;">
          <button
            v-for="p in ['alta', 'media', 'baixa']" :key="p" class="chip r-tap"
            :class="{ on: board.filtro.prio === p }"
            @click="board.filtro.prio = board.filtro.prio === p ? '' : (p as any)"
          >{{ p === 'media' ? 'Média' : p === 'alta' ? 'Alta' : 'Baixa' }}</button>
        </div>
      </div>
    </div>

    <!-- Quadro -->
    <div v-if="board.loading" style="padding:40px clamp(12px,4vw,30px);color:var(--c-text-muted);font-size:13.5px;">Carregando o quadro…</div>
    <div v-else-if="board.error" style="padding:40px clamp(12px,4vw,30px);color:var(--c-danger-soft,#ff9a9a);font-size:13.5px;">
      {{ board.error }}
      <button class="link" style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:13.5px;font-weight:600;cursor:pointer;margin-left:6px;" @click="board.load()">tentar de novo</button>
    </div>

    <div v-else class="kanban-wrap r-kanban" style="flex:1;overflow-x:auto;overflow-y:hidden;padding:18px clamp(12px,4vw,30px) 26px;">
      <div class="r-sm-h-auto" style="display:flex;gap:15px;height:100%;min-width:max-content;align-items:flex-start;">
        <BoardColumn
          v-for="(col, i) in board.colunas" :key="col.id"
          :coluna="col" :primeira="i === 0" :ultima="i === board.colunas.length - 1"
          style="height:100%;"
          @abrir="board.abrirCartao($event)"
        />

        <!-- Nova lista -->
        <div style="width:270px;flex-shrink:0;">
          <div v-if="novaLista" style="background:var(--c-bg-deep);border-radius:14px;padding:12px;">
            <input
              v-model="nomeLista" autofocus placeholder="Nome da lista"
              style="width:100%;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:9px;padding:9px 10px;color:var(--c-text);font-family:inherit;font-size:13px;outline:none;"
              @keyup.enter="criarLista" @keyup.esc="novaLista = false"
            >
            <div style="display:flex;gap:7px;margin-top:9px;">
              <button class="wabtn r-tap" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:12.5px;font-weight:700;padding:7px 13px;border-radius:9px;cursor:pointer;" @click="criarLista">Criar lista</button>
              <button class="ghost r-tap" style="background:none;border:none;color:var(--c-text-muted);font-family:inherit;font-size:12.5px;padding:7px 9px;cursor:pointer;" @click="novaLista = false">Cancelar</button>
            </div>
          </div>
          <button v-else class="novalista r-tap" @click="novaLista = true">
            <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>
            Adicionar lista
          </button>
        </div>
      </div>
    </div>

    <BoardCardModal />
    <BoardArchive />
  </div>
</template>

<style scoped>
/*
  ≤820px a raiz rola no Y: a faixa do kanban precisa parar de disputar a altura com o
  cabeçalho, senão a coluna vira uma janelinha de dois dedos. Só `flex-basis` resolve.
*/
@media (max-width: 820px) {
  .kanban-wrap { flex: 0 0 auto !important; }
}

.novalista {
  width: 100%;
  background: var(--c-bg-deep);
  border: 1px dashed var(--c-surface-3);
  color: var(--c-text-muted);
  font-family: inherit;
  font-size: 13px;
  font-weight: 600;
  padding: 13px;
  border-radius: 14px;
  cursor: pointer;
  display: flex;
  align-items: center;
  gap: 7px;
}
.novalista:hover { background: var(--c-surface-1); color: var(--c-text); }
.chip {
  font-size: 12px;
  font-weight: 600;
  color: var(--c-text-secondary);
  background: var(--c-surface-2);
  border: 1px solid transparent;
  padding: 6px 11px;
  border-radius: 8px;
  cursor: pointer;
  font-family: inherit;
}
.chip.on { background: var(--accent); color: var(--accent-ink); font-weight: 700; }
.ghost:hover { background: var(--c-surface-3) !important; }
.wabtn:hover { background: var(--accent-hi) !important; }
.link:hover { filter: brightness(1.2); }
</style>
