<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\MarketingCreative;
use App\Models\MarketingCredential;
use App\Support\FacebookAds;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Módulo de marketing: credencial do Facebook Ads e biblioteca de criativos.
 * O terminal do agente reusa AgentController (sessões com kind=marketing).
 */
class MarketingController extends Controller
{
    /** Estado da configuração — nunca devolve os segredos, só se estão preenchidos. */
    public function status()
    {
        $c = MarketingCredential::atual();

        return response()->json([
            'app_id' => $c->app_id,
            'ad_account_id' => $c->ad_account_id,
            'page_id' => $c->page_id,
            'graph_version' => $c->graph_version,
            'tem_token' => filled($c->access_token),
            'tem_app_secret' => filled($c->app_secret),
            'checked_at' => $c->checked_at,
            'last_error' => $c->last_error,
        ]);
    }

    /** Salva a configuração. Campo de segredo em branco = manter o que já está lá. */
    public function salvar(Request $request)
    {
        $data = $request->validate([
            'app_id' => 'nullable|string|max:64',
            'app_secret' => 'nullable|string|max:255',
            'access_token' => 'nullable|string|max:1000',
            'ad_account_id' => 'nullable|string|max:64',
            'page_id' => 'nullable|string|max:64',
            'graph_version' => 'nullable|string|max:10',
        ]);

        $c = MarketingCredential::atual();
        foreach (['app_id', 'ad_account_id', 'page_id', 'graph_version'] as $campo) {
            if (array_key_exists($campo, $data)) {
                $c->$campo = $data[$campo] ?: null;
            }
        }
        // Segredo só é sobrescrito quando vem preenchido — assim a tela pode salvar
        // "o resto" sem obrigar a recolar o token toda vez.
        foreach (['app_secret', 'access_token'] as $segredo) {
            if (filled($data[$segredo] ?? null)) {
                $c->$segredo = $data[$segredo];
            }
        }
        $c->graph_version = $c->graph_version ?: 'v23.0';
        $c->save();

        return response()->json(['ok' => true] + FacebookAds::make()->testar());
    }

    /** Testa a credencial de verdade (uma chamada mínima à Graph API). */
    public function testar()
    {
        return response()->json(FacebookAds::make()->testar());
    }

    public function criativos()
    {
        return MarketingCreative::orderBy('number')->get()->map(fn ($c) => [
            'id' => $c->id,
            'name' => $c->name,
            'number' => $c->number,
            'original_name' => $c->original_name,
            'mime' => $c->mime,
            'size' => $c->size,
            'notes' => $c->notes,
            'no_facebook' => (bool) $c->fb_image_hash,
            'url' => "/api/marketing/creatives/{$c->id}/arquivo",
            'created_at' => $c->created_at,
        ]);
    }

    /**
     * Sobe um criativo. O nome é gerado (Criativo 1, 2, 3…) porque é assim que o
     * usuário vai se referir a ele conversando com o agente.
     */
    public function subirCriativo(Request $request)
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:jpg,jpeg,png,gif,webp|max:30720', // 30MB
            'notes' => 'nullable|string|max:500',
        ]);

        $file = $request->file('file');
        $path = $file->store('marketing/creatives');

        // Lock para dois uploads simultâneos não virarem dois "Criativo 4".
        $criativo = DB::transaction(function () use ($file, $path, $data) {
            $n = MarketingCreative::lockForUpdate()->max('number') + 1;

            return MarketingCreative::create([
                'name' => "Criativo {$n}",
                'number' => $n,
                'original_name' => $file->getClientOriginalName() ?: basename($path),
                'path' => $path,
                'mime' => $file->getMimeType() ?: 'image/jpeg',
                'size' => (int) $file->getSize(),
                'notes' => $data['notes'] ?? null,
            ]);
        });

        return response()->json($criativo, 201);
    }

    public function atualizarCriativo(Request $request, MarketingCreative $creative)
    {
        $data = $request->validate(['notes' => 'nullable|string|max:500']);
        $creative->update(['notes' => $data['notes'] ?? null]);

        return response()->json($creative);
    }

    public function apagarCriativo(MarketingCreative $creative)
    {
        Storage::delete($creative->path);
        $creative->delete();

        return response()->json(['ok' => true]);
    }

    /** Serve a imagem para a miniatura da tela. */
    public function arquivo(MarketingCreative $creative)
    {
        abort_unless(Storage::exists($creative->path), 404);

        return response(Storage::get($creative->path), 200, [
            'Content-Type' => $creative->mime ?: 'image/jpeg',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }
}
