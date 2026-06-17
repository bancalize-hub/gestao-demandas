// Gating de autenticação global (Sanctum SPA via composable próprio).
const PUBLIC_ROUTES = ['/login', '/solicitar', '/reuniao']

export default defineNuxtRouteMiddleware(async (to) => {
  const isPublic = PUBLIC_ROUTES.some(p => to.path === p || to.path.startsWith(`${p}/`))

  const { user, fetchUser } = useAuth()

  // Garante a identidade carregada (load inicial / revalidação).
  if (user.value === null) {
    await fetchUser()
  }

  if (!user.value && !isPublic) {
    return navigateTo('/login', { replace: true })
  }
  if (user.value && to.path === '/login') {
    return navigateTo('/', { replace: true })
  }
})
