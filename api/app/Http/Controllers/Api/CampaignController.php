<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Campaign;
use App\Models\CampaignContact;
use App\Models\WaAccount;
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

        $campaigns = Campaign::with('account:id,name,role')
            ->orderByDesc('id')
            ->get();

        return response()->json(['campaigns' => $campaigns]);
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
        ]);

        // Só números de prospecção podem disparar (o principal é só atendimento de anúncios).
        $acct = WaAccount::findOrFail($data['wa_account_id']);
        abort_if($acct->isPrimary(), 422, 'O número principal não pode ser usado para disparo. Conecte um número de prospecção.');

        $campaign = Campaign::create([
            'name' => $data['name'],
            'wa_account_id' => $acct->id,
            'objective' => $data['objective'] ?? null,
            'min_gap_s' => $data['min_gap_s'] ?? 60,
            'max_gap_s' => max($data['min_gap_s'] ?? 60, $data['max_gap_s'] ?? 180),
            'daily_cap' => $data['daily_cap'] ?? 40,
            'window_start' => $data['window_start'] ?? '09:00',
            'window_end' => $data['window_end'] ?? '18:00',
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
        ]);

        // Não deixa iniciar uma campanha sem contatos.
        if (($data['status'] ?? null) === 'running' && $campaign->contacts()->where('status', 'pending')->count() === 0) {
            return response()->json(['message' => 'Adicione contatos (CSV) antes de iniciar.'], 422);
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
