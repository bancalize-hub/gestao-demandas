<script setup lang="ts">
// Gestor de tráfego automático: revisa a conta inteira de hora em hora, gera criativo com
// IA, publica dentro do teto, otimiza pela qualificação do CRM e avisa no WhatsApp. A tela é
// o contrato do que o robô pode fazer com dinheiro — e o diário de tudo que ele fez, para
// cada real gasto ter um motivo escrito.
const api = useApi()
const apiOrigin = useRuntimeConfig().public.apiOrigin as string

interface Config {
  enabled: boolean; daily_cap: number; budget_per_ad: number; max_new_per_day: number
  lifetime_days: number; min_triados: number; kill_rate: number; max_spend_no_qualified: number
  use_stock: boolean; image_provider: string | null; image_model: string | null
  tem_chave_imagem: boolean; targeting: Record<string, any> | null; instructions: string | null
  run_requested_at: string | null; run_requested_mode: string | null
  last_run_at: string | null; last_result: string | null
  max_cap: number; current_cap: number | null; target_cpq: number; manage_whole_account: boolean
  alert_phone: string | null; alert_wa_account_id: number | null; report_hour: string | null
  last_review_at: string | null; last_report_at: string | null; last_analysis: string | null
}
interface AutoAd {
  id: number; status: string; daily_budget: number; ad_id: string; campaign_id: string
  launched_at: string | null; paused_at: string | null; pause_reason: string | null
  copy: { mensagem: string, titulo?: string } | null
  creative: { id: number, name: string, original_name: string, notes: string | null, status: string, source: string } | null
}
interface Evento { id: number; kind: string; message: string; simulated: boolean; created_at: string }
interface Resposta {
  config: Config
  publico_padrao: Record<string, any>
  modelos_padrao: Record<string, string>
  conta: { saldo: number | null, comprometido: number | null, itens: { id: string, nome: string, diario: number }[], erro: string | null }
  anuncios: AutoAd[]
  eventos: Evento[]
  teto_vigente: number
  numeros_aviso: { id: number, name: string, phone: string, avatars_only: boolean }[]
}

const dados = ref<Resposta | null>(null)
const form = ref<Partial<Config> & { image_api_key?: string }>({})
const publico = ref({ idadeMin: 18, idadeMax: 54, genero: '1', soInstagram: true })
const carregando = ref(true)
const salvando = ref(false)
const erro = ref('')
const aviso = ref('')
const verItens = ref(false)

async function carregar() {
  carregando.value = true
  erro.value = ''
  try {
    const r = await api<Resposta>('/api/marketing/autopiloto')
    dados.value = r
    form.value = { ...r.config, image_api_key: '' }
    const t = r.config.targeting || r.publico_padrao
    publico.value = {
      idadeMin: t.age_min ?? 18,
      idadeMax: t.age_max ?? 65,
      genero: t.genders?.length === 1 ? String(t.genders[0]) : '',
      soInstagram: (t.publisher_platforms || []).length === 1 && t.publisher_platforms[0] === 'instagram',
    }
  } catch (e: any) {
    erro.value = e?.response?._data?.message || e?.message || 'Não consegui carregar.'
  } finally { carregando.value = false }
}
onMounted(carregar)

function montarPublico() {
  const p = publico.value
  const t: Record<string, any> = {
    geo_locations: { countries: ['BR'], location_types: ['home', 'recent'] },
    age_min: Number(p.idadeMin), age_max: Number(p.idadeMax),
    targeting_automation: { advantage_audience: 0 },
  }
  if (p.genero) t.genders = [Number(p.genero)]
  if (p.soInstagram) {
    t.publisher_platforms = ['instagram']
    t.instagram_positions = ['stream', 'story', 'reels']
  }
  return t
}

async function salvar(extra: Partial<Config> = {}) {
  salvando.value = true
  aviso.value = ''
  try {
    const f = form.value
    await api('/api/marketing/autopiloto', {
      method: 'PUT',
      body: {
        enabled: f.enabled, daily_cap: Number(f.daily_cap), budget_per_ad: Number(f.budget_per_ad),
        max_new_per_day: Number(f.max_new_per_day), lifetime_days: Number(f.lifetime_days),
        min_triados: Number(f.min_triados), kill_rate: Number(f.kill_rate),
        max_spend_no_qualified: Number(f.max_spend_no_qualified), use_stock: f.use_stock,
        image_provider: f.image_provider || null, image_model: f.image_model || null,
        image_api_key: f.image_api_key || null, instructions: f.instructions || null,
        targeting: montarPublico(),
        max_cap: Number(f.max_cap), target_cpq: Number(f.target_cpq), manage_whole_account: f.manage_whole_account,
        alert_phone: f.alert_phone || null, alert_wa_account_id: f.alert_wa_account_id || null,
        report_hour: f.report_hour || null, ...extra,
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
  const alcance = f.manage_whole_account
    ? 'Ele gerencia a CONTA INTEIRA: pode pausar, religar e mudar verba inclusive das campanhas feitas à mão.'
    : 'Ele só mexe nos anúncios que ele mesmo criar.'
  if (ligar && !confirm(`Ligar o gestor de tráfego?\n\n${alcance}\n\nRevisa de hora em hora, trabalha num teto de ${dinheiro(Number(f.daily_cap))}/dia e só sobe até ${dinheiro(Number(f.max_cap))}/dia quando o custo por qualificado estiver dentro de ${dinheiro(Number(f.target_cpq))}. Se a conta já estiver acima do máximo, ele corta as piores campanhas na primeira revisão.`)) return
  form.value.enabled = ligar
  await salvar({ enabled: ligar })
}

async function testarImagem() {
  await salvar()
  const r = await api<{ ok: boolean, mensagem: string }>('/api/marketing/autopiloto/testar-imagem', { method: 'POST' })
  aviso.value = (r.ok ? '✓ ' : '✗ ') + r.mensagem
}

async function testarAviso() {
  await salvar()
  const r = await api<{ ok: boolean, mensagem: string }>('/api/marketing/autopiloto/testar-aviso', { method: 'POST' })
  aviso.value = (r.ok ? '✓ ' : '✗ ') + r.mensagem
}

async function rodar(modo: 'simular' | 'real' | 'amostra') {
  if (modo === 'real' && !confirm('Rodar de verdade agora? Ele pode pausar e publicar anúncios.')) return
  try {
    const r = await api<{ ok: boolean, mensagem: string }>('/api/marketing/autopiloto/rodar', { method: 'POST', body: { modo } })
    aviso.value = r.mensagem
    await carregar()
  } catch (e: any) {
    aviso.value = e?.response?._data?.mensagem || e?.response?._data?.message || 'Não consegui pedir a rodada.'
  }
}

const dinheiro = (v: number | null | undefined) => v == null ? '—' : Number(v).toLocaleString('pt-BR', { style: 'currency', currency: 'BRL' })
const quando = (s: string | null) => s ? new Date(s).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' }) : '—'
const diasNoAr = (a: AutoAd) => a.launched_at ? Math.floor((Date.now() - new Date(a.launched_at).getTime()) / 86400000) : null

/** Folga do teto — o número que decide se entra criativo novo. */
const folga = computed(() => {
  const c = dados.value?.conta.comprometido
  return c == null || !dados.value ? null : dados.value.teto_vigente - c
})
const vagas = computed(() => folga.value == null ? null : Math.max(0, Math.floor(folga.value / Math.max(1, Number(form.value.budget_per_ad || 25)))))

const corEvento: Record<string, string> = {
  publicou: 'var(--c-teal)', publicaria: 'var(--c-teal)', pausou: 'var(--c-warn-hi)',
  erro: 'var(--c-danger-soft)', sem_saldo: 'var(--c-danger-soft)', descartou: 'var(--c-warn-hi)',
  acao: 'var(--accent)', recusou: 'var(--c-text-faint)', alerta: 'var(--c-warn-hi)', analise: 'var(--c-text)',
}

const cartao = 'background:var(--c-bg-deepest);border:1px solid var(--c-surface-1);border-radius:12px;padding:14px 16px;'
const campo = 'width:100%;background:var(--c-bg-deep);border:1px solid var(--c-surface-3);border-radius:9px;padding:8px 10px;color:var(--c-text);font-family:inherit;font-size:12.5px;outline:none;'
const rotulo = 'display:block;font-size:10.5px;font-weight:800;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.03em;margin-bottom:5px;'
const botao = 'background:var(--c-surface-2);border:none;color:var(--c-text-secondary);font-family:inherit;font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;cursor:pointer;'
const th = 'text-align:left;font-size:10.5px;font-weight:800;color:var(--c-text-faint);text-transform:uppercase;letter-spacing:.03em;padding:6px 10px;white-space:nowrap;'
const td = 'font-size:12px;padding:7px 10px;border-top:1px solid var(--c-surface-1);vertical-align:top;'
</script>

<template>
  <div style="flex:1;min-width:0;background:var(--c-bg-deep);display:flex;flex-direction:column;overflow-y:auto;">
    <div class="r-wrap r-pad" style="padding-block:16px;border-bottom:1px solid var(--c-surface-1);display:flex;align-items:center;gap:12px;flex-shrink:0;">
      <div style="width:34px;height:34px;border-radius:10px;background:var(--accent);display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:17px;">🤖</div>
      <div style="min-width:0;">
        <div style="font-weight:800;font-size:15px;">Gestor de tráfego</div>
        <div style="font-size:12px;color:var(--c-text-muted);">Revisa a conta de hora em hora, decide sozinho dentro do teto e te avisa no WhatsApp</div>
      </div>
      <div class="r-break-line" style="flex:1;" />
      <NuxtLink to="/marketing" class="r-tap" style="text-decoration:none;background:var(--c-surface-2);color:var(--c-text-secondary);font-size:12.5px;font-weight:700;padding:8px 13px;border-radius:9px;">← Gerenciador</NuxtLink>
    </div>

    <div class="r-page r-pad" style="--r-page:1100px;padding-block:20px;">
      <div v-if="carregando && !dados" style="font-size:12.5px;color:var(--c-text-faint);">Carregando…</div>
      <div v-else-if="erro" style="background:rgba(255,77,77,.08);border:1px solid rgba(255,77,77,.3);border-radius:10px;padding:10px 12px;font-size:12px;color:var(--c-danger-soft);">{{ erro }}</div>

      <template v-else-if="dados">
        <!-- Liga/desliga + estado da conta: o que decide se algo vai acontecer hoje. -->
        <div :style="cartao + 'display:flex;align-items:center;gap:14px;flex-wrap:wrap;margin-bottom:14px;'">
          <button
            class="r-tap" :disabled="salvando" @click="alternar"
            :style="{ background: form.enabled ? 'var(--c-teal)' : 'var(--c-surface-2)', color: form.enabled ? '#fff' : 'var(--c-text-secondary)', border: 'none', fontFamily: 'inherit', fontSize: '13px', fontWeight: 800, padding: '10px 16px', borderRadius: '10px', cursor: 'pointer' }"
          >{{ form.enabled ? '● LIGADO' : '○ DESLIGADO' }}</button>
          <div style="font-size:12px;color:var(--c-text-secondary);line-height:1.6;flex:1;min-width:220px;">
            <template v-if="form.enabled">Revisa a conta de hora em hora. Última revisão: {{ quando(form.last_review_at ?? null) }}.</template>
            <template v-else>Nada acontece com o gestor desligado. Use <strong>Simular agora</strong> para ver o que ele faria (a IA decide de verdade, mas nada é executado).</template>
            <div v-if="form.run_requested_at" style="color:var(--c-warn-hi);">Rodada pedida ({{ form.run_requested_mode }}) — começa em até 10 min.</div>
          </div>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <button class="r-tap" :style="botao" @click="rodar('simular')">Simular agora</button>
            <button class="r-tap" :style="botao" title="Gera 1 criativo e guarda na biblioteca, sem publicar" @click="rodar('amostra')">Gerar amostra</button>
            <button class="r-tap" :style="botao" :disabled="!form.enabled" @click="rodar('real')">Rodar agora</button>
          </div>
        </div>

        <div v-if="aviso" style="font-size:12px;color:var(--c-text-secondary);margin-bottom:12px;">{{ aviso }}</div>

        <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(150px,100%),1fr));gap:10px;margin-bottom:14px;">
          <div :style="cartao">
            <div :style="rotulo">Saldo da conta</div>
            <div :style="{ fontSize: '20px', fontWeight: 800, color: dados.conta.saldo !== null && dados.conta.saldo < Number(form.budget_per_ad) ? 'var(--c-danger-soft)' : 'inherit' }">{{ dinheiro(dados.conta.saldo) }}</div>
            <div v-if="dados.conta.saldo !== null && dados.conta.saldo < Number(form.budget_per_ad)" style="font-size:10.5px;color:var(--c-danger-soft);">Sem saldo: nada entrega e nada novo é criado</div>
          </div>
          <div :style="cartao + 'cursor:pointer;'" @click="verItens = !verItens">
            <div :style="rotulo">Comprometido / dia</div>
            <div style="font-size:20px;font-weight:800;">{{ dinheiro(dados.conta.comprometido) }}</div>
            <div style="font-size:10.5px;color:var(--c-text-faint);">conta inteira · {{ verItens ? 'ocultar' : 'ver de onde vem' }}</div>
          </div>
          <div :style="cartao">
            <div :style="rotulo">Teto em vigor</div>
            <div style="font-size:20px;font-weight:800;">{{ dinheiro(dados.teto_vigente) }}</div>
            <div style="font-size:10.5px;color:var(--c-text-faint);">normal {{ dinheiro(Number(form.daily_cap)) }} · máx. {{ dinheiro(Number(form.max_cap)) }}</div>
          </div>
          <div :style="cartao">
            <div :style="rotulo">Folga no teto</div>
            <div :style="{ fontSize: '20px', fontWeight: 800, color: (folga ?? 0) < 0 ? 'var(--c-danger-soft)' : 'inherit' }">{{ dinheiro(folga) }}</div>
            <div style="font-size:10.5px;color:var(--c-text-faint);">{{ vagas === null ? '' : `${vagas} vaga(s) de ${dinheiro(Number(form.budget_per_ad))}` }}</div>
          </div>
        </div>
        <div v-if="dados.conta.erro" style="font-size:12px;color:var(--c-danger-soft);margin-bottom:12px;">Meta: {{ dados.conta.erro }}</div>

        <div v-if="verItens" :style="cartao + 'margin-bottom:14px;font-size:12px;'">
          <div style="color:var(--c-text-muted);margin-bottom:8px;line-height:1.55;">
            O teto vale para a <strong>conta inteira</strong>: estas campanhas, inclusive as que o piloto não criou, já ocupam o orçamento.
            <template v-if="form.manage_whole_account">O gestor mexe nelas também: acima do teto máximo ele corta as de pior custo por qualificado na hora.</template>
            <template v-else>O gestor não mexe nelas — se a soma passa do teto, ele só deixa de criar. Pause pelo Gerenciador as que não quer mais.</template>
          </div>
          <div v-for="i in dados.conta.itens" :key="i.id" style="display:flex;gap:10px;padding:4px 0;border-top:1px solid var(--c-surface-1);">
            <span style="flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">{{ i.nome }}</span>
            <strong>{{ dinheiro(i.diario) }}</strong>
          </div>
        </div>

        <!-- Leitura da última revisão -->
        <div v-if="form.last_analysis" :style="cartao + 'margin-bottom:14px;'">
          <div :style="rotulo">Leitura do gestor</div>
          <div style="font-size:12.5px;line-height:1.6;white-space:pre-line;color:var(--c-text-secondary);">{{ form.last_analysis }}</div>
        </div>

        <!-- Autonomia do gestor -->
        <div :style="cartao + 'margin-bottom:14px;'">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:12px;">Autonomia do gestor</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(170px,100%),1fr));gap:12px;">
            <label><span :style="rotulo">Teto normal (R$/dia)</span><input v-model="form.daily_cap" type="number" min="0" :style="campo"></label>
            <label><span :style="rotulo">Teto máximo (R$/dia)</span><input v-model="form.max_cap" type="number" min="0" :style="campo"></label>
            <label><span :style="rotulo">Meta custo/qualificado (R$)</span><input v-model="form.target_cpq" type="number" min="1" :style="campo"></label>
          </div>
          <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin-top:12px;">
            <input v-model="form.manage_whole_account" type="checkbox"> Gerenciar a conta inteira (inclusive campanhas feitas à mão)
          </label>
          <div style="font-size:11px;color:var(--c-text-faint);margin-top:10px;line-height:1.55;">
            Ele pausa, religa, ajusta verba (no máximo ±50% por vez, cada coisa no máximo 1× a cada 24h), testa público (1 por dia)
            e escolhe o teto em vigor. Acima do normal, só com custo por qualificado ≤ meta e ao menos 3 qualificados em 3 dias; o máximo ele nunca passa.
            Recarregar saldo ele não pode — avisa antes de acabar.
          </div>
        </div>

        <!-- Avisos -->
        <div :style="cartao + 'margin-bottom:14px;'">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:12px;">Avisos no WhatsApp</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(190px,100%),1fr));gap:12px;">
            <label><span :style="rotulo">Seu WhatsApp (com DDI e DDD)</span><input v-model="form.alert_phone" inputmode="tel" placeholder="5541999999999" :style="campo"></label>
            <label><span :style="rotulo">Enviar pelo número</span>
              <select v-model="form.alert_wa_account_id" :style="campo">
                <option :value="null">automático (1º da Evolution)</option>
                <option v-for="n in dados.numeros_aviso" :key="n.id" :value="n.id">{{ n.name }} · {{ n.phone }}{{ n.avatars_only ? ' (só fotos)' : '' }}</option>
              </select>
            </label>
            <label><span :style="rotulo">Resumo do dia às</span><input v-model="form.report_hour" type="time" :style="campo"></label>
          </div>
          <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap;align-items:center;">
            <button class="r-tap" :style="botao" :disabled="!form.alert_phone" @click="testarAviso">Salvar e mandar teste</button>
            <span style="font-size:11px;color:var(--c-text-faint);">Último resumo: {{ quando(form.last_report_at ?? null) }}</span>
          </div>
          <div style="font-size:11px;color:var(--c-text-faint);margin-top:10px;line-height:1.55;">
            Avisa de saldo acabando, conta sem saldo, anúncio reprovado pela Meta, teto máximo estourado e falha repetida — cada aviso no máximo a cada 12h.
            Sai pela Evolution porque a API oficial só manda mensagem fora da janela de 24h com template aprovado.
          </div>
        </div>

        <!-- Regras de dinheiro -->
        <div :style="cartao + 'margin-bottom:14px;'">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:12px;">Regras fixas dos anúncios que ele cria</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(170px,100%),1fr));gap:12px;">
            <label><span :style="rotulo">Verba por criativo (R$/dia)</span><input v-model="form.budget_per_ad" type="number" min="6" :style="campo"></label>
            <label><span :style="rotulo">Criativos novos por dia</span><input v-model="form.max_new_per_day" type="number" min="0" :style="campo"></label>
            <label><span :style="rotulo">Vida útil (dias)</span><input v-model="form.lifetime_days" type="number" min="2" :style="campo"></label>
            <label><span :style="rotulo">Pausa abaixo de (% qualif.)</span><input v-model="form.kill_rate" type="number" min="0" max="100" :style="campo"></label>
            <label><span :style="rotulo">…com no mínimo (triados)</span><input v-model="form.min_triados" type="number" min="3" :style="campo"></label>
            <label><span :style="rotulo">Pausa se gastar sem qualificado (R$)</span><input v-model="form.max_spend_no_qualified" type="number" min="5" :style="campo"></label>
          </div>
          <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;margin-top:12px;">
            <input v-model="form.use_stock" type="checkbox"> Usar primeiro os criativos da biblioteca que nunca rodaram (sem custo de gerar imagem)
          </label>
          <div style="font-size:11px;color:var(--c-text-faint);margin-top:10px;line-height:1.55;">
            Os padrões vêm do que já foi medido nesta conta: um criativo traz ~2–3 qualificados/dia e não melhora com mais verba,
            e todo criativo cai em 5–7 dias. Por isso o piloto cresce com <em>mais criativos pequenos</em>, nunca subindo o orçamento de um.
          </div>
        </div>

        <!-- Público -->
        <div :style="cartao + 'margin-bottom:14px;'">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:12px;">Público dos anúncios novos</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(140px,100%),1fr));gap:12px;align-items:end;">
            <label><span :style="rotulo">Idade mínima</span><input v-model="publico.idadeMin" type="number" min="18" max="65" :style="campo"></label>
            <label><span :style="rotulo">Idade máxima</span><input v-model="publico.idadeMax" type="number" min="18" max="65" :style="campo"></label>
            <label><span :style="rotulo">Gênero</span>
              <select v-model="publico.genero" :style="campo"><option value="">Todos</option><option value="1">Homens</option><option value="2">Mulheres</option></select>
            </label>
            <label style="display:flex;align-items:center;gap:8px;font-size:12.5px;padding-bottom:8px;"><input v-model="publico.soInstagram" type="checkbox"> Só Instagram</label>
          </div>
          <div style="font-size:11px;color:var(--c-text-faint);margin-top:10px;">Padrão medido: homens, 18–54, só Instagram (feed, stories e reels) — o recorte com menor custo por qualificado.</div>
        </div>

        <!-- Gerador de imagem -->
        <div :style="cartao + 'margin-bottom:14px;'">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:12px;">Gerador de imagem (IA)</div>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(min(200px,100%),1fr));gap:12px;">
            <label><span :style="rotulo">Provedor</span>
              <select v-model="form.image_provider" :style="campo">
                <option :value="null">— escolher —</option>
                <option value="openai">OpenAI (gpt-image)</option>
                <option value="google">Google (Gemini / Imagen)</option>
                <option value="ideogram">Ideogram</option>
              </select>
            </label>
            <label><span :style="rotulo">Chave da API</span>
              <input v-model="form.image_api_key" type="password" autocomplete="off" :placeholder="form.tem_chave_imagem ? '•••••• salva (deixe vazio para manter)' : 'cole a chave'" :style="campo">
            </label>
            <label><span :style="rotulo">Modelo (opcional)</span>
              <input v-model="form.image_model" :placeholder="form.image_provider ? dados.modelos_padrao[form.image_provider] : ''" :style="campo">
            </label>
          </div>
          <div style="display:flex;gap:6px;margin-top:12px;flex-wrap:wrap;">
            <button class="r-tap" :style="botao" :disabled="!form.image_provider" @click="testarImagem">Salvar e testar chave</button>
          </div>
          <div style="font-size:11px;color:var(--c-text-faint);margin-top:10px;line-height:1.55;">
            Cada imagem gerada é lida pela IA antes de ir ao ar: se o texto saiu com erro ou a peça parece anúncio de crédito/conta pessoal, ela é descartada e gerada de novo.
          </div>
        </div>

        <!-- Instruções -->
        <div :style="cartao + 'margin-bottom:14px;'">
          <div style="font-weight:800;font-size:13.5px;margin-bottom:6px;">Instruções para a IA</div>
          <div style="font-size:11.5px;color:var(--c-text-muted);margin-bottom:10px;line-height:1.55;">
            Briefing, posicionamento, o que pode e o que não pode dizer. Tem prioridade sobre tudo. A memória de marketing
            (<NuxtLink to="/marketing/memoria" style="color:inherit;">🧠 Memória</NuxtLink>) e o desempenho dos criativos entram junto, sozinhos.
          </div>
          <textarea v-model="form.instructions" rows="10" :style="campo + 'line-height:1.55;resize:vertical;font-family:ui-monospace,monospace;font-size:11.5px;'" />
        </div>

        <div style="display:flex;justify-content:flex-end;margin-bottom:22px;">
          <button class="r-tap" :disabled="salvando" :style="botao + 'background:var(--accent);color:#fff;'" @click="salvar()">{{ salvando ? 'Salvando…' : 'Salvar configuração' }}</button>
        </div>

        <!-- O que o piloto pôs no ar -->
        <div :style="cartao + 'padding:0;overflow:hidden;margin-bottom:22px;'">
          <div style="padding:13px 16px;border-bottom:1px solid var(--c-surface-1);font-weight:800;font-size:13.5px;">Anúncios do piloto</div>
          <div v-if="!dados.anuncios.length" style="padding:14px 16px;font-size:12px;color:var(--c-text-faint);">Nenhum ainda.</div>
          <div v-else class="r-table-wrap">
            <table class="r-table" style="width:100%;border-collapse:collapse;">
              <thead><tr><th :style="th" /><th :style="th">Criativo</th><th :style="th">Status</th><th :style="th">No ar</th><th :style="th">Verba</th><th :style="th">Copy</th></tr></thead>
              <tbody>
                <tr v-for="a in dados.anuncios" :key="a.id">
                  <td :style="td + 'width:56px;'">
                    <img v-if="a.creative" :src="`${apiOrigin}/api/marketing/creatives/${a.creative.id}/arquivo`" :alt="a.creative.name" loading="lazy" style="width:44px;height:55px;object-fit:cover;border-radius:6px;display:block;">
                  </td>
                  <td :style="td">
                    <strong>{{ a.creative?.name ?? '—' }}</strong>
                    <div style="font-size:10.5px;color:var(--c-text-faint);">{{ a.creative?.source === 'ia' ? 'gerado pela IA' : 'estoque' }} · {{ a.creative?.status }}</div>
                  </td>
                  <td :style="td">
                    <span :style="{ fontWeight: 800, color: a.status === 'ativo' ? 'var(--c-teal)' : 'var(--c-text-faint)' }">{{ a.status }}</span>
                    <div v-if="a.pause_reason" style="font-size:10.5px;color:var(--c-text-faint);max-width:220px;white-space:normal;">{{ a.pause_reason }}</div>
                  </td>
                  <td :style="td + 'white-space:nowrap;'">{{ diasNoAr(a) === null ? '—' : `${diasNoAr(a)} dia(s)` }}</td>
                  <td :style="td + 'white-space:nowrap;'">{{ dinheiro(a.daily_budget) }}</td>
                  <td :style="td + 'max-width:340px;white-space:normal;color:var(--c-text-secondary);'">{{ a.copy?.mensagem }}</td>
                </tr>
              </tbody>
            </table>
          </div>
        </div>

        <!-- Diário -->
        <div :style="cartao + 'padding:0;overflow:hidden;'">
          <div style="padding:13px 16px;border-bottom:1px solid var(--c-surface-1);font-weight:800;font-size:13.5px;">Diário de decisões</div>
          <div v-if="!dados.eventos.length" style="padding:14px 16px;font-size:12px;color:var(--c-text-faint);">Nada registrado ainda.</div>
          <div v-for="e in dados.eventos" :key="e.id" style="padding:8px 16px;border-top:1px solid var(--c-surface-1);display:flex;gap:10px;font-size:12px;line-height:1.5;">
            <span style="color:var(--c-text-faint);white-space:nowrap;font-size:11px;padding-top:1px;">{{ quando(e.created_at) }}</span>
            <span :style="{ fontWeight: 800, whiteSpace: 'nowrap', color: corEvento[e.kind] || 'var(--c-text-secondary)' }">{{ e.kind }}{{ e.simulated ? ' (sim.)' : '' }}</span>
            <span style="flex:1;min-width:0;color:var(--c-text-secondary);">{{ e.message }}</span>
          </div>
        </div>
      </template>
    </div>
  </div>
</template>
