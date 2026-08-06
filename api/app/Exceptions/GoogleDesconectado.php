<?php

namespace App\Exceptions;

use Illuminate\Http\Request;
use RuntimeException;

/**
 * A conta Google do usuário não vale mais: o refresh token foi revogado ou expirou.
 *
 * Não é falha do servidor — é uma credencial que morreu e que só o usuário pode
 * renovar. Por isso vira 409 com recado em português em vez de 500 "erro no servidor":
 * quem clicou em "agendar reunião com a IA" precisa saber que o caminho é reconectar o
 * Google, não tentar de novo.
 *
 * O `render()` faz esse mapeamento em QUALQUER rota que use o Google — agenda, reunião
 * pela IA, sincronização — sem cada controller ter de lembrar de tratar.
 */
class GoogleDesconectado extends RuntimeException
{
    public function __construct(string $motivo = '')
    {
        parent::__construct(
            'A conexão com o Google expirou ou foi revogada. Reconecte sua conta na página Agenda.'
            .($motivo !== '' ? " ({$motivo})" : '')
        );
    }

    public function render(Request $request)
    {
        return response()->json(['error' => 'sem_google', 'message' => $this->getMessage()], 409);
    }
}
