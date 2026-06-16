/** Paleta do design "CRM com chat ao vivo" — tema escuro estilo WhatsApp. */
export default {
  theme: {
    extend: {
      fontFamily: {
        sans: ['Manrope', 'system-ui', 'sans-serif'],
      },
      colors: {
        // Destaque verde WhatsApp + violeta da IA
        wa: { DEFAULT: '#25D366', hi: '#2ee070', ink: '#062014', deep: '#0e8a4f' },
        ai: { DEFAULT: '#7c6cf5', soft: '#a89bf9' },
        info: '#53bdeb',
        warn: '#ffb443',
        danger: '#ff6b6b',
        hot: '#ff7a45',
        // Superfícies (do mais escuro ao mais claro)
        night: '#06090b',
        rail: '#0a0f12',
        base: '#0b141a',
        col: '#0f181e',
        panel: '#111b21',
        sunken: '#16222a',
        line: '#1c2730',
        chip: '#202c33',
        raise: '#2a3942',
        // Texto
        fg: { DEFAULT: '#e9edef', soft: '#aebac1', muted: '#8696a0' },
      },
      boxShadow: {
        wa: '0 6px 16px rgba(37,211,102,.30)',
      },
    },
  },
}
