// Tema claro/escuro por USUÁRIO. Fonte da verdade: preferência salva no backend
// (users.theme); espelhada no localStorage para aplicar sem flash no load.

export type ThemeMode = 'light' | 'dark' | 'system'

const STORAGE_KEY = 'crm.theme'

function systemPrefersDark(): boolean {
  return import.meta.client && window.matchMedia?.('(prefers-color-scheme: dark)').matches
}

/** Resolve o tema efetivo ('light' | 'dark') a partir do modo escolhido. */
function resolve(mode: ThemeMode): 'light' | 'dark' {
  if (mode === 'system') return systemPrefersDark() ? 'dark' : 'light'
  return mode
}

/** Escreve o data-theme no <html> (o CSS reage a :root[data-theme="dark"]). */
function paint(mode: ThemeMode) {
  if (!import.meta.client) return
  document.documentElement.setAttribute('data-theme', resolve(mode))
  document.documentElement.style.colorScheme = resolve(mode)
}

export function useTheme() {
  const mode = useState<ThemeMode>('theme.mode', () => 'light')
  const api = useApi()

  /** Aplica imediatamente a preferência do localStorage (chamado cedo, sem flash). */
  function initFromStorage() {
    if (!import.meta.client) return
    const saved = localStorage.getItem(STORAGE_KEY) as ThemeMode | null
    mode.value = saved || 'light'
    paint(mode.value)
  }

  /** Sincroniza com a preferência do usuário vinda do backend (após login). */
  function syncFromUser(theme?: string | null) {
    const t = (theme as ThemeMode) || 'light'
    mode.value = t
    if (import.meta.client) localStorage.setItem(STORAGE_KEY, t)
    paint(t)
  }

  /** Usuário troca o tema: aplica na hora, persiste local e no backend. */
  async function setTheme(next: ThemeMode) {
    mode.value = next
    if (import.meta.client) localStorage.setItem(STORAGE_KEY, next)
    paint(next)
    try {
      await api('/api/me/theme', { method: 'PATCH', body: { theme: next } })
    }
    catch {
      // silencioso: a preferência local já valeu; sincroniza na próxima vez.
    }
  }

  function toggle() {
    setTheme(resolve(mode.value) === 'dark' ? 'light' : 'dark')
  }

  const isDark = computed(() => resolve(mode.value) === 'dark')

  return { mode, isDark, initFromStorage, syncFromUser, setTheme, toggle }
}
