import { BREAKPOINTS, useBreakpoint } from './useBreakpoint'

/**
 * Tela estreita (celular/tablet em pé). Atalho histórico — continua existindo porque
 * cinco telas já o usam; por baixo é o `useBreakpoint()`, que mede a janela em um
 * listener só. Para distinguir celular de tablet de desktop, use `useBreakpoint()`.
 */
export function useIsMobile(breakpoint: number = BREAKPOINTS.sm) {
  const { width, isMobile } = useBreakpoint()
  // O caminho comum (820) reaproveita o computed já pronto.
  return breakpoint === BREAKPOINTS.sm ? isMobile : computed(() => width.value <= breakpoint)
}
