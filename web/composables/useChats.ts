// Quantos chats existem no menu lateral (1 a 4) e em que ORDEM os balões aparecem.
// Cada balão abre uma página própria (/, /chat-2, /chat-3, …) com tab, busca e filtro
// independentes — dá para deixar um chat só de qualificados, outro de CS e outro com
// todos os leads, e arrastar os balões para a hierarquia que quiser. A preferência é
// por navegador (localStorage), igual à escolha de tab de cada chat.
export const MAX_CHATS = 4
const total = ref(1)
// Ordem de exibição dos balões (números de instância). O balão guarda o número dele
// mesmo mudando de posição — a URL e as preferências continuam as mesmas.
const ordem = ref<number[]>([1])
let carregado = false

function persistir() {
  try {
    localStorage.setItem('crm.chats', String(total.value))
    localStorage.setItem('crm.chats.order', JSON.stringify(ordem.value))
  }
  catch { /* sem storage, sem persistência — a sessão atual segue valendo */ }
}

/** Garante que a ordem tem exatamente os chats existentes (sem duplicar nem sobrar). */
function sanear() {
  const todos = Array.from({ length: total.value }, (_, k) => k + 1)
  const validos = [...new Set(ordem.value.filter(i => todos.includes(i)))]
  ordem.value = [...validos, ...todos.filter(i => !validos.includes(i))]
}

export function useChats() {
  if (!carregado && import.meta.client) {
    carregado = true
    try {
      const n = Number.parseInt(localStorage.getItem('crm.chats') || '', 10)
      if (n >= 1 && n <= MAX_CHATS) total.value = n
      // Versão anterior guardava só "tem um segundo chat?" — migra pra contagem.
      else if (localStorage.getItem('crm.chat2') === '1') total.value = 2
      const o = JSON.parse(localStorage.getItem('crm.chats.order') || '[]')
      if (Array.isArray(o)) ordem.value = o.filter((x: any) => Number.isInteger(x))
    }
    catch { /* sem storage: fica um chat só */ }
    sanear()
  }
  function setChats(n: number) {
    total.value = Math.max(1, Math.min(MAX_CHATS, n))
    sanear()
    persistir()
  }
  function setOrder(o: number[]) {
    ordem.value = [...o]
    sanear()
    persistir()
  }
  /**
   * Remove o chat `i` (2..total). As preferências (tab escolhida) dos chats seguintes
   * andam uma posição para trás, para quem sumir ser ESTE balão — sem o deslocamento,
   * decrementar a contagem apagaria sempre o último, não o clicado. A ordem dos balões
   * é renumerada do mesmo jeito, preservando as posições escolhidas.
   */
  function removeChat(i: number) {
    if (total.value <= 1 || i <= 1 || i > total.value) return
    try {
      for (let k = i; k < total.value; k++) {
        const prox = localStorage.getItem(`crm.chat.panes:${k + 1}`)
        if (prox === null) localStorage.removeItem(`crm.chat.panes:${k}`)
        else localStorage.setItem(`crm.chat.panes:${k}`, prox)
      }
      localStorage.removeItem(`crm.chat.panes:${total.value}`)
    }
    catch { /* sem storage: só decrementa */ }
    ordem.value = ordem.value.filter(x => x !== i).map(x => x > i ? x - 1 : x)
    setChats(total.value - 1)
  }
  return { chats: total, order: ordem, setChats, setOrder, removeChat }
}
