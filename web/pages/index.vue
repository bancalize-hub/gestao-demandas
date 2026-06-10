<script setup lang="ts">
// Página inicial provisória — na Fase 2 o destino padrão passa a ser o /board.
</script>

<template>
  <main class="mx-auto flex min-h-screen max-w-xl flex-col items-center justify-center gap-6 px-4 text-center">
    <h1 class="text-3xl font-bold text-slate-900">Gestão de Demandas</h1>
    <p class="text-slate-500">Sistema de abertura e acompanhamento de solicitações.</p>
    <div class="flex gap-4">
      <NuxtLink to="/solicitar" class="rounded-xl bg-indigo-600 px-6 py-3 font-semibold text-white shadow transition hover:bg-indigo-700">
        Abrir Solicitação
      </NuxtLink>
      <NuxtLink to="/board" class="rounded-xl border border-slate-300 bg-white px-6 py-3 font-semibold text-slate-700 shadow-sm transition hover:border-slate-400">
        Abrir Board
      </NuxtLink>
    </div>
  </main>
</template>
