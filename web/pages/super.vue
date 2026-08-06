<script setup lang="ts">
/**
 * Painel do dono da plataforma. Responde, sem SSH, as perguntas que antes só o banco
 * respondia: quantas empresas existem e o que cada uma está usando, qual número de
 * WhatsApp está recusando envio, e se a máquina/agendador estão de pé.
 *
 * A aba "Números" é a razão de existir da tela: em 27/07 um número levou recusa do
 * WhatsApp por ~20h sem ninguém ver, e toda primeira resposta a lead novo morreu calada.
 */
const api = useApi()
const { user } = useAuth()

interface Empresa {
  id: number, nome: string, slug: string, ativa: boolean, criada_em: string
  usuarios: number, conversas: number, ultima_atividade: string | null, numeros: number
  msgs_recebidas_24h: number, msgs_enviadas_24h: number
}
interface Numero {
  id: number, empresa_id: number, empresa: string, nome: string, telefone: string
  canal: string, papel: string, ativo: boolean, estado: string | null
  enviadas_24h: number, entregues_24h: number, lidas_24h: number, pendentes_24h: number
  recusadas_24h: number, recusa_pct: number, ultima_recusa: string | null
  cap_diario: number, enviadas_hoje: number, ultimo_envio: string | null, sem_backup: boolean
}
interface Servico { nome: string, endereco: string, no_ar: boolean, ms: number | null }
interface Plataforma {
  servicos: Servico[]
  agendador: { ultimo_tick: string | null, ha_segundos: number | null, ok: boolean }
  disco: { total_gb: number, livre_gb: number, usado_pct: number | null }
  memoria: { total_mb: number | null, disponivel_mb: number | null, usado_pct: number | null }
  banco_mb: number | null, migrations_pendentes: string[]
  erros_24h: { mensagem: string, vezes: number }[]
  php: string, lido_em: string
}

const aba = ref<'empresas' | 'numeros' | 'plataforma'>('numeros')
const empresas = ref<Empresa[]>([])
const numeros = ref<Numero[]>([])
const plataforma = ref<Plataforma | null>(null)
const carregando = ref(false)
const erro = ref('')
const salvando = ref<Record<string, boolean>>({})

async function carregar() {
  carregando.value = true
  erro.value = ''
  try {
    const [e, n, p] = await Promise.all([
      api<{ empresas: Empresa[] }>('/api/super/companies'),
      api<{ numeros: Numero[] }>('/api/super/wa-accounts'),
      api<Plataforma>('/api/super/platform'),
    ])
    empresas.value = e.empresas || []
    numeros.value = n.numeros || []
    plataforma.value = p
  }
  catch (err: any) {
    erro.value = err?.data?.message || 'Não consegui carregar o painel.'
  }
  finally { carregando.value = false }
}
onMounted(carregar)

async function alternarEmpresa(c: Empresa) {
  const acao = c.ativa ? 'suspender' : 'reativar'
  if (!confirm(`Tem certeza que quer ${acao} a empresa "${c.nome}"?\n\n${c.ativa ? 'Os usuários dela param de conseguir usar o sistema na hora (nenhum dado é apagado).' : 'Os usuários voltam a ter acesso.'}`))
    return
  salvando.value[`e${c.id}`] = true
  try {
    await api(`/api/super/companies/${c.id}`, { method: 'PATCH', body: { ativa: !c.ativa } })
    c.ativa = !c.ativa
  }
  catch (err: any) { alert(err?.data?.erro || 'Não deu para mudar o estado da empresa.') }
  finally { salvando.value[`e${c.id}`] = false }
}

async function alternarNumero(n: Numero) {
  salvando.value[`n${n.id}`] = true
  try {
    await api(`/api/super/wa-accounts/${n.id}`, { method: 'PATCH', body: { ativo: !n.ativo } })
    n.ativo = !n.ativo
  }
  catch { alert('Não deu para mudar o estado do número.') }
  finally { salvando.value[`n${n.id}`] = false }
}

function num(v: number) { return v.toLocaleString('pt-BR') }
function quando(iso: string | null) {
  if (!iso) return '—'
  const d = new Date(iso.replace(' ', 'T'))
  const min = Math.floor((Date.now() - d.getTime()) / 60000)
  if (min < 1) return 'agora'
  if (min < 60) return `há ${min} min`
  if (min < 1440) return `há ${Math.floor(min / 60)} h`
  return `há ${Math.floor(min / 1440)} d`
}
/** Vermelho passa de 20% de recusa (a regra de alerta do plano); amarelo a partir de 5%. */
function corRecusa(n: Numero) {
  if (!n.enviadas_24h) return 'var(--c-text-faint)'
  if (n.recusa_pct >= 20) return 'var(--c-danger, #ff5c5c)'
  if (n.recusa_pct >= 5) return 'var(--c-warn, #ffaa00)'
  return 'var(--c-text-secondary)'
}
function corEstado(n: Numero) {
  if (!n.ativo) return 'var(--c-text-muted)'
  if (n.canal === 'API oficial') return '#25D366'
  return n.estado === 'open' ? '#25D366' : 'var(--c-warn, #ffaa00)'
}
function textoEstado(n: Numero) {
  if (!n.ativo) return 'desligado'
  if (n.canal === 'API oficial') return 'oficial'
  return n.estado === 'open' ? 'conectado' : (n.estado || 'sem estado')
}

const cardBase = 'background:var(--c-surface-0);border:1px solid var(--c-surface-1);border-radius:12px;padding:14px 16px;'
const th = 'padding:10px 12px;text-align:left;font-size:10.5px;font-weight:700;color:var(--c-text-faint);white-space:nowrap;text-transform:uppercase;letter-spacing:.04em;'
const td = 'padding:11px 12px;font-size:12px;vertical-align:middle;white-space:nowrap;'
</script>

<template>
  <div style="display:flex;flex-direction:column;height:100dvh;background:var(--c-bg-deepest);color:var(--c-text);font-family:'JetBrains Mono',ui-monospace,monospace;overflow:hidden;">
    <!-- barra do topo -->
    <header style="display:flex;align-items:center;gap:12px;padding:12px clamp(12px,4vw,28px);border-bottom:1px solid var(--c-surface-1);flex-shrink:0;">
      <button
        title="Voltar ao CRM"
        style="background:transparent;border:1px solid var(--c-surface-3);color:var(--c-text-faint);font-family:inherit;font-size:11.5px;font-weight:700;padding:5px 10px;border-radius:7px;cursor:pointer;"
        @click="navigateTo('/')"
      >
        ← CRM
      </button>
      <div>
        <div style="font-size:14px;font-weight:800;letter-spacing:-.01em;">
          Plataforma
        </div>
        <div style="font-size:10.5px;color:var(--c-text-faint);">
          visão do dono · {{ user?.name }}
        </div>
      </div>

      <div style="flex:1;" />

      <div style="display:flex;gap:2px;background:var(--c-bg-deepest);border:1px solid var(--c-surface-1);border-radius:9px;padding:3px;">
        <button
          v-for="t in [{ k: 'numeros', l: 'Números' }, { k: 'empresas', l: 'Empresas' }, { k: 'plataforma', l: 'Servidor' }]"
          :key="t.k"
          :style="{ background: aba === t.k ? 'var(--c-surface-0)' : 'transparent', border: '1px solid ' + (aba === t.k ? 'var(--c-surface-3)' : 'transparent'), color: aba === t.k ? 'var(--c-text)' : 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 12px', borderRadius: '7px', cursor: 'pointer' }"
          @click="aba = t.k as any"
        >{{ t.l }}</button>
      </div>

      <button
        :disabled="carregando"
        :style="{ background: 'transparent', border: '1px solid var(--c-surface-3)', color: 'var(--c-text-faint)', fontFamily: 'inherit', fontSize: '11.5px', fontWeight: 700, padding: '5px 10px', borderRadius: '7px', cursor: carregando ? 'default' : 'pointer', opacity: carregando ? .5 : 1 }"
        @click="carregar()"
      >
        {{ carregando ? '…' : '↻ Atualizar' }}
      </button>
    </header>

    <div v-if="erro" style="margin:14px clamp(12px,4vw,28px);background:rgba(255,92,92,.08);border:1px solid rgba(255,92,92,.3);border-radius:11px;padding:12px 14px;font-size:12px;">
      {{ erro }}
    </div>

    <main style="flex:1;overflow:auto;padding:16px clamp(12px,4vw,28px) 28px;">
      <!-- ============ NÚMEROS ============ -->
      <template v-if="aba === 'numeros'">
        <p style="font-size:11.5px;color:var(--c-text-faint);margin:0 0 12px;">
          Envio de todas as empresas nas últimas 24h. <b style="color:var(--c-text-secondary);">Recusadas</b> é o sinal que importa:
          quando o WhatsApp começa a recusar, a mensagem some sem erro na tela do atendente.
        </p>

        <div :style="cardBase + 'padding:0;overflow:auto;'">
          <table style="width:100%;border-collapse:collapse;">
            <thead>
              <tr style="border-bottom:1px solid var(--c-surface-1);">
                <th :style="th">Número</th>
                <th :style="th">Empresa</th>
                <th :style="th">Canal</th>
                <th :style="th">Estado</th>
                <th :style="th + 'text-align:right;'">Enviadas 24h</th>
                <th :style="th + 'text-align:right;'">Entregues</th>
                <th :style="th + 'text-align:right;'">Lidas</th>
                <th :style="th + 'text-align:right;'">Recusadas</th>
                <th :style="th + 'text-align:right;'">Última recusa</th>
                <th :style="th + 'text-align:right;'">Hoje / cap</th>
                <th :style="th" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="n in numeros" :key="n.id" style="border-bottom:1px solid var(--c-surface-1);">
                <td :style="td">
                  <div style="font-weight:700;">
                    {{ n.nome }}
                  </div>
                  <div style="font-size:10.5px;color:var(--c-text-faint);">
                    {{ n.telefone || '—' }} · {{ n.papel }}
                    <span v-if="n.sem_backup" title="A empresa só tem este número ativo: se ele cair ou for bloqueado, não há para onde o CRM redirecionar o envio." style="color:var(--c-warn, #ffaa00);cursor:help;"> · sem backup</span>
                  </div>
                </td>
                <td :style="td + 'color:var(--c-text-secondary);'">
                  {{ n.empresa }}
                </td>
                <td :style="td + 'color:var(--c-text-secondary);'">
                  {{ n.canal }}
                </td>
                <td :style="td">
                  <span :style="{ color: corEstado(n), fontWeight: 700, fontSize: '11px' }">● {{ textoEstado(n) }}</span>
                </td>
                <td :style="td + 'text-align:right;font-variant-numeric:tabular-nums;'">
                  {{ num(n.enviadas_24h) }}
                </td>
                <td :style="td + 'text-align:right;color:var(--c-text-secondary);font-variant-numeric:tabular-nums;'">
                  {{ num(n.entregues_24h) }}
                </td>
                <td :style="td + 'text-align:right;color:var(--c-text-secondary);font-variant-numeric:tabular-nums;'">
                  {{ num(n.lidas_24h) }}
                </td>
                <td :style="td + 'text-align:right;font-variant-numeric:tabular-nums;'">
                  <span :style="{ color: corRecusa(n), fontWeight: n.recusa_pct >= 5 ? 800 : 400 }">
                    {{ n.recusadas_24h ? `${num(n.recusadas_24h)} (${String(n.recusa_pct).replace('.', ',')}%)` : '—' }}
                  </span>
                </td>
                <td :style="td + 'text-align:right;color:var(--c-text-faint);font-size:11px;'">
                  {{ quando(n.ultima_recusa) }}
                </td>
                <td :style="td + 'text-align:right;color:var(--c-text-secondary);font-variant-numeric:tabular-nums;'">
                  {{ num(n.enviadas_hoje) }}<span v-if="n.cap_diario" style="color:var(--c-text-faint);"> / {{ num(n.cap_diario) }}</span>
                </td>
                <td :style="td + 'text-align:right;'">
                  <button
                    :disabled="salvando[`n${n.id}`]"
                    :style="{ background: 'transparent', border: '1px solid var(--c-surface-3)', color: n.ativo ? 'var(--c-text-faint)' : '#25D366', fontFamily: 'inherit', fontSize: '11px', fontWeight: 700, padding: '4px 9px', borderRadius: '6px', cursor: 'pointer' }"
                    @click="alternarNumero(n)"
                  >
                    {{ salvando[`n${n.id}`] ? '…' : (n.ativo ? 'Desligar' : 'Ligar') }}
                  </button>
                </td>
              </tr>
              <tr v-if="!numeros.length && !carregando">
                <td colspan="11" style="padding:20px;text-align:center;color:var(--c-text-faint);font-size:12px;">
                  Nenhum número cadastrado.
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </template>

      <!-- ============ EMPRESAS ============ -->
      <template v-else-if="aba === 'empresas'">
        <div :style="cardBase + 'padding:0;overflow:auto;'">
          <table style="width:100%;border-collapse:collapse;">
            <thead>
              <tr style="border-bottom:1px solid var(--c-surface-1);">
                <th :style="th">Empresa</th>
                <th :style="th + 'text-align:right;'">Usuários</th>
                <th :style="th + 'text-align:right;'">Números</th>
                <th :style="th + 'text-align:right;'">Conversas</th>
                <th :style="th + 'text-align:right;'">Recebidas 24h</th>
                <th :style="th + 'text-align:right;'">Enviadas 24h</th>
                <th :style="th + 'text-align:right;'">Última atividade</th>
                <th :style="th + 'text-align:right;'">Estado</th>
                <th :style="th" />
              </tr>
            </thead>
            <tbody>
              <tr v-for="c in empresas" :key="c.id" style="border-bottom:1px solid var(--c-surface-1);">
                <td :style="td">
                  <div style="font-weight:700;">
                    {{ c.nome }}
                  </div>
                  <div style="font-size:10.5px;color:var(--c-text-faint);">
                    #{{ c.id }} · {{ c.slug }}
                  </div>
                </td>
                <td :style="td + 'text-align:right;font-variant-numeric:tabular-nums;'">
                  {{ num(c.usuarios) }}
                </td>
                <td :style="td + 'text-align:right;font-variant-numeric:tabular-nums;'">
                  {{ num(c.numeros) }}
                </td>
                <td :style="td + 'text-align:right;font-variant-numeric:tabular-nums;'">
                  {{ num(c.conversas) }}
                </td>
                <td :style="td + 'text-align:right;color:var(--c-text-secondary);font-variant-numeric:tabular-nums;'">
                  {{ num(c.msgs_recebidas_24h) }}
                </td>
                <td :style="td + 'text-align:right;color:var(--c-text-secondary);font-variant-numeric:tabular-nums;'">
                  {{ num(c.msgs_enviadas_24h) }}
                </td>
                <td :style="td + 'text-align:right;color:var(--c-text-faint);font-size:11px;'">
                  {{ quando(c.ultima_atividade) }}
                </td>
                <td :style="td + 'text-align:right;'">
                  <span :style="{ color: c.ativa ? '#25D366' : 'var(--c-danger, #ff5c5c)', fontWeight: 700, fontSize: '11px' }">
                    ● {{ c.ativa ? 'ativa' : 'suspensa' }}
                  </span>
                </td>
                <td :style="td + 'text-align:right;'">
                  <button
                    :disabled="salvando[`e${c.id}`]"
                    :style="{ background: 'transparent', border: '1px solid var(--c-surface-3)', color: c.ativa ? 'var(--c-text-faint)' : '#25D366', fontFamily: 'inherit', fontSize: '11px', fontWeight: 700, padding: '4px 9px', borderRadius: '6px', cursor: 'pointer' }"
                    @click="alternarEmpresa(c)"
                  >
                    {{ salvando[`e${c.id}`] ? '…' : (c.ativa ? 'Suspender' : 'Reativar') }}
                  </button>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
        <p style="font-size:11px;color:var(--c-text-faint);margin:10px 2px 0;">
          Suspender bloqueia o acesso dos usuários da empresa na hora (403 em toda requisição). Nenhum dado é apagado.
        </p>
      </template>

      <!-- ============ SERVIDOR ============ -->
      <template v-else>
        <div v-if="plataforma" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:12px;">
          <div :style="cardBase">
            <div style="font-size:10.5px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">
              Serviços
            </div>
            <div v-for="s in plataforma.servicos" :key="s.nome" style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12px;">
              <span :style="{ color: s.no_ar ? '#25D366' : 'var(--c-danger, #ff5c5c)', fontWeight: 800 }">●</span>
              <span style="flex:1;">{{ s.nome }}</span>
              <span style="color:var(--c-text-faint);font-size:10.5px;">{{ s.no_ar ? `${s.ms} ms` : 'fora do ar' }}</span>
            </div>
            <div style="display:flex;align-items:center;gap:8px;padding:5px 0;font-size:12px;border-top:1px solid var(--c-surface-1);margin-top:6px;padding-top:9px;">
              <span :style="{ color: plataforma.agendador.ok ? '#25D366' : 'var(--c-danger, #ff5c5c)', fontWeight: 800 }">●</span>
              <span style="flex:1;">Agendador (ticks)</span>
              <span style="color:var(--c-text-faint);font-size:10.5px;">{{ plataforma.agendador.ultimo_tick ? quando(plataforma.agendador.ultimo_tick) : 'sem batimento' }}</span>
            </div>
          </div>

          <div :style="cardBase">
            <div style="font-size:10.5px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">
              Máquina
            </div>
            <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12px;">
              <span>Disco</span>
              <span :style="{ color: (plataforma.disco.usado_pct ?? 0) > 85 ? 'var(--c-danger, #ff5c5c)' : 'var(--c-text-secondary)' }">
                {{ plataforma.disco.usado_pct }}% · {{ plataforma.disco.livre_gb }} GB livres
              </span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12px;">
              <span>Memória</span>
              <span :style="{ color: (plataforma.memoria.usado_pct ?? 0) > 90 ? 'var(--c-danger, #ff5c5c)' : 'var(--c-text-secondary)' }">
                {{ plataforma.memoria.usado_pct }}% · {{ plataforma.memoria.disponivel_mb }} MB livres
              </span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12px;">
              <span>Banco</span><span style="color:var(--c-text-secondary);">{{ plataforma.banco_mb }} MB</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12px;">
              <span>PHP</span><span style="color:var(--c-text-secondary);">{{ plataforma.php }}</span>
            </div>
            <div style="display:flex;justify-content:space-between;padding:5px 0;font-size:12px;">
              <span>Migrations pendentes</span>
              <span :style="{ color: plataforma.migrations_pendentes.length ? 'var(--c-warn, #ffaa00)' : 'var(--c-text-secondary)', fontWeight: plataforma.migrations_pendentes.length ? 700 : 400 }">
                {{ plataforma.migrations_pendentes.length || 'nenhuma' }}
              </span>
            </div>
          </div>

          <div :style="cardBase + 'grid-column:1/-1;'">
            <div style="font-size:10.5px;font-weight:700;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.04em;margin-bottom:10px;">
              Erros nas últimas 24h
            </div>
            <div v-if="!plataforma.erros_24h.length" style="font-size:12px;color:var(--c-text-faint);">
              Nenhum erro registrado.
            </div>
            <div
              v-for="e in plataforma.erros_24h" :key="e.mensagem"
              style="display:flex;gap:10px;padding:6px 0;font-size:11.5px;border-bottom:1px solid var(--c-surface-1);"
            >
              <span style="color:var(--c-warn, #ffaa00);font-weight:800;min-width:34px;">{{ e.vezes }}×</span>
              <span style="color:var(--c-text-secondary);word-break:break-word;">{{ e.mensagem }}</span>
            </div>
          </div>
        </div>
      </template>
    </main>
  </div>
</template>
