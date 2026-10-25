<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MarketingAutoAd;
use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Support\FacebookAds;
use App\Support\ImageGen;
use App\Support\Tenancy;
use Illuminate\Http\Request;

/** Painel do piloto automático de anúncios (/marketing/autopiloto). */
class MarketingAutopilotController extends Controller
{
    public function show()
    {
        $cfg = MarketingAutopilot::atual();
        $this->prefillInstrucoes($cfg);

        $conta = ['saldo' => null, 'comprometido' => null, 'itens' => [], 'erro' => null];
        try {
            $fb = FacebookAds::make();
            if ($fb->configurado()) {
                $c = $fb->orcamentoComprometido();
                $conta = ['saldo' => $fb->saldoDisponivel(), 'comprometido' => $c['total'], 'itens' => $c['itens'], 'erro' => null];
            } else {
                $conta['erro'] = 'Conta de anúncios não configurada.';
            }
        } catch (\Throwable $e) {
            $conta['erro'] = $e->getMessage();
        }

        return response()->json([
            'config' => $cfg,
            'publico_padrao' => MarketingAutopilot::PUBLICO_PADRAO,
            'modelos_padrao' => ImageGen::MODELO_PADRAO,
            'conta' => $conta,
            'anuncios' => MarketingAutoAd::with('creative:id,name,number,original_name,notes,status,source')
                ->orderByRaw("status = 'ativo' DESC")->orderByDesc('id')->limit(60)->get(),
            'eventos' => MarketingAutopilotEvent::orderByDesc('id')->limit(80)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'daily_cap' => 'sometimes|numeric|min:0|max:100000',
            'budget_per_ad' => 'sometimes|numeric|min:6|max:5000',
            'max_new_per_day' => 'sometimes|integer|min:0|max:20',
            'lifetime_days' => 'sometimes|integer|min:2|max:60',
            'min_triados' => 'sometimes|integer|min:3|max:100',
            'kill_rate' => 'sometimes|integer|min:0|max:100',
            'max_spend_no_qualified' => 'sometimes|numeric|min:5|max:10000',
            'use_stock' => 'sometimes|boolean',
            'image_provider' => 'sometimes|nullable|in:'.implode(',', MarketingAutopilot::PROVEDORES),
            'image_api_key' => 'sometimes|nullable|string|max:500',
            'image_model' => 'sometimes|nullable|string|max:80',
            'targeting' => 'sometimes|nullable|array',
            'instructions' => 'sometimes|nullable|string|max:30000',
        ]);

        // Campo de chave vazio = "não mexer" (a tela nunca recebe a chave de volta).
        if (array_key_exists('image_api_key', $data) && blank($data['image_api_key'])) {
            unset($data['image_api_key']);
        }

        $cfg = MarketingAutopilot::atual();
        $cfg->update($data);

        return response()->json($cfg->fresh());
    }

    public function testarImagem()
    {
        try {
            return response()->json(ImageGen::make(MarketingAutopilot::atual())->testar());
        } catch (\Throwable $e) {
            return response()->json(['ok' => false, 'mensagem' => $e->getMessage()]);
        }
    }

    /** Pede uma rodada ao agendador (roda em até 10 min, em background). */
    public function rodar(Request $request)
    {
        $modo = $request->validate(['modo' => 'required|in:simular,real,amostra'])['modo'];
        $cfg = MarketingAutopilot::atual();

        if ($modo === 'real' && ! $cfg->enabled) {
            return response()->json(['ok' => false, 'mensagem' => 'Ligue o piloto antes de rodar de verdade.'], 422);
        }

        $cfg->update(['run_requested_at' => now(), 'run_requested_mode' => $modo]);

        return response()->json(['ok' => true, 'mensagem' => 'Pedido registrado — começa em até 10 minutos.']);
    }

    /**
     * Na empresa principal, o campo de instruções nasce com o briefing de criativos que já
     * existe no repositório: é o que a casa sabe sobre o que funciona, e começar vazio faria a
     * primeira geração ignorar tudo isso.
     */
    private function prefillInstrucoes(MarketingAutopilot $cfg): void
    {
        if (filled($cfg->instructions) || app(Tenancy::class)->id() !== Company::orderBy('id')->value('id')) {
            return;
        }
        $arquivo = base_path('../docs/marketing/brief-criativos.md');
        if (is_file($arquivo)) {
            $cfg->update(['instructions' => (string) file_get_contents($arquivo)]);
        }
    }
}
