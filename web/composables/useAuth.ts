// Autenticação Sanctum SPA (cookie de sessão). Estado compartilhado via useState.

export interface AuthCompany {
  id: number
  name: string
  slug: string
  is_active: boolean
  brand_color?: string | null
  logo_light_url?: string | null
  logo_dark_url?: string | null
}

export interface AuthUser {
  id: number
  name: string
  email: string
  is_admin: boolean
  is_super_admin: boolean
  company_id: number | null
  company?: AuthCompany | null
  theme?: string | null
}

export function useAuth() {
  const user = useState<AuthUser | null>('auth.user', () => null)
  const api = useApi()

  const isAuthenticated = computed(() => user.value !== null)

  // Aplica tema e marca da empresa a partir do usuário carregado (e guarda em cache).
  function applyIdentityTheming(u: AuthUser | null) {
    if (!u) return
    const { syncFromUser, isDark } = useTheme()
    syncFromUser(u.theme)
    const brand = u.company?.brand_color ?? null
    applyBrand(brand)
    // Favicon da aba = logo da empresa (variante do tema atual).
    setFavicon((isDark.value ? u.company?.logo_dark_url : u.company?.logo_light_url) || u.company?.logo_light_url || u.company?.logo_dark_url)
    if (import.meta.client) {
      if (brand) localStorage.setItem('crm.brand', brand)
      else localStorage.removeItem('crm.brand')
    }
  }

  async function fetchUser() {
    try {
      user.value = await api<AuthUser>('/api/me')
      applyIdentityTheming(user.value)
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
    applyIdentityTheming(user.value)
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
