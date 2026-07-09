import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
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

  const crm = useCrmStore()
  let t: ReturnType<typeof setTimeout> | null = null
  const refresh = () => {
    if (t) clearTimeout(t)
    t = setTimeout(() => {
      crm.refreshBoards()
      crm.refreshEvents() // mantém a agenda em dia (presença/resumo apurados pelo servidor)
    }, 350) // debounce: agrupa rajadas
  }

  // Assina o canal da empresa do usuário; re-assina se a identidade mudar (login/troca).
  const { user } = useAuth()
  let current: number | null = null
  watch(() => user.value?.company_id ?? null, (companyId) => {
    if (companyId === current)
      return
    if (current !== null)
      echo.leave(`crm.${current}`)
    current = companyId ?? null
    if (companyId)
      echo.private(`crm.${companyId}`).listen('.updated', refresh)
  }, { immediate: true })

  return { provide: { echo } }
})
