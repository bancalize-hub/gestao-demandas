<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Deal;
use App\Models\Stage;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class StageController extends Controller
{
    public function index()
    {
        return Stage::orderBy('position')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:40',
            'color' => 'nullable|string|max:9',
            'goal' => 'nullable|string|max:2000',
        ]);

        $base = Str::slug($data['name']) ?: 'etapa';
        $key = $base;
        $i = 2;
        while (Stage::where('key', $key)->exists()) {
            $key = $base.'-'.$i++;
        }

        return response()->json(Stage::create([
            'key' => $key,
            'name' => $data['name'],
            'color' => $data['color'] ?? '#8696a0',
            'goal' => $data['goal'] ?? null,
            'position' => (Stage::max('position') ?? -1) + 1,
        ]), 201);
    }

    public function update(Request $request, Stage $stage)
    {
        $stage->update($request->only(['name', 'color', 'goal', 'wa_label_id', 'position']));

        return response()->json($stage);
    }

    public function destroy(Stage $stage)
    {
        if (Stage::count() <= 1) {
            return response()->json(['message' => 'Mantenha pelo menos uma etapa.'], 422);
        }

        // Move os negócios desta etapa para a primeira etapa restante.
        $fallback = Stage::where('id', '!=', $stage->id)->orderBy('position')->first();
        Deal::where('stage', $stage->key)->update(['stage' => $fallback->key]);

        $stage->delete();

        return response()->json(['message' => 'ok']);
    }

    /** Reordena: recebe a lista de ids na nova ordem. */
    public function reorder(Request $request)
    {
        $ids = $request->validate(['ids' => 'required|array'])['ids'];
        foreach ($ids as $pos => $id) {
            Stage::where('id', $id)->update(['position' => $pos]);
        }

        return response()->json(['message' => 'ok']);
    }
}
