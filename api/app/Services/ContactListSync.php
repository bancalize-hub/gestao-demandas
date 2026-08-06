<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Conversation;
use App\Support\Csv;

/**
 * Mantém as listas automáticas vivas: reaplica o critério com que a lista foi criada
 * e traz quem passou a se encaixar depois.
 *
 * Antes, "puxar do CRM" gerava uma FOTO: os leads que chegaram no dia seguinte ficavam
 * de fora e a campanha disparava para uma base velha. Agora a lista guarda o critério
 * (`criteria`) e cresce sozinha.
 *
 * Também é o que recupera o lead de anúncio que entrou como @lid (sem telefone) e só
 * ganhou número depois — na criação ele não virava contato e ninguém revisitava.
 */
class ContactListSync
{
    /** Reaplica o critério da lista. Devolve quantos contatos entraram agora. */
    public function sync(ContactList $lista): int
    {
        $criteria = (array) ($lista->criteria ?? []);

        // Lista de anúncio nasceu sem critério explícito — o critério dela é ser de anúncio.
        $soAnuncio = (bool) ($criteria['somente_anuncio'] ?? ($lista->kind === 'anuncio'));
        $stage = $criteria['stage'] ?? null;

        // Incremental: só o que mudou desde a última passada (com folga para não perder
        // nada numa corrida). Sem `synced_at`, varre tudo uma vez.
        $desde = $lista->synced_at?->copy()->subMinutes(10);

        $entraram = 0;

        Conversation::query()
            ->where('origin', 'WhatsApp')
            ->whereNotNull('phone')
            ->when($stage, fn ($q) => $q->where('stage', $stage))
            ->when($desde, fn ($q) => $q->where('conversations.updated_at', '>=', $desde))
            ->orderBy('id')
            ->chunkById(200, function ($conversas) use ($lista, $soAnuncio, &$entraram) {
                foreach ($conversas as $conv) {
                    if ($soAnuncio && ! LeadsDeAnuncio::daConversa($conv)) {
                        continue;
                    }

                    $tel = Csv::telefone((string) $conv->phone);
                    if ($tel === '') {
                        continue;
                    }

                    $contato = Contact::where('phone', $tel)->first()
                        ?? Contact::create(['name' => $conv->name ?: '+'.$tel, 'phone' => $tel]);

                    // O pivô tem chave única: quem já está na lista não duplica nem conta.
                    if (! $lista->contacts()->whereKey($contato->id)->exists()) {
                        $lista->contacts()->syncWithoutDetaching([$contato->id]);
                        $entraram++;
                    }
                }
            });

        $lista->forceFill(['synced_at' => now()])->save();

        return $entraram;
    }
}
