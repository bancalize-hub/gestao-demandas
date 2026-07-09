// Troca o favicon da aba (usado para a logo da empresa no app e no portal).
export function setFavicon(url?: string | null) {
  if (!import.meta.client || !url) return
  let link = document.querySelector<HTMLLinkElement>('link[rel="icon"]')
  if (!link) {
    link = document.createElement('link')
    link.rel = 'icon'
    document.head.appendChild(link)
  }
  link.href = url
}
