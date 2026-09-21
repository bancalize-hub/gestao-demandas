<script setup lang="ts">
const api = useApi()

interface Campanha {
  id: string
  name: string
  objective: string | null
  status: string
  effective_status: string
  daily_budget: string | null
  lifetime_budget: string | null
  created_time: string
}

interface Metrica {
  campaign_id?: string
  campaign_name?: string
  impressions?: string
  clicks?: string
  ctr?: string
  spend?: string
  cpc?: string
  cpm?: string
  actions?: { action_type: string; value: string }[]
}

const periodos: { label: string; value: string }[] = [
  { label: 'Hoje', value: 'today' },
  { label: '7 dias', value: 'last_7d' },
  { label: '30 dias', value: 'last_30d' },
  { label: 'Este mês', value: 'this_month' },
]

const campanhas = ref<Campanha[]>([])
const metricas = ref<Record<string, Metrica>>({})
const periodo = ref('last_7d')
const loading = ref(true)
const erroMetricas = ref('')

const editandoOrcamento = ref<Record<string, string>>({})
const salvandoOrcamento = ref<Record<string, boolean>>({})
const salvandoStatus = ref<Record<string, boolean>>({})

async function load() {
  loading.value = true
  erroMetricas.value = ''
  try {
    const [c, m] = await Promise.all([
      api<{ campanhas: Campanha[] }>('/api/marketing/campanhas'),
      api<{ metricas: Metrica[]; erro?: string }>(`/api/marketing/metricas?nivel=campaign&periodo=${periodo.value}`),
    ])
    campanhas.value = c.campanhas || []
    if (m.erro) erroMetricas.value = m.erro
    const map: Record<string, Metrica> = {}
    for (const r of m.metricas || []) {
      if (r.campaign_id) map[r.campaign_id] = r
    }
    metricas.value = map
  }
  catch {
    campanhas.value = []
  }
  finally {
    loading.value = false
  }
}

watch(periodo, load)
onMounted(load)

function fmt(c: Campanha): Metrica {
  return metricas.value[c.id] || {}
}

function gastoTotal() {
  let t = 0
  for (const m of Object.values(metricas.value)) {
    t += parseFloat(m.spend || '0')
  }
  return t
}

function brl(v: string | number | null | undefined) {
  const n = parseFloat(String(v || 0))
  return n.toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
}

function num(v: string | null | undefined) {
  if (!v) return '—'
  return parseInt(v, 10).toLocaleString('pt-BR')
}

function pct(v: string | null | undefined) {
  if (!v) return '—'
  return parseFloat(v).toFixed(2).replace('.', ',') + '%'
}

function orcamentoDiario(c: Campanha) {
  if (c.daily_budget) return parseFloat(c.daily_budget) / 100
  return null
}

function isAtiva(c: Campanha) {
  return c.effective_status === 'ACTIVE'
}

function statusLabel(c: Campanha) {
  const map: Record<string, string> = {
    ACTIVE: 'Ativa',
    PAUSED: 'Pausada',
    DELETED: 'Excluída',
    ARCHIVED: 'Arquivada',
    IN_PROCESS: 'Processando',
    WITH_ISSUES: 'Com problemas',
  }
  return map[c.effective_status] || c.effective_status
}

function statusColor(c: Campanha) {
  if (c.effective_status === 'ACTIVE') return 'var(--c-ai)'
  if (c.effective_status === 'PAUSED') return 'var(--c-warn)'
  return 'var(--c-text-muted)'
}

function objetivoLabel(obj: string | null) {
  const map: Record<string, string> = {
    OUTCOME_TRAFFIC: 'Tráfego',
    OUTCOME_AWARENESS: 'Reconhecimento',
    OUTCOME_ENGAGEMENT: 'Engajamento',
    OUTCOME_LEADS: 'Leads',
    OUTCOME_SALES: 'Vendas',
    OUTCOME_APP_PROMOTION: 'App',
    MESSAGES: 'Mensagens',
    LINK_CLICKS: 'Cliques',
    CONVERSIONS: 'Conversões',
    VIDEO_VIEWS: 'Vídeo',
    REACH: 'Alcance',
    BRAND_AWARENESS: 'Reconhecimento',
  }
  return obj ? (map[obj] || obj) : '—'
}

function conversas(c: Campanha) {
  const m = fmt(c)
  if (!m.actions) return null
  const tipos = [
    'onsite_conversion.messaging_conversation_started_7d',
    'onsite_conversion.messaging_first_reply',
    'messaging_conversation_started',
  ]
  for (const tipo of tipos) {
    const a = m.actions.find(x => x.action_type === tipo)
    if (a) return parseInt(a.value, 10)
  }
  return null
}

function custoPorConversa(c: Campanha) {
  const m = fmt(c)
  const conv = conversas(c)
  if (!conv || !m.spend) return null
  return parseFloat(m.spend) / conv
}

async function toggleStatus(c: Campanha) {
  if (salvandoStatus.value[c.id]) return
  const novoStatus = isAtiva(c) ? 'PAUSED' : 'ACTIVE'
  salvandoStatus.value[c.id] = true
  try {
    const r = await api<{ ok: boolean; erro?: string }>(`/api/marketing/campanhas/${c.id}`, {
      method: 'PATCH',
      body: { status: novoStatus },
    })
    if (!r.ok) throw new Error(r.erro || 'Falha')
    await load()
  }
  catch (e: any) {
    alert(e?.response?._data?.erro || e?.message || 'Erro ao atualizar status.')
  }
  finally {
    salvandoStatus.value[c.id] = false
  }
}

function iniciarEdicaoOrcamento(c: Campanha) {
  const v = orcamentoDiario(c)
  editandoOrcamento.value[c.id] = v ? String(v.toFixed(2)).replace('.', ',') : ''
}

async function salvarOrcamento(c: Campanha) {
  const raw = (editandoOrcamento.value[c.id] || '').replace(',', '.')
  const valor = parseFloat(raw)
  if (!valor || valor < 1) {
    alert('Orçamento mínimo: R$ 1,00')
    return
  }
  salvandoOrcamento.value[c.id] = true
  try {
    const r = await api<{ ok: boolean; erro?: string }>(`/api/marketing/campanhas/${c.id}`, {
      method: 'PATCH',
      body: { orcamento_diario_reais: valor },
    })
    if (!r.ok) throw new Error(r.erro || 'Falha')
    delete editandoOrcamento.value[c.id]
    await load()
  }
  catch (e: any) {
    alert(e?.response?._data?.erro || e?.message || 'Erro ao salvar orçamento.')
  }
  finally {
    salvandoOrcamento.value[c.id] = false
  }
}

function cancelarEdicaoOrcamento(id: string) {
  delete editandoOrcamento.value[id]
}

function dataHora(iso: string | null | undefined) {
  if (!iso) return ''
  return new Date(iso).toLocaleDateString('pt-BR', { day: '2-digit', month: '2-digit', year: 'numeric' })
}
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <!-- topo -->
    <div style="padding:16px clamp(12px,4vw,30px);border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:11px;flex-shrink:0;flex-wrap:wrap;">
      <div style="width:34px;height:34px;border-radius:10px;background:#1877F2;display:flex;align-items:center;justify-content:center;flex-shrink:0;">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="white"><path d="M18 2h-3a5 5 0 0 0-5 5v3H7v4h3v8h4v-8h3l1-4h-4V7a1 1 0 0 1 1-1h3z" /></svg>
      </div>
      <div style="flex:1;min-width:0;">
        <div style="font-weight:800;font-size:15px;">Facebook Ads</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Campanhas, métricas e orçamentos · somente administradores</div>
      </div>

      <div style="display:flex;gap:5px;flex-shrink:0;flex-wrap:wrap;">
        <button
          v-for="p in periodos" :key="p.value"
          :style="{
            background: periodo === p.value ? 'var(--c-ai)' : 'var(--c-surface-2)',
            border: 'none', color: periodo === p.value ? 'var(--c-on-accent)' : 'var(--c-text-muted)',
            fontFamily: 'inherit', fontSize: '12px', fontWeight: 700,
            padding: '7px 12px', borderRadius: '8px', cursor: 'pointer',
          }"
          @click="periodo = p.value"
        >{{ p.label }}</button>
      </div>
      <button
        :style="{ background: 'var(--c-surface-2)', border: 'none', color: 'var(--c-text-muted)', fontFamily: 'inherit', fontSize: '12px', fontWeight: 700, padding: '7px 12px', borderRadius: '8px', cursor: loading ? 'default' : 'pointer', opacity: loading ? 0.5 : 1 }"
        :disabled="loading"
        @click="load"
      >{{ loading ? '…' : '↻ Atualizar' }}</button>

      <NuxtLink to="/marketing" style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:12px;font-weight:700;padding:7px 12px;border-radius:8px;cursor:pointer;text-decoration:none;">
        Agente IA →
      </NuxtLink>
    </div>

    <!-- resumo -->
    <div v-if="!loading && Object.keys(metricas).length" style="padding:16px clamp(12px,4vw,30px) 0;display:flex;gap:12px;flex-wrap:wrap;">
      <div style="background:var(--c-surface-1);border-radius:12px;padding:12px 18px;">
        <div style="font-size:11px;color:var(--c-text-faint);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Gasto no período</div>
        <div style="font-size:22px;font-weight:800;letter-spacing:-.5px;">{{ brl(gastoTotal()) }}</div>
      </div>
      <div style="background:var(--c-surface-1);border-radius:12px;padding:12px 18px;">
        <div style="font-size:11px;color:var(--c-text-faint);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Campanhas</div>
        <div style="font-size:22px;font-weight:800;letter-spacing:-.5px;">{{ campanhas.length }}</div>
      </div>
      <div style="background:var(--c-surface-1);border-radius:12px;padding:12px 18px;">
        <div style="font-size:11px;color:var(--c-text-faint);font-weight:600;text-transform:uppercase;letter-spacing:.5px;margin-bottom:4px;">Ativas</div>
        <div style="font-size:22px;font-weight:800;letter-spacing:-.5px;color:var(--c-ai);">{{ campanhas.filter(c => c.effective_status === 'ACTIVE').length }}</div>
      </div>
    </div>

    <div v-if="erroMetricas" style="margin:12px 30px 0;background:rgba(255,180,67,.1);border:1px solid rgba(255,180,67,.3);border-radius:10px;padding:10px 14px;font-size:12.5px;color:var(--c-warn-soft);">
      Métricas indisponíveis: {{ erroMetricas }}
    </div>

    <div style="flex:1;padding:18px clamp(12px,4vw,30px) 40px;">
      <div v-if="loading" style="color:var(--c-text-muted);font-size:14px;text-align:center;padding:60px;">Carregando campanhas…</div>

      <div v-else-if="!campanhas.length" style="text-align:center;color:var(--c-text-muted);font-size:13.5px;padding:60px 20px;">
        <div style="font-size:38px;margin-bottom:12px;">📊</div>
        Nenhuma campanha encontrada. Crie uma pelo
        <NuxtLink to="/marketing" style="color:var(--c-ai);text-decoration:none;font-weight:700;">Agente de Marketing</NuxtLink>.
      </div>

      <div v-else style="display:grid;grid-template-columns:repeat(auto-fill,minmax(min(380px,100%),1fr));gap:16px;">
        <div
          v-for="c in campanhas" :key="c.id"
          :style="{
            background: 'var(--c-bg)',
            border: `1px solid ${isAtiva(c) ? 'rgba(96,165,250,.3)' : 'var(--c-surface-1)'}`,
            borderRadius: '18px', padding: '18px', display: 'flex', flexDirection: 'column', gap: '13px',
          }"
        >
          <!-- cabeçalho -->
          <div style="display:flex;align-items:flex-start;gap:8px;">
            <div style="flex:1;min-width:0;">
              <div style="display:flex;align-items:center;gap:7px;flex-wrap:wrap;margin-bottom:3px;">
                <span :style="{ width: '8px', height: '8px', borderRadius: '50%', background: statusColor(c), display: 'inline-block', flexShrink: 0 }" />
                <span style="font-weight:800;font-size:15px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ c.name }}</span>
              </div>
              <div style="font-size:11.5px;color:var(--c-text-muted);">
                <span :style="{ color: statusColor(c), fontWeight: 700 }">{{ statusLabel(c) }}</span>
                <template v-if="c.objective"> · {{ objetivoLabel(c.objective) }}</template>
                <template v-if="c.created_time"> · desde {{ dataHora(c.created_time) }}</template>
              </div>
            </div>
            <button
              :disabled="salvandoStatus[c.id] || c.effective_status === 'DELETED' || c.effective_status === 'ARCHIVED'"
              :style="{
                background: isAtiva(c) ? 'rgba(255,180,67,.14)' : 'rgba(96,165,250,.14)',
                border: `1px solid ${isAtiva(c) ? 'rgba(255,180,67,.3)' : 'rgba(96,165,250,.3)'}`,
                color: isAtiva(c) ? 'var(--c-warn)' : 'var(--c-ai)',
                fontFamily: 'inherit', fontSize: '12px', fontWeight: 700,
                padding: '6px 12px', borderRadius: '8px', cursor: 'pointer', flexShrink: 0, whiteSpace: 'nowrap',
                opacity: (salvandoStatus[c.id] || c.effective_status === 'DELETED' || c.effective_status === 'ARCHIVED') ? 0.5 : 1,
              }"
              @click="toggleStatus(c)"
            >{{ salvandoStatus[c.id] ? '…' : (isAtiva(c) ? '⏸ Pausar' : '▶ Ativar') }}</button>
          </div>

          <!-- métricas do período -->
          <div v-if="fmt(c).spend !== undefined" style="display:grid;grid-template-columns:repeat(3,1fr);gap:7px;">
            <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
              <div style="font-size:15px;font-weight:800;letter-spacing:-.5px;">{{ brl(fmt(c).spend) }}</div>
              <div style="font-size:10px;color:var(--c-text-faint);">gasto</div>
            </div>
            <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
              <div style="font-size:15px;font-weight:800;letter-spacing:-.5px;">{{ num(fmt(c).impressions) }}</div>
              <div style="font-size:10px;color:var(--c-text-faint);">impressões</div>
            </div>
            <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
              <div style="font-size:15px;font-weight:800;letter-spacing:-.5px;">{{ num(fmt(c).clicks) }}</div>
              <div style="font-size:10px;color:var(--c-text-faint);">cliques</div>
            </div>
            <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
              <div style="font-size:15px;font-weight:800;letter-spacing:-.5px;">{{ pct(fmt(c).ctr) }}</div>
              <div style="font-size:10px;color:var(--c-text-faint);">CTR</div>
            </div>
            <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
              <div style="font-size:15px;font-weight:800;letter-spacing:-.5px;">{{ fmt(c).cpc ? brl(fmt(c).cpc) : '—' }}</div>
              <div style="font-size:10px;color:var(--c-text-faint);">CPC</div>
            </div>
            <div style="background:var(--c-surface-2);border-radius:9px;padding:8px 10px;">
              <div style="font-size:15px;font-weight:800;letter-spacing:-.5px;">{{ fmt(c).cpm ? brl(fmt(c).cpm) : '—' }}</div>
              <div style="font-size:10px;color:var(--c-text-faint);">CPM</div>
            </div>
          </div>
          <div v-else style="font-size:12px;color:var(--c-text-faint);text-align:center;padding:8px 0;">Sem dados para o período selecionado.</div>

          <!-- custo por mensagem -->
          <div v-if="conversas(c) !== null" style="background:rgba(96,165,250,.08);border:1px solid rgba(96,165,250,.2);border-radius:9px;padding:9px 12px;display:flex;justify-content:space-between;align-items:center;gap:8px;">
            <div>
              <div style="font-size:11px;color:var(--c-text-faint);margin-bottom:2px;">Conversas iniciadas</div>
              <div style="font-size:18px;font-weight:800;">{{ conversas(c) }}</div>
            </div>
            <div v-if="custoPorConversa(c) !== null" style="text-align:right;">
              <div style="font-size:11px;color:var(--c-text-faint);margin-bottom:2px;">Custo/mensagem</div>
              <div style="font-size:18px;font-weight:800;color:var(--c-ai);">{{ brl(custoPorConversa(c)) }}</div>
            </div>
          </div>

          <!-- orçamento -->
          <div style="border-top:1px solid var(--c-surface-1);padding-top:12px;">
            <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:7px;">
              <template v-if="c.daily_budget">
                Orçamento diário: <b style="color:var(--c-text-secondary);">{{ brl(orcamentoDiario(c)) }}/dia</b>
              </template>
              <template v-else-if="c.lifetime_budget">
                Orçamento total: <b style="color:var(--c-text-secondary);">{{ brl(parseFloat(c.lifetime_budget) / 100) }}</b>
              </template>
              <template v-else>
                Orçamento: <b style="color:var(--c-text-faint);">não definido</b>
              </template>
            </div>

            <div v-if="c.id in editandoOrcamento" style="display:flex;gap:7px;align-items:center;">
              <span style="font-size:13px;color:var(--c-text-muted);">R$</span>
              <input
                v-model="editandoOrcamento[c.id]"
                type="text"
                inputmode="decimal"
                placeholder="0,00"
                style="flex:1;background:var(--c-surface-2);border:1px solid var(--c-surface-3);border-radius:8px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:13.5px;outline:none;"
                @keydown.enter="salvarOrcamento(c)"
                @keydown.escape="cancelarEdicaoOrcamento(c.id)"
              >
              <button
                :disabled="salvandoOrcamento[c.id]"
                style="background:var(--c-ai);border:none;color:var(--c-on-accent);font-family:inherit;font-size:12px;font-weight:700;padding:8px 13px;border-radius:8px;cursor:pointer;"
                @click="salvarOrcamento(c)"
              >{{ salvandoOrcamento[c.id] ? '…' : 'Salvar' }}</button>
              <button
                style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:12px;font-weight:700;padding:8px 10px;border-radius:8px;cursor:pointer;"
                @click="cancelarEdicaoOrcamento(c.id)"
              >✕</button>
            </div>
            <button
              v-else-if="c.daily_budget"
              style="background:var(--c-surface-2);border:none;color:var(--c-text-muted);font-family:inherit;font-size:12px;font-weight:700;padding:7px 13px;border-radius:8px;cursor:pointer;"
              @click="iniciarEdicaoOrcamento(c)"
            >✏ Editar orçamento</button>
            <div v-else-if="c.lifetime_budget" style="font-size:11.5px;color:var(--c-text-faint);">Orçamento total não é editável aqui.</div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>
