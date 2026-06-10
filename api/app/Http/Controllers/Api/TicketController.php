<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTicketRequest;
use App\Http\Requests\UpdateTicketRequest;
use App\Http\Resources\TicketResource;
use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class TicketController extends Controller
{
    public function index(Request $request)
    {
        $tickets = Ticket::with('anexos')
            ->when($request->filled('tipo'), fn ($q) => $q->where('tipo', $request->string('tipo')))
            ->when($request->filled('prioridade'), fn ($q) => $q->where('prioridade', $request->string('prioridade')))
            ->when($request->filled('empresa'), fn ($q) => $q->where('solicitante_empresa', 'like', '%'.$request->string('empresa').'%'))
            ->when($request->filled('modulo'), fn ($q) => $q->where('modulo', 'like', '%'.$request->string('modulo').'%'))
            ->when($request->filled('busca'), function ($q) use ($request) {
                $busca = $request->string('busca');
                $q->where(fn ($w) => $w
                    ->where('titulo', 'like', "%{$busca}%")
                    ->orWhere('codigo', 'like', "%{$busca}%")
                    ->orWhere('descricao', 'like', "%{$busca}%"));
            })
            ->orderBy('ordem')
            ->orderByDesc('created_at')
            ->get();

        return TicketResource::collection($tickets);
    }

    public function store(StoreTicketRequest $request)
    {
        $dados = $request->safe()->except('anexos');
        $dados['data_solicitacao'] = now();

        $ticket = DB::transaction(function () use ($request, $dados) {
            $ticket = Ticket::create($dados);

            foreach ($request->file('anexos', []) as $arquivo) {
                $path = $arquivo->store("anexos/{$ticket->id}", 'public');

                $ticket->anexos()->create([
                    'nome_arquivo' => $arquivo->getClientOriginalName(),
                    'path' => $path,
                    'mime_type' => $arquivo->getClientMimeType(),
                    'tamanho' => $arquivo->getSize(),
                ]);
            }

            return $ticket;
        });

        return (new TicketResource($ticket->fresh()->load('anexos')))
            ->response()
            ->setStatusCode(201);
    }

    public function show(Ticket $ticket)
    {
        return new TicketResource($ticket->load('anexos'));
    }

    public function update(UpdateTicketRequest $request, Ticket $ticket)
    {
        $ticket->update($request->validated());

        return new TicketResource($ticket->fresh()->load('anexos'));
    }
}
