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

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'sub' => 'nullable|string|max:255',
            'value' => 'nullable|string|max:50',
            'stage' => 'required|string|max:32',
            'tag' => 'nullable|string|max:50',
            'hot' => 'sometimes|boolean',
        ]);

        $data['position'] = (Deal::where('stage', $data['stage'])->max('position') ?? -1) + 1;

        return response()->json(Deal::create($data), 201);
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
