<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class AnexoResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'nome_arquivo' => $this->nome_arquivo,
            'mime_type' => $this->mime_type,
            'tamanho' => $this->tamanho,
            'url' => Storage::disk('public')->url($this->path),
        ];
    }
}
