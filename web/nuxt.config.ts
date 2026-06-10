export default defineNuxtConfig({
  compatibilityDate: '2026-06-10',
  modules: ['@nuxtjs/tailwindcss', '@pinia/nuxt'],
  devtools: { enabled: false },
  runtimeConfig: {
    public: {
      // Mesmo host (localhost) da página para o cookie de sessão Sanctum valer
      apiBase: process.env.NUXT_PUBLIC_API_BASE || 'http://localhost:8000/api',
    },
  },
  app: {
    head: {
      title: 'Gestão de Demandas',
      htmlAttrs: { lang: 'pt-BR' },
    },
  },
})
