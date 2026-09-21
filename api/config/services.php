<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    // Claude Code via assinatura (mesmo token longevo do Paperclip/Hermes).
    // O CLI roda como subprocesso; sem ANTHROPIC_API_KEY => sem custo de API.
    'claude' => [
        'oauth_token' => env('CLAUDE_CODE_OAUTH_TOKEN'),
        'bin' => env('CLAUDE_BIN', '/usr/local/bin/claude'),
    ],

    // Google Calendar (OAuth2 por usuário). Credenciais do projeto no Google Cloud.
    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', 'https://api-demandas.bancalize.com.br/api/google/callback'),
    ],

    // Evolution API (WhatsApp). URL no loopback; key fica server-side.
    'evolution' => [
        'url' => env('EVOLUTION_API_URL', 'http://127.0.0.1:8085'),
        'key' => env('EVOLUTION_API_KEY'),
        'instance' => env('EVOLUTION_INSTANCE', 'vertice'),
        'webhook_token' => env('EVOLUTION_WEBHOOK_TOKEN'),
    ],

    // WhatsApp Cloud API (API OFICIAL da Meta). As credenciais são POR NÚMERO, no banco
    // (wa_accounts) — o CRM é multi-empresa e cada empresa tem seu app/WABA. Aqui ficam só
    // os padrões: a versão do Graph usada quando a conta não fixa uma, e um verify token
    // global opcional para o handshake do webhook.
    'wa_cloud' => [
        'graph_version' => env('WA_CLOUD_GRAPH_VERSION', 'v21.0'),
        'verify_token' => env('WA_CLOUD_VERIFY_TOKEN'),
    ],

    // Lembrete automático de reunião: a IA avisa o cliente pelo WhatsApp antes de começar.
    // Resposta automática: pendência mais velha que isto (desde a última mensagem DO
    // CLIENTE) é descartada em vez de responder o lead horas depois.
    'auto_reply' => [
        'stale_hours' => (int) env('AUTO_REPLY_STALE_HOURS', 6),
        // Minutos de silêncio da IA depois que um humano escreve pelo painel (0 desliga).
        'human_pause_minutes' => (int) env('AUTO_REPLY_HUMAN_PAUSE_MINUTES', 180),
    ],

    // Agenda: horas do dia que a IA só oferece em último caso. NÃO são horas bloqueadas —
    // o lead que PEDIR 9h ou meio-dia continua sendo atendido, e o dia que só tem esses
    // horários livres volta a oferecê-los. A régua é a hora de INÍCIO da reunião, então
    // 9 cobre 9:00 e 9:30, e 12 cobre 12:00 e 12:30.
    // Etapas em que um negócio é "quente": passou da reunião e ainda pode fechar. É a
    // fila que o watchdog (deals:watchdog-tick) cobra por dono, proposta e silêncio.
    'crm_watchdog' => [
        'stages_quentes' => array_values(array_filter(array_map('trim', explode(
            ',', (string) env('CRM_STAGES_QUENTES', 'reuniao-realizada,proposta,negociacao,disse-que-vai-fechar'),
        )))),
    ],

    'agenda' => [
        'horas_despriorizadas' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('AGENDA_HORAS_DESPRIORIZADAS', '9,12')),
        ), fn ($h) => $h >= 0 && $h <= 23)),

        // Antecedência mínima, em DIAS, entre hoje e a reunião que a IA pode oferecer ou
        // marcar. 1 = só a partir de amanhã (padrão): reunião marcada para daqui a duas
        // horas pega o anfitrião de surpresa, sem tempo de preparar a call nem de reorganizar
        // o dia. 0 devolve o comportamento antigo (pode marcar para hoje).
        'antecedencia_dias' => max(0, (int) env('AGENDA_ANTECEDENCIA_DIAS', 1)),
    ],

    // Retomada ativa ("nudge"): a IA não espera o lead voltar — ela busca quem sumiu no meio
    // da conversa. delays_minutes = minutos de SILÊNCIO (desde a última mensagem) para cada
    // degrau da escada; o tamanho da lista é o teto de retomadas por rodada de silêncio.
    // Padrão: 30 min (ainda na mesma conversa) → 20h → 3 dias → 7 dias.
    //
    // O primeiro degrau só vale se a conversa estava VIVA: o lead falou nos últimos
    // flow_minutes antes da nossa mensagem. Fora disso ele é pulado — cutucar em 30 min
    // quem já estava frio é atropelo, não retomada.
    //
    // A janela de horário evita mandar mensagem de madrugada; domingo nunca.
    'nudge' => [
        'delays_minutes' => array_values(array_filter(array_map(
            'intval',
            explode(',', (string) env('NUDGE_DELAYS_MINUTES', '30,1200,4320,10080')),
        ))),
        'flow_minutes' => (int) env('NUDGE_FLOW_MINUTES', 120),
        'start_hour' => (int) env('NUDGE_START_HOUR', 9),
        'end_hour' => (int) env('NUDGE_END_HOUR', 19),
        'per_tick' => (int) env('NUDGE_PER_TICK', 15),

        // Número na API oficial: todo degrau a partir do de 20h cai fora da janela de 24h e a
        // Meta só aceita TEMPLATE aprovado — sem isto a retomada não existe no número oficial.
        //
        // `template_text` é a MESMA frase aprovada e é a fonte da verdade das variáveis:
        // :nome (= {{1}}, primeiro nome do lead) e :assunto (= {{2}}, o assunto que estava em
        // jogo, preenchido pela IA). O tick manda exatamente os parâmetros que aparecem aqui,
        // então trocar para um template de 1 variável é tirar o :assunto desta frase — mandar
        // parâmetro a mais do que o template declara é recusa na hora (erro 132000).
        // Serve também para espelhar a bolha no chat: mantenha a frase igual à aprovada.
        //
        // Sem template configurado, a rodada é encerrada com registro (nada é enviado).
        'template' => env('NUDGE_TEMPLATE', 'retomada_conversa'),
        'template_language' => env('NUDGE_TEMPLATE_LANG', 'pt_BR'),
        'template_text' => env(
            'NUDGE_TEMPLATE_TEXT',
            'Oi :nome, tudo bem? Ficamos de continuar nossa conversa sobre :assunto e não quis deixar você sem retorno. Se quiser retomar, é só responder por aqui.',
        ),
    ],

    'meeting_reminder' => [
        'enabled' => env('MEETING_REMINDER_ENABLED', true),
        'lead_minutes' => (int) env('MEETING_REMINDER_LEAD_MINUTES', 60), // quanto antes lembrar (padrão: 1h)
        // 2º lembrete, "está começando". O de 1h chega cedo demais para reunião de manhã
        // (às 08:00 para uma de 09:00) e a manhã é justamente onde o cliente não aparece:
        // 1 comparecimento em 10 entre 28/07 e 07/08/2026. Zero desliga o segundo aviso.
        'second_lead_minutes' => (int) env('MEETING_REMINDER_SECOND_LEAD_MINUTES', 15),
        // Número na API oficial: 1h antes da reunião a janela de 24h quase sempre está
        // fechada (o cliente marcou dias atrás), e aí a Meta só aceita TEMPLATE aprovado.
        // O template precisa de 3 variáveis: {{1}} nome, {{2}} quando, {{3}} link/recado.
        'template' => env('MEETING_REMINDER_TEMPLATE', 'lembrete_reuniao'),
        'template_language' => env('MEETING_REMINDER_TEMPLATE_LANG', 'pt_BR'),
    ],

    // Transcrição de áudios (voice) do WhatsApp via Groq Whisper (free tier). A IA "ouve"
    // os áudios do cliente e responde. Sem a chave, a transcrição fica desligada (silencioso).
    'groq' => [
        'key' => env('GROQ_API_KEY'),
        'whisper_model' => env('GROQ_WHISPER_MODEL', 'whisper-large-v3'),
    ],

    // Automatismos de reunião no funil. As chaves apontam para stages.key (editáveis no /pipeline).
    'crm' => [
        'stage_meeting_booked' => env('CRM_STAGE_MEETING_BOOKED', 'proposta'),          // "Reunião Agendada"
        'stage_meeting_done' => env('CRM_STAGE_MEETING_DONE', 'reuniao-realizada'),      // "Reunião Realizada"
        'attendance_min_minutes' => (int) env('CRM_ATTENDANCE_MIN_MINUTES', 10),         // tempo mínimo p/ contar presença
        // Quem é do TIME, por nome de exibição no Google Meet. Presença de cliente ignora
        // estes nomes: a API do Meet não devolve e-mail (só display name), então casar por
        // nome é o único caminho. Sem esta lista o cálculo conta a própria equipe como
        // cliente — reunião em que só o Paulo e a Maysa entram sai como "compareceu".
        'team_display_names' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('CRM_TEAM_DISPLAY_NAMES', 'Guilherme Rodrigues,Maysa Lima,Bancalize'))
        ))),
    ],

];
