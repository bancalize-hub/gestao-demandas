import Echo from 'laravel-echo'
import Pusher from 'pusher-js'
import { useCrmStore } from '~/stores/crm'

// Tempo real via Laravel Reverb (protocolo Pusher). Canal público 'crm':
// ao receber o aviso 'updated', rebusca os dados pela API autenticada (debounced).
export default defineNuxtPlugin(() => {
  const cfg = useRuntimeConfig().public
  ;(window as any).Pusher = Pusher

  const echo = new Echo({
    broadcaster: 'reverb',
    key: cfg.wsKey,
    wsHost: cfg.wsHost,
    wsPort: cfg.wsPort,
    wssPort: cfg.wsPort,
    forceTLS: cfg.wsScheme === 'wss',
    enabledTransports: ['ws', 'wss'],
  })

  const crm = useCrmStore()
  let t: ReturnType<typeof setTimeout> | null = null
  const refresh = () => {
    if (t) clearTimeout(t)
    t = setTimeout(() => crm.refreshBoards(), 350) // debounce: agrupa rajadas
  }

  echo.channel('crm').listen('.updated', refresh)

  return { provide: { echo } }
})
