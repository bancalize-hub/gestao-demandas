import { defineStore } from 'pinia'

export interface Anexo {
  id: number
  nome_arquivo: string
  url: string
  mime_type: string | null
  tamanho: number
}

export interface Ticket {
  id: number
  codigo: string
  tipo: 'BUG' | 'FEATURE'
  titulo: string
  descricao: string
  modulo: string
  prioridade: 'BAIXA' | 'MEDIA' | 'ALTA' | 'CRITICA'
  impacto_negocio: string | null
  status: Status
  ordem: number
  solicitante_nome: string
  solicitante_empresa: string
  solicitante_email: string
  data_solicitacao: string
  comportamento_atual: string | null
  comportamento_esperado: string | null
  passos_reproducao: string | null
  ambiente_url: string | null
  navegador: string | null
  dispositivo: string | null
  data_hora_ocorrencia: string | null
  frequencia: 'SEMPRE' | 'AS_VEZES' | 'UMA_VEZ' | null
  objetivo: string | null
  regras_negocio: string | null
  fluxo_desejado: string | null
  criterios_aceitacao: string | null
  prazo_desejado: string | null
  anexos: Anexo[]
  created_at: string
}

export const STATUSES = ['TRIAGEM', 'A_FAZER', 'EM_ANDAMENTO', 'EM_REVISAO', 'CONCLUIDO'] as const
export type Status = (typeof STATUSES)[number]

export const LABEL_STATUS: Record<Status, string> = {
  TRIAGEM: 'Triagem',
  A_FAZER: 'A Fazer',
  EM_ANDAMENTO: 'Em Andamento',
  EM_REVISAO: 'Em Revisão',
  CONCLUIDO: 'Concluído',
}

function colunasVazias(): Record<Status, Ticket[]> {
  return { TRIAGEM: [], A_FAZER: [], EM_ANDAMENTO: [], EM_REVISAO: [], CONCLUIDO: [] }
}

export const useBoardStore = defineStore('board', () => {
  const { apiFetch, csrf } = useApi()

  const colunas = ref<Record<Status, Ticket[]>>(colunasVazias())
  const carregando = ref(false)
  const erro = ref<string | null>(null)

  async function carregar() {
    carregando.value = true
    erro.value = null
    try {
      const resposta = await apiFetch<{ data: Ticket[] }>('/tickets')
      const novas = colunasVazias()
      for (const ticket of resposta.data) {
        (novas[ticket.status] ?? novas.TRIAGEM).push(ticket)
      }
      colunas.value = novas
    }
    catch {
      erro.value = 'Não foi possível carregar as demandas. A API está rodando?'
    }
    finally {
      carregando.value = false
    }
  }

  // Persiste status/ordem após o drag & drop; em caso de falha recarrega o board.
  async function mover(ticket: Ticket, novoStatus: Status, novaOrdem: number) {
    ticket.status = novoStatus
    ticket.ordem = novaOrdem
    try {
      await csrf()
      await apiFetch(`/tickets/${ticket.id}`, {
        method: 'PATCH',
        body: { status: novoStatus, ordem: novaOrdem },
      })
    }
    catch {
      erro.value = `Falha ao mover ${ticket.codigo}. Recarregando o board...`
      await carregar()
    }
  }

  return { colunas, carregando, erro, carregar, mover }
})
