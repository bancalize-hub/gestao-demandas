import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useBoardStore } from '~/stores/board'
import { useCrmStore } from '~/stores/crm'

// Tempo real via Laravel Reverb (protocolo Pusher). Canal PRIVADO por empresa
// (`private-crm.{companyId}`): ao receber o aviso 'updated', rebusca os dados pela
// API autenticada (debounced). Cada empresa só recebe os avisos da sua operação.
export default defineNuxtPlugin(() => {
  const cfg = useRuntimeConfig().public
  const apiOrigin = cfg.apiOrigin as string
  ;(window as any).Pusher = Pusher

  function readCookie(name: string): string | null {
    const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
    return m ? decodeURIComponent(m[1]) : null
  }

  const echo = new Echo({
    broadcaster: 'reverb',
    key: cfg.wsKey,
    wsHost: cfg.wsHost,
    wsPort: cfg.wsPort,
    wssPort: cfg.wsPort,
    forceTLS: cfg.wsScheme === 'wss',
    enabledTransports: ['ws', 'wss'],
    // Autoriza canais privados no backend usando o cookie de sessão + CSRF (Sanctum SPA).
    authorizer: (channel: any) => ({
      authorize: (socketId: string, callback: (error: boolean, data?: any) => void) => {
        const doAuth = () => $fetch('/broadcasting/auth', {
          baseURL: apiOrigin,
          method: 'POST',
          credentials: 'include',
          headers: {
            'Accept': 'application/json',
            'X-XSRF-TOKEN': readCookie('XSRF-TOKEN') || '',
          },
          body: { socket_id: socketId, channel_name: channel.name },
        }).then((res: any) => callback(false, res)).catch((err: any) => callback(true, err))

        // Garante o cookie CSRF antes de autorizar (primeira conexão após o load).
        if (!readCookie('XSRF-TOKEN'))
          $fetch('/sanctum/csrf-cookie', { baseURL: apiOrigin, credentials: 'include' }).then(doAuth).catch(doAuth)
        else
          doAuth()
      },
    }),
  })

  // useApi lê daqui o socketId p/ mandar o X-Socket-ID (broadcast toOthers).
  ;(globalThis as any).__crmEcho = echo

  const crm = useCrmStore()

  // Fallback: refetch COMPLETO (lista+deals) no máximo 1x a cada 10s, para eventos
  // sem tratamento incremental. Antes, TODO evento re-baixava a lista inteira
  // (770KB) com 350ms de debounce — 19 downloads num minuto medidos.
  let lastFull = 0
  let fullTimer: ReturnType<typeof setTimeout> | null = null
  const throttledBoards = () => {
    const wait = Math.max(0, 10_000 - (Date.now() - lastFull))
    if (fullTimer)
      return
    fullTimer = setTimeout(() => {
      fullTimer = null
      lastFull = Date.now()
      crm.refreshBoards()
    }, wait)
  }

  let evTimer: ReturnType<typeof setTimeout> | null = null
  const debouncedEvents = () => {
    if (evTimer) clearTimeout(evTimer)
    evTimer = setTimeout(() => crm.refreshEvents(), 1000)
  }

  // Sinal genérico "algo mudou": roteia pelo tipo — cada evento atualiza SÓ o que
  // lhe diz respeito (patch de 1 conversa, só deals, só agenda…).
  const onUpdated = (e: any) => {
    const kind = String(e?.kind ?? '')
    if (kind === 'conversation' && e?.slug) {
      crm.patchConversationFromServer(String(e.slug))
      // Mudança real de conversa pode vir de apuração de presença/etapa — mantém a
      // agenda em dia (com o saved silenciando mudanças triviais, isso é raro).
      debouncedEvents()
      return
    }
    if (kind === 'deal') {
      crm.refreshDeals()
      return
    }
    // Quadro de tarefas: só rebusca se a tela estiver montada (a store só existe aí).
    if (kind === 'board') {
      useBoardStore().refresh()
      return
    }
    if (kind === 'followup' || kind === 'calendar' || kind === 'meeting') {
      debouncedEvents()
      return
    }
    throttledBoards()
    debouncedEvents()
  }

  // Reconexão do WebSocket (rede caiu, notebook dormiu): eventos perdidos não têm
  // replay — re-sincroniza lista + delta da conversa aberta ao reconectar.
  let hadSession = false
  ;(echo.connector as any)?.pusher?.connection?.bind('connected', () => {
    if (hadSession) {
      crm.refreshBoards()
      // Recarrega a última página inteira (não só o delta): se um evento se perdeu
      // NO MEIO da desconexão, o delta ancorado na última msg não cura o buraco.
      if (crm.activeId) crm.loadFullThread(crm.activeId, true)
    }
    hadSession = true
  })

  // Aba voltou ao foco: o que chegou enquanto estava em segundo plano fica lido
  // (o zerar de não-lido em tempo real só acontece com a aba visível e focada).
  const onFocus = () => {
    if (crm.screen === 'chat' && crm.chatOpen && crm.activeId) {
      crm.markRead(crm.activeId)
      crm.syncThread(crm.activeId)
    }
  }
  window.addEventListener('focus', onFocus)
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') onFocus()
  })

  // Assina o canal da empresa do usuário; re-assina se a identidade mudar (login/troca).
  const { user } = useAuth()
  let current: number | null = null
  watch(() => user.value?.company_id ?? null, (companyId) => {
    if (companyId === current)
      return
    if (current !== null)
      echo.leave(`crm.${current}`)
    current = companyId ?? null
    if (companyId) {
      echo.private(`crm.${companyId}`)
        // Push com payload: a mensagem chega PRONTA no evento — zero refetch.
        .listen('.message.new', (e: any) => crm.applyRealtimeMessage(e))
        .listen('.message.patch', (e: any) => crm.applyMessagePatch(e))
        .listen('.updated', onUpdated)
    }
  }, { immediate: true })

  return { provide: { echo } }
})
