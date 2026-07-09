// Aplica a MARCA da empresa (cor de destaque) em runtime, derivando toda a
// família de acento a partir de UMA cor. Os defaults ficam no theme.css; aqui
// sobrescrevemos as variáveis --accent* quando a empresa tem cor própria.

type RGB = [number, number, number]

function hexToRgb(hex: string): RGB | null {
  let h = hex.replace('#', '').trim()
  if (h.length === 3) h = h.split('').map(c => c + c).join('')
  if (h.length !== 6 || /[^0-9a-fA-F]/.test(h)) return null
  const n = Number.parseInt(h, 16)
  return [(n >> 16) & 255, (n >> 8) & 255, n & 255]
}
function toHex([r, g, b]: RGB): string {
  return '#' + [r, g, b].map(v => Math.max(0, Math.min(255, Math.round(v))).toString(16).padStart(2, '0')).join('')
}
function mix(a: RGB, b: RGB, t: number): RGB {
  return [a[0] + (b[0] - a[0]) * t, a[1] + (b[1] - a[1]) * t, a[2] + (b[2] - a[2]) * t] as RGB
}
const darken = (rgb: RGB, t: number) => mix(rgb, [0, 0, 0], t)
// Luminância relativa (WCAG) 0..1.
function luminance([r, g, b]: RGB): number {
  const a = [r, g, b].map((v) => {
    v /= 255
    return v <= 0.03928 ? v / 12.92 : ((v + 0.055) / 1.055) ** 2.4
  })
  return 0.2126 * a[0] + 0.7152 * a[1] + 0.0722 * a[2]
}
const rgbStr = (rgb: RGB) => rgb.map(Math.round).join(',')

/**
 * Aplica a cor de destaque da empresa (ou limpa, voltando ao padrão do tema).
 * Só sobrescreve os tokens de acento INDEPENDENTES de tema; os derivados
 * (--accent-hi/deep/soft) vêm de color-mix(var(--accent)) no theme.css, então
 * rastreiam automaticamente esta cor e ainda invertem por tema quando preciso.
 */
export function applyBrand(brandColor?: string | null) {
  if (!import.meta.client) return
  const root = document.documentElement.style
  const vars = ['--accent', '--accent-rgb', '--accent-ink', '--accent-ink-rgb']

  const rgb = brandColor ? hexToRgb(brandColor) : null
  if (!rgb) {
    // Sem cor da empresa: remove overrides → valem os defaults do theme.css.
    vars.forEach(v => root.removeProperty(v))
    return
  }

  const lum = luminance(rgb)
  // Texto sobre o acento: escuro quando o acento é claro/vibrante, branco quando é escuro.
  const ink: RGB = lum > 0.55 ? darken(rgb, 0.82) : [255, 255, 255]

  root.setProperty('--accent', toHex(rgb))
  root.setProperty('--accent-rgb', rgbStr(rgb))
  root.setProperty('--accent-ink', toHex(ink))
  root.setProperty('--accent-ink-rgb', rgbStr(ink))
}
