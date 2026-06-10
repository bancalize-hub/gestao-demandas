interface Usuario {
  id: number
  name: string
  email: string
}

export function useAuth() {
  const usuario = useState<Usuario | null>('usuario', () => null)
  const { apiFetch, csrf } = useApi()

  async function checar(): Promise<boolean> {
    if (usuario.value) return true
    try {
      usuario.value = await apiFetch<Usuario>('/user')
      return true
    }
    catch {
      return false
    }
  }

  async function login(email: string, password: string) {
    await csrf()
    const resposta = await apiFetch<{ data: Usuario }>('/login', {
      method: 'POST',
      body: { email, password },
    })
    usuario.value = resposta.data
  }

  async function logout() {
    try {
      await csrf()
      await apiFetch('/logout', { method: 'POST' })
    }
    finally {
      usuario.value = null
      navigateTo('/login')
    }
  }

  return { usuario, checar, login, logout }
}
