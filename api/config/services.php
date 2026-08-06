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

        // Número na API oficial: retomada de 3+ dias cai fora da janela de 24h e a Meta só
        // aceita TEMPLATE aprovado. O template tem 2 variáveis: {{1}} primeiro nome do lead,
        // {{2}} assunto que estava sendo tratado (a IA preenche). `template_text` é a MESMA
        // frase aprovada, usada só para espelhar a bolha no chat — manter as duas em sincronia.
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
    ],

];
