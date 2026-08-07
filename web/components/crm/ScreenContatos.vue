<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { type Contact, useCrmStore } from '~/stores/crm'

const api = useApi()
const crm = useCrmStore()
const search = ref('')

// ---- Listas de contatos ----------------------------------------------------
// A agenda deixou de ser "o que veio do Google": cada lista é um grupo (Google,
// planilha importada, leads do CRM) e é o que a campanha usa no disparo.
interface Lista { id: number, name: string, kind: string, auto?: boolean, contacts_count: number }
const listas = ref<Lista[]>([])
// null = tela de cards (as listas). 'todos' ou o id = dentro de uma lista, vendo contatos.
const vendo = ref<number | 'todos' | null>(null)
const listaAtiva = computed(() => typeof vendo.value === 'number' ? vendo.value : null)
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
async function abrirLista(id: number | 'todos') {
  vendo.value = id
  search.value = ''
  carregando.value = true
  try { await crm.loadContacts(typeof id === 'number' ? id : undefined) }
  finally { carregando.value = false }
}
function voltarParaListas() {
  vendo.value = null
  carregarListas()
}
const nomeDaLista = computed(() => listaAtiva.value ? (listas.value.find(l => l.id === listaAtiva.value)?.name || '') : 'Todos os contatos')
const listaAtivaObj = computed(() => listas.value.find(l => l.id === listaAtiva.value))
const kindDaLista = computed(() => listaAtivaObj.value?.kind || '')

// Lista automática guarda o critério e é reaplicada a cada 15 min — o lead que chegar
// amanhã entra sozinho. Dizer isso na tela evita a dúvida de "preciso puxar de novo?".
function descricaoDaLista(kind: string, auto = false) {
  if (auto && kind === 'anuncio') return 'Se alimenta sozinha: todo lead que chega pelo anúncio entra aqui'
  if (auto && kind === 'crm') return 'Se alimenta sozinha: todo lead novo que casa com o filtro entra aqui'
  return ({
    google: 'Sincronizada com a sua agenda do Google',
    planilha: 'Importada de planilha',
    crm: 'Gerada a partir das conversas do CRM (foto do momento em que foi criada)',
    anuncio: 'Leads que chegaram pelo anúncio',
  } as Record<string, string>)[kind] || 'Lista criada por você'
}

function iconeDaLista(kind: string) {
  return ({ google: '🔵', planilha: '📄', crm: '💬', anuncio: '📣' } as Record<string, string>)[kind] || '🏷️'
}

// ---- Importar planilha / puxar do CRM ----
const importOpen = ref(false)
const importTab = ref<'planilha' | 'crm'>('planilha')
const impNome = ref('')
const impArquivo = ref<File | null>(null)
const impStage = ref('')
// Triagem: '' = todas, '1' qualificados, '0' desqualificados, 'sem' ainda sem triagem.
// "Sem triagem" não é o mesmo que desqualificado — metade dos leads de anúncio nunca
// disse nada, e jogar esses no balaio dos ruins estragaria a lista de disparo.
const impQualificado = ref('')
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
  impQualificado.value = ''
  impSoAnuncio.value = false
  impErro.value = ''
}
function escolherArquivo(e: Event) {
  const f = (e.target as HTMLInputElement).files?.[0] || null
  impArquivo.value = f
  if (f && !impNome.value.trim()) impNome.value = f.name.replace(/\.[^.]+$/, '')
}
async function importar() {
  if (impSalvando.value) return
  if (!impSoAnuncio.value && !impNome.value.trim()) return
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
        body: {
          name: impSoAnuncio.value ? 'Leads de anúncio' : impNome.value.trim(),
          stage: impSoAnuncio.value ? undefined : (impStage.value || undefined),
          qualified: impSoAnuncio.value ? undefined : (impQualificado.value || undefined),
          somente_anuncio: impSoAnuncio.value,
        },
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
  if (listaAtiva.value === l.id) vendo.value = null
  await carregarListas()
}

onMounted(carregarListas)

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
  <div style="flex:1;display:flex;flex-direction:column;min-width:0;background:var(--c-bg-deep);">
    <!-- ================= CARDS DAS LISTAS ================= -->
    <template v-if="vendo === null">
      <div style="padding:22px 30px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--c-surface-1);flex-wrap:wrap;gap:10px;">
        <div>
          <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Contatos</div>
          <div style="font-size:13.5px;color:var(--c-text-muted);margin-top:3px;">{{ listas.length }} listas · {{ totalGeral }} contatos na agenda</div>
        </div>
        <div style="display:flex;gap:8px;">
          <button style="background:var(--c-surface-2);border:none;color:var(--c-text);font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;" @click="novaLista">+ Lista vazia</button>
          <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;" @click="abrirImport">📄 Importar lista</button>
        </div>
      </div>

      <div style="flex:1;overflow-y:auto;padding:22px 30px 30px;">
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:14px;">
          <!-- todos -->
          <div class="lcard" style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:18px;cursor:pointer;display:flex;flex-direction:column;gap:10px;" @click="abrirLista('todos')">
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:24px;">👥</span>
              <div style="flex:1;min-width:0;">
                <div style="font-size:15px;font-weight:800;">Todos os contatos</div>
                <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:2px;">A agenda inteira</div>
              </div>
            </div>
            <div style="font-size:26px;font-weight:800;letter-spacing:-1px;">{{ totalGeral }}</div>
          </div>

          <!-- listas -->
          <div v-for="l in listas" :key="l.id" class="lcard" style="background:var(--c-bg);border:1px solid var(--c-surface-1);border-radius:16px;padding:18px;cursor:pointer;display:flex;flex-direction:column;gap:10px;position:relative;" @click="abrirLista(l.id)">
            <button v-if="l.kind !== 'google'" class="ldel" title="Apagar lista" style="position:absolute;top:10px;right:12px;background:none;border:none;color:var(--c-text-faint);cursor:pointer;font-size:16px;line-height:1;" @click.stop="apagarLista(l)">×</button>
            <div style="display:flex;align-items:center;gap:10px;">
              <span style="font-size:24px;">{{ iconeDaLista(l.kind) }}</span>
              <div style="flex:1;min-width:0;padding-right:14px;">
                <div style="font-size:15px;font-weight:800;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">{{ l.name }}</div>
                <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:2px;line-height:1.35;">{{ descricaoDaLista(l.kind, l.auto) }}</div>
              </div>
            </div>
            <div style="font-size:26px;font-weight:800;letter-spacing:-1px;">{{ l.contacts_count }}</div>
          </div>

          <!-- criar -->
          <div class="lcard" style="background:transparent;border:1px dashed var(--c-surface-3);border-radius:16px;padding:18px;cursor:pointer;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;min-height:130px;color:var(--c-text-muted);" @click="abrirImport">
            <span style="font-size:24px;">＋</span>
            <div style="font-size:13px;font-weight:700;">Nova lista</div>
            <div style="font-size:11.5px;color:var(--c-text-faint);text-align:center;line-height:1.4;">Planilha ou leads que já estão no CRM</div>
          </div>
        </div>
      </div>
    </template>

    <!-- ================= CONTATOS DE UMA LISTA ================= -->
    <template v-else>
      <div style="padding:18px 30px;display:flex;align-items:center;gap:14px;border-bottom:1px solid var(--c-surface-1);flex-wrap:wrap;">
        <button class="lback" title="Voltar às listas" style="background:var(--c-surface-2);border:none;color:var(--c-text);width:34px;height:34px;border-radius:10px;cursor:pointer;font-size:15px;flex-shrink:0;" @click="voltarParaListas">←</button>
        <div style="flex:1;min-width:0;">
          <div style="font-size:20px;font-weight:800;letter-spacing:-.3px;display:flex;align-items:center;gap:8px;">
            <span v-if="listaAtiva">{{ iconeDaLista(kindDaLista) }}</span>{{ nomeDaLista }}
          </div>
          <div style="font-size:12.5px;color:var(--c-text-muted);margin-top:2px;">
            {{ crm.contacts.length }} contatos<template v-if="listaAtiva"> · {{ descricaoDaLista(kindDaLista, listaAtivaObj?.auto) }}</template>
          </div>
        </div>
        <button class="wabtn" style="background:var(--accent);border:none;color:var(--accent-ink);font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:6px;flex-shrink:0;" @click="openNew">
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
          <template v-if="search">Nenhum contato para essa busca.</template>
          <template v-else-if="listaAtiva">Esta lista ainda está vazia.</template>
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
    </template>

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

        <label v-if="!(importTab === 'crm' && impSoAnuncio)" class="lbl">Nome da lista</label>
        <input v-if="!(importTab === 'crm' && impSoAnuncio)" v-model="impNome" class="inp" placeholder="Ex.: Base de fornecedores">

        <template v-if="importTab === 'planilha'">
          <label class="lbl">Arquivo CSV</label>
          <input ref="impInput" type="file" accept=".csv,text/csv" style="width:100%;font-size:12.5px;color:var(--c-text-muted);font-family:inherit;" @change="escolherArquivo">
          <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:8px;line-height:1.5;">
            Precisa de uma coluna de telefone (<b>telefone</b>, <b>celular</b> ou <b>whatsapp</b>) e, se tiver, uma de <b>nome</b>. Sem DDI, assume Brasil. Quem já está na agenda não duplica.
          </div>
        </template>

        <template v-else>
          <label style="display:flex;align-items:center;gap:8px;margin-top:12px;font-size:12.5px;color:var(--c-text-secondary);cursor:pointer;">
            <input v-model="impSoAnuncio" type="checkbox" style="cursor:pointer;">
            📣 Só quem veio de anúncio (clique-para-WhatsApp)
          </label>

          <template v-if="impSoAnuncio">
            <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:10px;line-height:1.55;">
              Vai para a lista fixa <b>Leads de anúncio</b> — a mesma que se alimenta sozinha: todo lead novo que chegar pelo anúncio entra nela na hora. Rodar aqui só completa o histórico, sem duplicar ninguém.
            </div>
          </template>
          <template v-else>
            <label class="lbl">Etapa do funil (opcional)</label>
            <select v-model="impStage" class="inp" style="cursor:pointer;">
              <option value="">Todas as etapas</option>
              <option v-for="s in crm.stages" :key="s.key" :value="s.key">{{ s.name }}</option>
            </select>

            <label class="lbl">Triagem (opcional)</label>
            <select v-model="impQualificado" class="inp" style="cursor:pointer;">
              <option value="">Qualquer triagem</option>
              <option value="1">✓ Só leads qualificados</option>
              <option value="0">✗ Só desqualificados</option>
              <option value="sem">◌ Ainda sem triagem</option>
            </select>

            <div style="font-size:11.5px;color:var(--c-text-faint);margin-top:8px;line-height:1.5;">
              Cria a lista com quem já conversou com a gente no WhatsApp.
              <template v-if="impQualificado === '1'"> Todo lead que for qualificado daqui pra frente entra nela sozinho, na hora.</template>
              <template v-else-if="impQualificado === 'sem'"> Quem ainda não passou pela triagem. A lista só soma: quem entrar hoje continua nela depois de ser triado.</template>
            </div>
          </template>
        </template>

        <div v-if="impErro" style="margin-top:12px;font-size:12.5px;color:var(--c-danger-soft);">{{ impErro }}</div>

        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:18px;">
          <button style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:13px;padding:9px 15px;border-radius:9px;cursor:pointer;" @click="importOpen = false">Cancelar</button>
          <button class="wabtn" :disabled="impSalvando || (!impNome.trim() && !(importTab === 'crm' && impSoAnuncio))" :style="{ background:'var(--accent)',border:'none',color:'var(--accent-ink)',fontFamily:'inherit',fontSize:'13px',fontWeight:700,padding:'9px 18px',borderRadius:'9px',cursor:'pointer',opacity:(impSalvando || (!impNome.trim() && !(importTab === 'crm' && impSoAnuncio)))?0.6:1 }" @click="importar">{{ impSalvando ? 'Importando…' : 'Importar' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: var(--accent-hi) !important; }
.ccard:hover { background: var(--c-surface-0) !important; border-color: var(--c-surface-3) !important; }
.cedit:hover { background: var(--c-surface-3) !important; color: var(--c-text) !important; }
.lcard:hover { background: var(--c-surface-0) !important; border-color: var(--c-surface-3) !important; }
.lback:hover { background: var(--c-surface-3) !important; }
.ldel:hover { color: var(--c-danger) !important; }
.lbl { display:block; font-size:11.5px; color:var(--c-text-muted); font-weight:600; margin:11px 0 5px; }
.inp { width:100%; box-sizing:border-box; background:var(--c-surface-2); border:1px solid var(--c-surface-3); color:var(--c-text); font-family:inherit; font-size:13.5px; padding:9px 11px; border-radius:9px; outline:none; }
.inp:focus { border-color:var(--accent); }
</style>
