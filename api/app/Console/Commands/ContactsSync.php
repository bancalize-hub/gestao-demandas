<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\User;
use App\Services\GoogleCalendarService;
use App\Support\Tenancy;
use Illuminate\Console\Command;

class ContactsSync extends Command
{
    protected $signature = 'contacts:sync';

    protected $description = 'Puxa os contatos do Google (People API) para a tabela contacts, por empresa.';

    public function handle(): int
    {
        $tenancy = app(Tenancy::class);

        // Um usuário Google-conectado por empresa (o primeiro de cada). Cada empresa
        // sincroniza os contatos da SUA conta Google, isolado das demais.
        $users = User::whereNotNull('google_refresh_token')
            ->whereNotNull('company_id')
            ->get()->unique('company_id');

        if ($users->isEmpty()) {
            $this->warn('Nenhum usuario com Google conectado.');

            return self::SUCCESS;
        }

        foreach ($users as $gu) {
            $tenancy->run((int) $gu->company_id, function () use ($gu) {
                try {
                    $list = app(GoogleCalendarService::class)->listContactsFull($gu);
                } catch (\Throwable $e) {
                    $this->error("Empresa {$gu->company_id}: falha ao listar contatos do Google (reconecte c/ permissao de contatos): ".$e->getMessage());

                    return;
                }

                $seen = [];
                foreach ($list as $c) {
                    if (empty($c['resource_name'])) {
                        continue;
                    }
                    $seen[] = $c['resource_name'];
                    // updateOrCreate roda dentro do escopo da empresa: o match por resource_name
                    // já é filtrado por company_id e o novo registro nasce carimbado.
                    Contact::updateOrCreate(
                        ['resource_name' => $c['resource_name']],
                        [
                            'name' => $c['name'] !== '' ? $c['name'] : ($c['phone'] ?? 'Sem nome'),
                            'phone' => $c['phone'],
                            'email' => $c['email'],
                            'avatar' => $c['avatar'],
                            'etag' => $c['etag'],
                            'google_user_id' => $gu->id,
                        ]
                    );
                }
                // Remove os que sumiram do Google (apenas os desta empresa — delete é escopado).
                $removed = Contact::whereNotNull('resource_name')
                    ->whereNotIn('resource_name', $seen ?: ['__none__'])->delete();

                $this->info("Empresa {$gu->company_id}: contatos sincronizados ".count($seen)." (removidos: {$removed}).");
            });
        }

        return self::SUCCESS;
    }
}
