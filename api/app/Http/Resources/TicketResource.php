<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'codigo' => $this->codigo,
            'tipo' => $this->tipo,
            'titulo' => $this->titulo,
            'descricao' => $this->descricao,
            'modulo' => $this->modulo,
            'prioridade' => $this->prioridade,
            'impacto_negocio' => $this->impacto_negocio,
            'status' => $this->status,
            'ordem' => $this->ordem,

            'solicitante_nome' => $this->solicitante_nome,
            'solicitante_empresa' => $this->solicitante_empresa,
            'solicitante_email' => $this->solicitante_email,
            'data_solicitacao' => $this->data_solicitacao?->toIso8601String(),

            'comportamento_atual' => $this->comportamento_atual,
            'comportamento_esperado' => $this->comportamento_esperado,
            'passos_reproducao' => $this->passos_reproducao,
            'ambiente_url' => $this->ambiente_url,
            'navegador' => $this->navegador,
            'dispositivo' => $this->dispositivo,
            'data_hora_ocorrencia' => $this->data_hora_ocorrencia?->toIso8601String(),
            'frequencia' => $this->frequencia,

            'objetivo' => $this->objetivo,
            'regras_negocio' => $this->regras_negocio,
            'fluxo_desejado' => $this->fluxo_desejado,
            'criterios_aceitacao' => $this->criterios_aceitacao,
            'prazo_desejado' => $this->prazo_desejado?->toDateString(),

            'anexos' => AnexoResource::collection($this->whenLoaded('anexos')),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
