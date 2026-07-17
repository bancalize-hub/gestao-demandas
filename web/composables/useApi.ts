// Cliente HTTP para a API Laravel com auth Sanctum (SPA cookie + CSRF).
// Implementação própria (sem nuxt-auth-sanctum, incompatível com Nuxt 3.21).

function readCookie(name: string): string | null {
  if (!import.meta.client)
    return null
  const m = document.cookie.match(new RegExp(`(?:^|; )${name}=([^;]*)`))
  return m ? decodeURIComponent(m[1]) : null
}

export function useApi() {
  const base = useRuntimeConfig().public.apiOrigin as string

  async function ensureCsrf() {
    if (!readCookie('XSRF-TOKEN')) {
      await $fetch('/sanctum/csrf-cookie', { baseURL: base, credentials: 'include' })
    }
  }

  return $fetch.create({
    baseURL: base,
    credentials: 'include',
    async onRequest({ options }) {
      const method = (options.method ?? 'GET').toUpperCase()
      const headers = new Headers(options.headers as HeadersInit)
      headers.set('Accept', 'application/json')

      // Identifica a conexão WebSocket desta aba: o backend usa broadcast()->toOthers()
      // p/ não devolver pra própria aba o evento causado pela ação dela (evita o
      // ciclo abrir conversa → broadcast → re-baixar tudo).
      const sid = (globalThis as any).__crmEcho?.socketId?.()
      if (sid)
        headers.set('X-Socket-ID', sid)

      if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
        await ensureCsrf()
        const token = readCookie('XSRF-TOKEN')
        if (token)
          headers.set('X-XSRF-TOKEN', token)
      }
      options.headers = headers
    },
  })
}
