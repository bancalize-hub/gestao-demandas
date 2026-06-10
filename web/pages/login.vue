<script setup lang="ts">
const { login } = useAuth()

const email = ref('')
const senha = ref('')
const erro = ref<string | null>(null)
const entrando = ref(false)

async function entrar() {
  erro.value = null
  entrando.value = true
  try {
    await login(email.value, senha.value)
    navigateTo('/board')
  }
  catch (e: any) {
    erro.value = e?.status === 422
      ? 'E-mail ou senha inválidos.'
      : 'Não foi possível entrar. A API está rodando?'
  }
  finally {
    entrando.value = false
  }
}
</script>

<template>
  <main class="flex min-h-screen items-center justify-center px-4">
    <form class="w-full max-w-sm rounded-2xl border border-slate-200 bg-white p-8 shadow-sm" @submit.prevent="entrar">
      <h1 class="mb-1 text-2xl font-bold text-slate-900">Entrar</h1>
      <p class="mb-6 text-sm text-slate-500">Acesso ao board de demandas.</p>

      <div class="space-y-4">
        <FormCampoTexto v-model="email" label="E-mail" type="email" obrigatorio />
        <FormCampoTexto v-model="senha" label="Senha" type="password" obrigatorio />
      </div>

      <p v-if="erro" class="mt-4 rounded-lg bg-red-50 px-3 py-2 text-sm text-red-700">{{ erro }}</p>

      <button
        type="submit"
        :disabled="entrando"
        class="mt-6 w-full rounded-xl bg-indigo-600 py-2.5 font-semibold text-white transition hover:bg-indigo-700 disabled:opacity-60"
      >
        {{ entrando ? 'Entrando...' : 'Entrar' }}
      </button>
    </form>
  </main>
</template>
