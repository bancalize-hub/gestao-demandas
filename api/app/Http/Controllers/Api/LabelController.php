<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Label;
use Illuminate\Http\Request;

class LabelController extends Controller
{
    public function index()
    {
        return Label::orderBy('position')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:40',
            'color' => 'nullable|string|max:9',
        ]);

        $data['color'] = $data['color'] ?? '#7c6cf5';
        $data['position'] = (Label::max('position') ?? -1) + 1;

        return response()->json(Label::create($data), 201);
    }

    public function destroy(Label $label)
    {
        $label->delete();

        return response()->json(['message' => 'ok']);
    }
}
