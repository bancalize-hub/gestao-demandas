<script setup lang="ts">
/**
 * Campo de senha com olhinho para revelar o que foi digitado.
 *
 * O botão é `type="button"` de propósito: dentro de um <form>, botão sem type
 * declarado vira submit e o olhinho enviaria o login.
 */
const props = withDefaults(defineProps<{
  placeholder?: string
  autocomplete?: string
}>(), {
  placeholder: '••••••••',
  autocomplete: 'current-password',
})

const model = defineModel<string>({ required: true })
const visivel = ref(false)
</script>

<template>
  <div style="position:relative;display:flex;">
    <input
      v-model="model"
      :type="visivel ? 'text' : 'password'"
      :autocomplete="props.autocomplete"
      :placeholder="props.placeholder"
      style="flex:1;min-width:0;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:11px;padding:12px 44px 12px 13px;color:var(--c-text);font-family:inherit;font-size:16px;outline:none;"
    >
    <button
      type="button"
      class="r-tap"
      :title="visivel ? 'Ocultar senha' : 'Mostrar senha'"
      :aria-label="visivel ? 'Ocultar senha' : 'Mostrar senha'"
      :aria-pressed="visivel"
      style="position:absolute;top:0;right:0;height:100%;width:42px;display:flex;align-items:center;justify-content:center;background:none;border:none;padding:0;color:var(--c-text-muted);cursor:pointer;"
      @click="visivel = !visivel"
    >
      <svg v-if="!visivel" width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M2 12s3.6-7 10-7 10 7 10 7-3.6 7-10 7-10-7-10-7Z" />
        <circle cx="12" cy="12" r="3" />
      </svg>
      <svg v-else width="19" height="19" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
        <path d="M10.7 5.1A9.9 9.9 0 0 1 12 5c6.4 0 10 7 10 7a17.7 17.7 0 0 1-3.2 4.2M6.2 6.2A17.6 17.6 0 0 0 2 12s3.6 7 10 7a9.8 9.8 0 0 0 5.1-1.4" />
        <path d="M9.9 9.9a3 3 0 0 0 4.2 4.2" />
        <path d="m3 3 18 18" />
      </svg>
    </button>
  </div>
</template>
