<?php

namespace App\Console\Commands;

use App\Models\Company;
use App\Models\ContactList;
use App\Services\ContactListSync;
use App\Support\Tenancy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Alimenta as listas automáticas de todas as empresas: o lead que chegou depois da
 * lista ser criada entra nela sozinho, sem ninguém apertar "puxar do CRM" de novo.
 */
class ListsSync extends Command
{
    protected $signature = 'lists:sync';

    protected $description = 'Reaplica o critério das listas automáticas (leads novos entram sozinhos)';

    public function handle(ContactListSync $sync): int
    {
        $tenancy = app(Tenancy::class);

        foreach (Company::where('is_active', true)->pluck('id') as $companyId) {
            $tenancy->run((int) $companyId, function () use ($sync, $companyId) {
                foreach (ContactList::where('auto', true)->get() as $lista) {
                    try {
                        $n = $sync->sync($lista);
                        if ($n > 0) {
                            $this->info("lists:sync empresa {$companyId} — {$n} contato(s) novo(s) em \"{$lista->name}\"");
                        }
                    } catch (\Throwable $e) {
                        // Uma lista problemática não pode parar as outras (nem as outras empresas).
                        Log::warning('lists:sync falhou', ['lista' => $lista->id, 'e' => $e->getMessage()]);
                    }
                }
            });
        }

        return self::SUCCESS;
    }
}
