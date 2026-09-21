<script setup lang="ts">
import { useBoardStore, usePrioMeta } from '~/stores/board'

/**
 * O cartão aberto — é aqui que mora o trabalho: descrição, checklist, comentários,
 * anexos, etiquetas, responsáveis, prazo e para onde ele vai.
 *
 * Cada campo grava sozinho quando sai do foco (não existe um "salvar" geral): quem
 * fecha o modal no meio não perde o que escreveu.
 */
const board = useBoardStore()

const card = computed(() => board.aberto)

const TIPOS = ['Novo recurso', 'Suporte', 'Bug', 'Outro']
const PRIOS = ['baixa', 'media', 'alta'] as const
const CORES = ['#25D366', '#ffb443', '#ff9f43', '#ff6b6b', '#a78bfa', '#53bdeb', '#4fd1c5', '#f472b6', '#8696a0']

const titulo = ref('')
const descricao = ref('')
const cliente = ref('')
const prazo = ref('')
const novoItem = ref('')
const novoComentario = ref('')
const gerenciandoEtiquetas = ref(false)
const arquivo = ref<HTMLInputElement | null>(null)
const enviando = ref(false)

/**
 * Data para o <input type=date>, no fuso de quem está olhando. `toISOString()` não
 * serve: o prazo é gravado às 23:59 e em UTC isso já é o dia seguinte — o campo
 * mostrava sempre um dia a mais.
 */
function paraInput(iso: string | null) {
  if (!iso) return ''
  const d = new Date(iso)
  if (Number.isNaN(d.getTime())) return ''
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

// Sempre que o modal troca de cartão, os campos recomeçam do que veio do servidor.
watch(() => card.value?.id, () => {
  titulo.value = card.value?.title ?? ''
  descricao.value = card.value?.description ?? ''
  cliente.value = card.value?.client ?? ''
  prazo.value = paraInput(card.value?.due_at ?? null)
}, { immediate: true })

const progresso = computed(() => {
  const total = card.value?.checklist?.length ?? 0
  const feitos = card.value?.checklist?.filter(i => i.done).length ?? 0
  return { total, feitos, pct: total ? Math.round((feitos / total) * 100) : 0 }
})

function salvar(campo: string, valor: any) {
  if (!card.value) return
  board.atualizarCartao(card.value.id, { [campo]: valor })
}

function salvarTitulo() {
  const v = titulo.value.trim()
  if (!card.value || !v || v === card.value.title) { titulo.value = card.value?.title ?? ''; return }
  salvar('title', v)
}

function salvarPrazo() {
  // <input type=date> devolve só a data; o prazo é o fim do dia — cartão para "hoje"
  // não pode nascer atrasado às 9h da manhã.
  salvar('due_at', prazo.value ? `${prazo.value} 23:59:00` : null)
}

async function anexar(e: Event) {
  const input = e.target as HTMLInputElement
  const file = input.files?.[0]
  input.value = ''
  if (!file) return
  enviando.value = true
  try { await board.anexar(file) }
  finally { enviando.value = false }
}

function arquivarCartao() {
  if (card.value && confirm('Arquivar este cartão? Ele sai do quadro e fica no Arquivo.')) board.arquivarCartao(card.value.id)
}
function excluirCartao() {
  if (card.value && confirm('Excluir este cartão de vez? Isso não tem volta.')) board.apagarCartao(card.value.id)
}

function iniciais(nome: string) {
  const p = nome.trim().split(/\s+/).filter(Boolean)
  return p.length ? (p[0][0] + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() : '?'
}
function corDe(id: number) {
  return ['#53bdeb', '#25D366', '#ffb443', '#a78bfa', '#ff6b6b', '#4fd1c5'][id % 6]
}
function tamanho(bytes: number) {
  return bytes > 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`
}
function quando(iso: string | null) {
  if (!iso) return ''
  const d = new Date(iso)
  const min = Math.round((Date.now() - d.getTime()) / 60000)
  if (min < 1) return 'agora'
  if (min < 60) return `há ${min} min`
  if (min < 1440) return `há ${Math.round(min / 60)} h`
  return d.toLocaleDateString('pt-BR', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' })
}

const rotulo = 'font-size:12px;font-weight:700;color:var(--c-text-secondary);text-transform:uppercase;letter-spacing:.4px;'
const campo = 'background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:10px;padding:9px 11px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;width:100%;'
</script>

<template>
  <div v-if="card" class="overlay r-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:60;padding:20px;" @click.self="board.fecharCartao()">
    <div class="r-sheet" style="width:880px;max-width:100%;max-height:92vh;overflow-y:auto;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px;">
      <!-- Cabeçalho -->
      <div style="display:flex;align-items:flex-start;gap:12px;">
        <div style="flex:1;min-width:0;">
          <textarea
            v-model="titulo" rows="1"
            style="width:100%;background:transparent;border:none;color:var(--c-text);font-family:inherit;font-size:19px;font-weight:800;outline:none;resize:none;line-height:1.3;"
            @blur="salvarTitulo" @keydown.enter.prevent="salvarTitulo"
          />
          <div style="display:flex;align-items:center;gap:8px;margin-top:4px;flex-wrap:wrap;">
            <span style="font-size:12.5px;color:var(--c-text-muted);">na lista</span>
            <select
              :value="card.task_list_id ?? ''" style="background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:5px 8px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;"
              @change="board.moverCartao(card.id, Number(($event.target as HTMLSelectElement).value), 0)"
            >
              <option v-for="l in board.lists" :key="l.id" :value="l.id">{{ l.name }}</option>
            </select>
            <span v-if="card.conversation_id" style="font-size:11.5px;color:var(--c-text-faint);">· veio de uma conversa</span>
          </div>
        </div>
        <button class="ghost r-tap" style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:17px;line-height:1;padding:7px 11px;border-radius:9px;cursor:pointer;" @click="board.fecharCartao()">✕</button>
      </div>

      <div class="r-sm-stack" style="display:flex;gap:22px;margin-top:20px;align-items:flex-start;">
        <!-- Coluna principal -->
        <div style="flex:1;min-width:0;display:flex;flex-direction:column;gap:22px;">
          <div>
            <div :style="rotulo">Descrição</div>
            <textarea
              v-model="descricao" rows="4" placeholder="Do que se trata? O que precisa acontecer para fechar?"
              :style="campo + 'margin-top:8px;resize:vertical;line-height:1.55;'"
              @blur="salvar('description', descricao)"
            />
          </div>

          <!-- Checklist -->
          <div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
              <div :style="rotulo">Checklist</div>
              <span v-if="progresso.total" style="font-size:12px;color:var(--c-text-muted);">{{ progresso.feitos }}/{{ progresso.total }}</span>
            </div>
            <div v-if="progresso.total" style="height:5px;border-radius:99px;background:var(--c-surface-2);margin-top:9px;overflow:hidden;">
              <div :style="{ width: `${progresso.pct}%`, height: '100%', background: progresso.pct === 100 ? '#25D366' : 'var(--accent)', transition: 'width .2s' }" />
            </div>
            <div style="display:flex;flex-direction:column;gap:2px;margin-top:9px;">
              <div v-for="item in (card.checklist || [])" :key="item.id" class="citem" style="display:flex;align-items:flex-start;gap:9px;padding:6px 7px;border-radius:9px;">
                <button class="r-tap" :style="{ marginTop: '1px', width: '17px', height: '17px', flexShrink: 0, borderRadius: '5px', cursor: 'pointer', background: item.done ? 'var(--accent)' : 'transparent', border: item.done ? 'none' : '1.6px solid var(--c-surface-3)', display: 'flex', alignItems: 'center', justifyContent: 'center' }" @click="board.alternarItemChecklist(item.id)">
                  <svg v-if="item.done" width="11" height="11" viewBox="0 0 24 24" fill="none" stroke="var(--accent-ink)" stroke-width="3.4"><path d="m6 12.4 4 4L18 7.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
                </button>
                <input
                  :value="item.text"
                  :style="{ flex: 1, minWidth: 0, background: 'transparent', border: 'none', color: item.done ? 'var(--c-text-muted)' : 'var(--c-text)', fontFamily: 'inherit', fontSize: '13.5px', outline: 'none', textDecoration: item.done ? 'line-through' : 'none' }"
                  @blur="board.renomearItemChecklist(item.id, ($event.target as HTMLInputElement).value)"
                  @keyup.enter="($event.target as HTMLInputElement).blur()"
                >
                <button class="del r-tap r-touch-show" title="Remover" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;padding:0 2px;" @click="board.removerItemChecklist(item.id)">✕</button>
              </div>
            </div>
            <input
              v-model="novoItem" placeholder="Adicionar item…" :style="campo + 'margin-top:8px;font-size:13px;'"
              @keyup.enter="board.addItemChecklist(novoItem); novoItem = ''"
            >
          </div>

          <!-- Anexos -->
          <div>
            <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
              <div :style="rotulo">Anexos</div>
              <button class="link r-tap" style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;" :disabled="enviando" @click="arquivo?.click()">
                {{ enviando ? 'Enviando…' : 'Anexar arquivo' }}
              </button>
              <input ref="arquivo" type="file" style="display:none;" @change="anexar">
            </div>
            <div v-if="card.attachments?.length" style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
              <div v-for="a in (card.attachments || [])" :key="a.id" style="display:flex;align-items:center;gap:11px;background:var(--c-surface-1);border-radius:11px;padding:9px 11px;">
                <a :href="a.url" target="_blank" rel="noopener" style="flex-shrink:0;">
                  <img v-if="a.is_image" :src="a.url" :alt="a.name" style="width:52px;height:40px;object-fit:cover;border-radius:7px;display:block;">
                  <span v-else style="width:52px;height:40px;border-radius:7px;background:var(--c-surface-2);display:flex;align-items:center;justify-content:center;color:var(--c-text-muted);">
                    <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 3v5h5M7 3h8l5 5v12a1 1 0 0 1-1 1H7a1 1 0 0 1-1-1V4a1 1 0 0 1 1-1Z" stroke-linejoin="round" /></svg>
                  </span>
                </a>
                <div style="flex:1;min-width:0;">
                  <a :href="a.url" target="_blank" rel="noopener" class="r-ellipsis" style="display:block;font-size:13px;font-weight:600;color:var(--c-text);text-decoration:none;">{{ a.name }}</a>
                  <div style="font-size:11px;color:var(--c-text-muted);margin-top:2px;">{{ tamanho(a.size) }} · {{ quando(a.created_at) }}</div>
                </div>
                <button class="del r-tap" title="Remover anexo" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;" @click="board.removerAnexo(a.id)">✕</button>
              </div>
            </div>
            <div v-else style="font-size:12.5px;color:var(--c-text-faint);margin-top:8px;">Nenhum arquivo por aqui.</div>
          </div>

          <!-- Comentários -->
          <div>
            <div :style="rotulo">Comentários</div>
            <textarea
              v-model="novoComentario" rows="2" placeholder="Escreva um comentário…"
              :style="campo + 'margin-top:8px;resize:vertical;'"
            />
            <div v-if="novoComentario.trim()" style="margin-top:8px;">
              <button class="wabtn r-tap" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 15px;border-radius:9px;cursor:pointer;" @click="board.comentar(novoComentario); novoComentario = ''">Comentar</button>
            </div>
            <div style="display:flex;flex-direction:column;gap:11px;margin-top:14px;">
              <div v-for="c in (card.comments || [])" :key="c.id" style="display:flex;gap:10px;">
                <span :style="{ width: '28px', height: '28px', flexShrink: 0, borderRadius: '50%', background: corDe(c.user_id ?? 0), color: '#10231c', fontSize: '10.5px', fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center' }">{{ iniciais(c.author) }}</span>
                <div style="flex:1;min-width:0;">
                  <div style="display:flex;align-items:center;gap:8px;">
                    <span style="font-size:12.5px;font-weight:700;">{{ c.author }}</span>
                    <span style="font-size:11px;color:var(--c-text-faint);">{{ quando(c.created_at) }}</span>
                    <button class="del r-tap r-touch-show" title="Apagar" style="margin-left:auto;background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:12px;" @click="board.apagarComentario(c.id)">✕</button>
                  </div>
                  <div class="r-break" style="font-size:13.5px;line-height:1.5;margin-top:3px;white-space:pre-wrap;">{{ c.body }}</div>
                </div>
              </div>
            </div>
          </div>
        </div>

        <!-- Barra lateral -->
        <div class="r-sm-full" style="width:250px;flex-shrink:0;display:flex;flex-direction:column;gap:18px;">
          <div>
            <div style="display:flex;align-items:center;justify-content:space-between;">
              <div :style="rotulo">Etiquetas</div>
              <button class="link r-tap" style="background:none;border:none;color:var(--c-text-muted);font-family:inherit;font-size:12px;cursor:pointer;" @click="gerenciandoEtiquetas = !gerenciandoEtiquetas">
                {{ gerenciandoEtiquetas ? 'pronto' : 'gerenciar' }}
              </button>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:9px;">
              <button
                v-for="l in board.labels" :key="l.id" class="r-tap"
                :style="{ fontSize: '12px', fontWeight: 700, color: '#10231c', background: l.color, opacity: card.label_ids.includes(l.id) ? 1 : 0.42, padding: '5px 10px', borderRadius: '8px', border: 'none', cursor: 'pointer', fontFamily: 'inherit' }"
                @click="board.alternarEtiqueta(card.id, l.id)"
              >{{ l.name || '—' }}</button>
            </div>
            <div v-if="gerenciandoEtiquetas" style="margin-top:11px;display:flex;flex-direction:column;gap:7px;">
              <div v-for="l in board.labels" :key="l.id" style="display:flex;align-items:center;gap:7px;">
                <span :style="{ width: '15px', height: '15px', borderRadius: '5px', background: l.color, flexShrink: 0 }" />
                <input
                  :value="l.name" placeholder="sem nome"
                  style="flex:1;min-width:0;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:7px;padding:5px 7px;color:var(--c-text);font-family:inherit;font-size:12px;outline:none;"
                  @blur="board.atualizarEtiqueta(l.id, { name: ($event.target as HTMLInputElement).value })"
                >
                <button class="del r-tap" title="Apagar etiqueta" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;" @click="board.apagarEtiqueta(l.id)">✕</button>
              </div>
              <div style="display:flex;flex-wrap:wrap;gap:5px;margin-top:3px;">
                <span
                  v-for="c in CORES" :key="c" title="Criar etiqueta com esta cor"
                  :style="{ width: '19px', height: '19px', borderRadius: '6px', background: c, cursor: 'pointer' }"
                  @click="board.criarEtiqueta(c)"
                />
              </div>
            </div>
          </div>

          <div>
            <div :style="rotulo">Responsáveis</div>
            <div style="display:flex;flex-direction:column;gap:4px;margin-top:9px;">
              <button
                v-for="m in board.members" :key="m.id" class="pessoa r-tap"
                :style="{ display: 'flex', alignItems: 'center', gap: '9px', background: card.members.some(x => x.id === m.id) ? 'var(--c-surface-2)' : 'transparent', border: 'none', borderRadius: '9px', padding: '6px 8px', cursor: 'pointer', fontFamily: 'inherit', textAlign: 'left' }"
                @click="board.alternarResponsavel(card.id, m.id)"
              >
                <span :style="{ width: '25px', height: '25px', borderRadius: '50%', background: corDe(m.id), color: '#10231c', fontSize: '10px', fontWeight: 800, display: 'flex', alignItems: 'center', justifyContent: 'center', flexShrink: 0 }">{{ iniciais(m.name) }}</span>
                <span class="r-ellipsis" style="flex:1;min-width:0;font-size:13px;color:var(--c-text);">{{ m.name }}</span>
                <svg v-if="card.members.some(x => x.id === m.id)" width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="var(--accent)" stroke-width="3"><path d="m6 12.4 4 4L18 7.6" stroke-linecap="round" stroke-linejoin="round" /></svg>
              </button>
            </div>
          </div>

          <div>
            <div :style="rotulo">Prazo</div>
            <div style="display:flex;gap:7px;align-items:center;margin-top:9px;">
              <input v-model="prazo" type="date" :style="campo + 'color-scheme:dark;'" @change="salvarPrazo">
              <button v-if="prazo" class="ghost r-tap" title="Tirar o prazo" style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;padding:8px 10px;border-radius:9px;cursor:pointer;" @click="prazo = ''; salvarPrazo()">✕</button>
            </div>
            <div v-if="!prazo && card.due" style="font-size:11.5px;color:var(--c-text-faint);margin-top:6px;">Do formulário: “{{ card.due }}”</div>
          </div>

          <div>
            <div :style="rotulo">Prioridade</div>
            <div style="display:flex;gap:6px;margin-top:9px;">
              <button
                v-for="p in PRIOS" :key="p" class="r-tap"
                :style="{ flex: 1, fontSize: '12.5px', fontWeight: card.priority === p ? 700 : 600, color: card.priority === p ? usePrioMeta(p).color : 'var(--c-text-secondary)', background: card.priority === p ? `${usePrioMeta(p).color}22` : 'var(--c-surface-2)', border: card.priority === p ? `1px solid ${usePrioMeta(p).color}` : '1px solid transparent', padding: '7px 6px', borderRadius: '9px', cursor: 'pointer', fontFamily: 'inherit' }"
                @click="salvar('priority', p)"
              >{{ usePrioMeta(p).label }}</button>
            </div>
          </div>

          <div>
            <div :style="rotulo">Tipo</div>
            <div style="display:flex;flex-wrap:wrap;gap:6px;margin-top:9px;">
              <button
                v-for="t in TIPOS" :key="t" class="r-tap"
                :style="{ fontSize: '12px', fontWeight: card.type === t ? 700 : 600, color: card.type === t ? 'var(--accent-ink)' : 'var(--c-text-secondary)', background: card.type === t ? 'var(--accent)' : 'var(--c-surface-2)', padding: '6px 11px', borderRadius: '8px', cursor: 'pointer', border: 'none', fontFamily: 'inherit' }"
                @click="salvar('type', card.type === t ? null : t)"
              >{{ t }}</button>
            </div>
          </div>

          <div>
            <div :style="rotulo">Cliente</div>
            <input v-model="cliente" placeholder="Quem pediu" :style="campo + 'margin-top:9px;'" @blur="salvar('client', cliente)">
          </div>

          <div style="display:flex;flex-direction:column;gap:7px;border-top:1px solid var(--c-surface-2);padding-top:14px;">
            <button class="acao r-tap" @click="arquivarCartao">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 8h16v11a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V8ZM3 4h18v4H3zM10 12h4" stroke-linejoin="round" /></svg>
              Arquivar cartão
            </button>
            <button class="acao perigo r-tap" @click="excluirCartao">
              <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 7h16M9 7V5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1v2M6 7l1 13a1 1 0 0 0 1 1h8a1 1 0 0 0 1-1l1-13" stroke-linecap="round" stroke-linejoin="round" /></svg>
              Excluir de vez
            </button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.citem:hover { background: var(--c-surface-1); }
.citem .del { opacity: 0; transition: opacity .12s; }
.citem:hover .del { opacity: 1; }
.del:hover { color: var(--c-danger, #ff6b6b) !important; }
.link:hover { filter: brightness(1.2); }
.pessoa:hover { background: var(--c-surface-2) !important; }
.wabtn:hover { background: var(--accent-hi) !important; }
.ghost:hover { background: var(--c-surface-3) !important; }
.acao {
  display: flex;
  align-items: center;
  gap: 8px;
  background: var(--c-surface-2);
  border: none;
  color: var(--c-text-secondary);
  font-family: inherit;
  font-size: 12.5px;
  font-weight: 600;
  padding: 9px 12px;
  border-radius: 10px;
  cursor: pointer;
}
.acao:hover { background: var(--c-surface-3); color: var(--c-text); }
.acao.perigo { color: var(--c-danger-soft, #ff9a9a); }
.acao.perigo:hover { background: rgba(255, 107, 107, .14); }
</style>
