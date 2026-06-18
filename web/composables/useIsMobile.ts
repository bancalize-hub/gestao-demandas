// Detecção reativa de tela estreita (celular/tablet em pé). SSR-safe.
export function useIsMobile(breakpoint = 820) {
  const isMobile = ref(false)

  function update() {
    if (import.meta.client) isMobile.value = window.innerWidth < breakpoint
  }

  onMounted(() => {
    update()
    window.addEventListener('resize', update)
  })
  onBeforeUnmount(() => {
    if (import.meta.client) window.removeEventListener('resize', update)
  })

  return isMobile
}
