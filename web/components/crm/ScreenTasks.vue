<script setup lang="ts">
import { useCrmStore, prioMeta } from '~/stores/crm'

const crm = useCrmStore()

const TYPES = ['Novo recurso', 'Suporte', 'Bug', 'Outro']
const PRIOS = [
  { k: 'baixa', label: 'Baixa', color: '#53bdeb' },
  { k: 'media', label: 'Média', color: '#ffb443' },
  { k: 'alta', label: 'Alta', color: '#ff6b6b' },
] as const

function delTask(id: number) {
  if (confirm('Excluir esta tarefa?')) crm.removeTask(id)
}

// ----- Detalhe da tarefa (modal ao clicar no card) -----
const open = ref(false)
const edit = reactive({ id: 0, title: '', description: '', client: '', priority: 'media', type: '', due: '' })

function openDetail(card: any) {
  Object.assign(edit, {
    id: card.id, title: card.title, description: card.description || '', client: card.client || '',
    priority: card.priority || 'media', type: card.type || '', due: card.due || '',
  })
  open.value = true
}
function saveDetail() {
  crm.updateTask(edit.id, {
    title: edit.title.trim() || edit.title, description: edit.description, client: edit.client,
    priority: edit.priority as any, type: edit.type, due: edit.due,
  })
  open.value = false
}
function delFromDetail() {
  if (confirm('Excluir esta tarefa?')) {
    crm.removeTask(edit.id)
    open.value = false
  }
}

const cols = computed(() => crm.tasks.map((col) => {
  const key = `tasks:${col.id}`
  const over = crm.dragOverCol === key
  return {
    id: col.id, title: col.title, dot: col.dot, count: col.cards.length,
    bodyStyle: { display: 'flex', flexDirection: 'column', gap: '10px', overflowY: 'auto', flex: 1, borderRadius: '10px', minHeight: '70px', transition: 'background .15s', background: over ? 'rgba(var(--accent-rgb),.08)' : 'transparent', outline: over ? '2px dashed rgba(var(--accent-rgb),.45)' : '2px dashed transparent', outlineOffset: '-2px' },
    cards: col.cards.map((card) => {
      const p = prioMeta(card.priority)
      return {
        ...card, prioLabel: p.label,
        prioStyle: { fontSize: '10px', fontWeight: 700, color: p.color, background: `${p.color}22`, padding: '2px 8px', borderRadius: '6px' },
        cardStyle: { background: 'var(--c-surface-1)', borderRadius: '12px', padding: '13px', cursor: 'pointer', borderLeft: `3px solid ${col.dot}` },
      }
    }),
  }
}))
</script>

<template>
  <div class="r-sm-scroll-y" style="flex:1;display:flex;flex-direction:column;min-width:0;background:var(--c-bg-deep);">
    <div class="r-wrap r-page" style="padding:22px clamp(12px,4vw,30px) 0;display:flex;align-items:center;justify-content:space-between;">
      <div>
        <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Tarefas</div>
        <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:3px;">Clique numa tarefa para ver os detalhes · arraste para mover entre as etapas</div>
      </div>
      <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 17px;border-radius:11px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="crm.go('form')"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Nova tarefa</button>
    </div>
    <!--
      No celular a tela rola no Y (raiz com r-sm-scroll-y) e a faixa do kanban para de
      disputar altura (.kanban-wrap, no scoped): antes o cabeçalho consumia os 100dvh
      travados pelo layout e a coluna virava uma janelinha. Cada etapa vira um cartão de
      86vw com encaixe, no lugar dos 288px fixos que deixavam meia coluna vizinha à mostra.
    -->
    <div class="kanban-wrap r-kanban" style="flex:1;overflow-x:auto;overflow-y:hidden;padding:22px clamp(12px,4vw,30px) 26px;">
      <div class="r-sm-h-auto" style="display:flex;gap:16px;height:100%;min-width:max-content;">
        <div v-for="col in cols" :key="col.id" class="r-kanban-col" style="width:288px;display:flex;flex-direction:column;background:var(--c-bg-deep);border-radius:14px;padding:13px;flex-shrink:0;">
          <div style="display:flex;align-items:center;gap:8px;margin-bottom:13px;padding:0 3px;"><span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: col.dot }" /><span style="font-weight:700;font-size:13.5px;">{{ col.title }}</span><span style="font-size:12px;color:var(--c-text-muted);background:var(--c-surface-1);min-width:20px;height:20px;border-radius:6px;display:flex;align-items:center;justify-content:center;padding:0 6px;">{{ col.count }}</span></div>
          <div
            :style="col.bodyStyle" class="r-kanban-body"
            @dragover.prevent="crm.setDragOver(`tasks:${col.id}`)"
            @drop.prevent="crm.dropTo('tasks', col.id)"
          >
            <div
              v-for="card in col.cards" :key="card.id"
              draggable="true" :style="card.cardStyle" class="card"
              @dragstart="crm.setDrag('tasks', col.id, card.id)"
              @dragend="crm.setDragOver(null)"
              @click="openDetail(card)"
            >
              <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:8px;">
                <div class="r-break" style="font-weight:700;font-size:13.5px;line-height:1.35;">{{ card.title }}</div>
                <button class="delbtn r-tap r-touch-show" title="Excluir" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;padding:0;flex-shrink:0;display:flex;" @click.stop="delTask(card.id)"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg></button>
              </div>
              <div v-if="card.description" style="font-size:11.5px;color:var(--c-text-muted);margin-top:5px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;">{{ card.description }}</div>
              <div style="display:flex;align-items:center;gap:6px;margin-top:9px;"><span :style="card.prioStyle">{{ card.prioLabel }}</span><span style="font-size:10.5px;color:var(--c-text-muted);background:var(--c-bg-deep);padding:2px 8px;border-radius:6px;">{{ card.type }}</span></div>
              <div style="display:flex;align-items:center;justify-content:space-between;margin-top:11px;"><span style="font-size:11.5px;color:var(--c-text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:150px;">{{ card.client }}</span><span style="font-size:11px;color:var(--c-text-muted);display:flex;align-items:center;gap:4px;flex-shrink:0;"><svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="8.5" /><path d="M12 7.5V12l3 2" stroke-linecap="round" /></svg>{{ card.due }}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Modal: detalhes da tarefa -->
    <div v-if="open" class="overlay r-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:20px;" @click.self="open = false">
      <div class="r-sheet" style="width:560px;max-width:100%;max-height:90vh;overflow-y:auto;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:26px;">
        <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;margin-bottom:18px;">
          <div style="font-size:18px;font-weight:800;">Detalhes da tarefa</div>
          <button class="ghost r-tap" style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:18px;line-height:1;padding:6px 11px;border-radius:9px;cursor:pointer;" @click="open = false">✕</button>
        </div>

        <div style="display:flex;flex-direction:column;gap:16px;">
          <label style="display:flex;flex-direction:column;gap:6px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Título</span>
            <input v-model="edit.title" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;">
          </label>

          <label style="display:flex;flex-direction:column;gap:6px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Descrição</span>
            <textarea v-model="edit.description" rows="6" placeholder="Sem descrição registrada para esta tarefa." style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:11px 12px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;resize:vertical;line-height:1.55;" />
          </label>

          <label style="display:flex;flex-direction:column;gap:6px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Cliente</span>
            <input v-model="edit.client" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;">
          </label>

          <div>
            <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Tipo</span>
            <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:8px;">
              <button v-for="t in TYPES" :key="t" class="r-tap" :style="{ fontSize: '13px', fontWeight: edit.type === t ? 700 : 600, color: edit.type === t ? 'var(--accent-ink)' : 'var(--c-text-secondary)', background: edit.type === t ? 'var(--accent)' : 'var(--c-surface-2)', padding: '8px 13px', borderRadius: '9px', cursor: 'pointer', border: 'none', fontFamily: 'inherit' }" @click="edit.type = t">{{ t }}</button>
            </div>
          </div>

          <div>
            <span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Prioridade</span>
            <div class="r-wrap" style="display:flex;gap:8px;margin-top:8px;">
              <button v-for="p in PRIOS" :key="p.k" class="r-tap" :style="{ fontSize: '13px', fontWeight: edit.priority === p.k ? 700 : 600, color: edit.priority === p.k ? p.color : 'var(--c-text-secondary)', background: edit.priority === p.k ? `${p.color}22` : 'var(--c-surface-2)', border: edit.priority === p.k ? `1px solid ${p.color}` : '1px solid transparent', padding: '8px 15px', borderRadius: '9px', cursor: 'pointer', fontFamily: 'inherit' }" @click="edit.priority = p.k">{{ p.label }}</button>
            </div>
          </div>

          <label style="display:flex;flex-direction:column;gap:6px;max-width:220px;"><span style="font-size:12.5px;font-weight:600;color:var(--c-text-secondary);">Prazo</span>
            <input v-model="edit.due" placeholder="ex.: 20/06 ou Sem prazo" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:10px 12px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;">
          </label>
        </div>

        <div class="r-wrap r-sm-gap-sm" style="display:flex;align-items:center;justify-content:space-between;margin-top:24px;">
          <button class="delbig" style="background:none;border:1px solid var(--c-danger-bg);color:var(--c-danger-soft);font-family:inherit;font-size:13px;font-weight:600;padding:10px 16px;border-radius:11px;cursor:pointer;" @click="delFromDetail">Excluir</button>
          <div style="display:flex;gap:10px;">
            <button class="ghost" style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13.5px;font-weight:600;padding:10px 17px;border-radius:11px;cursor:pointer;" @click="open = false">Cancelar</button>
            <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13.5px;font-weight:700;padding:10px 20px;border-radius:11px;cursor:pointer;" @click="saveDetail">Salvar</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
/*
  ≤820px a raiz passa a rolar no eixo Y (r-sm-scroll-y). Para a rolagem existir, a faixa
  do kanban tem de PARAR de disputar a altura: com `flex:1` ela ficava com a sobra do
  cabeçalho — no celular, quase nada. Nenhum utilitário cobre isto: só o `flex-basis`
  resolve, `height:auto` não vence.
*/
@media (max-width: 820px) {
  .kanban-wrap { flex: 0 0 auto !important; }
}

.wabtn:hover { background: var(--accent-hi) !important; }
.card:hover { filter: brightness(1.12); }
.card .delbtn { opacity: 0; transition: opacity .15s; }
.card:hover .delbtn { opacity: 1; }
.delbtn:hover { color: var(--c-danger) !important; }
.ghost:hover { background: var(--c-surface-3) !important; }
.delbig:hover { background: rgba(255,107,107,.12) !important; }
</style>
