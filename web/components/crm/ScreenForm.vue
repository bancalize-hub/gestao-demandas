<script setup lang="ts">
import { useCrmStore } from '~/stores/crm'

const crm = useCrmStore()

// Empresa (tenant) destino: slug na URL do portal, ex.: /solicitar?e=minha-empresa
const slug = computed(() => (useRoute().query.e as string) || '')

const name = ref('')
const company = ref('')
const desc = ref('')
const due = ref('')

const types = ['Novo recurso', 'Suporte', 'Bug', 'Outro']
const prios = [
  { k: 'baixa', label: 'Baixa', color: '#53bdeb' },
  { k: 'media', label: 'Média', color: '#ffb443' },
  { k: 'alta', label: 'Alta', color: '#ff6b6b' },
] as const

function typeStyle(active: boolean) {
  return { fontSize: '13px', fontWeight: active ? 700 : 600, color: active ? '#062014' : '#aebac1', background: active ? '#25D366' : '#202c33', padding: '9px 14px', borderRadius: '10px', cursor: 'pointer', border: 'none', fontFamily: 'inherit' }
}
function prioStyle(p: { color: string }, active: boolean) {
  return { fontSize: '13px', fontWeight: active ? 700 : 600, color: active ? p.color : '#aebac1', background: active ? `${p.color}22` : '#202c33', border: active ? `1px solid ${p.color}` : '1px solid transparent', padding: '9px 16px', borderRadius: '10px', cursor: 'pointer', fontFamily: 'inherit' }
}

function submit() {
  crm.submitForm({ name: name.value, company: company.value, desc: desc.value, due: due.value, slug: slug.value })
}
function reset() {
  name.value = ''
  company.value = ''
  desc.value = ''
  due.value = ''
  crm.resetForm()
}
</script>

<template>
  <div style="flex:1;min-width:0;background:#0b141a;display:flex;flex-direction:column;overflow-y:auto;">
    <div style="padding:16px 30px;border-bottom:1px solid #1c2730;display:flex;align-items:center;justify-content:space-between;flex-shrink:0;">
      <div style="display:flex;align-items:center;gap:11px;"><div style="width:34px;height:34px;border-radius:10px;background:#25D366;display:flex;align-items:center;justify-content:center;"><svg width="18" height="18" viewBox="0 0 24 24" fill="#0b141a"><path d="M12 3c-4.97 0-9 3.58-9 8 0 2.5 1.3 4.7 3.3 6.1L5.5 21l3.6-1.5c.9.25 1.9.4 2.9.4 4.97 0 9-3.58 9-8s-4.03-8.9-9-8.9Z" /></svg></div><div><div style="font-weight:800;font-size:15px;">Portal de solicitações</div><div style="font-size:12px;color:#8696a0;">Vértice · canal do cliente</div></div></div>
      <button class="ghost" style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:12.5px;font-weight:600;padding:9px 14px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:7px;" @click="crm.go('tasks')"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><rect x="4" y="4" width="16" height="16" rx="3.5" /><path d="m8 12.2 2.4 2.4L16 9" stroke-linecap="round" stroke-linejoin="round" /></svg>Ver Kanban</button>
    </div>

    <div style="flex:1;display:flex;align-items:flex-start;justify-content:center;padding:38px 24px 60px;">
      <div style="width:640px;max-width:100%;">
        <div v-if="!crm.formSubmitted" style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:30px;">
          <div style="font-size:23px;font-weight:800;letter-spacing:-.2px;">Nova solicitação</div>
          <div style="font-size:14px;color:#8696a0;margin-top:6px;">Conte o que você precisa que a equipe Vértice faça. Vamos priorizar e te retornar pelo WhatsApp.</div>

          <div style="margin-top:26px;display:flex;flex-direction:column;gap:20px;">
            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Nome*</span><input v-model="name" placeholder="Seu nome" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:11px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;"></label>
              <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Empresa</span><input v-model="company" placeholder="Sua empresa (opcional)" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:11px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;"></label>
            </div>

            <div>
              <span style="font-size:12.5px;font-weight:600;color:#aebac1;">Tipo de solicitação</span>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:9px;">
                <button v-for="t in types" :key="t" :style="typeStyle(crm.formType === t)" @click="crm.formType = t">{{ t }}</button>
              </div>
            </div>

            <div>
              <span style="font-size:12.5px;font-weight:600;color:#aebac1;">Prioridade</span>
              <div style="display:flex;gap:8px;margin-top:9px;">
                <button v-for="p in prios" :key="p.k" :style="prioStyle(p, crm.formPriority === p.k)" @click="crm.formPriority = p.k">{{ p.label }}</button>
              </div>
            </div>

            <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Descrição*</span><textarea v-model="desc" rows="4" placeholder="Descreva o que você precisa com o máximo de detalhes" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:12px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;resize:vertical;line-height:1.5;" /></label>

            <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;">
              <label style="display:flex;flex-direction:column;gap:7px;"><span style="font-size:12.5px;font-weight:600;color:#aebac1;">Prazo desejado</span><input v-model="due" type="date" style="background:#202c33;border:1px solid #2a3942;border-radius:11px;padding:11px 13px;color:#e9edef;font-family:inherit;font-size:14px;outline:none;color-scheme:dark;"></label>
              <div />
            </div>

            <div v-if="crm.formError" style="display:flex;align-items:center;gap:8px;background:rgba(255,77,77,.1);border:1px solid rgba(255,77,77,.3);color:#ff8d8d;font-size:13px;font-weight:600;padding:11px 14px;border-radius:11px;"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="9" /><path d="M12 8v5M12 16v.5" stroke-linecap="round" /></svg>Preencha pelo menos o nome e a descrição.</div>

            <button class="wabig" style="width:100%;background:#25D366;border:none;color:#062014;font-family:inherit;font-size:15px;font-weight:700;padding:14px;border-radius:13px;cursor:pointer;box-shadow:0 6px 16px rgba(37,211,102,.28);" @click="submit">Enviar solicitação</button>
          </div>
        </div>

        <div v-else style="background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:46px 40px;text-align:center;display:flex;flex-direction:column;align-items:center;gap:16px;">
          <div style="width:74px;height:74px;border-radius:50%;background:rgba(37,211,102,.14);display:flex;align-items:center;justify-content:center;"><svg width="36" height="36" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2.4"><path d="m5 13 4 4L19 7" stroke-linecap="round" stroke-linejoin="round" /></svg></div>
          <div style="font-size:23px;font-weight:800;">Solicitação enviada! 🎉</div>
          <div style="font-size:14px;color:#8696a0;max-width:440px;line-height:1.55;">Recebemos seu pedido e ele já entrou no quadro de tarefas da equipe na coluna <b style="color:#aebac1;">A fazer</b>. Você será avisado pelo WhatsApp assim que começarmos.</div>
          <div style="display:flex;gap:10px;margin-top:6px;">
            <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13.5px;font-weight:700;padding:11px 18px;border-radius:11px;cursor:pointer;" @click="crm.go('tasks')">Ver no Kanban</button>
            <button class="ghost" style="background:#202c33;border:none;color:#e9edef;font-family:inherit;font-size:13.5px;font-weight:700;padding:11px 18px;border-radius:11px;cursor:pointer;" @click="reset">Nova solicitação</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.ghost:hover { background: #2a3942 !important; }
.wabtn:hover { background: #2ee070 !important; }
.wabig:hover { background: #2ee070 !important; }
</style>
