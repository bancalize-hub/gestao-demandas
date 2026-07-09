// Autenticação Sanctum SPA (cookie de sessão). Estado compartilhado via useState.

export interface AuthCompany {
  id: number
  name: string
  slug: string
  is_active: boolean
}

export interface AuthUser {
  id: number
  name: string
  email: string
  is_admin: boolean
  is_super_admin: boolean
  company_id: number | null
  company?: AuthCompany | null
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

  // Cadastro self-service: cria a empresa + usuário dono e já autentica.
  async function register(payload: { company: string, name: string, email: string, password: string }) {
    const res = await api<{ user: AuthUser }>('/api/register', { method: 'POST', body: payload })
    user.value = res.user
    return user.value
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

  return { user, isAuthenticated, fetchUser, login, register, logout }
}
