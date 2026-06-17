<script setup lang="ts">
// Lobby público de reuniões.
definePageMeta({ layout: 'blank' })

const room = ref('')

function slug(s: string) {
  return s.trim().toLowerCase().replace(/\s+/g, '-').replace(/[^a-z0-9-]/g, '')
}

function enter() {
  navigateTo(`/reuniao/${slug(room.value) || 'sala'}`)
}

function nova() {
  navigateTo(`/reuniao/sala-${Math.random().toString(36).slice(2, 8)}`)
}
</script>

<template>
  <div style="flex:1;display:flex;align-items:center;justify-content:center;padding:24px;background-image:radial-gradient(circle at 20% 30%,rgba(37,211,102,.05),transparent 42%),radial-gradient(circle at 80% 70%,rgba(124,108,245,.05),transparent 42%);">
    <div style="width:420px;max-width:100%;">
      <div style="display:flex;align-items:center;gap:11px;justify-content:center;margin-bottom:22px;">
        <div style="width:44px;height:44px;border-radius:13px;background:#25D366;display:flex;align-items:center;justify-content:center;box-shadow:0 6px 16px rgba(37,211,102,.35);">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#06090b" stroke-width="2"><rect x="2.5" y="6" width="13" height="12" rx="2.5" /><path d="M15.5 10l6-3.2v10.4l-6-3.2" stroke-linejoin="round" /></svg>
        </div>
        <span style="font-size:20px;font-weight:800;letter-spacing:-.3px;">Reuniões Vértice</span>
      </div>

      <div style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:28px;display:flex;flex-direction:column;gap:16px;">
        <div>
          <div style="font-size:18px;font-weight:800;">Entrar em uma sala</div>
          <div style="font-size:13px;color:#8696a0;margin-top:4px;">Vídeo, áudio e compartilhamento de tela — sem instalar nada.</div>
        </div>

        <label style="display:flex;flex-direction:column;gap:7px;">
          <span style="font-size:12.5px;font-weight:600;color:#aebac1;">Nome da sala</span>
          <input v-model="room" placeholder="ex: demo-mariana" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:12px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;" @keydown.enter="enter">
        </label>

        <button :style="{ width: '100%', background: '#25D366', border: 'none', color: '#062014', fontFamily: 'inherit', fontSize: '14.5px', fontWeight: 700, padding: '13px', borderRadius: '12px', cursor: 'pointer', boxShadow: '0 6px 16px rgba(37,211,102,.28)' }" @click="enter">
          Entrar na sala
        </button>
        <button style="width:100%;background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13.5px;font-weight:600;padding:12px;border-radius:12px;cursor:pointer;" @click="nova">
          Criar sala nova
        </button>
      </div>
    </div>
  </div>
</template>
