<script setup lang="ts">
import { computed, onMounted, reactive, ref } from 'vue'
import { useRoute, useRouter } from 'vue-router'
import { type CalEvent, useCrmStore } from '~/stores/crm'

const crm = useCrmStore()
const route = useRoute()
const router = useRouter()

// Cores em rotação para diferenciar visualmente os eventos.
const PALETTE = [
  { bar: '#53bdeb', bg: 'rgba(83,189,235,.16)', fg: '#9fdcf5' },
  { bar: '#7c6cf5', bg: 'rgba(124,108,245,.16)', fg: '#c0b6f9' },
  { bar: '#ffb443', bg: 'rgba(255,180,67,.16)', fg: '#ffd494' },
  { bar: '#25D366', bg: 'rgba(37,211,102,.16)', fg: '#7ee6a8' },
]
const DAY_NAMES = ['DOM', 'SEG', 'TER', 'QUA', 'QUI', 'SEX', 'SÁB']
const MONTHS = ['Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro']
const START_HOUR = 7
const END_HOUR = 21
const HOUR_H = 58 // px por hora

const HOURS = Array.from({ length: END_HOUR - START_HOUR }, (_, i) => START_HOUR + i)

// ----- Semana atual (segunda como início) -----
function startOfWeek(d: Date): Date {
  const x = new Date(d)
  x.setHours(0, 0, 0, 0)
  const dow = x.getDay() // 0=dom
  const diff = (dow === 0 ? -6 : 1) - dow // volta até segunda
  x.setDate(x.getDate() + diff)
  return x
}
const weekStart = ref(startOfWeek(new Date()))

const weekDays = computed(() => Array.from({ length: 7 }, (_, i) => {
  const d = new Date(weekStart.value)
  d.setDate(d.getDate() + i)
  return d
}))

const rangeLabel = computed(() => {
  const a = weekDays.value[0]
  const b = weekDays.value[6]
  const mesA = MONTHS[a.getMonth()]
  const mesB = MONTHS[b.getMonth()]
  if (a.getMonth() === b.getMonth())
    return `${a.getDate()} – ${b.getDate()} de ${mesA}, ${b.getFullYear()}`
  return `${a.getDate()} ${mesA} – ${b.getDate()} ${mesB}, ${b.getFullYear()}`
})

function isToday(d: Date) {
  const t = new Date()
  return d.getFullYear() === t.getFullYear() && d.getMonth() === t.getMonth() && d.getDate() === t.getDate()
}
function sameDay(a: Date, b: Date) {
  return a.getFullYear() === b.getFullYear() && a.getMonth() === b.getMonth() && a.getDate() === b.getDate()
}

// ----- Carga -----
async function loadWeek() {
  const from = new Date(weekDays.value[0]); from.setHours(0, 0, 0, 0)
  const to = new Date(weekDays.value[6]); to.setHours(23, 59, 59, 999)
  await crm.fetchEvents(from.toISOString(), to.toISOString())
}
function shiftWeek(delta: number) {
  const d = new Date(weekStart.value)
  d.setDate(d.getDate() + delta * 7)
  weekStart.value = d
  loadWeek()
}
function goToday() {
  weekStart.value = startOfWeek(new Date())
  loadWeek()
}

const banner = ref<{ type: 'ok' | 'erro', text: string } | null>(null)

onMounted(async () => {
  await crm.loadGoogleStatus()
  // Feedback do retorno do OAuth (?google=conectado|erro).
  const g = route.query.google
  if (g === 'conectado') {
    banner.value = { type: 'ok', text: 'Google Agenda conectado!' }
    await crm.loadGoogleStatus()
  }
  else if (g === 'erro') {
    banner.value = { type: 'erro', text: 'Não foi possível conectar o Google. Tente de novo.' }
  }
  if (g) router.replace({ query: {} })
  if (crm.googleConnected) await loadWeek()
})

// ----- Posicionamento dos eventos na grade -----
interface Positioned { ev: CalEvent, top: number, height: number, color: typeof PALETTE[number], label: string }

function colorFor(ev: CalEvent) {
  // Eventos vindos de tarefas do CRM ganham o verde; resto, hash pela id.
  if (ev.task_id) return PALETTE[3]
  let h = 0
  for (const c of ev.id) h = (h + c.charCodeAt(0)) % PALETTE.length
  return PALETTE[h]
}
function hm(iso: string | null) {
  if (!iso) return ''
  const d = new Date(iso)
  return d.toLocaleTimeString('pt-BR', { hour: '2-digit', minute: '2-digit' })
}

function eventsForDay(day: Date): Positioned[] {
  const out: Positioned[] = []
  for (const ev of crm.events) {
    if (!ev.starts_at) continue
    const s = new Date(ev.starts_at)
    if (!sameDay(s, day) || ev.all_day) continue
    const e = ev.ends_at ? new Date(ev.ends_at) : new Date(s.getTime() + 3600_000)
    const startH = s.getHours() + s.getMinutes() / 60
    const endH = e.getHours() + e.getMinutes() / 60
    const top = Math.max(0, (startH - START_HOUR) * HOUR_H)
    const height = Math.max(26, (Math.min(endH, END_HOUR) - Math.max(startH, START_HOUR)) * HOUR_H - 3)
    out.push({ ev, top, height, color: colorFor(ev), label: `${hm(ev.starts_at)} – ${hm(ev.ends_at)}` })
  }
  return out
}

const allDayFor = (day: Date) => crm.events.filter(ev => ev.starts_at && ev.all_day && sameDay(new Date(ev.starts_at), day))

// Lista lateral: eventos de hoje, ordenados por horário.
const todayEvents = computed(() => crm.events
  .filter(ev => ev.starts_at && isToday(new Date(ev.starts_at)))
  .sort((a, b) => new Date(a.starts_at!).getTime() - new Date(b.starts_at!).getTime()))
const today = new Date()

// ----- Modal de evento -----
const modalOpen = ref(false)
const saving = ref(false)
const editingId = ref<string | null>(null)
const form = reactive({ title: '', date: '', start: '09:00', end: '10:00', location: '', description: '', deal_id: '' as string | number, guests: '', add_meet: false })
// Link do Meet do evento em edição (se já existir).
const editingMeet = ref<string | null>(null)

// Quebra a lista de convidados (vírgula / espaço / quebra de linha) em e-mails válidos.
function parseGuests(raw: string): string[] {
  return raw.split(/[\s,;]+/).map(s => s.trim()).filter(s => /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(s))
}

function ymd(d: Date) {
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}
function openNew(day?: Date) {
  editingId.value = null
  editingMeet.value = null
  const base = day ?? new Date()
  Object.assign(form, { title: '', date: ymd(base), start: '09:00', end: '10:00', location: '', description: '', deal_id: '', guests: '', add_meet: false })
  modalOpen.value = true
}
function openEdit(ev: CalEvent) {
  editingId.value = ev.id
  editingMeet.value = ev.hangout_link ?? null
  const s = ev.starts_at ? new Date(ev.starts_at) : new Date()
  const e = ev.ends_at ? new Date(ev.ends_at) : new Date(s.getTime() + 3600_000)
  const hhmm = (d: Date) => `${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}`
  Object.assign(form, {
    title: ev.title, date: ymd(s), start: hhmm(s), end: hhmm(e),
    location: ev.location ?? '', description: ev.description ?? '', deal_id: ev.deal_id ?? '',
    // Convidados existentes (sem o organizador) viram texto editável.
    guests: (ev.attendees ?? []).filter(a => !a.organizer).map(a => a.email).join(', '),
    add_meet: !!ev.hangout_link,
  })
  modalOpen.value = true
}
function closeModal() { modalOpen.value = false }

async function save() {
  if (!form.title.trim() || !form.date) return
  saving.value = true
  // Envia datetime local (sem timezone) — o servidor interpreta em America/Sao_Paulo.
  const payload: Partial<CalEvent> & { deal_id?: number | null, attendees?: string[], add_meet?: boolean } = {
    title: form.title.trim(),
    location: form.location.trim() || null,
    description: form.description.trim() || null,
    starts_at: `${form.date}T${form.start}:00`,
    ends_at: `${form.date}T${form.end}:00`,
    deal_id: form.deal_id ? Number(form.deal_id) : null,
    attendees: parseGuests(form.guests),
    add_meet: form.add_meet,
  }
  try {
    if (editingId.value) await crm.updateEvent(editingId.value, payload)
    else await crm.createEvent(payload)
    modalOpen.value = false
  }
  catch { banner.value = { type: 'erro', text: 'Erro ao salvar o evento.' } }
  finally { saving.value = false }
}
async function removeEvent() {
  if (!editingId.value) return
  saving.value = true
  await crm.deleteEvent(editingId.value)
  saving.value = false
  modalOpen.value = false
}
</script>

<template>
  <div style="flex:1;display:flex;min-width:0;background:#0b141a;">
    <div style="flex:1;display:flex;flex-direction:column;min-width:0;">
      <!-- Cabeçalho -->
      <div style="padding:22px 30px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #1c2730;">
        <div>
          <div style="font-size:23px;font-weight:800;letter-spacing:-.3px;">Agenda</div>
          <div style="font-size:13.5px;color:#8696a0;margin-top:3px;">{{ rangeLabel }}</div>
        </div>
        <div v-if="crm.googleConnected" style="display:flex;gap:10px;align-items:center;">
          <div style="display:flex;background:#202c33;border-radius:10px;overflow:hidden;">
            <button class="seg" style="border:none;background:transparent;color:#8696a0;padding:8px 11px;cursor:pointer;" @click="shiftWeek(-1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m15 6-6 6 6 6" /></svg></button>
            <button class="seg" style="border:none;background:transparent;color:#8696a0;padding:8px 11px;cursor:pointer;" @click="shiftWeek(1)"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="m9 6 6 6-6 6" /></svg></button>
          </div>
          <button class="seg" style="border:none;background:#202c33;border-radius:10px;color:#8696a0;font-family:inherit;font-size:12.5px;font-weight:600;padding:8px 13px;cursor:pointer;" @click="goToday">Hoje</button>
          <span v-if="crm.googleEmail" title="Conta Google conectada" style="font-size:12px;color:#5fb585;background:#16241c;border:1px solid rgba(37,211,102,.25);padding:6px 11px;border-radius:9px;">{{ crm.googleEmail }}</span>
          <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:9px 15px;border-radius:10px;cursor:pointer;display:flex;align-items:center;gap:6px;" @click="openNew()"><svg width="15" height="15" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.4"><path d="M12 5v14M5 12h14" stroke-linecap="round" /></svg>Evento</button>
        </div>
      </div>

      <div v-if="banner" :style="`margin:14px 30px 0;padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;${banner.type === 'ok' ? 'background:#16241c;color:#7ee6a8;border:1px solid rgba(37,211,102,.3);' : 'background:#2a1518;color:#ff9a9a;border:1px solid rgba(255,107,107,.3);'}`">
        {{ banner.text }}
      </div>

      <!-- Não conectado: CTA -->
      <div v-if="!crm.googleConnected" style="flex:1;display:flex;align-items:center;justify-content:center;flex-direction:column;gap:18px;padding:40px;">
        <div style="width:64px;height:64px;border-radius:18px;background:#202c33;display:flex;align-items:center;justify-content:center;">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="#25D366" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" /><path d="M16 2v4M8 2v4M3 10h18" /></svg>
        </div>
        <div style="text-align:center;max-width:360px;">
          <div style="font-size:18px;font-weight:700;">Conecte seu Google Agenda</div>
          <div style="font-size:13.5px;color:#8696a0;margin-top:6px;line-height:1.5;">Veja e gerencie seus eventos aqui, e sincronize automaticamente as tarefas agendadas do CRM.</div>
        </div>
        <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:14px;font-weight:700;padding:11px 20px;border-radius:11px;cursor:pointer;" @click="crm.connectGoogle()">Conectar com Google</button>
      </div>

      <!-- Grade da semana -->
      <div v-else style="flex:1;overflow:auto;padding:0 30px 24px;">
        <!-- Cabeçalho dos dias -->
        <div style="display:grid;grid-template-columns:54px repeat(7,1fr);position:sticky;top:0;background:#0b141a;z-index:3;padding-top:14px;">
          <div />
          <div v-for="d in weekDays" :key="d.toISOString()" style="text-align:center;padding-bottom:12px;">
            <div :style="`font-size:11.5px;${isToday(d) ? 'color:#25D366;font-weight:700;' : 'color:#8696a0;'}`">{{ DAY_NAMES[d.getDay()] }}</div>
            <div v-if="isToday(d)" style="width:34px;height:34px;border-radius:50%;background:#25D366;color:#062014;font-size:17px;font-weight:800;display:flex;align-items:center;justify-content:center;margin:2px auto 0;">{{ d.getDate() }}</div>
            <div v-else style="font-size:18px;font-weight:700;margin-top:2px;">{{ d.getDate() }}</div>
            <!-- Eventos de dia inteiro -->
            <div v-for="ev in allDayFor(d)" :key="ev.id" style="margin-top:4px;background:rgba(83,189,235,.16);border-radius:6px;padding:3px 5px;font-size:10px;color:#9fdcf5;cursor:pointer;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" @click="openEdit(ev)">{{ ev.title }}</div>
          </div>
        </div>

        <!-- Linhas de hora + colunas -->
        <div style="position:relative;">
          <div v-for="h in HOURS" :key="h" style="display:grid;grid-template-columns:54px repeat(7,1fr);">
            <div :style="`font-size:11px;color:#8696a0;text-align:right;padding:0 10px;height:${HOUR_H}px;border-top:1px solid #16222a;`">{{ String(h).padStart(2, '0') }}:00</div>
            <div v-for="d in weekDays" :key="h + d.toISOString()" style="border-top:1px solid #16222a;border-left:1px solid #16222a;cursor:pointer;" @click="openNew(d)" />
          </div>

          <!-- Camada dos eventos posicionados -->
          <div style="position:absolute;inset:0;display:grid;grid-template-columns:54px repeat(7,1fr);pointer-events:none;">
            <div />
            <div v-for="d in weekDays" :key="`col${d.toISOString()}`" style="position:relative;">
              <div
                v-for="p in eventsForDay(d)" :key="p.ev.id"
                :style="`pointer-events:auto;position:absolute;left:3px;right:3px;top:${p.top}px;height:${p.height}px;background:${p.color.bg};border-left:3px solid ${p.color.bar};border-radius:7px;padding:5px 8px;overflow:hidden;cursor:pointer;`"
                @click="openEdit(p.ev)"
              >
                <div :style="`font-size:12px;font-weight:700;color:${p.color.fg};overflow:hidden;text-overflow:ellipsis;white-space:nowrap;`">{{ p.ev.title }}</div>
                <div style="font-size:10.5px;color:#8696a0;margin-top:1px;">{{ p.label }}</div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>

    <!-- Painel lateral: hoje -->
    <div v-if="crm.googleConnected" style="width:300px;flex-shrink:0;background:#111b21;border-left:1px solid #1c2730;display:flex;flex-direction:column;">
      <div style="padding:20px 20px 14px;border-bottom:1px solid #1c2730;">
        <div style="font-weight:700;font-size:16px;">Hoje · {{ todayEvents.length }} {{ todayEvents.length === 1 ? 'evento' : 'eventos' }}</div>
        <div style="font-size:12.5px;color:#8696a0;margin-top:2px;">{{ DAY_NAMES[today.getDay()] }}, {{ today.getDate() }} de {{ MONTHS[today.getMonth()] }}</div>
      </div>
      <div style="flex:1;overflow-y:auto;padding:16px;display:flex;flex-direction:column;gap:11px;">
        <div v-if="!todayEvents.length" style="font-size:13px;color:#8696a0;text-align:center;margin-top:30px;">Nada agendado para hoje.</div>
        <div
          v-for="ev in todayEvents" :key="ev.id"
          style="background:#202c33;border-radius:13px;padding:14px;cursor:pointer;"
          @click="openEdit(ev)"
        >
          <div style="font-size:11px;font-weight:700;color:#8696a0;">{{ ev.all_day ? 'Dia inteiro' : hm(ev.starts_at) }}</div>
          <div style="font-weight:700;font-size:14px;margin-top:6px;">{{ ev.title }}</div>
          <div v-if="ev.location" style="font-size:12px;color:#8696a0;margin-top:3px;">{{ ev.location }}</div>
          <div v-if="ev.attendees && ev.attendees.length" style="font-size:12px;color:#8696a0;margin-top:3px;">👤 {{ ev.attendees.length }} {{ ev.attendees.length === 1 ? 'convidado' : 'convidados' }}</div>
          <a v-if="ev.hangout_link" :href="ev.hangout_link" target="_blank" style="display:inline-block;margin-top:8px;font-size:12px;color:#25D366;text-decoration:none;" @click.stop>Entrar na reunião →</a>
        </div>
      </div>
      <div style="padding:14px 16px;border-top:1px solid #1c2730;">
        <button style="width:100%;background:transparent;border:1px solid #2a3942;color:#8696a0;font-family:inherit;font-size:12.5px;padding:9px;border-radius:9px;cursor:pointer;" @click="crm.disconnectGoogle()">Desconectar Google</button>
      </div>
    </div>

    <!-- Modal criar/editar evento -->
    <div v-if="modalOpen" style="position:fixed;inset:0;background:rgba(0,0,0,.55);display:flex;align-items:center;justify-content:center;z-index:50;" @click.self="closeModal">
      <div style="width:420px;max-width:92vw;background:#111b21;border:1px solid #1c2730;border-radius:16px;padding:22px;">
        <div style="font-size:17px;font-weight:800;margin-bottom:16px;">{{ editingId ? 'Editar evento' : 'Novo evento' }}</div>
        <label class="lbl">Título</label>
        <input v-model="form.title" class="inp" placeholder="Ex.: Demo com cliente" >
        <div style="display:flex;gap:10px;">
          <div style="flex:1;"><label class="lbl">Data</label><input v-model="form.date" type="date" class="inp" ></div>
          <div style="width:90px;"><label class="lbl">Início</label><input v-model="form.start" type="time" class="inp" ></div>
          <div style="width:90px;"><label class="lbl">Fim</label><input v-model="form.end" type="time" class="inp" ></div>
        </div>
        <label class="lbl">Local / link</label>
        <input v-model="form.location" class="inp" placeholder="Sala, endereço ou link" >

        <label class="lbl">Convidados (e-mails separados por vírgula)</label>
        <textarea v-model="form.guests" class="inp" rows="2" placeholder="fulano@email.com, ciclano@email.com" />

        <label class="meet-toggle">
          <input v-model="form.add_meet" type="checkbox" >
          <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M15 10l4.5-2.5v9L15 14M3 6h12v12H3z" stroke-linejoin="round" /></svg>
          Adicionar videochamada do Google Meet
        </label>
        <a v-if="editingMeet" :href="editingMeet" target="_blank" style="display:block;margin-top:8px;font-size:12.5px;color:#25D366;text-decoration:none;">🎥 {{ editingMeet }}</a>

        <label class="lbl">Negócio vinculado (opcional)</label>
        <select v-model="form.deal_id" class="inp">
          <option value="">— Nenhum —</option>
          <option v-for="d in crm.dealList" :key="d.id" :value="d.id">{{ d.name }}</option>
        </select>
        <label class="lbl">Descrição</label>
        <textarea v-model="form.description" class="inp" rows="2" placeholder="Detalhes" />

        <div style="display:flex;align-items:center;gap:10px;margin-top:18px;">
          <button v-if="editingId" style="background:transparent;border:1px solid #5a2630;color:#ff9a9a;font-family:inherit;font-size:13px;font-weight:600;padding:9px 13px;border-radius:9px;cursor:pointer;" :disabled="saving" @click="removeEvent">Excluir</button>
          <div style="flex:1;" />
          <button style="background:transparent;border:1px solid #2a3942;color:#8696a0;font-family:inherit;font-size:13px;padding:9px 15px;border-radius:9px;cursor:pointer;" @click="closeModal">Cancelar</button>
          <button class="wabtn" style="background:#25D366;border:none;color:#062014;font-family:inherit;font-size:13px;font-weight:700;padding:9px 18px;border-radius:9px;cursor:pointer;" :disabled="saving" @click="save">{{ saving ? 'Salvando…' : 'Salvar' }}</button>
        </div>
      </div>
    </div>
  </div>
</template>

<style scoped>
.wabtn:hover { background: #2ee070 !important; }
.seg:hover { background: #2a3942 !important; }
.lbl { display:block; font-size:11.5px; color:#8696a0; font-weight:600; margin:11px 0 5px; }
.inp {
  width:100%; box-sizing:border-box; background:#202c33; border:1px solid #2a3942; color:#e9edef;
  font-family:inherit; font-size:13.5px; padding:9px 11px; border-radius:9px; outline:none;
}
.inp:focus { border-color:#25D366; }
.meet-toggle {
  display:flex; align-items:center; gap:8px; margin-top:13px; cursor:pointer;
  font-size:13px; color:#c7d1d6; user-select:none;
}
.meet-toggle input { width:16px; height:16px; accent-color:#25D366; cursor:pointer; }
.meet-toggle svg { color:#25D366; }
</style>
