<script setup lang="ts">
import { z } from 'zod'

const { apiFetch, csrf } = useApi()

type Tipo = 'BUG' | 'FEATURE'

const tipo = ref<Tipo | null>(null)
const enviando = ref(false)
const codigoCriado = ref<string | null>(null)
const erroGeral = ref<string | null>(null)
const erros = ref<Record<string, string>>({})
const anexos = ref<File[]>([])

const form = reactive({
  titulo: '',
  descricao: '',
  modulo: '',
  prioridade: '',
  impacto_negocio: '',
  // Bug
  comportamento_atual: '',
  comportamento_esperado: '',
  passos_reproducao: '',
  ambiente_url: '',
  navegador: '',
  dispositivo: '',
  data_hora_ocorrencia: '',
  frequencia: '',
  // Feature
  objetivo: '',
  regras_negocio: '',
  fluxo_desejado: '',
  criterios_aceitacao: '',
  prazo_desejado: '',
  // Solicitante
  solicitante_nome: '',
  solicitante_empresa: '',
  solicitante_email: '',
})

const prioridades = [
  { valor: 'BAIXA', label: 'Baixa' },
  { valor: 'MEDIA', label: 'Média' },
  { valor: 'ALTA', label: 'Alta' },
  { valor: 'CRITICA', label: 'Crítica' },
]

const obrigatorio = (msg: string) => z.string().trim().min(1, msg)

const baseSchema = z.object({
  titulo: obrigatorio('Informe o título da solicitação'),
  descricao: obrigatorio('Descreva a necessidade ou problema'),
  prioridade: obrigatorio('Selecione a prioridade'),
  solicitante_nome: obrigatorio('Informe seu nome'),
  solicitante_empresa: obrigatorio('Informe sua empresa'),
  solicitante_email: z.string().trim().email('Informe um e-mail válido'),
})

const bugSchema = baseSchema.extend({
  comportamento_atual: obrigatorio('Descreva o comportamento atual'),
  comportamento_esperado: obrigatorio('Descreva o comportamento esperado'),
})

const featureSchema = baseSchema.extend({
  modulo: obrigatorio('Informe o módulo/sistema afetado'),
  objetivo: obrigatorio('Descreva o objetivo da funcionalidade'),
  regras_negocio: obrigatorio('Descreva as regras de negócio'),
  fluxo_desejado: obrigatorio('Descreva o fluxo desejado'),
  criterios_aceitacao: obrigatorio('Informe os critérios de aceitação'),
})

function selecionarAnexos(event: Event) {
  const input = event.target as HTMLInputElement
  anexos.value = Array.from(input.files ?? []).slice(0, 5)
}

function removerAnexo(indice: number) {
  anexos.value = anexos.value.filter((_, i) => i !== indice)
}

async function enviar() {
  if (!tipo.value) {
    erroGeral.value = 'Selecione o tipo da solicitação.'
    return
  }

  erros.value = {}
  erroGeral.value = null

  const schema = tipo.value === 'BUG' ? bugSchema : featureSchema
  const resultado = schema.safeParse(form)

  if (!resultado.success) {
    for (const issue of resultado.error.issues) {
      const campo = String(issue.path[0])
      if (!erros.value[campo]) erros.value[campo] = issue.message
    }
    erroGeral.value = 'Revise os campos destacados antes de enviar.'
    return
  }

  enviando.value = true
  try {
    const dados = new FormData()
    dados.append('tipo', tipo.value)
    for (const [campo, valor] of Object.entries(form)) {
      if (String(valor).trim() !== '') dados.append(campo, String(valor))
    }
    for (const arquivo of anexos.value) {
      dados.append('anexos[]', arquivo)
    }

    await csrf()
    const resposta = await apiFetch<{ data: { codigo: string } }>('/tickets', {
      method: 'POST',
      body: dados,
    })

    codigoCriado.value = resposta.data.codigo
  }
  catch (e: any) {
    if (e?.status === 422 && e?.data?.errors) {
      for (const [campo, mensagens] of Object.entries(e.data.errors as Record<string, string[]>)) {
        erros.value[campo] = mensagens[0]
      }
      erroGeral.value = 'Revise os campos destacados antes de enviar.'
    }
    else {
      erroGeral.value = 'Não foi possível enviar sua solicitação. Tente novamente em instantes.'
    }
  }
  finally {
    enviando.value = false
  }
}
</script>

<template>
  <main class="mx-auto max-w-3xl px-4 py-10">
    <!-- Sucesso -->
    <div v-if="codigoCriado" class="rounded-2xl border border-emerald-200 bg-white p-10 text-center shadow-sm">
      <div class="mx-auto mb-4 flex h-14 w-14 items-center justify-center rounded-full bg-emerald-100 text-3xl">✓</div>
      <h1 class="text-2xl font-bold text-slate-900">Solicitação registrada!</h1>
      <p class="mt-2 text-slate-600">
        Sua demanda foi criada com o código
        <span class="font-mono font-bold text-indigo-600">{{ codigoCriado }}</span>.
        Guarde esse código para acompanhamento.
      </p>
      <button
        class="mt-6 rounded-lg bg-indigo-600 px-5 py-2.5 text-sm font-semibold text-white transition hover:bg-indigo-700"
        @click="codigoCriado = null"
      >
        Abrir nova solicitação
      </button>
    </div>

    <!-- Formulário -->
    <form v-else novalidate @submit.prevent="enviar">
      <header class="mb-8 text-center">
        <h1 class="text-3xl font-bold text-slate-900">Abertura de Ticket</h1>
        <p class="mt-1 text-slate-500">Descreva sua demanda e acompanharemos por aqui.</p>
      </header>

      <!-- Tipo -->
      <section class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <h2 class="mb-4 text-lg font-semibold text-slate-900">Tipo da Solicitação <span class="text-red-500">*</span></h2>
        <div class="grid gap-4 sm:grid-cols-2">
          <button
            type="button"
            class="rounded-xl border-2 p-4 text-left transition"
            :class="tipo === 'BUG' ? 'border-red-400 bg-red-50' : 'border-slate-200 hover:border-slate-300'"
            @click="tipo = 'BUG'"
          >
            <span class="text-2xl">🐛</span>
            <p class="mt-1 font-semibold text-slate-900">Bug — Correção de Erro</p>
            <p class="text-sm text-slate-500">Algo não está funcionando como deveria.</p>
          </button>
          <button
            type="button"
            class="rounded-xl border-2 p-4 text-left transition"
            :class="tipo === 'FEATURE' ? 'border-indigo-400 bg-indigo-50' : 'border-slate-200 hover:border-slate-300'"
            @click="tipo = 'FEATURE'"
          >
            <span class="text-2xl">✨</span>
            <p class="mt-1 font-semibold text-slate-900">Nova Funcionalidade</p>
            <p class="text-sm text-slate-500">Preciso de algo novo no sistema.</p>
          </button>
        </div>
      </section>

      <template v-if="tipo">
        <!-- Informações gerais -->
        <section class="mb-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-lg font-semibold text-slate-900">Informações Gerais</h2>
          <FormCampoTexto v-model="form.titulo" label="Título da Solicitação" dica="Resumo curto da demanda" obrigatorio :erro="erros.titulo" />
          <FormCampoArea v-model="form.descricao" label="Descrição Detalhada" dica="Explique a necessidade ou problema encontrado" obrigatorio :linhas="4" :erro="erros.descricao" />
          <FormCampoTexto v-if="tipo === 'FEATURE'" v-model="form.modulo" label="Módulo/Sistema Afetado" dica="Ex.: Login, Pagamentos, Relatórios, API..." obrigatorio :erro="erros.modulo" />

          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Prioridade <span class="text-red-500">*</span></label>
            <div class="flex flex-wrap gap-2">
              <button
                v-for="p in prioridades"
                :key="p.valor"
                type="button"
                class="rounded-full border px-4 py-1.5 text-sm font-medium transition"
                :class="form.prioridade === p.valor
                  ? 'border-indigo-500 bg-indigo-600 text-white'
                  : 'border-slate-300 bg-white text-slate-600 hover:border-slate-400'"
                @click="form.prioridade = p.valor"
              >
                {{ p.label }}
              </button>
            </div>
            <p v-if="erros.prioridade" class="mt-1 text-xs text-red-600">{{ erros.prioridade }}</p>
          </div>

          <FormCampoArea v-if="tipo === 'FEATURE'" v-model="form.impacto_negocio" label="Impacto no Negócio" dica="Como essa solicitação afeta a operação da empresa?" :erro="erros.impacto_negocio" />

          <div>
            <label class="mb-1 block text-sm font-medium text-slate-700">Anexos</label>
            <input
              type="file"
              multiple
              class="block w-full text-sm text-slate-500 file:mr-3 file:rounded-lg file:border-0 file:bg-indigo-50 file:px-4 file:py-2 file:text-sm file:font-semibold file:text-indigo-700 hover:file:bg-indigo-100"
              @change="selecionarAnexos"
            >
            <p class="mt-1 text-xs text-slate-400">Prints, vídeos ou documentos relevantes (até 5 arquivos, 10MB cada).</p>
            <ul v-if="anexos.length" class="mt-2 space-y-1">
              <li v-for="(arquivo, i) in anexos" :key="i" class="flex items-center justify-between rounded-lg bg-slate-50 px-3 py-1.5 text-sm">
                <span class="truncate">📎 {{ arquivo.name }}</span>
                <button type="button" class="ml-2 text-red-500 hover:text-red-700" @click="removerAnexo(i)">remover</button>
              </li>
            </ul>
          </div>
        </section>

        <!-- Campos de BUG -->
        <section v-if="tipo === 'BUG'" class="mb-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-lg font-semibold text-slate-900">🐛 Detalhes do Bug</h2>
          <FormCampoArea v-model="form.comportamento_atual" label="Comportamento Atual" dica="O que está acontecendo atualmente?" obrigatorio :erro="erros.comportamento_atual" />
          <FormCampoArea v-model="form.comportamento_esperado" label="Comportamento Esperado" dica="O que deveria acontecer?" obrigatorio :erro="erros.comportamento_esperado" />
          <FormCampoTexto v-model="form.ambiente_url" label="URL" dica="https://..." :erro="erros.ambiente_url" />
        </section>

        <!-- Campos de FEATURE -->
        <section v-if="tipo === 'FEATURE'" class="mb-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-lg font-semibold text-slate-900">✨ Detalhes da Funcionalidade</h2>
          <FormCampoArea v-model="form.objetivo" label="Objetivo da Funcionalidade" dica="Qual problema será resolvido ou qual benefício será gerado?" obrigatorio :erro="erros.objetivo" />
          <FormCampoArea v-model="form.regras_negocio" label="Regras de Negócio" dica="Descreva as regras necessárias para a funcionalidade" obrigatorio :erro="erros.regras_negocio" />
          <FormCampoArea v-model="form.fluxo_desejado" label="Fluxo Desejado" dica="Explique como o usuário utilizará a nova funcionalidade" obrigatorio :erro="erros.fluxo_desejado" />
          <FormCampoArea v-model="form.criterios_aceitacao" label="Critérios de Aceitação" dica="O que precisa estar funcionando para considerar a entrega concluída?" obrigatorio :erro="erros.criterios_aceitacao" />
          <FormCampoTexto v-model="form.prazo_desejado" label="Prazo Desejado" type="date" :erro="erros.prazo_desejado" />
        </section>

        <!-- Solicitante -->
        <section class="mb-6 space-y-4 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
          <h2 class="text-lg font-semibold text-slate-900">Solicitante</h2>
          <div class="grid gap-4 sm:grid-cols-2">
            <FormCampoTexto v-model="form.solicitante_nome" label="Nome" obrigatorio :erro="erros.solicitante_nome" />
            <FormCampoTexto v-model="form.solicitante_empresa" label="Empresa" obrigatorio :erro="erros.solicitante_empresa" />
          </div>
          <FormCampoTexto v-model="form.solicitante_email" label="E-mail" type="email" obrigatorio :erro="erros.solicitante_email" />
          <p class="text-xs text-slate-400">A data da solicitação é registrada automaticamente.</p>
        </section>

        <p v-if="erroGeral" class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700">{{ erroGeral }}</p>

        <button
          type="submit"
          :disabled="enviando"
          class="w-full rounded-xl bg-indigo-600 py-3 text-base font-semibold text-white shadow transition hover:bg-indigo-700 disabled:opacity-60"
        >
          {{ enviando ? 'Enviando...' : 'Enviar Solicitação' }}
        </button>
      </template>
    </form>
  </main>
</template>
