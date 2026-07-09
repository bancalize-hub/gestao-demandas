// Gating de autenticação global (Sanctum SPA via composable próprio).
const PUBLIC_ROUTES = ['/login', '/register', '/solicitar']

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
  if (user.value && (to.path === '/login' || to.path === '/register')) {
    return navigateTo('/', { replace: true })
  }
  // Área administrativa: somente admin da empresa.
  if (to.path.startsWith('/admin') && !user.value?.is_admin) {
    return navigateTo('/', { replace: true })
  }
  // Agente (opera a VPS): EXCLUSIVO do dono da plataforma (super-admin).
  if (to.path === '/agente' && !user.value?.is_super_admin) {
    return navigateTo('/', { replace: true })
  }
})
