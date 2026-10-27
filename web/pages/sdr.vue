<script setup lang="ts">
// Agente SDR: lê as conversas de quem ainda não foi à call e decide quem procurar, quando e
// com que mensagem — até o lead comparecer. A tela é o contrato do que ele pode mandar em nome
// da empresa (as travas anti-spam) e o diário de cada mensagem que saiu.
import { useCrmStore } from '~/stores/crm'

const api = useApi()
const crm = useCrmStore()

interface Config {
  enabled: boolean; max_unanswered: number; min_hours_between: number; daily_cap: number; per_turn_cap: number
  instructions: string | null; learnings: { quando: string, texto: string }[] | null
  run_requested_at: string | null; run_requested_mode: string | null
  last_review_at: string | null; next_review_at: string | null; last_result: string | null
}
interface Sessao {
  id: number; simulated: boolean; started_at: string | null; status: string
  ferramentas_usadas: number; resumo: string | null; erro: string | null; passos?: { t: string, v: string }[]
}
interface Lead { id: number; slug: string; nome: string; categoria: string; etapa: string; ultima: string; reuniao: string | null; bloqueio: string | null; seguidas_sdr: number }
interface Msg { id: number; conversation_id: number; text: string; status: string | null; created_at: string; conversation: { id: number, slug: string, name: string } | null }
interface Resposta { config: Config; enviadas_hoje: number; dentro_do_horario: boolean; fila: Lead[]; mensagens: Msg[]; sessoes: Sessao[] }

const dados = ref<Resposta | null>(null)
const form = ref<Partial<Config>>({})
const carregando = ref(true)
const salvando = ref(false)
const erro = ref('')
const aviso = ref('')
const verFila = ref(false)

async function carregar() {
  carregando.value = true
  erro.value = ''
  try {
    const r = await api<Resposta>('/api/sdr')
    dados.value = r
    form.value = { ...r.config }
  } catch (e: any) {
    erro.value = e?.response?._data?.message || e?.message || 'Não consegui carregar.'
  } finally { carregando.value = false }
}
onMounted(carregar)

async function salvar(extra: Partial<Config> = {}) {
  salvando.value = true
  aviso.value = ''
  try {
    const f = form.value
    await api('/api/sdr', {
      method: 'PUT',
      body: {
        enabled: f.enabled, max_unanswered: Number(f.max_unanswered), min_hours_between: Number(f.min_hours_between),
        daily_cap: Number(f.daily_cap), per_turn_cap: Number(f.per_turn_cap), instructions: f.instructions || null, ...extra,
      },
    })
    aviso.value = 'Salvo.'
    await carregar()
  } catch (e: any) {
    aviso.value = e?.response?._data?.message || 'Não consegui salvar.'
  } finally { salvando.value = false }
}

async function alternar() {
  const ligar = !form.value.enabled
  const f = form.value
  if (ligar && !confirm(`Ligar o SDR?\n\nEle vai mandar mensagens pelo WhatsApp em nome da empresa, sozinho, em horário de atendimento — no máximo ${f.max_unanswered} seguidas sem resposta por lead, com ${f.min_hours_between}h entre elas, e até ${f.daily_cap} por dia.\n\nRecomendado: rode uma simulação antes e leia o que ele faria.`)) return
  form.value.enabled = ligar
  await salvar({ enabled: ligar })
}

async function rodar(modo: 'simular' | 'real') {
  if (modo === 'real' && !confirm('Rodar um turno de verdade agora? Ele pode mandar mensagens aos leads.')) return
  try {
    const r = await api<{ ok: boolean, mensagem: string }>('/api/sdr/rodar', { method: 'POST', body: { modo } })
    aviso.value = r.mensagem
    await carregar()
  } catch (e: any) {
    aviso.value = e?.response?._data?.mensagem || e?.response?._data?.message || 'Não consegui pedir o turno.'
  }
}

const aberta = ref<Sessao | null>(null)
async function abrirSessao(s: Sessao) {
  if (aberta.value?.id === s.id) { aberta.value = null; return }
  aberta.value = await api<Sessao>(`/api/sdr/sessoes/${s.id}`)
}
const rotuloPasso: Record<string, string> = { pensou: '💭', usou: '🔧', viu: '👁' }
const quando = (s: string | null) => s ? new Date(s).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'
function abrir(slug?: string) {
  if (!slug) return
  crm.selectConv(slug)
  crm.go('chat')
}
const podem = computed(() => dados.value?.fila.filter(l => !l.bloqueio).length ?? 0)

const cartao = 'background:var(--c-bg-deepest);border:1px solid var(--c-surface-1);border-radius:12px;padding:14px 16px;'
const campo = 'width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;'
const rotulo = 'display:block;font-size:10.5px;font-weight:800;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.03em;margin-bottom:5px;'
const botao = 'background:var(--c-surface-2);border:none;color:var(--c-text-secondary);font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;cursor:pointer;'
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <div class="r-wrap r-pad" style="padding-block:16px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px;">📞</div>
      <div style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Agente SDR</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Vigia as conversas e mantém o lead aquecido até ele comparecer à call</div>
      </div>
    </div>

    <div class="r-page r-pad" style="--r-page:1100px;padding-block:20px;">
      <div v-if="carregando && !dados" style="font-size:12.5px;color:var(--c-text-faint);">Carregando…</div>
      <div v-else-if="erro" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-danger-soft);">{{ erro }}</div>

      <template v-else-if="dados">
        <div :style="cartao + 'display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px;'">
          <button
            class="r-tap" :disabled="salvando" @click="alternar"
            :style="{ background: form.enabled ? 'var(--c-teal)' : 'var(--c-surface-2)', color: form.enabled ? '#fff' : 'var(--c-text-secondary)', border: 'none', fontFamily: 'inherit', fontSize: '13px', fontWeight: 800, padding: '10px 16px', borderRadius: '10px', cursor: 'pointer' }"
          >{{ form.enabled ? '● LIGADO' : '○ DESLIGADO' }}</button>
          <div style="font-size:12px;color:var(--c-text-secondary);line-height:1.6;flex:1;min-width:220px;">
            <template v-if="form.enabled">Ele escolhe quando volta (30 min–4h), só em horário de atendimento ({{ dados.dentro_do_horario ? 'aberto agora' : 'fechado agora' }}), e é acordado na hora quando um lead falta à reunião. Último turno: {{ quando(form.last_review_at ?? null) }} · próximo: {{ quando(form.next_review_at ?? null) }}.</template>
            <template v-else>Desligado, ele não manda nada. Use <strong>Simular agora</strong> para ver um turno completo: ele lê a fila e as conversas e decide de verdade, mas nada é enviado.</template>
            <div v-if="form.run_requested_at" style="color:var(--c-warn-hi);">Turno pedido ({{ form.run_requested_mode }}) — começa em até 5 min.</div>
            <div v-else-if="form.last_result" style="color:var(--c-text-faint);">{{ form.last_result }}</div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button class="r-tap" :style="botao" @click="rodar('simular')">Simular agora</button>
            <button class="r-tap" :style="botao" :disabled="!form.enabled" @click="rodar('real')">Rodar agora</button>
          </div>
        </div>

        <div v-if="aviso" style="font-size:12px;color:var(--c-text-secondary);margin-bottom:12px;">{{ aviso }}</div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:10px;margin-bottom:14px;">
          <div :style="cartao + 'cursor:pointer;'" @click="verFila = !verFila">
            <div :style="rotulo">Leads até a call</div>
            <div style="font-size:20px;font-weight:800;">{{ dados.fila.length }}</div>
            <div style="font-size:10.5px;color:var(--c-text-faint);">{{ podem }} podem receber agora · {{ verFila ? 'ocultar' : 'ver a fila' }}</div>
          </div>
          <div :style="cartao">
            <div :style="rotulo">Mensagens hoje</div>
            <div style="font-size:20px;font-weight:800;">{{ dados.enviadas_hoje }} <span style="font-size:13px;color:var(--c-text-faint);">/ {{ form.daily_cap }}</span></div>
          </div>
        </div>

        <div v-if="verFila" :style="cartao + 'padding:0;overflow:hidden;margin-bottom:14px;'">
          <div v-for="l in dados.fila" :key="l.id" style="padding:9px 16px;border-top:1px solid var(--c-surface-1);font-size:12px;line-height:1.5;">
            <a style="font-weight:800;color:var(--c-text);cursor:pointer;" @click="abrir(l.slug)">{{ l.nome || `#${l.id}` }}</a>
            <span style="color:var(--c-text-faint);"> · {{ l.categoria }} · {{ l.etapa }} · última {{ l.ultima }}</span>
            <span v-if="l.reuniao" style="color:var(--c-teal);"> · reunião {{ l.reuniao }}</span>
            <div :style="{ color: l.bloqueio ? 'var(--c-text-faint)' : 'var(--c-teal)', fontSize: '11px' }">{{ l.bloqueio ? 'não pode agora: ' + l.bloqueio : 'pode receber' }}</div>
          </div>
        </div>

        <!-- Travas: o que ele não passa, nem que queira. -->
        <div :style="cartao + 'margin-bottom:14px;'">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:4px;">Travas anti-spam</div>
          <div style="font-size:11.5px;color:var(--c-text-faint);margin-bottom:12px;line-height:1.5;">Ficam no código: o agente não consegue passar delas. Existem porque em 14/08 a Meta travou o número por insistência com quem não respondia.</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(180px,100%),1fr));gap:10px;">
            <label><span :style="rotulo">Seguidas sem resposta (por lead)</span><input v-model="form.max_unanswered" type="number" min="1" max="4" :style="campo"></label>
            <label><span :style="rotulo">Horas entre elas (sem resposta)</span><input v-model="form.min_hours_between" type="number" min="4" max="168" :style="campo"></label>
            <label><span :style="rotulo">Máximo por dia (empresa)</span><input v-model="form.daily_cap" type="number" min="1" max="80" :style="campo"></label>
            <label><span :style="rotulo">Máximo por turno</span><input v-model="form.per_turn_cap" type="number" min="1" max="30" :style="campo"></label>
          </div>
          <label style="display:block;margin-top:12px;"><span :style="rotulo">Instruções para o SDR</span>
            <textarea v-model="form.instructions" rows="5" :style="campo + 'resize:vertical;line-height:1.5;'" placeholder="Ex.: confirmar a reunião sempre na véspera às 18h; nunca falar de preço antes da call; leads de anúncio X são mais frios…" />
          </label>
          <div style="display:flex;justify-content:flex-end;margin-top:10px;">
            <button class="r-tap" :disabled="salvando" :style="botao + 'background:var(--accent);color:#fff;'" @click="salvar()">{{ salvando ? 'Salvando…' : 'Salvar' }}</button>
          </div>
          <div v-if="form.learnings?.length" style="margin-top:12px;font-size:11.5px;line-height:1.55;color:var(--c-text-secondary);">
            <div :style="rotulo">O que ele aprendeu</div>
            <div v-for="(l, i) in form.learnings" :key="i">{{ i + 1 }}. [{{ l.quando }}] {{ l.texto }}</div>
          </div>
        </div>

        <!-- Turnos -->
        <div :style="cartao + 'padding:0;overflow:hidden;margin-bottom:14px;'">
          <div style="padding:13px 16px;border-bottom:1px solid var(--c-surface-1);font-weight:800;font-size:13.5px;">Turnos</div>
          <div v-if="!dados.sessoes.length" style="padding:14px 16px;font-size:12px;color:var(--c-text-faint);">Nenhum turno ainda.</div>
          <div v-for="t in dados.sessoes" :key="t.id" style="border-top:1px solid var(--c-surface-1);">
            <div class="r-tap" style="padding:10px 16px;cursor:pointer;display:flex;gap:10px;align-items:baseline;flex-wrap:wrap;font-size:12px;" @click="abrirSessao(t)">
              <strong>{{ quando(t.started_at) }}</strong>
              <span v-if="t.simulated" style="color:var(--c-warn-hi);">simulação</span>
              <span :style="{ fontWeight: 800, color: t.status === 'ok' ? 'var(--c-teal)' : t.status === 'rodando' ? 'var(--c-warn-hi)' : 'var(--c-danger-soft)' }">{{ t.status }}</span>
              <span style="color:var(--c-text-faint);">{{ t.ferramentas_usadas }} consulta(s)/ação(ões)</span>
              <span style="flex:1;" />
              <span style="color:var(--c-text-faint);">{{ aberta?.id === t.id ? 'fechar' : 'ver o que ele fez' }}</span>
            </div>
            <div v-if="t.resumo || t.erro" style="padding:0 16px 10px;font-size:12px;line-height:1.6;white-space:pre-line;color:var(--c-text-secondary);">{{ t.resumo || t.erro }}</div>
            <div v-if="aberta?.id === t.id" style="padding:0 16px 12px;">
              <div v-for="(p, i) in aberta.passos || []" :key="i" style="display:flex;gap:8px;font-size:11.5px;line-height:1.5;padding:4px 0;border-top:1px dashed var(--c-surface-1);">
                <span>{{ rotuloPasso[p.t] || '·' }}</span>
                <span :style="{ flex: 1, minWidth: 0, whiteSpace: 'pre-wrap', wordBreak: 'break-word', color: p.t === 'pensou' ? 'var(--c-text)' : 'var(--c-text-muted)', fontFamily: p.t === 'pensou' ? 'inherit' : 'ui-monospace,monospace' }">{{ p.v }}</span>
              </div>
            </div>
          </div>
        </div>

        <!-- Mensagens que saíram -->
        <div :style="cartao + 'padding:0;overflow:hidden;'">
          <div style="padding:13px 16px;border-bottom:1px solid var(--c-surface-1);font-weight:800;font-size:13.5px;">Mensagens que ele mandou</div>
          <div v-if="!dados.mensagens.length" style="padding:14px 16px;font-size:12px;color:var(--c-text-faint);">Nenhuma ainda.</div>
          <div v-for="m in dados.mensagens" :key="m.id" style="padding:9px 16px;border-top:1px solid var(--c-surface-1);font-size:12px;line-height:1.5;">
            <span style="color:var(--c-text-faint);">{{ quando(m.created_at) }} · </span>
            <a style="font-weight:800;color:var(--c-text);cursor:pointer;" @click="abrir(m.conversation?.slug)">{{ m.conversation?.name || `#${m.conversation_id}` }}</a>
            <span v-if="m.status === 'error'" style="color:var(--c-danger-soft);"> · não entregue</span>
            <div style="white-space:pre-line;color:var(--c-text-secondary);">{{ m.text }}</div>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
