# Plano — Painel Super Admin (dono da plataforma)

Documento de planejamento. Nada aqui foi implementado ainda, exceto o que está
marcado como **já existe**.

## 1. Para que serve

O CRM virou multi-empresa: cada empresa tem seus próprios clientes, usuários e
números de WhatsApp. Hoje quem administra **uma** empresa tem `/admin/*`
(usuários, marca, WhatsApp, campanhas, automações, memória). Não existe nenhuma
tela para quem administra **a plataforma inteira** — o dono precisa entrar no
banco e na VPS por SSH para responder perguntas básicas: quantas empresas
existem, quem está usando, qual número parou de entregar.

O incidente de 27/07/2026 é o argumento central: o número principal da Empresa 1
começou a levar recusa `463` do WhatsApp às 23h24 do dia 26 e **ninguém
percebeu por quase 20 horas** — enquanto isso, toda primeira resposta a lead novo
(inclusive as da IA) morria em silêncio. Um painel com a saúde dos números teria
mostrado isso na primeira hora.

## 2. Quem acessa

| Item | Situação |
|---|---|
| Flag `users.is_super_admin` | **já existe** (só o usuário 1 tem hoje) |
| Middleware `super.admin` (`EnsureSuperAdmin`) | **já existe**, registrado em `bootstrap/app.php` |
| Rotas protegidas por ele | **já existe**: só o grupo `/api/agent/*` (página `/agente`) |
| Páginas `/super/*` no front | a criar |

Regra que o painel herda do `/agente`: **nunca exposto às empresas**. Toda rota
nova entra no mesmo grupo `Route::middleware('super.admin')`.

## 3. Princípios

1. **O painel roda fora do escopo de empresa.** O `CompanyScope` filtra tudo por
   `company_id`; as consultas do painel precisam de `withoutGlobalScopes()`
   explícito. Isso é justamente o que torna essas telas perigosas — cada consulta
   precisa dizer de que empresa está falando.
2. **Agregado por padrão, conteúdo só quando necessário.** O painel mostra
   contagem, taxa e estado. Ler mensagem de cliente de outra empresa é exceção,
   passa por "entrar como" e fica registrado.
3. **Toda ação de plataforma é auditada** — principalmente impersonation.
4. **Nada cruza empresa.** Regra aprendida hoje: o fallback de envio só aceita
   número irmão da mesma `company_id`; cair no número de outra empresa mandaria
   o cliente de uma pelo WhatsApp da outra.

## 4. Fases

### Fase 1 — Empresas e usuários (base)

Página `/super/empresas`.

- Lista de empresas: nome, criada em, nº de usuários, nº de conversas, nº de
  números, última atividade.
- Ficha da empresa: editar nome, **suspender/reativar** (bloqueia login dos
  usuários sem apagar dado), limites do plano.
- Usuários de qualquer empresa: listar, promover a admin, resetar senha,
  desativar.
- **Entrar como** (impersonation): assume a sessão de um usuário para dar
  suporte; banner fixo "você está como Fulano (Empresa X)" e botão de sair.
  Registra início e fim na auditoria.

Banco: `companies` ganha `status` (`active`/`suspended`), `plan`,
`suspended_at`. Novo `audit_logs` (ver Fase 6).

### Fase 2 — Saúde dos números de WhatsApp (prioridade)

Página `/super/numeros`. É a tela que faltava hoje.

Por número (`wa_accounts`), atravessando empresas:

| Coluna | Fonte |
|---|---|
| Empresa / nome / telefone / papel | `wa_accounts` |
| Estado da conexão | `GET /instance/connectionState` do Evolution |
| Enviadas 24h · entregues · lidas | `messages` (`is_out=1`) por `wa_account_id` |
| **Recusadas (`error`) e % do total** | idem — o sinal do 463 |
| Última recusa | `MAX(updated_at)` das `error` |
| Cap diário / enviadas hoje | `daily_cap`, `sent_today` |

Ações: reconectar (QR), ativar/desativar, definir como número de backup da
empresa.

Detalhe importante: hoje o CRM já reenvia sozinho por um número irmão da mesma
empresa quando leva 463 — mas **a Empresa 1 só tem um número**, então o
mecanismo fica inerte. A tela precisa mostrar isso explicitamente ("sem número
de backup") em vez de deixar o dono descobrir no incidente.

### Fase 3 — Alertas

Regras simples avaliadas no agendador (`AlertTick`):

- taxa de recusa de um número > 20% em 30 min → avisa;
- instância caiu (`state != open`) por mais de 5 min → avisa;
- fila de jobs parada / scheduler sem rodar há 10 min → avisa;
- empresa sem nenhuma resposta enviada há X horas em horário comercial → avisa.

Entrega: e-mail para o super-admin + mensagem no WhatsApp interno. Cada alerta
some sozinho quando a condição normaliza.

### Fase 4 — Saúde da plataforma

Página `/super/plataforma`, leitura só:

- processos pm2 (`gestao-web`, `gestao-reverb`, `gestao-scheduler`,
  `gestao-agent-worker`) — no ar? há quanto tempo?
- Evolution: versão da imagem, versão do Baileys, estado das instâncias;
- último erro do `laravel.log` agregado por tipo e contagem nas 24h;
- disco, memória, workers do PHP-FPM;
- migrations pendentes.

### Fase 5 — Uso e faturamento

Página `/super/uso`. Por empresa e por mês: mensagens enviadas/recebidas,
conversas novas, reuniões, usuários ativos, números. Base para plano e cobrança
(e para achar o tenant que está consumindo o servidor inteiro).

### Fase 6 — Auditoria

Tabela `audit_logs` (`user_id`, `company_id`, `action`, `target_type`,
`target_id`, `meta` JSON, `ip`, `created_at`). Escreve em: impersonation,
suspender/reativar empresa, mexer em número de WhatsApp, criar/remover usuário,
alterar limite de plano. Página `/super/auditoria` com filtro por empresa,
usuário e período.

## 5. Rotas previstas

Todas dentro de `Route::middleware('super.admin')`:

```
GET    /api/super/companies                 lista + métricas
POST   /api/super/companies                 cria
PATCH  /api/super/companies/{company}       renomeia, suspende, muda plano
GET    /api/super/companies/{company}/users
POST   /api/super/impersonate/{user}        entra como
POST   /api/super/impersonate/stop          volta a ser você
GET    /api/super/wa-accounts               saúde dos números (todas empresas)
POST   /api/super/wa-accounts/{acct}/reconnect
GET    /api/super/platform                  processos, Evolution, disco, erros
GET    /api/super/usage?month=YYYY-MM       uso por empresa
GET    /api/super/audit                     auditoria
```

Front: `web/pages/super/{empresas,numeros,plataforma,uso,auditoria}.vue`, com
entrada no `Rail.vue` visível só quando `is_super_admin`.

## 6. Riscos

| Risco | Mitigação |
|---|---|
| Vazamento entre empresas numa consulta sem escopo | Toda query do painel declara a empresa; revisão específica desse ponto antes do deploy |
| Impersonation virar porta dos fundos | Auditada, com banner permanente e expiração de sessão |
| Painel pesado derrubar a VPS (2 vCPU) | Métricas agregadas em cache de 1–5 min, nunca query crua por request |
| Chamar o Evolution a cada carregamento | Estado da instância vem do cache do `wa_accounts.state`, atualizado pelo agendador |

## 7. Ordem sugerida

1. **Fase 2** (saúde dos números) — é o buraco que causou prejuízo real hoje.
2. **Fase 3** (alertas) — transforma a tela em aviso ativo.
3. **Fase 1** (empresas/usuários/impersonation) — vira necessidade quando entrar a 3ª empresa.
4. Fases 4–6 conforme a plataforma crescer.
