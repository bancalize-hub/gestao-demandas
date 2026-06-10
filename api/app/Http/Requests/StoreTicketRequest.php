<?php

namespace App\Http\Requests;

use App\Enums\Frequencia;
use App\Enums\Prioridade;
use App\Enums\TipoTicket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTicketRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'tipo' => ['required', Rule::enum(TipoTicket::class)],

            // Informações gerais
            'titulo' => ['required', 'string', 'max:255'],
            'descricao' => ['required', 'string'],
            'modulo' => ['required_if:tipo,FEATURE', 'nullable', 'string', 'max:255'],
            'prioridade' => ['required', Rule::enum(Prioridade::class)],
            'impacto_negocio' => ['nullable', 'string'],

            // Solicitante
            'solicitante_nome' => ['required', 'string', 'max:255'],
            'solicitante_empresa' => ['required', 'string', 'max:255'],
            'solicitante_email' => ['required', 'email', 'max:255'],

            // Anexos
            'anexos' => ['nullable', 'array', 'max:5'],
            'anexos.*' => ['file', 'max:10240'],

            // Campos de BUG
            'comportamento_atual' => ['required_if:tipo,BUG', 'nullable', 'string'],
            'comportamento_esperado' => ['required_if:tipo,BUG', 'nullable', 'string'],
            'passos_reproducao' => ['nullable', 'string'],
            'ambiente_url' => ['nullable', 'string', 'max:255'],
            'navegador' => ['nullable', 'string', 'max:255'],
            'dispositivo' => ['nullable', 'string', 'max:255'],
            'data_hora_ocorrencia' => ['nullable', 'date'],
            'frequencia' => ['nullable', Rule::enum(Frequencia::class)],

            // Campos de FEATURE
            'objetivo' => ['required_if:tipo,FEATURE', 'nullable', 'string'],
            'regras_negocio' => ['required_if:tipo,FEATURE', 'nullable', 'string'],
            'fluxo_desejado' => ['required_if:tipo,FEATURE', 'nullable', 'string'],
            'criterios_aceitacao' => ['required_if:tipo,FEATURE', 'nullable', 'string'],
            'prazo_desejado' => ['nullable', 'date'],
        ];
    }

    public function attributes(): array
    {
        return [
            'titulo' => 'título da solicitação',
            'descricao' => 'descrição detalhada',
            'modulo' => 'módulo/sistema afetado',
            'impacto_negocio' => 'impacto no negócio',
            'solicitante_nome' => 'nome',
            'solicitante_empresa' => 'empresa',
            'solicitante_email' => 'e-mail',
            'comportamento_atual' => 'comportamento atual',
            'comportamento_esperado' => 'comportamento esperado',
            'passos_reproducao' => 'passos para reproduzir',
            'frequencia' => 'frequência',
            'objetivo' => 'objetivo da funcionalidade',
            'regras_negocio' => 'regras de negócio',
            'fluxo_desejado' => 'fluxo desejado',
            'criterios_aceitacao' => 'critérios de aceitação',
            'prazo_desejado' => 'prazo desejado',
        ];
    }
}
