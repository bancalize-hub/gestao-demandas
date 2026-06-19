<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\StageAutomation;
use App\Models\StageAutomationStep;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class StageAutomationController extends Controller
{
    /** Lista as automações (com seus passos). Opcionalmente filtra por etapa. */
    public function index(Request $request)
    {
        $q = StageAutomation::with('steps')->orderBy('id');
        if ($stage = $request->query('stage')) {
            $q->where('stage_key', $stage);
        }

        return $q->get();
    }

    /**
     * Cria/atualiza a automação de uma etapa e sincroniza seus passos.
     * Passos com `id` existente são atualizados (preservando o PDF já anexado);
     * passos sem `id` são criados; passos ausentes do payload são removidos.
     */
    public function store(Request $request)
    {
        $data = $request->validate([
            'stage_key' => 'required|string|max:60',
            'name' => 'required|string|max:80',
            'enabled' => 'boolean',
            'steps' => 'array',
            'steps.*.id' => 'nullable|integer',
            'steps.*.type' => 'required|string|in:text,media',
            'steps.*.delay_minutes' => 'nullable|integer|min:0',
            'steps.*.text' => 'nullable|string|max:4000',
        ]);

        $automation = StageAutomation::updateOrCreate(
            ['stage_key' => $data['stage_key']],
            ['name' => $data['name'], 'enabled' => $data['enabled'] ?? true],
        );

        $keepIds = [];
        foreach ($data['steps'] ?? [] as $pos => $s) {
            $payload = [
                'position' => $pos,
                'type' => $s['type'],
                'delay_minutes' => (int) ($s['delay_minutes'] ?? 0),
                'text' => $s['text'] ?? null,
            ];
            if (! empty($s['id'])) {
                $step = $automation->steps()->whereKey($s['id'])->first();
                if ($step) {
                    $step->update($payload);  // mantém asset_* já anexado
                    $keepIds[] = $step->id;

                    continue;
                }
            }
            $step = $automation->steps()->create($payload);
            $keepIds[] = $step->id;
        }

        // Remove passos que saíram do payload (e seus PDFs).
        $automation->steps()->whereNotIn('id', $keepIds ?: [0])->get()
            ->each(function (StageAutomationStep $step) {
                if ($step->asset_path) {
                    Storage::disk('local')->delete($step->asset_path);
                }
                $step->delete();
            });

        return response()->json($automation->load('steps'));
    }

    /** Anexa (ou substitui) o PDF de um passo de mídia. */
    public function uploadAsset(Request $request, StageAutomationStep $step)
    {
        $request->validate([
            'file' => 'required|file|mimes:pdf|max:30720', // até 30MB
        ]);

        $file = $request->file('file');
        $path = $file->store('automations', 'local');

        if ($step->asset_path && $step->asset_path !== $path) {
            Storage::disk('local')->delete($step->asset_path);
        }

        $step->update([
            'asset_path' => $path,
            'asset_mime' => $file->getMimeType() ?: 'application/pdf',
            'asset_filename' => $file->getClientOriginalName() ?: 'proposta.pdf',
        ]);

        return response()->json($step);
    }

    public function destroy(StageAutomation $stageAutomation)
    {
        $stageAutomation->steps->each(function (StageAutomationStep $step) {
            if ($step->asset_path) {
                Storage::disk('local')->delete($step->asset_path);
            }
        });
        $stageAutomation->steps()->delete();
        $stageAutomation->delete();

        return response()->json(['message' => 'ok']);
    }
}
