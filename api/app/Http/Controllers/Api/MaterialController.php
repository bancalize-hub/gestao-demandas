<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ChatTab;
use App\Models\Material;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/** Materiais (PDF etc.) que a IA envia quando julgar pertinente. */
class MaterialController extends Controller
{
    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }

    public function index()
    {
        return response()->json(['materials' => Material::orderByDesc('id')->get()]);
    }

    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'file' => 'required|file|max:20480', // 20MB: acima disso o WhatsApp recusa documento
            'name' => 'required|string|max:120',
            'quando' => 'nullable|string|max:500',
            'chat_tab_id' => ['nullable', 'integer', Rule::in(ChatTab::pluck('id')->all())],
        ]);

        $file = $request->file('file');
        $path = $file->store('materials');

        $material = Material::create([
            'chat_tab_id' => $data['chat_tab_id'] ?? null,
            'name' => $data['name'],
            'quando' => $data['quando'] ?? null,
            'path' => $path,
            'filename' => $file->getClientOriginalName() ?: basename($path),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => (int) $file->getSize(),
            'is_active' => true,
        ]);

        return response()->json($material, 201);
    }

    public function update(Request $request, Material $material)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'quando' => 'sometimes|nullable|string|max:500',
            'is_active' => 'sometimes|boolean',
            'chat_tab_id' => ['sometimes', 'nullable', 'integer', Rule::in(ChatTab::pluck('id')->all())],
        ]);
        $material->update($data);

        return response()->json($material);
    }

    public function destroy(Request $request, Material $material)
    {
        $this->ensureAdmin($request);

        Storage::delete($material->path);
        $material->delete();

        return response()->json(['message' => 'ok']);
    }
}
