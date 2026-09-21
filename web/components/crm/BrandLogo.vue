<script setup lang="ts">
// Logo da empresa, escolhida conforme o tema (clara p/ tema claro, escura p/ escuro).
// Sem logo → wordmark de fallback (ícone do acento + nome da empresa/plataforma).
const props = withDefaults(defineProps<{
  company?: { name?: string, logo_light_url?: string | null, logo_dark_url?: string | null } | null
  fallbackName?: string
  height?: number
}>(), { height: 30, fallbackName: 'Vértice CRM' })

const { isDark } = useTheme()

const logoSrc = computed(() => {
  const c = props.company
  if (!c) return null
  const primary = isDark.value ? c.logo_dark_url : c.logo_light_url
  return primary || c.logo_dark_url || c.logo_light_url || null
})

const label = computed(() => props.company?.name || props.fallbackName)
</script>

<template>
  <div style="display:flex;align-items:center;gap:10px;min-width:0;">
    <img v-if="logoSrc" :src="logoSrc" :alt="label" :style="{ height: `${height}px`, maxWidth: '160px', objectFit: 'contain' }">
    <template v-else>
      <div :style="{ width: `${height}px`, height: `${height}px`, borderRadius: '10px', background: 'var(--accent)', display: 'flex', alignItems: 'center', justifyContent: 'center', flex: 'none', boxShadow: '0 4px 12px rgba(var(--accent-rgb),.35)' }">
        <svg :width="height * 0.55" :height="height * 0.55" viewBox="0 0 24 24" fill="var(--accent-ink)"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" /></svg>
      </div>
      <!-- No celular sobram ~296px para o nome: com reticências um nome de empresa
           comprido simplesmente sumia. Abaixo de 820px ele quebra em vez de ser cortado. -->
      <span class="r-sm-unclamp r-break" style="font-size:16px;font-weight:800;letter-spacing:-.3px;color:var(--c-text);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ label }}</span>
    </template>
  </div>
</template>
