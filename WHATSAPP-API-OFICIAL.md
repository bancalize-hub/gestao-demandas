# WhatsApp API oficial (Cloud API da Meta)

O CRM fala WhatsApp por **dois canais**, escolhidos por número (`wa_accounts.provider`):

| | `evolution` (Baileys) | `cloud` (**oficial**) |
|---|---|---|
| Aprovação da Meta | não precisa | obrigatória |
| Risco de bloqueio | real | nenhum |
| Histórico antigo | importa quando quiser | só no onboarding de coexistência (~6 meses) |
| Falar depois de 24h | livre | só template aprovado (**pago**) |
| Etiquetas do app Business | sincroniza com o funil | não existem na API |
| Custo por mensagem | zero | por template entregue |

Trocar de canal é mudar uma coluna: nada no resto do CRM (chat, funil, IA, campanhas,
automações) sabe qual canal está por baixo — todos passam por `App\Support\Wa::for($conta)`.

---

## 1. O que fazer no lado da Meta (uma vez)

Nada disso é feito pelo CRM: são contas e aprovações da Meta.

1. **Meta Business** — em <https://business.facebook.com>, crie/entre no Business da empresa
   e faça a **verificação do negócio** (CNPJ, comprovante de endereço, site). É a etapa mais
   demorada: pode levar dias. Sem ela, o número fica limitado a 250 conversas/dia.
2. **App** — em <https://developers.facebook.com/apps>, crie um app do tipo **Negócios** e
   adicione o produto **WhatsApp**. Vincule o app ao Business do passo 1.
3. **Número** — em *WhatsApp → Configuração da API*:
   - **número novo**: adicione e verifique por SMS/ligação;
   - **número que já está no app WhatsApp Business** (nosso caso): use o fluxo de
     **coexistência** — o número continua funcionando no celular e a Meta sincroniza
     até ~6 meses de conversas para o CRM.
4. **Nome de exibição** — passa por aprovação da Meta (nome real da empresa, não slogan).
5. **Token permanente** — *Configurações do Business → Usuários do sistema* → crie um
   usuário de sistema **Admin**, dê acesso ao app e ao WhatsApp Business Account, e gere um
   token com as permissões `whatsapp_business_messaging` e `whatsapp_business_management`.
   Esse token **não expira** (o da tela de teste dura 24h — não serve).
6. Anote: **Phone number ID**, **WhatsApp Business Account ID (WABA)** e o **App secret**
   (*Configurações do app → Básico*).

## 2. Ligar no CRM

Em **/admin/whatsapp → "Conectar número pela API oficial"**, preencha apelido, telefone,
*Phone number ID*, *WABA ID*, *token* e *app secret*. Marque **coexistência** se o número
continua em uso no celular.

Ao salvar, a tela mostra a **URL do webhook** e o **token de verificação** gerado. No painel
da Meta (*WhatsApp → Configuração → Webhook*):

- **Callback URL**: `https://api-demandas.bancalize.com.br/api/wpp/cloud/webhook`
- **Verify token**: o que a tela mostrou
- **Campos assinados**: `messages` e, na coexistência, também `smb_message_echoes`,
  `history` e `smb_app_state_sync`

O handshake (GET) responde na hora; se der erro, o token não bate.

## 3. Como funciona depois de ligado

- **Recebimento**: webhook → `WhatsAppCloudController` resolve a empresa pelo
  `phone_number_id`, confere a assinatura `X-Hub-Signature-256` (HMAC do corpo com o app
  secret) e grava conversa/mensagem — as mesmas tabelas do canal antigo.
- **Coexistência**: o que a equipe responde **pelo celular** entra como mensagem nossa
  (`smb_message_echoes`); o histórico antigo entra em lotes (`history`), sem broadcast; a
  agenda do celular (`smb_app_state_sync`) só dá nome a quem estava salvo como número.
- **Recibos**: `sent → delivered → read`, e `failed` vira ⚠ na bolha. O recibo nunca regride.
- **Mídia**: o webhook traz um id (`messages.wa_media_id`); o CRM baixa em streaming e
  guarda em `storage/app/wa-media/`. Mídia que **nós** enviamos é copiada para esse cache no
  ato do envio — a Meta não deixa baixar de volta o que foi enviado.
- **Janela de 24h**: passou 24h da última mensagem do cliente, o envio de texto/mídia é
  recusado com `code: window_closed` e o chat abre o painel de **templates aprovados**.
- **Anúncios**: lead vindo de Click-to-WhatsApp guarda título/origem em
  `conversations.custom_fields.anuncio`.

## 4. Limites herdados da Meta (não são bugs do CRM)

- **Sem etiquetas**: a Cloud API não expõe as labels do app Business, então o vínculo
  etapa-do-funil ↔ etiqueta não funciona em número oficial (`findLabels()` devolve vazio).
- **Sem busca de histórico**: `sync`/`import`/`import-txt` continuam existindo só para o
  canal Evolution — a tela esconde os botões em número oficial.
- **Sem checagem de número**: não dá para saber se um número existe no WhatsApp antes de
  enviar; o erro aparece como recibo de falha (importa para as campanhas).
- **Templates são pagos** e por categoria (marketing/utilidade/autenticação) e país.
  Conversa de atendimento dentro da janela de 24h é gratuita.
- **Versão do Graph**: padrão `v21.0` (`WA_CLOUD_GRAPH_VERSION` no `.env`, ou por conta em
  `wa_accounts.graph_version`). A Meta aposenta versões ~2 anos depois; quando avisar, é só
  trocar a variável.

## 5. Migrar um número que hoje está na Evolution

1. Conecte o número na Meta (coexistência) e configure a conta cloud no `/admin/whatsapp`.
2. Confirme recebimento e envio numa conversa de teste.
3. Aponte as conversas existentes para a nova conta:
   `UPDATE conversations SET wa_account_id = <id_cloud> WHERE wa_account_id = <id_evolution>;`
4. Desative a conta antiga (botão *Desconectar*) — o histórico já importado continua no CRM.

Não existe caminho de volta automático: o WhatsApp não deixa o mesmo número em duas
sessões Baileys/Cloud sem passar pelo fluxo da Meta de novo.

## 6. Onde mexer no código

| Assunto | Arquivo |
|---|---|
| Escolha do canal | `api/app/Support/Wa.php` |
| Contrato dos canais | `api/app/Support/Channels/WaChannel.php` |
| Cloud API (envio, mídia, templates) | `api/app/Support/Channels/CloudChannel.php` |
| Evolution (inalterado, embrulhado) | `api/app/Support/Channels/EvolutionChannel.php` |
| Webhook + admin do canal oficial | `api/app/Http/Controllers/Api/WhatsAppCloudController.php` |
| Janela de 24h / templates no chat | `api/app/Http/Controllers/Api/MessageController.php` |
| Tela de números | `web/pages/admin/whatsapp.vue` |
| Painel de templates no chat | `web/components/crm/ScreenChat.vue` |
