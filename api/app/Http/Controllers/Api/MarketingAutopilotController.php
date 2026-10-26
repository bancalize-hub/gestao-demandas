<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Company;
use App\Models\MarketingAutoAd;
use App\Models\MarketingAutopilot;
use App\Models\MarketingAutopilotEvent;
use App\Models\MarketingCreativeRequest;
use App\Models\MarketingGestorSession;
use App\Models\WaAccount;
use App\Services\Marketing\Avisos;
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
            'teto_vigente' => $cfg->tetoVigente(),
            // Os números da Evolution: é por eles que sai o aviso (a API oficial exige template).
            'numeros_aviso' => WaAccount::where(fn ($w) => $w->whereNull('provider')->orWhere('provider', '!=', 'cloud'))
                ->whereNotNull('instance')->get(['id', 'name', 'phone', 'avatars_only']),
            'anuncios' => MarketingAutoAd::with('creative:id,name,number,original_name,notes,status,source')
                ->orderByRaw("status = 'ativo' DESC")->orderByDesc('id')->limit(60)->get(),
            'eventos' => MarketingAutopilotEvent::orderByDesc('id')->limit(80)->get(),
            // Os turnos dos agentes sem os passos (que podem ser grandes): a tela pede um por vez.
            'sessoes' => MarketingGestorSession::orderByDesc('id')->limit(20)
                ->get(['id', 'agente', 'simulated', 'started_at', 'ended_at', 'status', 'ferramentas_usadas', 'resumo', 'erro']),
            'pedidos' => MarketingCreativeRequest::orderByDesc('id')->limit(20)->get(),
        ]);
    }

    public function update(Request $request)
    {
        $data = $request->validate([
            'enabled' => 'sometimes|boolean',
            'daily_cap' => 'sometimes|numeric|min:0|max:100000',
            'max_cap' => 'sometimes|numeric|min:0|max:100000',
            'target_cpq' => 'sometimes|numeric|min:1|max:100000',
            'manage_whole_account' => 'sometimes|boolean',
            'alert_phone' => 'sometimes|nullable|string|max:20',
            'alert_wa_account_id' => 'sometimes|nullable|integer',
            'report_hour' => ['sometimes', 'nullable', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
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

        if (array_key_exists('alert_phone', $data)) {
            $data['alert_phone'] = preg_replace('/\D/', '', (string) $data['alert_phone']) ?: null;
        }

        $cfg = MarketingAutopilot::atual();
        $cfg->fill($data);
        // O máximo nunca fica abaixo do normal: "teto máximo R$ 50 com normal R$ 100" não tem
        // leitura possível, e o gestor ficaria preso escolhendo entre dois limites.
        if ($cfg->max_cap < $cfg->daily_cap) {
            $cfg->max_cap = $cfg->daily_cap;
        }
        $cfg->save();

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

    /** Um turno inteiro do agente: o que ele pensou, que ferramenta usou e o que viu. */
    public function sessao(int $id)
    {
        return response()->json(MarketingGestorSession::findOrFail($id));
    }

    /** Manda uma mensagem de teste para o WhatsApp de aviso — é como se sabe que o alerta chega. */
    public function testarAviso()
    {
        $cfg = MarketingAutopilot::atual();
        if (blank($cfg->alert_phone)) {
            return response()->json(['ok' => false, 'mensagem' => 'Preencha e salve o WhatsApp de aviso primeiro.']);
        }
        $ok = (new Avisos($cfg))->enviar('teste-'.now()->timestamp, 'Teste: é por aqui que eu aviso de saldo, teto e o resumo do dia.', 0);
        $ultimo = MarketingAutopilotEvent::where('kind', 'alerta')->latest('id')->first();

        return response()->json(['ok' => $ok, 'mensagem' => $ok ? 'Mensagem enviada.' : ($ultimo?->message ?? 'Falhou o envio.')]);
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
