/*
  Largura da janela em um lugar só.

  Antes cada componente chamava `useIsMobile()`, e cada chamada pendurava o SEU próprio
  listener de `resize` — cinco telas montadas = cinco listeners fazendo a mesma conta a
  cada pixel arrastado. Aqui o estado é de módulo (o app é SPA, `ssr:false`, um usuário
  por aba), o listener é um só e é ligado na primeira vez que alguém pergunta.

  Os cortes são os MESMOS de assets/css/responsive.css. Se mudar um, mude o outro —
  quando os dois discordam, aparece uma faixa de larguras em que o CSS acha que é
  celular e o JS acha que é desktop (ou o contrário), e o layout briga consigo mesmo.

  FRONTEIRA INCLUSIVA: `@media (max-width: 820px)` INCLUI 820, então aqui é `<=` e não
  `<`. Com `<` a discordância não era teórica — 820px é exatamente a largura do iPad
  Air 11" em pé, e nesse aparelho o JS montava o esqueleto de desktop enquanto o CSS
  formatava o conteúdo para celular. Mesma armadilha em 1200.
*/

export const BREAKPOINTS = {
  xs: 480, // celular
  sm: 820, // celular grande / tablet em pé
  md: 1200, // tablet deitado / notebook pequeno
  xl: 1600, // a partir daqui o problema é esticar, não espremer
} as const

// 1280 é o chute de SSR/primeiro render: erra para o lado do desktop, que é o layout
// que o estilo inline já descreve — assim nada "pula" de mobile para desktop ao hidratar.
const width = ref(1280)
const coarse = ref(false)
let bound = false

function measure() {
  if (!import.meta.client) return
  width.value = window.innerWidth
  coarse.value = window.matchMedia?.('(pointer: coarse)').matches ?? false
}

function bind() {
  if (bound || !import.meta.client) return
  bound = true
  measure()
  window.addEventListener('resize', measure, { passive: true })
  // `resize` sozinho perde a virada de retrato/paisagem em alguns Android.
  window.addEventListener('orientationchange', measure, { passive: true })
}

export function useBreakpoint() {
  if (import.meta.client) {
    bind()
    // Um componente pode montar antes do primeiro `resize`; remede no mount dele.
    // O `getCurrentInstance` é o guarda para quando alguém chamar isto fora de um
    // `setup()` (um store, um plugin) — aí `onMounted` não existe e o Vue avisa no console.
    if (getCurrentInstance()) onMounted(measure)
  }

  return {
    width: readonly(width),
    /** ≤480px — celular comum em pé. */
    isPhone: computed(() => width.value <= BREAKPOINTS.xs),
    /** ≤820px — celular e tablet em pé. É o corte histórico do app. */
    isMobile: computed(() => width.value <= BREAKPOINTS.sm),
    /** 821–1200px — tablet deitado / notebook pequeno. */
    isTablet: computed(() => width.value > BREAKPOINTS.sm && width.value <= BREAKPOINTS.md),
    /** >1200px — desktop. */
    isDesktop: computed(() => width.value > BREAKPOINTS.md),
    /** ≥1600px — tela larga: aqui entra o teto de largura (.r-page / .r-wide-cap). */
    isWide: computed(() => width.value >= BREAKPOINTS.xl),
    /** Aparelho de ponteiro grosso (dedo): alvos de toque maiores, sem hover. */
    isTouch: readonly(coarse),
  }
}
