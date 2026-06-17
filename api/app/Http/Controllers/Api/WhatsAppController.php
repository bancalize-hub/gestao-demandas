<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

class WhatsAppController extends Controller
{
    private function evo(): PendingRequest
    {
        return Http::baseUrl(rtrim((string) config('services.evolution.url'), '/'))
            ->withHeaders(['apikey' => (string) config('services.evolution.key')])
            ->timeout(20);
    }

    private function instance(): string
    {
        return (string) config('services.evolution.instance');
    }

    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }

    /** Estado da conexão (open | connecting | close) + número conectado. */
    public function status(Request $request)
    {
        $this->ensureAdmin($request);

        $state = $this->evo()->get("/instance/connectionState/{$this->instance()}")
            ->json('instance.state') ?? 'close';

        $number = null;
        if ($state === 'open') {
            $list = $this->evo()->get('/instance/fetchInstances', ['instanceName' => $this->instance()])->json();
            $jid = $list[0]['ownerJid'] ?? null;
            $number = $jid ? explode('@', $jid)[0] : null;
        }

        return response()->json(['state' => $state, 'number' => $number]);
    }

    /** QR code (base64) para parear. */
    public function qr(Request $request)
    {
        $this->ensureAdmin($request);

        $res = $this->evo()->get("/instance/connect/{$this->instance()}");

        return response()->json([
            'base64' => $res->json('base64'),
            'pairingCode' => $res->json('pairingCode'),
        ]);
    }

    /** Desconecta o WhatsApp (logout do aparelho). */
    public function logout(Request $request)
    {
        $this->ensureAdmin($request);

        $this->evo()->delete("/instance/logout/{$this->instance()}");

        return response()->json(['message' => 'ok']);
    }
}
