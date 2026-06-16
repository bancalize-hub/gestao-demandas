<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use Illuminate\Http\Request;

class DealController extends Controller
{
    public function index()
    {
        return Deal::orderBy('position')->orderBy('id')->get();
    }

    public function update(Request $request, Deal $deal)
    {
        $data = $request->validate([
            'stage' => 'nullable|string|max:32',
            'name' => 'nullable|string',
            'sub' => 'nullable|string',
            'value' => 'nullable|string',
            'tag' => 'nullable|string',
            'hot' => 'boolean',
            'won' => 'boolean',
            'tag_strong' => 'boolean',
        ]);

        // Ao mudar de etapa, joga o card para o fim da coluna destino.
        if (! empty($data['stage']) && $data['stage'] !== $deal->stage) {
            $data['position'] = (Deal::where('stage', $data['stage'])->max('position') ?? -1) + 1;
        }

        $deal->update($data);

        return $deal;
    }
}
