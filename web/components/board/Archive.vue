<script setup lang="ts">
import { prazoMeta, useBoardStore } from '~/stores/board'

/**
 * Arquivo do quadro: o que saiu de vista sem ser apagado. Cartão volta para a lista
 * de onde saiu (ou para a primeira, se a lista também foi arquivada); lista volta com
 * os cartões que foram arquivados junto com ela.
 */
const board = useBoardStore()

function excluir(id: number, titulo: string) {
  if (confirm(`Excluir "${titulo}" de vez? Isso não tem volta.`)) board.apagarCartao(id)
}
</script>

<template>
  <div v-if="board.arquivo.aberto" class="overlay r-overlay" style="position:fixed;inset:0;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;z-index:55;padding:20px;" @click.self="board.arquivo.aberto = false">
    <div class="r-sheet" style="width:560px;max-width:100%;max-height:86vh;overflow-y:auto;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:22px;">
      <div style="display:flex;align-items:center;justify-content:space-between;gap:10px;">
        <div>
          <div style="font-size:17px;font-weight:800;">Arquivo</div>
          <div style="font-size:12.5px;color:var(--c-text-muted);margin-top:3px;">O que saiu do quadro sem ser apagado.</div>
        </div>
        <button class="ghost r-tap" style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:17px;line-height:1;padding:7px 11px;border-radius:9px;cursor:pointer;" @click="board.arquivo.aberto = false">✕</button>
      </div>

      <div v-if="board.arquivo.carregando" style="font-size:13px;color:var(--c-text-muted);margin-top:20px;">Carregando…</div>

      <template v-else>
        <div v-if="board.arquivo.lists.length" style="margin-top:20px;">
          <div style="font-size:12px;font-weight:700;color:var(--c-text-secondary);text-transform:uppercase;letter-spacing:.4px;">Listas</div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
            <div v-for="l in board.arquivo.lists" :key="l.id" style="display:flex;align-items:center;gap:10px;background:var(--c-surface-1);border-radius:11px;padding:11px 13px;">
              <span :style="{ width: '9px', height: '9px', borderRadius: '50%', background: l.color, flexShrink: 0 }" />
              <span class="r-ellipsis" style="flex:1;min-width:0;font-size:13.5px;font-weight:600;">{{ l.name }}</span>
              <button class="link r-tap" style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:12.5px;font-weight:700;cursor:pointer;" @click="board.restaurarLista(l.id)">restaurar</button>
            </div>
          </div>
        </div>

        <div style="margin-top:20px;">
          <div style="font-size:12px;font-weight:700;color:var(--c-text-secondary);text-transform:uppercase;letter-spacing:.4px;">Cartões</div>
          <div v-if="!board.arquivo.cards.length" style="font-size:13px;color:var(--c-text-faint);margin-top:10px;">Nada arquivado por enquanto.</div>
          <div style="display:flex;flex-direction:column;gap:8px;margin-top:10px;">
            <div v-for="c in board.arquivo.cards" :key="c.id" style="background:var(--c-surface-1);border-radius:11px;padding:11px 13px;">
              <div style="display:flex;align-items:flex-start;gap:10px;">
                <div style="flex:1;min-width:0;">
                  <div class="r-break" style="font-size:13.5px;font-weight:650;line-height:1.35;">{{ c.title }}</div>
                  <div style="display:flex;align-items:center;gap:8px;margin-top:5px;flex-wrap:wrap;">
                    <span v-if="c.client" style="font-size:11.5px;color:var(--c-text-muted);">{{ c.client }}</span>
                    <span v-if="prazoMeta(c)" style="font-size:11px;color:var(--c-text-faint);">{{ prazoMeta(c)?.label }}</span>
                  </div>
                </div>
                <div style="display:flex;gap:10px;flex-shrink:0;">
                  <button class="link r-tap" style="background:none;border:none;color:var(--accent);font-family:inherit;font-size:12.5px;font-weight:700;cursor:pointer;" @click="board.restaurarCartao(c.id)">restaurar</button>
                  <button class="link r-tap" style="background:none;border:none;color:var(--c-danger-soft,#ff9a9a);font-family:inherit;font-size:12.5px;font-weight:600;cursor:pointer;" @click="excluir(c.id, c.title)">excluir</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>

<style scoped>
.ghost:hover { background: var(--c-surface-3) !important; }
.link:hover { filter: brightness(1.2); }
</style>
