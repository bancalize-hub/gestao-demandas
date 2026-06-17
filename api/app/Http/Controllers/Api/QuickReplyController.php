<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\QuickReply;
use Illuminate\Http\Request;

class QuickReplyController extends Controller
{
    public function index()
    {
        return QuickReply::orderBy('position')->orderBy('id')->get();
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'label' => 'required|string|max:80',
            'text' => 'required|string|max:2000',
        ]);

        $data['position'] = (QuickReply::max('position') ?? -1) + 1;

        return response()->json(QuickReply::create($data), 201);
    }

    public function destroy(QuickReply $quickReply)
    {
        $quickReply->delete();

        return response()->json(['message' => 'ok']);
    }
}
