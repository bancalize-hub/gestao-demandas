<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { type Contact, useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
const search = ref('')

onMounted(() => { crm.loadContacts() })

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase()
  const list = [...crm.contacts].sort((a, b) => (a.name || '').localeCompare(b.name || ''))
  if (!q) return list
  return list.filter(c => `${c.name} ${c.phone} ${c.email}`.toLowerCase().includes(q))
})

function initials(name: string) {
  const p = (name || '').trim().split(/\s+/).filter(Boolean)
  return ((p[0]?.[0] ?? '') + (p.length > 1 ? p[p.length - 1][0] : '')).toUpperCase() || '#'
}
function colorFor(s: string) {
  let h = 0
  for (let i = 0; i < (s || '').length; i++) h = (h * 31 + s.charCodeAt(i)) % 360
  return `hsl(${h}, 60%, 50%)`
}

// Modal
const open = ref(false)
const saving = ref(false)
const editingId = ref<number | null>(null)
const broken = ref(new Set<number>())
const form = reactive({ name: '', phone: '', email: '' })
function openNew() { editingId.value = null; Object.assign(form, { name: '', phone: '', email: '' }); open.value = true }
function openEdit(c: Contact) { editingId.value = c.id; Object.assign(form, { name: c.name || '', phone: c.phone || '', email: c.email || '' }); open.value = true }
async function save() {
  if (!form.name.trim() || saving.value) return
  saving.value = true
  try {
    const payload = { name: form.name.trim(), phone: form.phone.trim() || undefined, email: form.email.trim() || undefined }
    if (editingId.value) await crm.updateContact(editingId.value, payload as any)
    else await crm.createContact(payload)
    open.value = false
  }
  finally { saving.value = false }
}
function remove() {
  if (editingId.value && confirm('Excluir este contato? (também sai do Google Contatos)')) {
    crm.removeContact(editingId.value)
    open.value = false
  }
}
</script>

<template>
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;background:#0b141a;">
    <div style="padding:22px 30px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #1c2730;">
      <div>
        <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Contatos</div>
        <div style="font-size:13.5px;color:#8696a0;margin-top:3px;">{{ crm.contacts.length }} contatos · sincronizados com o Google</div>
      </div>
      <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:6px;" @click="openNew">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Novo contato
      </button>
    </div>

    <div style="padding:16px 30px 0;">
      <div style="display:flex;align-items:center;gap:9px;background:#202c33;border-radius:11px;padding:9px 13px;max-width:420px;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="#8696a0" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
        <input v-model="search" placeholder="Buscar por nome, telefone ou e-mail" style="flex:1;background:transparent;border:none;outline:none;color:#e9edef;font-family:inherit;font-size:13.5px;">
      </div>
    </div>

    <div style="flex:1;overflow-y:auto;padding:16px 30px 28px;">
      <div v-if="!filtered.length" style="text-align:center;color:#8696a0;font-size:13.5px;margin-top:40px;">Nenhum contato. Crie um ou conecte o Google na Agenda.</div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
        <div v-for="c in filtered" :key="c.id" class="ccard" style="display:flex;align-items:center;gap:13px;background:#111b21;border:1px solid #1c2730;border-radius:13px;padding:13px 15px;cursor:pointer;" :title="c.phone ? 'Abrir conversa' : 'Sem telefone'" @click="crm.openContactChat(c)">
          <div :style="{ width: '46px', height: '46px', borderRadius: '50%', flexShrink: 0, background: colorFor(c.name || String(c.id)), display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '15px', overflow: 'hidden' }">
            <img v-if="c.avatar && !broken.has(c.id)" :src="c.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover;" @error="broken.add(c.id)">
            <template v-else>{{ initials(c.name) }}</template>
          </div>
          <div style="min-width:0;flex:1;">
            <div style="font-weight:700;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.name }}</div>
            <div v-if="c.phone" style="font-size:12.5px;color:#8696a0;margin-top:2px;">{{ c.phone }}</div>
            <div v-if="c.email" style="font-size:12px;color:#5f7d8c;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.email }}</div>
          </div>
          <button class="cedit" title="Editar contato" style="background:#202c33;border:none;color:#8696a0;width:32px;height:32px;border-radius:9px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;" @click.stop="openEdit(c)">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
        </div>
      </div>
    </div>

    <!-- Modal -->
    <div v-if="open" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:24px;" @click.self="open = false">
      <div style="width:400px;max-width:100%;background:#111b21;border:1px solid #1c2730;border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <div style="font-size:18px;font-weight:800;">{{ editingId ? 'Editar contato' : 'Novo contato' }}</div>
          <button style="background:none;border:none;color:#8696a0;cursor:pointer;font-size:20px;line-height:1;" @click="open = false">×</button>
        </div>
        <label class="lbl">Nome</label>
        <input v-model="form.name" class="inp" placeholder="Nome do contato">
        <label class="lbl">Telefone</label>
        <input v-model="form.phone" class="inp" placeholder="+55 11 99999-9999">
        <label class="lbl">E-mail</label>
        <input v-model="form.email" class="inp" placeholder="email@exemplo.com">
        <div style="display:flex;align-items:center;gap:10px;margin-top:18px;">
          <button v-if="editingId" style="background:transparent;border:1px solid #5a2630;color:#ff9a9a;font-family:inherit;font-size:13px;font-weight:600;padding:9px 13px;border-radius:9px;cursor:pointer;" @click="remove">Excluir</button>
          <div style="flex:1;" />
          <button style="background:#202c33;border:none;color:#8696a0;font-family:inherit;font-size:13px;padding:9px 15px;border-radius:9px;cursor:pointer;" @click="open = false">Cancelar</button>
          <button class="wabtn" :disabled="saving || !form.name.trim()" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:9px 18px;border-radius:9px;cursor:pointer;" @click="save">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: #2ee070 !important; }
.ccard:hover { background: #16222a !important; border-color: #2a3942 !important; }
.cedit:hover { background: #2a3942 !important; color: #e9edef !important; }
.lbl { display:block; font-size:11.5px; color:#8696a0; font-weight:600; margin:11px 0 5px; }
.inp { width:100%; box-sizing:border-box; background:#202c33; border:1px solid #2a3942; color:#e9edef; font-family:inherit; font-size:13.5px; padding:9px 11px; border-radius:9px; outline:none; }
.inp:focus { border-color:#25D366; }
</style>
