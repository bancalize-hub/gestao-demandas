export default defineNuxtRouteMiddleware(async () => {
  // Os cookies de sessão vivem no navegador; a checagem só faz sentido no cliente.
  if (import.meta.server) return

  const { checar } = useAuth()
  if (!(await checar())) return navigateTo('/login')
})
