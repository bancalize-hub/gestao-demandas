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

    /** Status da vinculação do usuário atual. */
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'connected' => $user->hasGoogle(),
            'email' => $user->google_email,
            'calendar_id' => $user->google_calendar_id,
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
