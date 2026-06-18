<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Illuminate\Console\Command;

class ContactsSync extends Command
{
    protected $signature = 'contacts:sync';

    protected $description = 'Puxa os contatos do Google (People API) para a tabela contacts.';

    public function handle(): int
    {
        $gu = User::whereNotNull('google_refresh_token')->first();
        if (! $gu) {
            $this->warn('Nenhum usuario com Google conectado.');

            return self::SUCCESS;
        }

        try {
            $list = app(GoogleCalendarService::class)->listContactsFull($gu);
        } catch (\Throwable $e) {
            $this->error('Falha ao listar contatos do Google (reconecte o Google c/ permissao de contatos): '.$e->getMessage());

            return self::FAILURE;
        }

        $seen = [];
        foreach ($list as $c) {
            if (empty($c['resource_name'])) {
                continue;
            }
            $seen[] = $c['resource_name'];
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
        // Remove os que sumiram do Google (apenas os vindos do Google).
        $removed = Contact::whereNotNull('resource_name')->whereNotIn('resource_name', $seen ?: ['__none__'])->delete();

        $this->info('Contatos sincronizados: '.count($seen).' (removidos: '.$removed.').');

        return self::SUCCESS;
    }
}
