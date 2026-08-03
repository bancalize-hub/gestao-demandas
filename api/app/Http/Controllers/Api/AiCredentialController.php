<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\Claude;
use App\Support\ClaudeLogin;
use Illuminate\Http\Request;

/**
 * Credencial da IA (token da assinatura Claude) pela tela.
 *
 * Existe porque a queda de 30/07 mostrou o buraco: o token vivia só no `.env`, foi
 * revogado, e trocar exigia SSH + `config:cache`. Enquanto isso a IA ficou 4 dias fora
 * sem ninguém saber. Aqui dá para ver o estado real, colar um token novo e testar na hora.
 *
 * Super-admin apenas: a credencial é do servidor (todas as empresas usam a mesma).
 */
class AiCredentialController extends Controller
{
    /** Estado atual da IA: tem token? de onde veio? está no ar? qual foi o último erro? */
    public function status()
    {
        $motivo = Claude::indisponivel();

        return response()->json([
            'origem' => Claude::origemDoToken(),          // painel | env | nenhum
            'tem_token' => Claude::token() !== null,
            'no_ar' => $motivo === null,
            'motivo' => $motivo,
        ]);
    }

    /** Salva o token gerado por `claude setup-token` e já testa. */
    public function salvar(Request $request)
    {
        $data = $request->validate([
            'token' => 'required|string|min:20|max:2000',
        ]);

        Claude::salvarToken(trim($data['token']));

        return response()->json(['message' => 'ok'] + $this->testar());
    }

    /** Testa a credencial atual e devolve o que o CLI respondeu (erro incluso). */
    public function testar(): array
    {
        return Claude::testar();
    }

    /** Passo 1 do login pela tela: devolve a URL de autorização da Anthropic. */
    public function loginIniciar()
    {
        return response()->json(ClaudeLogin::iniciar());
    }

    /** Passo 2: recebe o código que a Anthropic mostrou e guarda o token gerado. */
    public function loginConcluir(Request $request)
    {
        $data = $request->validate([
            'sessao' => 'required|string|max:32',
            'codigo' => 'required|string|max:500',
        ]);

        return response()->json(ClaudeLogin::concluir($data['sessao'], trim($data['codigo'])));
    }
}
