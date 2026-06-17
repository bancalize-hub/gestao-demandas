<script setup lang="ts">
// Sala de reunião pública com Jitsi embutido (vídeo/áudio/tela reais).
definePageMeta({ layout: 'blank' })

const route = useRoute()

const sala = computed(() => String(route.params.sala || 'sala'))
const room = computed(() => `vertice-${sala.value}`.replace(/[^a-zA-Z0-9-_]/g, ''))
const jitsiUrl = computed(() => `https://meet.jit.si/${room.value}#config.prejoinPageEnabled=true&config.disableDeepLinking=true`)

useHead({ title: `Reunião · ${sala.value}` })
</script>

<template>
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;background:#06090b;">
    <div style="display:flex;align-items:center;justify-content:space-between;padding:11px 20px;border-bottom:1px solid #1c2730;flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:11px;">
        <div style="width:30px;height:30px;border-radius:8px;background:#25D366;display:flex;align-items:center;justify-content:center;">
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#06090b" stroke-width="2"><rect x="2.5" y="6" width="13" height="12" rx="2.5" /><path d="M15.5 10l6-3.2v10.4l-6-3.2" stroke-linejoin="round" /></svg>
        </div>
        <div>
          <div style="font-weight:700;font-size:14px;">Reunião ao vivo</div>
          <div style="font-size:11.5px;color:#8696a0;">Sala: {{ sala }}</div>
        </div>
      </div>
      <button style="display:flex;align-items:center;gap:7px;background:#1a262e;border:none;color:#e9edef;font-family:inherit;font-size:12.5px;font-weight:600;padding:8px 14px;border-radius:10px;cursor:pointer;" @click="navigateTo('/')">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><path d="M15 12H4m0 0 3.5-3.5M4 12l3.5 3.5M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4" stroke-linecap="round" stroke-linejoin="round" /></svg>
        Sair
      </button>
    </div>

    <iframe
      :src="jitsiUrl"
      allow="camera; microphone; fullscreen; display-capture; autoplay; clipboard-write"
      style="flex:1;width:100%;border:none;"
    />
  </div>
</template>
