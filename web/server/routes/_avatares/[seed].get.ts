import { adventurer } from '@dicebear/collection'
import { createAvatar } from '@dicebear/core'

// Avatar de quem não tem foto de perfil no WhatsApp — que é a maioria dos contatos
// (ou nunca puseram, ou restringiram a "meus contatos").
//
// Roda AQUI, no servidor do Nuxt, e não no navegador nem na API: o DiceBear é uma
// biblioteca JS (o Laravel não executa), cada SVG tem ~10 KB, e a lista abre com
// centenas de conversas de uma vez — gerar tudo no cliente travaria a tela a cada
// abertura. Servido daqui, o navegador guarda por um ano e não gera de novo.
//
// A biblioteca é instalada no projeto, não consumida da api.dicebear.com: o CRM não
// depende de um serviço externo estar no ar, e o identificador do contato não sai daqui.
//
// A `seed` é um hash do contato (feito na API, ver ConversationController::avatar) e
// nunca o telefone: esta rota não tem autenticação, e telefone de lead não vai para
// dentro de uma URL pública.

// Fundo colorido por trás do personagem. O DiceBear sorteia uma cor desta lista usando
// a própria seed, então a escolha é estável — o mesmo contato tem sempre o mesmo fundo.
const FUNDOS = ['4A90D9', '2AA198', 'E8B84B', 'E8734A', 'C25B7C', '7B6CD9', '4CAF7D', '4B5B73']

export default defineEventHandler((event) => {
  const seed = (getRouterParam(event, 'seed') || '').replace(/\.svg$/, '').slice(0, 64)

  const svg = createAvatar(adventurer, {
    seed,
    backgroundColor: FUNDOS,
    backgroundType: ['solid'],
    // O container já é redondo; o raio evita quina aparecendo no antialiasing da borda.
    radius: 50,
  }).toString()

  setHeader(event, 'Content-Type', 'image/svg+xml')
  // Imutável: a seed identifica o desenho. Mudou o estilo, muda o prefixo da seed.
  setHeader(event, 'Cache-Control', 'public, max-age=31536000, immutable')

  return svg
})
