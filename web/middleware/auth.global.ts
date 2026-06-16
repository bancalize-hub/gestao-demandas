// Gating de autenticação global (substitui os middlewares nomeados do módulo,
// que não registram no Nuxt 3.21). Usa os composables públicos do nuxt-auth-sanctum.
const PUBLIC_ROUTES = ['/login', '/solicitar', '/reuniao']

export default defineNuxtRouteMiddleware(async (to) => {
  const isPublic = PUBLIC_ROUTES.some(p => to.path === p || to.path.startsWith(`${p}/`))

  const { isAuthenticated, refreshIdentity } = useSanctumAuth()

  // Garante a identidade carregada (cobre corrida do load inicial e revalidação).
  if (!isAuthenticated.value) {
    try {
      await refreshIdentity()
    }
    catch {
      /* permanece como visitante */
    }
  }

  if (!isAuthenticated.value && !isPublic) {
    return navigateTo('/login', { replace: true })
  }
  if (isAuthenticated.value && to.path === '/login') {
    return navigateTo('/', { replace: true })
  }
})
