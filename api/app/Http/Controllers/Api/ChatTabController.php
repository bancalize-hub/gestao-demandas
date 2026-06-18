<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatTab;
use Illuminate\Http\Request;

class ChatTabController extends Controller
{
    public function index()
    {
        return ChatTab::orderBy('position')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:40',
            'stages' => 'array',
            'stages.*' => 'string|max:64',
        ]);
        $data['stages'] = $data['stages'] ?? [];
        $data['position'] = (ChatTab::max('position') ?? -1) + 1;

        return response()->json(ChatTab::create($data), 201);
    }

    public function update(Request $request, ChatTab $chatTab)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:40',
            'stages' => 'sometimes|array',
            'stages.*' => 'string|max:64',
            'position' => 'sometimes|integer',
        ]);
        $chatTab->update($data);

        return response()->json($chatTab);
    }

    public function destroy(ChatTab $chatTab)
    {
        $chatTab->delete();

        return response()->json(['message' => 'ok']);
    }
}
