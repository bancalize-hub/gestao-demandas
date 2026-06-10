# Plano — Sistema de Gestão de Demandas (Laravel 12 + Nuxt 3)

## 1. Visão Geral

Sistema web com duas frentes:

1. **Formulário público** (`/solicitar`) — link compartilhável que você envia aos clientes. Cada envio cria um ticket automaticamente no board.
2. **Painel Kanban** (`/board`) — área administrativa (só você acessa) onde as demandas chegam e são movidas entre colunas por drag & drop. Cada card carrega **todas** as informações preenchidas no formulário.

```
Cliente ──> /solicitar (Nuxt, público) ──> POST /api/tickets (Laravel) ──> coluna "Triagem"
Você   ──> /board (Nuxt, autenticado) ──> GET/PATCH /api/tickets ──> arrasta cards, detalhes, filtros
```

## 2. Stack

| Camada | Escolha | Por quê |
|---|---|---|
| Backend | **Laravel 12 (PHP 8.3+)** — API REST | Migrations, validação condicional (FormRequest), storage de anexos, mail e queue nativos |
| Auth | **Laravel Sanctum** (SPA cookie-based) | Um único usuário admin (seeder); protege board e rotas de escrita |
| Banco | **MySQL 8** (dev via Laragon, produção em VPS/managed) | Banco escolhido pelo usuário; suportado nativamente pelo Laravel |
| Frontend | **Nuxt 3 + TypeScript** | SSR para o form público, SPA para o board |
| UI | **Nuxt UI + Tailwind CSS** | Componentes oficiais (UForm, UModal, UBadge, USelect) com validação integrada via zod |
| Drag & drop | **vuedraggable (vue.draggable.next / SortableJS)** | Padrão para kanban em Vue 3 |
| Estado | **Pinia** | Store do board (colunas, cards, filtros) |
| Anexos | **Laravel Storage** (disco `public` no dev; S3-compatível em produção) | Upload multipart direto pela API |
| E-mail (fase 4) | **Laravel Mail + queue** (SMTP/Resend/Mailgun) | Confirmação ao cliente e aviso de conclusão |
| Deploy | **VPS (Forge/Ploi/Hostinger) para a API** + **Nuxt no mesmo VPS ou Vercel/Netlify** | Link público para os clientes |

## 3. Estrutura do Repositório (monorepo)

```
Gestao/
├─ api/                          # Laravel 12
│  ├─ app/
│  │  ├─ Enums/        (TipoTicket, Prioridade, StatusTicket, Frequencia)
│  │  ├─ Models/       (Ticket, Anexo)
│  │  ├─ Http/
│  │  │  ├─ Controllers/Api/ (TicketController, AnexoController, AuthController)
│  │  │  ├─ Requests/  (StoreTicketRequest, UpdateTicketRequest)
│  │  │  └─ Resources/ (TicketResource, AnexoResource)
│  │  └─ Mail/         (TicketCriado, TicketConcluido)   # fase 4
│  ├─ database/
│  │  ├─ migrations/   (create_tickets, create_anexos)
│  │  └─ seeders/      (AdminUserSeeder)
│  └─ routes/api.php
└─ web/                          # Nuxt 3
   ├─ pages/
   │  ├─ solicitar.vue           # form público
   │  ├─ login.vue
   │  └─ board.vue               # kanban (middleware auth)
   ├─ components/
   │  ├─ form/   (TipoStep, CamposGerais, CamposBug, CamposFeature, DadosSolicitante, UploadAnexos)
   │  └─ board/  (KanbanColuna, CardDemanda, CardDetalheModal, BarraFiltros)
   ├─ composables/ (useApi, useAuth)
   ├─ stores/board.ts             # Pinia
   └─ middleware/auth.ts
```

## 4. Modelo de Dados

### tickets
| Campo | Tipo | Origem no formulário |
|---|---|---|
| `id` | bigint auto | — |
| `codigo` | string única `DEM-0001` | gerado a partir do id |
| `tipo` | enum `BUG \| FEATURE` | Tipo da Solicitação |
| `titulo` | string | Título da Solicitação |
| `descricao` | text | Descrição Detalhada |
| `modulo` | string | Módulo/Sistema Afetado |
| `prioridade` | enum `BAIXA \| MEDIA \| ALTA \| CRITICA` | Prioridade |
| `impacto_negocio` | text | Impacto no Negócio |
| `status` | enum `TRIAGEM \| A_FAZER \| EM_ANDAMENTO \| EM_REVISAO \| CONCLUIDO` | coluna do kanban (default TRIAGEM) |
| `ordem` | float | posição do card na coluna |
| `solicitante_nome` | string | Nome |
| `solicitante_empresa` | string | Empresa |
| `solicitante_email` | string | E-mail |
| `data_solicitacao` | datetime | Data da Solicitação (default: agora) |
| `created_at` / `updated_at` | timestamps | — |

**Campos condicionais de BUG** (nullable): `comportamento_atual`, `comportamento_esperado`, `passos_reproducao` (text), `ambiente_url`, `navegador`, `dispositivo`, `data_hora_ocorrencia`, `frequencia` (enum `SEMPRE | AS_VEZES | UMA_VEZ`)

**Campos condicionais de FEATURE** (nullable): `objetivo`, `regras_negocio`, `fluxo_desejado`, `criterios_aceitacao`, `prazo_desejado` (date)

> Enums como string no banco + PHP backed enums (`app/Enums`) com casts no model.

### anexos
`id`, `ticket_id` (FK cascade), `nome_arquivo`, `path`, `mime_type`, `tamanho`, timestamps

### comentarios (fase 4 — notas internas suas por ticket)
`id`, `ticket_id`, `texto`, timestamps

## 5. API (routes/api.php)

| Rota | Método | Auth | Função |
|---|---|---|---|
| `/api/tickets` | POST | pública (`throttle:10,1`) | criação pelo form; validação condicional `required_if:tipo,BUG/FEATURE` |
| `/api/tickets` | GET | Sanctum | lista para o board (com anexos), filtros via query string |
| `/api/tickets/{ticket}` | GET | Sanctum | detalhe completo |
| `/api/tickets/{ticket}` | PATCH | Sanctum | mover status/ordem, editar |
| `/api/tickets/{ticket}/anexos` | POST | pública junto à criação* | upload multipart (max 10MB/arquivo) |
| `/api/anexos/{anexo}/download` | GET | Sanctum | download do anexo |
| `/api/login` / `/api/logout` / `/api/me` | POST/POST/GET | — | sessão Sanctum do admin |

\* Estratégia de anexos: o form envia `multipart/form-data` único para `POST /api/tickets` com `anexos[]` — mais simples que upload em duas etapas.

## 6. Telas (Nuxt)

### 6.1 `/solicitar` — Formulário público
- Etapa 1: escolha **Bug** ou **Nova Funcionalidade** (cards clicáveis).
- Etapa 2: campos gerais (título, descrição, módulo, prioridade, impacto, anexos múltiplos).
- Etapa 3: campos condicionais conforme o tipo (exatamente os do seu modelo de ticket).
- Etapa 4: solicitante (nome, empresa, e-mail; data automática).
- Validação com zod (espelhando as regras do FormRequest); tela de sucesso com o código ("Sua demanda **DEM-0042** foi registrada").

### 6.2 `/board` — Kanban (middleware auth)
- 5 colunas: **Triagem → A Fazer → Em Andamento → Em Revisão → Concluído**.
- **Card (resumo)**: código, badge do tipo (🐛 / ✨), título, badge de prioridade colorida (Crítica = vermelho com borda destacada), empresa + solicitante, módulo, data, 📎 se houver anexos.
- **Clique no card → UModal com TODOS os campos do formulário** em seções (Geral / Bug ou Feature / Solicitante / Anexos com download).
- Drag & drop entre colunas e dentro da coluna → `PATCH` otimista com rollback em erro.
- Barra superior: busca por texto + filtros por tipo, prioridade, empresa e módulo.

### 6.3 `/login`
- Senha do admin → sessão Sanctum (cookie httpOnly). Middleware Nuxt redireciona não-autenticado.

## 7. Fases de Implementação

### Fase 1 — Fundação + Formulário (o link já fica utilizável)
1. Scaffold `api/` (Laravel 12) e `web/` (Nuxt 3 + Nuxt UI + Pinia).
2. Migrations, enums, models, seeder do admin; geração do código `DEM-XXXX`.
3. `POST /api/tickets` com StoreTicketRequest (regras condicionais) + anexos multipart.
4. Página `/solicitar` completa no Nuxt + tela de sucesso.
5. Listagem simples (tabela) dos tickets para validar o fluxo de ponta a ponta.

### Fase 2 — Kanban
6. `GET/PATCH /api/tickets` + store Pinia.
7. Board com 5 colunas, drag & drop persistente (vuedraggable), modal de detalhes completo.

### Fase 3 — Auth + Anexos no board
8. Sanctum SPA (login, middleware Nuxt, proteção das rotas de leitura/escrita).
9. Download/preview de anexos no modal do card.

### Fase 4 — Acabamento
10. Filtros e busca no board.
11. E-mails: confirmação na criação (com código) e aviso ao mover para Concluído (Mail + queue).
12. Deploy (API no VPS, Nuxt no VPS ou Vercel) + CORS/Sanctum stateful domains configurados.
