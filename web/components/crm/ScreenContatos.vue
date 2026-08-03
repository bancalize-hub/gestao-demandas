<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { type Contact, useCrmStore } from '~/stores/crm'

const api = useApi()
const crm = useCrmStore()
const search = ref('')

// ---- Listas de contatos ----------------------------------------------------
// A agenda deixou de ser "o que veio do Google": cada lista é um grupo (Google,
// planilha importada, leads do CRM) e é o que a campanha usa no disparo.
interface Lista { id: number, name: string, kind: string, contacts_count: number }
const listas = ref<Lista[]>([])
const listaAtiva = ref<number | null>(null) // null = todos
const totalGeral = ref(0)
const carregando = ref(false)

async function carregarListas() {
  try {
    const r = await api<{ lists: Lista[], total: number }>('/api/contact-lists')
    listas.value = r.lists
    totalGeral.value = r.total
  }
  catch { /* */ }
}
async function abrirLista(id: number | null) {
  listaAtiva.value = id
  carregando.value = true
  try { await crm.loadContacts(id ?? undefined) }
  finally { carregando.value = false }
}
const nomeDaLista = computed(() => listaAtiva.value ? (listas.value.find(l => l.id === listaAtiva.value)?.name || '') : 'Todos os contatos')

function iconeDaLista(kind: string) {
  return ({ google: '🔵', planilha: '📄', crm: '💬' } as Record<string, string>)[kind] || '🏷️'
}

// ---- Importar planilha / puxar do CRM ----
const importOpen = ref(false)
const importTab = ref<'planilha' | 'crm'>('planilha')
const impNome = ref('')
const impArquivo = ref<File | null>(null)
const impStage = ref('')
const impSoAnuncio = ref(false)
const impSalvando = ref(false)
const impErro = ref('')
const impInput = ref<HTMLInputElement | null>(null)

function abrirImport() {
  importOpen.value = true
  importTab.value = 'planilha'
  impNome.value = ''
  impArquivo.value = null
  impStage.value = ''
  impSoAnuncio.value = false
  impErro.value = ''
}
function escolherArquivo(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] || null
  impArquivo.value = f
  if (f && !impNome.value.trim()) impNome.value = f.name.replace(/\.[^.]+$/, '')
}
async function importar() {
  if (impSalvando.value || !impNome.value.trim()) return
  if (importTab.value === 'planilha' && !impArquivo.value) { impErro.value = 'Escolha o arquivo da planilha (CSV).'; return }
  impSalvando.value = true
  impErro.value = ''
  try {
    let r: any
    if (importTab.value === 'planilha') {
      const fd = new FormData()
      fd.append('name', impNome.value.trim())
      fd.append('file', impArquivo.value!)
      r = await api('/api/contact-lists/import', { method: 'POST', body: fd })
    }
    else {
      r = await api('/api/contact-lists/from-crm', {
        method: 'POST',
        body: { name: impNome.value.trim(), stage: impStage.value || undefined, somente_anuncio: impSoAnuncio.value },
      })
    }
    importOpen.value = false
    await carregarListas()
    await abrirLista(r.list.id)
  }
  catch (e: any) {
    const errs = e?.response?._data?.errors
    impErro.value = errs ? Object.values(errs).flat().join(' ') : (e?.response?._data?.message || 'Falha ao importar.')
  }
  finally { impSalvando.value = false }
}

async function novaLista() {
  const nome = prompt('Nome da nova lista:')?.trim()
  if (!nome) return
  await api('/api/contact-lists', { method: 'POST', body: { name: nome } })
  await carregarListas()
}
async function apagarLista(l: Lista) {
  if (!confirm(`Apagar a lista "${l.name}"? Os contatos continuam na agenda, só saem do grupo.`)) return
  await api(`/api/contact-lists/${l.id}`, { method: 'DELETE' }).catch(() => {})
  if (listaAtiva.value === l.id) await abrirLista(null)
  await carregarListas()
}

onMounted(async () => { await crm.loadContacts(); await carregarListas() })

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
  <div style="flex:1;display:flex;min-width:0;background:var(--c-bg-deep);">
    <!-- listas -->
    <aside style="width:230px;flex-shrink:0;border-right:1px solid var(--c-surface-1);display:flex;flex-direction:column;min-height:0;">
      <div style="padding:20px 16px 12px;font-size:11px;font-weight:700;letter-spacing:.5px;color:var(--c-text-faint);">LISTAS</div>
      <div style="flex:1;overflow-y:auto;padding:0 8px 8px;min-height:0;">
        <div :style="{ display:'flex',alignItems:'center',gap:'8px',padding:'9px 10px',borderRadius:'9px',cursor:'pointer',marginBottom:'2px',background: listaAtiva===null ? 'var(--c-surface-2)' : 'transparent' }" @click="abrirLista(null)">
          <span style="font-size:13px;">👥</span>
          <span style="flex:1;font-size:13px;font-weight:600;">Todos</span>
          <span style="font-size:11.5px;color:var(--c-text-faint);">{{ totalGeral }}</span>
        </div>
        <div v-for="l in listas" :key="l.id" class="lrow" :style="{ display:'flex',alignItems:'center',gap:'8px',padding:'9px 10px',borderRadius:'9px',cursor:'pointer',marginBottom:'2px',background: listaAtiva===l.id ? 'var(--c-surface-2)' : 'transparent' }" @click="abrirLista(l.id)">
          <span style="font-size:13px;">{{ iconeDaLista(l.kind) }}</span>
          <span style="flex:1;min-width:0;font-size:13px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ l.name }}</span>
          <span style="font-size:11.5px;color:var(--c-text-faint);">{{ l.contacts_count }}</span>
          <button v-if="l.kind !== 'google'" class="ldel" title="Apagar lista" style="background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:14px;padding:0 2px;" @click.stop="apagarLista(l)">×</button>
        </div>
      </div>
      <div style="padding:8px;border-top:1px solid var(--c-surface-1);display:flex;flex-direction:column;gap:6px;">
        <button style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:12.5px;font-weight:600;padding:9px;border-radius:9px;cursor:pointer;" @click="abrirImport">📄 Importar lista</button>
        <button style="background:none;border:1px dashed var(--c-surface-3);color:var(--c-text-muted);font-family:inherit;font-size:12.5px;padding:8px;border-radius:9px;cursor:pointer;" @click="novaLista">+ Lista vazia</button>
      </div>
    </aside>

    <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
    <div style="padding:22px 30px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--c-surface-1);">
      <div>
        <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">{{ nomeDaLista }}</div>
        <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:3px;">{{ crm.contacts.length }} contatos<template v-if="listaAtiva === null"> · o grupo "Google" vem da sua agenda</template></div>
      </div>
      <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:6px;" @click="openNew">
        <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Novo contato
      </button>
    </div>

    <div style="padding:16px 30px 0;">
      <div style="display:flex;align-items:center;gap:9px;background:var(--c-surface-2);border-radius:11px;padding:9px 13px;max-width:420px;">
        <svg width="17" height="17" viewBox="0 0 24 24" fill="none" stroke="var(--c-text-muted)" stroke-width="2"><circle cx="11" cy="11" r="7" /><path d="m20 20-3-3" stroke-linecap="round" /></svg>
        <input v-model="search" placeholder="Buscar por nome, telefone ou e-mail" style="flex:1;background:transparent;border:none;outline:none;color:var(--c-text);font-family:inherit;font-size:13.5px;">
      </div>
    </div>

    <div style="flex:1;overflow-y:auto;padding:16px 30px 28px;">
      <div v-if="carregando" style="text-align:center;color:var(--c-text-muted);font-size:13.5px;margin-top:40px;">Carregando…</div>
      <div v-else-if="!filtered.length" style="text-align:center;color:var(--c-text-muted);font-size:13.5px;margin-top:40px;">
        <template v-if="listaAtiva">Esta lista ainda está vazia.</template>
        <template v-else>Nenhum contato. Importe uma planilha, puxe do CRM ou conecte o Google na Agenda.</template>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:12px;">
        <div v-for="c in filtered" :key="c.id" class="ccard" style="display:flex;align-items:center;gap:13px;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:13px;padding:13px 15px;cursor:pointer;" :title="c.phone ? 'Abrir conversa' : 'Sem telefone'" @click="crm.openContactChat(c)">
          <div :style="{ width: '46px', height: '46px', borderRadius: '50%', flexShrink: 0, background: colorFor(c.name || String(c.id)), display: 'flex', alignItems: 'center', justifyContent: 'center', fontWeight: 700, fontSize: '15px', overflow: 'hidden' }">
            <img v-if="c.avatar && !broken.has(c.id)" :src="c.avatar" referrerpolicy="no-referrer" style="width:100%;height:100%;object-fit:cover;" @error="broken.add(c.id)">
            <template v-else>{{ initials(c.name) }}</template>
          </div>
          <div style="min-width:0;flex:1;">
            <div style="font-weight:700;font-size:14.5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.name }}</div>
            <div v-if="c.phone" style="font-size:12.5px;color:var(--c-text-muted);margin-top:2px;">{{ c.phone }}</div>
            <div v-if="c.email" style="font-size:12px;color:var(--c-text-faint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ c.email }}</div>
          </div>
          <button class="cedit" title="Editar contato" style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);width:32px;height:32px;border-radius:9px;cursor:pointer;flex-shrink:0;display:flex;align-items:center;justify-content:center;" @click.stop="openEdit(c)">
            <svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M12 20h9M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z" stroke-linecap="round" stroke-linejoin="round" /></svg>
          </button>
        </div>
      </div>
    </div>

    </div>

    <!-- Modal -->
    <div v-if="open" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:24px;" @click.self="open = false">
      <div style="width:400px;max-width:100%;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:16px;">
          <div style="font-size:18px;font-weight:800;">{{ editingId ? 'Editar contato' : 'Novo contato' }}</div>
          <button style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;font-size:20px;line-height:1;" @click="open = false">×</button>
        </div>
        <label class="lbl">Nome</label>
        <input v-model="form.name" class="inp" placeholder="Nome do contato">
        <label class="lbl">Telefone</label>
        <input v-model="form.phone" class="inp" placeholder="+55 11 99999-9999">
        <label class="lbl">E-mail</label>
        <input v-model="form.email" class="inp" placeholder="email@exemplo.com">
        <div style="display:flex;align-items:center;gap:10px;margin-top:18px;">
          <button v-if="editingId" style="background:transparent;border:1px solid var(--c-danger-bg);color:var(--c-danger-soft);font-family:inherit;font-size:13px;font-weight:600;padding:9px 13px;border-radius:9px;cursor:pointer;" @click="remove">Excluir</button>
          <div style="flex:1;" />
          <button style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:13px;padding:9px 15px;border-radius:9px;cursor:pointer;" @click="open = false">Cancelar</button>
          <button class="wabtn" :disabled="saving || !form.name.trim()" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 18px;border-radius:9px;cursor:pointer;" @click="save">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </div>
    </div>

    <!-- Importar lista: planilha (CSV) ou leads que já estão no CRM -->
    <div v-if="importOpen" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;padding:24px;" @click.self="importOpen = false">
      <div style="width:460px;max-width:100%;background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:18px;padding:24px;">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px;">
          <div style="font-size:18px;font-weight:800;">Importar lista</div>
          <button style="background:none;border:none;color:var(--c-text-muted);cursor:pointer;font-size:20px;line-height:1;" @click="importOpen = false">×</button>
        </div>

        <div style="display:flex;gap:6px;margin-bottom:16px;">
          <button :style="{ flex:1,fontSize:'12.5px',fontWeight:700,padding:'8px',borderRadius:'9px',border:'none',cursor:'pointer',fontFamily:'inherit',background: importTab==='planilha' ? 'var(--accent)' : 'var(--c-surface-2)', color: importTab==='planilha' ? 'var(--accent-ink)' : 'var(--c-text-muted)' }" @click="importTab = 'planilha'">📄 Planilha</button>
          <button :style="{ flex:1,fontSize:'12.5px',fontWeight:700,padding:'8px',borderRadius:'9px',border:'none',cursor:'pointer',fontFamily:'inherit',background: importTab==='crm' ? 'var(--accent)' : 'var(--c-surface-2)', color: importTab==='crm' ? 'var(--accent-ink)' : 'var(--c-text-muted)' }" @click="importTab = 'crm'">💬 Do CRM</button>
        </div>

        <label class="lbl">Nome da lista</label>
        <input v-model="impNome" class="inp" placeholder="Ex.: Leads do anúncio julho">

        <template v-if="importTab === 'planilha'">
          <label class="lbl">Arquivo CSV</label>
          <input ref="impInput" type="file" accept=".csv,text/csv" style="width:100%;font-size:12.5px;color:var(--c-text-muted);font-family:inherit;" @change="escolherArquivo">
          <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:8px;line-height:1.5;">
            Precisa de uma coluna de telefone (<b>telefone</b>, <b>celular</b> ou <b>whatsapp</b>) e, se tiver, uma de <b>nome</b>. Sem DDI, assume Brasil. Quem já está na agenda não duplica.
          </div>
        </template>

        <template v-else>
          <label class="lbl">Etapa do funil (opcional)</label>
          <select v-model="impStage" class="inp" style="cursor:pointer;">
            <option value="">Todas as etapas</option>
            <option v-for="s in crm.stages" :key="s.key" :value="s.key">{{ s.name }}</option>
          </select>
          <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:12.5px;color:var(--c-text-secondary);cursor:pointer;">
            <input v-model="impSoAnuncio" type="checkbox" style="cursor:pointer;">
            Só quem veio de anúncio (clique-para-WhatsApp)
          </label>
          <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:8px;line-height:1.5;">
            Cria a lista com quem já conversou com a gente no WhatsApp — é assim que os leads de anúncio viram lista.
          </div>
        </template>

        <div v-if="impErro" style="margin-top:12px;font-size:12.5px;color:var(--c-danger-soft);">{{ impErro }}</div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;">
          <button style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:13px;padding:9px 15px;border-radius:9px;cursor:pointer;" @click="importOpen = false">Cancelar</button>
          <button class="wabtn" :disabled="impSalvando || !impNome.trim()" :style="{ background:'var(--accent)',border:'none',color:'var(--accent-ink)',fontFamily:'inherit',fontSize:'13px',fontWeight:700,padding:'9px 18px',borderRadius:'9px',cursor:'pointer',opacity:(impSalvando||!impNome.trim())?0.6:1 }" @click="importar">{{ impSalvando ? 'Importando…' : 'Importar' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: var(--accent-hi) !important; }
.ccard:hover { background: var(--c-surface-0) !important; border-color: var(--c-surface-3) !important; }
.cedit:hover { background: var(--c-surface-3) !important; color: var(--c-text) !important; }
.lrow:hover { background: var(--c-surface-0) !important; }
.ldel:hover { color: var(--c-danger) !important; }
.lbl { display:block; font-size:11.5px; color:var(--c-text-muted); font-weight:600; margin:11px 0 5px; }
.inp { width:100%; box-sizing:border-box; background:var(--c-surface-2); border:1px solid var(--c-surface-3); color:var(--c-text); font-family:inherit; font-size:13.5px; padding:9px 11px; border-radius:9px; outline:none; }
.inp:focus { border-color:var(--accent); }
</style>
