<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;

class GoogleAuthController extends Controller
{
    public function __construct(private GoogleCalendarService $google) {}

    /**
     * Status da vinculação: a do usuário atual (para o botão "Conectar") e a lista de
     * agendas da EMPRESA — as camadas que a tela liga/desliga. Basta uma conta conectada
     * na empresa para a Agenda funcionar para todo mundo.
     */
    public function status(Request $request)
    {
        $user = $request->user();

        $agendas = User::where('company_id', $user->company_id)
            ->whereNotNull('google_refresh_token')
            ->orderBy('id')
            ->get(['id', 'name', 'google_email'])
            ->map(fn (User $u) => [
                'user_id' => $u->id,
                'name' => $u->name,
                'email' => $u->google_email,
                'color' => GoogleCalendarService::corDaAgenda($u->id),
                'is_me' => $u->id === $user->id,
            ])
            ->values();

        return response()->json([
            'connected' => $user->hasGoogle(),
            'email' => $user->google_email,
            'calendar_id' => $user->google_calendar_id,
            'calendars' => $agendas,
        ]);
    }

    /**
     * Inicia o OAuth. Devolve a URL de consentimento via XHR (autenticado por
     * sessão) — o front então faz window.location p/ o Google. Evita depender
     * do Referer para o Sanctum tratar a navegação como stateful.
     */
    public function connect(Request $request)
    {
        // `state` assinado identifica o usuário no callback (que é público).
        $state = Crypt::encryptString($request->user()->id.'|'.now()->timestamp);

        return response()->json(['url' => $this->google->authUrl($state)]);
    }

    /**
     * Callback do Google (rota pública). Troca o code por tokens,
     * salva no usuário identificado pelo state e volta pro front.
     */
    public function callback(Request $request)
    {
        $front = rtrim((string) config('app.frontend_url'), '/');

        if ($request->filled('error')) {
            return redirect()->away($front.'/agenda?google=erro');
        }

        try {
            $userId = (int) explode('|', Crypt::decryptString($request->query('state', '')))[0];
            $user = User::findOrFail($userId);

            $tokens = $this->google->exchangeCode($request->query('code', ''));

            // TRAVA DE DONO: a agenda ligada tem de ser a do PRÓPRIO e-mail de quem
            // está conectando. Sem isso dá para autorizar a conta de outra pessoa sem
            // perceber — aconteceu em 21/09/2026, um login `pauloguilherme…@gmail.com`
            // ficou com a agenda `bancalize@gmail.com` pendurada. O estrago é silencioso
            // e grande: a IA passa a oferecer horário, criar evento, gravar e transcrever
            // reunião DENTRO da agenda de um terceiro, e a apuração de presença lê de lá.
            $conectado = self::normalizaEmail($tokens['email'] ?? '');
            $proprio = self::normalizaEmail($user->email);

            if ($conectado === '' || $conectado !== $proprio) {
                // Devolve o consentimento: o token é de outra conta e não pode ficar
                // guardado aqui nem pendurado lá. E nada é salvo no usuário — uma
                // conexão anterior, legítima, continua valendo.
                $this->google->revokeToken($tokens['access_token']);

                Log::warning('Google OAuth recusado: conta diferente da do usuário', [
                    'user_id' => $user->id,
                    'esperado' => $proprio,
                    'recebido' => $conectado !== '' ? $conectado : '(sem e-mail no id_token)',
                ]);

                return redirect()->away($front.'/agenda?google=email-diferente');
            }

            $user->google_access_token = $tokens['access_token'];
            // Só sobrescreve o refresh token se o Google mandou um novo.
            if (! empty($tokens['refresh_token'])) {
                $user->google_refresh_token = $tokens['refresh_token'];
            }
            $user->google_token_expires_at = $tokens['expires_at'];
            $user->google_email = $tokens['email'] ?? $user->google_email;
            $user->save();
        } catch (\Throwable $e) {
            Log::error('Google OAuth callback falhou', ['e' => $e->getMessage()]);

            return redirect()->away($front.'/agenda?google=erro');
        }

        return redirect()->away($front.'/agenda?google=conectado');
    }

    /**
     * E-mail comparável.
     *
     * No Gmail o ponto é ignorado e o `+etiqueta` é apelido — `p.silva+crm@gmail.com`
     * e `psilva@gmail.com` são a MESMA conta. Sem normalizar, a trava recusaria o
     * próprio dono só porque ele cadastrou o e-mail com pontuação diferente. Fora do
     * Gmail a regra não vale (lá o ponto distingue caixas de verdade), então só
     * minúscula e espaço.
     */
    private static function normalizaEmail(?string $email): string
    {
        $email = strtolower(trim((string) $email));

        if (! str_contains($email, '@')) {
            return $email;
        }

        [$conta, $dominio] = explode('@', $email, 2);

        if (in_array($dominio, ['gmail.com', 'googlemail.com'], true)) {
            $conta = str_replace('.', '', explode('+', $conta, 2)[0]);
            $dominio = 'gmail.com';
        }

        return $conta.'@'.$dominio;
    }

    /** Desvincula a conta Google do usuário. */
    public function disconnect(Request $request)
    {
        $user = $request->user();
        $user->forceFill([
            'google_access_token' => null,
            'google_refresh_token' => null,
            'google_token_expires_at' => null,
            'google_email' => null,
        ])->save();

        return response()->json(['message' => 'ok']);
    }
}
