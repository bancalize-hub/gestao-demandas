<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\ContactList;
use App\Models\WaAccount;
use App\Support\Csv;
use Illuminate\Http\Request;

class CampaignController extends Controller
{
    private function ensureAdmin(Request $request): void
    {
        abort_unless((bool) $request->user()?->is_admin, 403, 'Apenas administradores.');
    }

    public function index(Request $request)
    {
        $this->ensureAdmin($request);

        $campaigns = Campaign::with('account:id,name,role,provider,state,is_active,daily_cap,warmup_day,sent_today,sent_date')
            ->orderByDesc('id')
            ->get();

        $hoje = now()->toDateString();
        foreach ($campaigns as $c) {
            $c->pending = $c->contacts()->where('status', 'pending')->count();
            $c->sent_today = $c->contacts()->whereIn('status', ['sent', 'replied'])->whereDate('sent_at', $hoje)->count();
            $c->restante_hoje = $c->account?->remainingToday() ?? 0;
            $c->motivo = $this->porQueNaoDispara($c);
        }

        return response()->json(['campaigns' => $campaigns]);
    }

    /**
     * Campanha "disparando" que não anda é a dúvida número um da tela. Em vez de deixar
     * o usuário adivinhar, devolve o motivo — as mesmas condições que o CampaignTick usa.
     */
    private function porQueNaoDispara(Campaign $c): ?string
    {
        if ($c->status !== 'running') {
            return null;
        }
        $acct = $c->account;
        if (! $acct || ! $acct->is_active) {
            return 'número desligado';
        }
        if ($acct->isCloud() && ! $c->template_name) {
            return 'sem template escolhido';
        }
        if ($acct->state !== 'open') {
            return 'número desconectado';
        }
        if ($c->starts_at && now()->lt($c->starts_at)) {
            return 'agendada para '.$c->starts_at->format('d/m/Y \à\s H:i');
        }
        // Espelha o CampaignTick: janela, dia e teto da campanha valem em TODO canal;
        // só o teto do número (warmup) é exclusivo da Evolution. Divergir daqui é a tela
        // mostrando "disparando" com a campanha parada sem motivo.
        $hm = now()->format('H:i');
        if ($hm < $c->window_start || $hm > $c->window_end) {
            return "fora da janela ({$c->window_start}–{$c->window_end})";
        }
        if (! in_array(now()->isoWeekday(), \App\Support\Attendance::dias(\App\Models\Company::find($c->company_id)), true)) {
            return 'fora dos dias de atendimento (Admin → Horário de atendimento)';
        }
        if ($c->daily_cap > 0 && $c->sent_today >= $c->daily_cap) {
            return 'teto diário da campanha atingido';
        }
        if ($c->usaAntiBan() && $acct->remainingToday() <= 0) {
            return 'teto diário do número atingido';
        }
        if ($c->pending === 0) {
            return 'sem contatos pendentes';
        }

        return null;
    }

    public function show(Request $request, Campaign $campaign)
    {
        $this->ensureAdmin($request);

        $campaign->load('account:id,name,role');
        $contacts = $campaign->contacts()->orderBy('id')->limit(500)->get();

        return response()->json(['campaign' => $campaign, 'contacts' => $contacts]);
    }

    public function store(Request $request)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => 'required|string|max:120',
            'wa_account_id' => 'required|integer|exists:wa_accounts,id',
            'objective' => 'nullable|string|max:4000',
            'min_gap_s' => 'nullable|integer|min:15|max:3600',
            'max_gap_s' => 'nullable|integer|min:15|max:7200',
            'daily_cap' => 'nullable|integer|min:1|max:1000',
            'window_start' => 'nullable|date_format:H:i',
            'window_end' => 'nullable|date_format:H:i',
            'starts_at' => 'nullable|date',
            // Canal oficial (Cloud API): template aprovado + o que vai em cada {{n}}.
            'template_name' => 'nullable|string|max:191',
            'template_language' => 'nullable|string|max:16',
            'template_body' => 'nullable|string|max:2000',
            'template_params' => 'nullable|array|max:10',
            'template_params.*' => 'nullable|string|max:300',
        ]);

        $acct = WaAccount::findOrFail($data['wa_account_id']);

        // O principal da EVOLUTION não dispara: é o número que atende os anúncios e uma
        // rodada de prospecção no Baileys arrisca o bloqueio dele. No canal OFICIAL isso
        // não se aplica — lá o disparo é template aprovado, que é o caminho que a própria
        // Meta oferece, e muitas empresas têm um número só.
        abort_if(
            $acct->isPrimary() && ! $acct->isCloud(),
            422,
            'O número principal não pode ser usado para disparo. Conecte um número de prospecção.'
        );
        // O template do canal oficial é cobrado só ao INICIAR (ver update): exigir aqui
        // travava a criação enquanto os templates estão em análise na Meta — dá para
        // montar a campanha e carregar as listas antes da aprovação sair.

        $campaign = Campaign::create([
            'name' => $data['name'],
            'wa_account_id' => $acct->id,
            'objective' => $data['objective'] ?? null,
            'min_gap_s' => $data['min_gap_s'] ?? 60,
            'max_gap_s' => max($data['min_gap_s'] ?? 60, $data['max_gap_s'] ?? 180),
            'daily_cap' => $data['daily_cap'] ?? 40,
            // Sem janela explícita, a campanha nasce com o horário de atendimento da
            // empresa (Admin → Horário de atendimento). Quem quiser diferente ajusta na
            // própria campanha — o campo dela continua mandando.
            'window_start' => $data['window_start'] ?? \App\Support\Attendance::da($request->user()->company)['start'],
            'window_end' => $data['window_end'] ?? \App\Support\Attendance::da($request->user()->company)['end'],
            'starts_at' => $data['starts_at'] ?? null,
            'template_name' => $data['template_name'] ?? null,
            'template_language' => $data['template_language'] ?? null,
            'template_body' => $data['template_body'] ?? null,
            'template_params' => $data['template_params'] ?? null,
            'status' => 'draft',
        ]);

        return response()->json($campaign, 201);
    }

    public function update(Request $request, Campaign $campaign)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'name' => 'sometimes|string|max:120',
            'objective' => 'nullable|string|max:4000',
            'min_gap_s' => 'sometimes|integer|min:15|max:3600',
            'max_gap_s' => 'sometimes|integer|min:15|max:7200',
            'daily_cap' => 'sometimes|integer|min:1|max:1000',
            'window_start' => 'sometimes|date_format:H:i',
            'window_end' => 'sometimes|date_format:H:i',
            'status' => 'sometimes|in:draft,running,paused,done',
            'starts_at' => 'sometimes|nullable|date',
            'template_name' => 'sometimes|nullable|string|max:191',
            'template_language' => 'sometimes|nullable|string|max:16',
            'template_body' => 'sometimes|nullable|string|max:2000',
            'template_params' => 'sometimes|nullable|array|max:10',
            'template_params.*' => 'nullable|string|max:300',
        ]);

        if (($data['status'] ?? null) === 'running') {
            if ($campaign->contacts()->where('status', 'pending')->count() === 0) {
                return response()->json(['message' => 'Adicione contatos (por lista ou CSV) antes de iniciar.'], 422);
            }
            // No canal oficial, quem nunca escreveu só recebe template aprovado.
            if ($campaign->account?->isCloud() && ! $campaign->template_name) {
                return response()->json(['message' => 'Este número dispara pela API oficial: escolha um template aprovado antes de iniciar.'], 422);
            }
        }

        $campaign->update($data);

        return response()->json($campaign->fresh()->load('account:id,name,role'));
    }

    public function destroy(Request $request, Campaign $campaign)
    {
        $this->ensureAdmin($request);

        $campaign->contacts()->delete();
        $campaign->delete();

        return response()->json(['message' => 'ok']);
    }

    /**
     * Carrega a campanha com os contatos das listas escolhidas.
     * Dedupe por telefone: a mesma pessoa em duas listas entra uma vez só.
     */
    public function importFromLists(Request $request, Campaign $campaign)
    {
        $this->ensureAdmin($request);

        $data = $request->validate([
            'list_ids' => 'required|array|min:1',
            'list_ids.*' => 'integer',
        ]);

        $listas = ContactList::whereIn('id', $data['list_ids'])->get();
        abort_if($listas->isEmpty(), 422, 'Nenhuma lista válida.');

        $jaNaCampanha = $campaign->contacts()->pluck('phone')->all();
        $vistos = array_flip($jaNaCampanha);
        $adicionados = 0;
        $ignorados = 0;

        foreach ($listas as $lista) {
            foreach ($lista->contacts()->get(['contacts.id', 'name', 'phone']) as $c) {
                $tel = Csv::telefone((string) $c->phone);
                if ($tel === '' || isset($vistos[$tel])) {
                    $ignorados++;

                    continue;
                }
                $vistos[$tel] = true;
                CampaignContact::create([
                    'campaign_id' => $campaign->id,
                    'name' => $c->name ?: null,
                    'phone' => $tel,
                    'status' => 'pending',
                ]);
                $adicionados++;
            }
        }

        $campaign->refreshCounts();

        return response()->json([
            'added' => $adicionados,
            'skipped' => $ignorados,
            'total' => $campaign->contacts()->count(),
        ]);
    }

    /**
     * Importa contatos de um CSV (multipart 'file' ou texto 'csv').
     * Detecta a coluna de telefone e nome; demais colunas viram variáveis de personalização.
     */
    public function importContacts(Request $request, Campaign $campaign)
    {
        $this->ensureAdmin($request);

        $raw = '';
        if ($request->hasFile('file')) {
            $raw = (string) file_get_contents($request->file('file')->getRealPath());
        } else {
            $raw = (string) $request->input('csv', '');
        }
        $raw = trim($raw);
        if ($raw === '') {
            return response()->json(['message' => 'Envie um arquivo CSV ou cole os dados.'], 422);
        }

        $rows = $this->parseCsv($raw);
        if (count($rows) < 1) {
            return response()->json(['message' => 'Não consegui ler linhas no CSV.'], 422);
        }

        // Cabeçalho → índices de telefone/nome.
        $header = array_map(fn ($h) => mb_strtolower(trim($h)), $rows[0]);
        $hasHeader = $this->looksLikeHeader($header);
        $phoneIdx = ($hasHeader ? $this->findColumn($header, ['telefone', 'phone', 'celular', 'whatsapp', 'fone', 'numero', 'número']) : 0) ?? 0;
        $nameIdx = $hasHeader ? $this->findColumn($header, ['nome', 'name', 'contato', 'cliente']) : 1;

        $dataRows = $hasHeader ? array_slice($rows, 1) : $rows;
        // Se não há cabeçalho e a 2ª coluna não existe, nome fica nulo.
        $added = 0;
        $skipped = 0;
        $seen = [];

        foreach ($dataRows as $cols) {
            $phoneRaw = $cols[$phoneIdx] ?? '';
            $phone = $this->normalizePhone((string) $phoneRaw);
            if ($phone === '') {
                $skipped++;

                continue;
            }
            if (isset($seen[$phone]) || $campaign->contacts()->where('phone', $phone)->exists()) {
                $skipped++;

                continue; // dedupe dentro do arquivo e contra o que já existe
            }
            $seen[$phone] = true;

            $name = $nameIdx !== null ? trim((string) ($cols[$nameIdx] ?? '')) : '';

            // Demais colunas viram vars (chave = cabeçalho, ou colN).
            $vars = [];
            foreach ($cols as $i => $val) {
                if ($i === $phoneIdx || $i === $nameIdx) {
                    continue;
                }
                $val = trim((string) $val);
                if ($val === '') {
                    continue;
                }
                $key = $hasHeader ? ($header[$i] ?? ('col'.$i)) : ('col'.$i);
                $vars[$key] = $val;
            }

            CampaignContact::create([
                'campaign_id' => $campaign->id,
                'name' => $name ?: null,
                'phone' => $phone,
                'vars' => $vars ?: null,
                'status' => 'pending',
            ]);
            $added++;
        }

        $campaign->refreshCounts();

        return response()->json([
            'added' => $added,
            'skipped' => $skipped,
            'total' => $campaign->contacts()->count(),
        ]);
    }

    /** Parser de CSV simples (vírgula ou ponto-e-vírgula), respeitando aspas. */
    private function parseCsv(string $raw): array
    {
        $delim = (substr_count($raw, ';') > substr_count($raw, ',')) ? ';' : ',';
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (trim($line) === '') {
                continue;
            }
            $rows[] = str_getcsv($line, $delim);
        }

        return $rows;
    }

    private function looksLikeHeader(array $header): bool
    {
        foreach ($header as $h) {
            if (in_array($h, ['telefone', 'phone', 'celular', 'whatsapp', 'fone', 'numero', 'número', 'nome', 'name', 'contato', 'cliente'], true)) {
                return true;
            }
        }

        return false;
    }

    private function findColumn(array $header, array $candidates): ?int
    {
        foreach ($candidates as $c) {
            $i = array_search($c, $header, true);
            if ($i !== false) {
                return (int) $i;
            }
        }

        return null;
    }

    /** Normaliza telefone para só dígitos com DDI Brasil quando ausente. */
    private function normalizePhone(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw);
        if ($d === '') {
            return '';
        }
        if (strlen($d) <= 11) {
            $d = '55'.$d; // assume Brasil quando vem sem código do país
        }

        return $d;
    }
}
