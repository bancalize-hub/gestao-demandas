<script setup lang="ts">
defineProps<{
  label: string
  modelValue: string
  erro?: string
  type?: string
  dica?: string
  obrigatorio?: boolean
}>()
defineEmits<{ 'update:modelValue': [value: string] }>()
</script>

<template>
  <div>
    <label class="mb-1 block text-sm font-medium text-slate-700">
      {{ label }} <span v-if="obrigatorio" class="text-red-500">*</span>
    </label>
    <input
      :type="type || 'text'"
      :value="modelValue"
      :placeholder="dica"
      class="w-full rounded-lg border bg-white px-3 py-2 text-sm shadow-sm outline-none transition focus:border-indigo-500 focus:ring-2 focus:ring-indigo-200"
      :class="erro ? 'border-red-400' : 'border-slate-300'"
      @input="$emit('update:modelValue', ($event.target as HTMLInputElement).value)"
    >
    <p v-if="erro" class="mt-1 text-xs text-red-600">{{ erro }}</p>
  </div>
</template>
