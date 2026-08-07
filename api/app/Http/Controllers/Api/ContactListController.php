<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Conversation;
use App\Services\ContactListSync;
use App\Services\LeadsDeAnuncio;
use App\Support\Csv;
use Illuminate\Http\Request;

/**
 * Listas de contatos: a agenda deixa de ser só "o que veio do Google".
 * Cada lista agrupa contatos (planilha importada, leads do CRM, seleção manual) e é
 * o que a campanha escolhe na hora do disparo.
 */
class ContactListController extends Controller
{
    public function index()
    {
        $listas = ContactList::withCount('contacts')->orderBy('name')->get();

        return response()->json([
            'lists' => $listas,
            'total' => Contact::count(),
            // Contato que não está em lista nenhuma continua visível em "Todos"; este número
            // é o que explica a diferença entre o total e a soma das listas.
            'sem_lista' => Contact::whereDoesntHave('lists')->count(),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate(['name' => 'required|string|max:120']);

        return response()->json(ContactList::create(['name' => trim($data['name']), 'kind' => 'manual']), 201);
    }

    public function update(Request $request, ContactList $contactList)
    {
        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'auto' => 'sometimes|boolean', // desligar = congelar a lista como está hoje
        ]);

        if (isset($data['name'])) {
            $contactList->name = trim($data['name']);
        }
        if (array_key_exists('auto', $data)) {
            // Ligar o automático exige saber QUAL é o critério. Lista de planilha/manual (ou
            // uma lista antiga sem critério gravado) não tem regra nenhuma — ligar ali faria
            // o sync despejar a base inteira dentro dela.
            abort_if(
                $data['auto'] && ! $contactList->criteria && $contactList->kind !== 'anuncio',
                422,
                'Esta lista não tem um critério gravado — crie uma lista a partir do CRM para ela se alimentar sozinha.',
            );
            $contactList->auto = $data['auto'];
        }
        $contactList->save();

        return response()->json($contactList);
    }

    /** Apaga a lista — os contatos continuam na agenda, só saem do grupo. */
    public function destroy(ContactList $contactList)
    {
        $contactList->contacts()->detach();
        $contactList->delete();

        return response()->json(['message' => 'ok']);
    }

    /** Entra/sai da lista (usado na ficha do contato). */
    public function sync(Request $request, ContactList $contactList)
    {
        $data = $request->validate([
            'contact_ids' => 'required|array',
            'contact_ids.*' => 'integer',
            'acao' => 'required|in:add,remove',
        ]);

        // Query escopada pela empresa: id de outro tenant simplesmente não existe aqui.
        $ids = Contact::whereIn('id', $data['contact_ids'])->pluck('id');

        $data['acao'] === 'add'
            ? $contactList->contacts()->syncWithoutDetaching($ids)
            : $contactList->contacts()->detach($ids);

        return response()->json(['message' => 'ok', 'total' => $contactList->contacts()->count()]);
    }

    /**
     * Importa uma planilha (CSV) criando a lista e os contatos que ainda não existem.
     * Dedupe por telefone: reimportar a mesma planilha não duplica a agenda.
     */
    public function importar(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:120',
            'file' => 'nullable|file|max:5120',
            'csv' => 'nullable|string',
        ]);

        $raw = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : (string) $request->input('csv', '');

        $linhas = Csv::contatos(trim($raw));
        if (! $linhas) {
            return response()->json(['message' => 'Não encontrei nenhum telefone na planilha. A coluna pode se chamar telefone, celular ou whatsapp.'], 422);
        }

        $lista = ContactList::create(['name' => trim($request->input('name')), 'kind' => 'planilha']);

        $novos = 0;
        $reaproveitados = 0;
        foreach ($linhas as $l) {
            $contato = Contact::where('phone', $l['telefone'])->first();
            if ($contato) {
                $reaproveitados++;
                if (! trim((string) $contato->name) && $l['nome']) {
                    $contato->update(['name' => $l['nome']]);
                }
            } else {
                $contato = Contact::create([
                    'name' => $l['nome'] ?: '+'.$l['telefone'],
                    'phone' => $l['telefone'],
                ]);
                $novos++;
            }
            $lista->contacts()->syncWithoutDetaching([$contato->id]);
        }

        return response()->json([
            'list' => $lista->loadCount('contacts'),
            'novos' => $novos,
            'ja_existiam' => $reaproveitados,
        ], 201);
    }

    /**
     * Cria uma lista com quem já conversou com a gente no WhatsApp — é assim que
     * "leads de anúncio" vira lista, já que eles entram no CRM como conversa.
     * `stage` limita a uma etapa do funil (ex.: só os que ainda são lead novo) e
     * `qualified` limita pela triagem (1 = qualificado, 0 = desqualificado, sem = sem triagem).
     */
    public function doCrm(Request $request, ContactListSync $sync)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'stage' => 'nullable|string|max:64',
            'qualified' => 'nullable|in:1,0,sem',
            'somente_anuncio' => 'boolean',
        ]);

        // "Só anúncio" tem lista própria e fixa: é a mesma que os webhooks alimentam
        // sozinhos, então rodar de novo só completa o que faltava (nada duplica).
        if ($request->boolean('somente_anuncio')) {
            $r = LeadsDeAnuncio::sincronizarHistorico();

            return response()->json([
                'list' => $r['list'],
                'novos' => $r['adicionados'],
                'sem_telefone' => $r['sem_telefone'],
            ], 201);
        }

        $criteria = [
            'stage' => $data['stage'] ?? null,
            'qualified' => $data['qualified'] ?? null,
            'somente_anuncio' => false,
        ];

        $conversas = Conversation::query()
            ->where('origin', 'WhatsApp')
            ->whereNotNull('phone')
            ->when(! empty($data['stage']), fn ($q) => $q->where('stage', $data['stage']))
            ->tap(fn ($q) => ContactListSync::filtroQualificacao($q, $criteria))
            ->get(['id', 'name', 'phone']);

        if ($conversas->isEmpty()) {
            return response()->json(['message' => 'Nenhuma conversa encontrada com esse filtro.'], 422);
        }

        // A lista guarda o CRITÉRIO, não só o resultado de agora: o `lists:sync` reaplica
        // a cada 15 min e o lead que chegar amanhã entra sozinho. Sem isso, a lista era a
        // foto do dia em que nasceu e a campanha disparava para uma base velha.
        $lista = ContactList::create([
            'name' => trim($data['name']),
            'kind' => 'crm',
            'auto' => true,
            'criteria' => $criteria,
            'synced_at' => now(),
        ]);

        $novos = 0;
        foreach ($conversas as $c) {
            $r = $sync->adicionar($lista, $c);
            if ($r && $r['contato_novo']) {
                $novos++;
            }
        }

        return response()->json(['list' => $lista->loadCount('contacts'), 'novos' => $novos], 201);
    }
}
