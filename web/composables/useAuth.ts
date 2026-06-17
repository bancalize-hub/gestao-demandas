// Autenticação Sanctum SPA (cookie de sessão). Estado compartilhado via useState.

export interface AuthUser {
  id: number
  name: string
  email: string
  is_admin: boolean
}

export function useAuth() {
  const user = useState<AuthUser | null>('auth.user', () => null)
  const api = useApi()

  const isAuthenticated = computed(() => user.value !== null)

  async function fetchUser() {
    try {
      user.value = await api<AuthUser>('/api/me')
    }
    catch {
      user.value = null
    }
    return user.value
  }

  async function login(credentials: { email: string, password: string, remember?: boolean }) {
    await api('/api/login', { method: 'POST', body: credentials })
    await fetchUser()
  }

  async function logout() {
    try {
      await api('/api/logout', { method: 'POST' })
    }
    finally {
      user.value = null
      await navigateTo('/login', { replace: true })
    }
  }

  return { user, isAuthenticated, fetchUser, login, logout }
}
