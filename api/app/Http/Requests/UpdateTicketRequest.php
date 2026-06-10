<?php

namespace App\Http\Requests;

use App\Enums\Prioridade;
use App\Enums\StatusTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'status' => ['sometimes', Rule::enum(StatusTicket::class)],
            'ordem' => ['sometimes', 'numeric'],
            'prioridade' => ['sometimes', Rule::enum(Prioridade::class)],
            'titulo' => ['sometimes', 'string', 'max:255'],
            'descricao' => ['sometimes', 'string'],
            'modulo' => ['sometimes', 'string', 'max:255'],
            'impacto_negocio' => ['sometimes', 'nullable', 'string'],
        ];
    }
}
