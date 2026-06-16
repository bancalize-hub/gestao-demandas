export default defineNuxtConfig({
  compatibilityDate: '2026-06-10',
  // SPA: a VPS não consegue bater no próprio hostname público (loop Cloudflare),
  // então o auth/fetch roda no browser (cross-subdomínio já configurado).
  ssr: false,
  modules: ['@nuxtjs/tailwindcss', '@pinia/nuxt', 'nuxt-auth-sanctum'],
  css: ['~/assets/css/main.css'],
  devtools: { enabled: false },
  runtimeConfig: {
    public: {
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api',
    },
  },
  sanctum: {
    // Origem da API (sem /api). Cookie de sessão compartilhado via SESSION_DOMAIN=.bancalize.com.br
    baseUrl: process.env.NUXT_PUBLIC_API_ORIGIN || 'https://api-demandas.bancalize.com.br',
    endpoints: {
      login: '/api/login',
      logout: '/api/logout',
      user: '/api/me',
    },
    redirect: {
      onLogin: '/',
      onLogout: '/login',
      onAuthOnly: '/login',
      onGuestOnly: '/',
    },
  },
  app: {
    head: {
      title: 'CRM com chat ao vivo',
      htmlAttrs: { lang: 'pt-BR' },
      link: [
        { rel: 'preconnect', href: 'https://fonts.googleapis.com' },
        { rel: 'preconnect', href: 'https://fonts.gstatic.com', crossorigin: '' },
        {
          rel: 'stylesheet',
          href: 'https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&display=swap',
        },
      ],
    },
  },
})
