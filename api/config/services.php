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

    // Lembrete automático de reunião: a IA avisa o cliente pelo WhatsApp antes de começar.
    'meeting_reminder' => [
        'enabled' => env('MEETING_REMINDER_ENABLED', true),
        'lead_minutes' => (int) env('MEETING_REMINDER_LEAD_MINUTES', 60), // quanto antes lembrar (padrão: 1h)
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
