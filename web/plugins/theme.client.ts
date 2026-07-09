// Aplica tema (claro/escuro) e a marca da empresa o mais cedo possível no load,
// a partir do que foi salvo no localStorage — evita "flash" do tema/cor errados
// enquanto o /api/me ainda não voltou. Depois, o useAuth sincroniza com o backend.
export default defineNuxtPlugin(() => {
  const { initFromStorage } = useTheme()
  initFromStorage()

  // Marca em cache (cor de destaque da empresa) aplicada na hora.
  try {
    const cached = localStorage.getItem('crm.brand')
    if (cached) applyBrand(cached)
  }
  catch {}
})
