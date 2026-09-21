<script setup lang="ts">
// Horário de atendimento (admin): quando a IA pode TOMAR A INICIATIVA — retomada de lead
// sumido, horários de reunião oferecidos e a janela padrão de campanha nova.
//
// O que esta tela NÃO controla, de propósito: a resposta imediata a quem escreve. Lead
// que manda mensagem às 23h é conversa viva — segurar a resposta até as 9h só esfria.
const api = useApi()

interface Attendance { days: number[], start: string, end: string, configured: boolean }

const DIAS = [
  { iso: 1, label: 'Seg' }, { iso: 2, label: 'Ter' }, { iso: 3, label: 'Qua' },
  { iso: 4, label: 'Qui' }, { iso: 5, label: 'Sex' }, { iso: 6, label: 'Sáb' },
  { iso: 7, label: 'Dom' },
]

const days = ref<number[]>([1, 2, 3, 4, 5])
const start = ref('09:00')
const end = ref('19:00')
const configured = ref(false)
const saving = ref(false)
const msg = ref('')

onMounted(async () => {
  try {
    const a = await api<Attendance>('/api/attendance')
    days.value = a.days
    start.value = a.start
    end.value = a.end
    configured.value = a.configured
  }
  catch {}
})

function toggleDay(iso: number) {
  days.value = days.value.includes(iso)
    ? days.value.filter(d => d !== iso)
    : [...days.value, iso].sort((a, b) => a - b)
}

async function save() {
  if (!days.value.length) { msg.value = 'Escolha pelo menos um dia.'; return }
  if (start.value >= end.value) { msg.value = 'O fim precisa ser depois do início.'; return }
  saving.value = true
  msg.value = ''
  try {
    const a = await api<Attendance>('/api/attendance', {
      method: 'PATCH',
      body: { days: days.value, start: start.value, end: end.value },
    })
    days.value = a.days
    start.value = a.start
    end.value = a.end
    configured.value = true
    msg.value = 'Horário salvo. Vale a partir de agora para retomadas, reuniões e campanhas novas.'
  }
  catch (e: any) { msg.value = e?.response?._data?.message || 'Não consegui salvar.' }
  finally { saving.value = false }
}

const box = {
  background: 'var(--c-surface-2)', border: '1px solid var(--c-surface-1)',
  borderRadius: '16px', padding: '22px 24px',
}
</script>

<template>
  <div class="r-min0 r-pad" style="flex:1;overflow-y:auto;padding-block:clamp(20px,4vw,32px);background:var(--c-bg);color:var(--c-text);">
    <div style="max-width:760px;margin:0 auto;display:flex;flex-direction:column;gap:20px;">
      <div>
        <div style="font-size:22px;font-weight:800;letter-spacing:-.3px;">Horário de atendimento</div>
        <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:4px;">
          Quando a IA pode procurar as pessoas por conta própria. Vale para a empresa inteira.
        </div>
      </div>

      <div class="r-xs-pad-sm" :style="box">
        <div style="font-size:15px;font-weight:700;margin-bottom:14px;">Dias e horário</div>

        <div style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px;">
          <button
            v-for="d in DIAS" :key="d.iso" class="r-tap"
            :style="{
              fontFamily: 'inherit', fontSize: '13.5px', fontWeight: 700, padding: '10px 16px',
              borderRadius: '11px', cursor: 'pointer', border: '1px solid',
              borderColor: days.includes(d.iso) ? 'var(--accent)' : 'var(--c-border-strong)',
              background: days.includes(d.iso) ? 'var(--accent)' : 'var(--c-surface-3)',
              color: days.includes(d.iso) ? 'var(--accent-ink)' : 'var(--c-text-secondary)',
            }"
            @click="toggleDay(d.iso)"
          >{{ d.label }}</button>
        </div>

        <!-- em ≤480 o Salvar sobrava pendurado ao lado do 2º campo de hora: empilhado, ele
             volta a ser a ação da seção. -->
        <div class="r-xs-stack" style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
          <label style="display:flex;align-items:center;gap:9px;font-size:14px;color:var(--c-text-secondary);">
            Das
            <input
              v-model="start" type="time"
              style="background:var(--c-surface-3);border:1px solid var(--c-border-strong);border-radius:11px;padding:10px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"
            >
          </label>
          <label style="display:flex;align-items:center;gap:9px;font-size:14px;color:var(--c-text-secondary);">
            às
            <input
              v-model="end" type="time"
              style="background:var(--c-surface-3);border:1px solid var(--c-border-strong);border-radius:11px;padding:10px 13px;color:var(--c-text);font-family:inherit;font-size:14px;outline:none;"
            >
          </label>
          <button
            class="r-tap r-xs-full"
            :style="{ background: 'var(--accent)', color: 'var(--accent-ink)', border: 'none', fontFamily: 'inherit', fontSize: '14px', fontWeight: 700, padding: '12px 20px', borderRadius: '11px', cursor: 'pointer', opacity: saving ? 0.7 : 1 }"
            :disabled="saving" @click="save"
          >{{ saving ? 'Salvando…' : 'Salvar horário' }}</button>
        </div>

        <div v-if="msg" style="margin-top:14px;font-size:13px;font-weight:600;color:var(--accent-soft);">{{ msg }}</div>
        <div v-if="!configured" style="margin-top:14px;font-size:12.5px;color:var(--c-text-muted);">
          Ainda usando o padrão (seg–sex, 09:00–19:00). Salve para fixar o seu.
        </div>
      </div>

      <div class="r-xs-pad-sm" :style="box">
        <div style="font-size:15px;font-weight:700;margin-bottom:10px;">O que este horário controla</div>
        <div style="display:flex;flex-direction:column;gap:9px;font-size:13.5px;color:var(--c-text-secondary);line-height:1.55;">
          <div><b style="color:var(--c-text);">Retomada de lead sumido</b> — a IA só busca quem parou de responder dentro deste horário. Única exceção: o primeiro toque, até 30 min depois do silêncio, sai a qualquer hora — é continuação de conversa viva, não abordagem nova.</div>
          <div><b style="color:var(--c-text);">Horários de reunião</b> — a IA só oferece reunião nos dias e no intervalo definidos aqui.</div>
          <div><b style="color:var(--c-text);">Campanhas novas</b> — nascem com esta janela de disparo. Cada campanha pode ajustar a própria janela depois, na tela de campanhas.</div>
        </div>
        <div style="margin-top:14px;padding:12px 14px;border-radius:11px;background:var(--c-surface-3);font-size:13px;color:var(--c-text-muted);line-height:1.55;">
          <b style="color:var(--c-text-secondary);">O que fica de fora:</b> quem escreve para a empresa é respondido na hora, em qualquer horário. Segurar a resposta de um lead vivo até a manhã seguinte só faria a conversa esfriar.
        </div>
      </div>
    </div>
  </div>
</template>
