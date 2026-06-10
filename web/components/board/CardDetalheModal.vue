<script setup lang="ts">
import type { Ticket } from '~/stores/board'
import { LABEL_STATUS } from '~/stores/board'

defineProps<{ ticket: Ticket }>()
defineEmits<{ fechar: [] }>()

const labelPrioridade: Record<string, string> = {
  BAIXA: 'Baixa',
  MEDIA: 'Média',
  ALTA: 'Alta',
  CRITICA: 'Crítica',
}

const labelFrequencia: Record<string, string> = {
  SEMPRE: 'Sempre acontece',
  AS_VEZES: 'Às vezes acontece',
  UMA_VEZ: 'Aconteceu apenas uma vez',
}

function formatarDataHora(iso: string | null) {
  return iso ? new Date(iso).toLocaleString('pt-BR') : '—'
}

function formatarData(iso: string | null) {
  return iso ? new Date(iso).toLocaleDateString('pt-BR') : '—'
}

function formatarTamanho(bytes: number) {
  if (bytes >= 1048576) return `${(bytes / 1048576).toFixed(1)} MB`
  if (bytes >= 1024) return `${(bytes / 1024).toFixed(0)} KB`
  return `${bytes} B`
}
</script>

<template>
  <Teleport to="body">
    <div class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/50 p-4 sm:p-8" @click.self="$emit('fechar')">
      <div class="w-full max-w-2xl rounded-2xl bg-white shadow-xl">
        <!-- Cabeçalho -->
        <header class="flex items-start justify-between gap-4 border-b border-slate-200 p-5">
          <div>
            <div class="mb-1 flex items-center gap-2">
              <span class="font-mono text-sm font-bold text-indigo-600">{{ ticket.codigo }}</span>
              <span class="rounded-full bg-slate-100 px-2.5 py-0.5 text-xs font-semibold text-slate-600">
                {{ ticket.tipo === 'BUG' ? '🐛 Bug' : '✨ Nova Funcionalidade' }}
              </span>
              <span
                class="rounded-full px-2.5 py-0.5 text-xs font-semibold"
                :class="ticket.prioridade === 'CRITICA' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-600'"
              >
                {{ labelPrioridade[ticket.prioridade] }}
              </span>
            </div>
            <h2 class="text-xl font-bold text-slate-900">{{ ticket.titulo }}</h2>
            <p class="mt-0.5 text-xs text-slate-400">Status: {{ LABEL_STATUS[ticket.status] }}</p>
          </div>
          <button class="rounded-lg p-1.5 text-slate-400 transition hover:bg-slate-100 hover:text-slate-600" @click="$emit('fechar')">✕</button>
        </header>

        <div class="space-y-6 p-5">
          <!-- Geral -->
          <section>
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Informações Gerais</h3>
            <dl class="space-y-3 text-sm">
              <div>
                <dt class="font-medium text-slate-500">Descrição Detalhada</dt>
                <dd class="whitespace-pre-line text-slate-800">{{ ticket.descricao }}</dd>
              </div>
              <div class="grid gap-3 sm:grid-cols-2">
                <div>
                  <dt class="font-medium text-slate-500">Módulo/Sistema Afetado</dt>
                  <dd class="text-slate-800">{{ ticket.modulo }}</dd>
                </div>
                <div>
                  <dt class="font-medium text-slate-500">Impacto no Negócio</dt>
                  <dd class="whitespace-pre-line text-slate-800">{{ ticket.impacto_negocio || '—' }}</dd>
                </div>
              </div>
            </dl>
          </section>

          <!-- Bug -->
          <section v-if="ticket.tipo === 'BUG'">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">🐛 Detalhes do Bug</h3>
            <dl class="space-y-3 text-sm">
              <div>
                <dt class="font-medium text-slate-500">Comportamento Atual</dt>
                <dd class="whitespace-pre-line text-slate-800">{{ ticket.comportamento_atual || '—' }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Comportamento Esperado</dt>
                <dd class="whitespace-pre-line text-slate-800">{{ ticket.comportamento_esperado || '—' }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Passos para Reproduzir</dt>
                <dd class="whitespace-pre-line rounded-lg bg-slate-50 p-2 font-mono text-xs text-slate-800">{{ ticket.passos_reproducao || '—' }}</dd>
              </div>
              <div class="grid gap-3 sm:grid-cols-2">
                <div>
                  <dt class="font-medium text-slate-500">URL</dt>
                  <dd class="break-all text-slate-800">{{ ticket.ambiente_url || '—' }}</dd>
                </div>
                <div>
                  <dt class="font-medium text-slate-500">Navegador</dt>
                  <dd class="text-slate-800">{{ ticket.navegador || '—' }}</dd>
                </div>
                <div>
                  <dt class="font-medium text-slate-500">Dispositivo</dt>
                  <dd class="text-slate-800">{{ ticket.dispositivo || '—' }}</dd>
                </div>
                <div>
                  <dt class="font-medium text-slate-500">Data/Hora da Ocorrência</dt>
                  <dd class="text-slate-800">{{ formatarDataHora(ticket.data_hora_ocorrencia) }}</dd>
                </div>
                <div>
                  <dt class="font-medium text-slate-500">Frequência</dt>
                  <dd class="text-slate-800">{{ ticket.frequencia ? labelFrequencia[ticket.frequencia] : '—' }}</dd>
                </div>
              </div>
            </dl>
          </section>

          <!-- Feature -->
          <section v-if="ticket.tipo === 'FEATURE'">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">✨ Detalhes da Funcionalidade</h3>
            <dl class="space-y-3 text-sm">
              <div>
                <dt class="font-medium text-slate-500">Objetivo da Funcionalidade</dt>
                <dd class="whitespace-pre-line text-slate-800">{{ ticket.objetivo || '—' }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Regras de Negócio</dt>
                <dd class="whitespace-pre-line text-slate-800">{{ ticket.regras_negocio || '—' }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Fluxo Desejado</dt>
                <dd class="whitespace-pre-line text-slate-800">{{ ticket.fluxo_desejado || '—' }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Critérios de Aceitação</dt>
                <dd class="whitespace-pre-line text-slate-800">{{ ticket.criterios_aceitacao || '—' }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Prazo Desejado</dt>
                <dd class="text-slate-800">{{ formatarData(ticket.prazo_desejado) }}</dd>
              </div>
            </dl>
          </section>

          <!-- Solicitante -->
          <section>
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Solicitante</h3>
            <dl class="grid gap-3 text-sm sm:grid-cols-2">
              <div>
                <dt class="font-medium text-slate-500">Nome</dt>
                <dd class="text-slate-800">{{ ticket.solicitante_nome }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Empresa</dt>
                <dd class="text-slate-800">{{ ticket.solicitante_empresa }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">E-mail</dt>
                <dd class="text-slate-800">{{ ticket.solicitante_email }}</dd>
              </div>
              <div>
                <dt class="font-medium text-slate-500">Data da Solicitação</dt>
                <dd class="text-slate-800">{{ formatarDataHora(ticket.data_solicitacao) }}</dd>
              </div>
            </dl>
          </section>

          <!-- Anexos -->
          <section v-if="ticket.anexos?.length">
            <h3 class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-400">Anexos</h3>
            <ul class="space-y-1.5">
              <li v-for="anexo in ticket.anexos" :key="anexo.id">
                <a
                  :href="anexo.url"
                  target="_blank"
                  class="flex items-center justify-between rounded-lg border border-slate-200 px-3 py-2 text-sm text-indigo-600 transition hover:bg-indigo-50"
                >
                  <span class="truncate">📎 {{ anexo.nome_arquivo }}</span>
                  <span class="ml-2 shrink-0 text-xs text-slate-400">{{ formatarTamanho(anexo.tamanho) }}</span>
                </a>
              </li>
            </ul>
          </section>
        </div>
      </div>
    </div>
  </Teleport>
</template>
