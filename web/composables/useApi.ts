type FetchOptions = Parameters<typeof $fetch>[1]

export function useApi() {
  const config = useRuntimeConfig()
  const apiBase = config.public.apiBase
  const serverBase = apiBase.replace(/\/api$/, '')

  function xsrfHeader(): Record<string, string> {
    const token = useCookie('XSRF-TOKEN').value
    return token ? { 'X-XSRF-TOKEN': token } : {}
  }

  const apiFetch = <T>(path: string, options: FetchOptions = {}) =>
    $fetch<T>(`${apiBase}${path}`, {
      credentials: 'include',
      ...options,
      headers: {
        Accept: 'application/json',
        ...xsrfHeader(),
        ...(options?.headers as Record<string, string> | undefined),
      },
    })

  // Necessário antes de POST/PATCH autenticados (sessão Sanctum SPA)
  const csrf = () => $fetch(`${serverBase}/sanctum/csrf-cookie`, { credentials: 'include' })

  return { apiBase, apiFetch, csrf }
}
