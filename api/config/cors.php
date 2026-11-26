<?php

return [

    'paths' => ['api/*', 'login', 'logout', 'sanctum/csrf-cookie'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        env('FRONTEND_URL', 'https://demandas.bancalize.com.br'),
    ],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    // Toda chamada do CRM manda X-Socket-ID, então o navegador faz a consulta de permissão
    // (OPTIONS) antes. Com 0 ele repetia a consulta em TODA chamada — o dobro de idas e voltas,
    // caro no celular (26/11). 7200 s = o máximo que o Chrome respeita.
    'max_age' => 7200,

    // Necessário para o fluxo de cookie/sessão do Sanctum (SPA).
    'supports_credentials' => true,

];
