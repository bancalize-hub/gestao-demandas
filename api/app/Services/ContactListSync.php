<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Conversation;
use App\Support\Csv;
use Illuminate\Database\Eloquent\Builder;

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
            ->tap(fn ($q) => self::filtroQualificacao($q, $criteria))
            ->when($desde, fn ($q) => $q->where('conversations.updated_at', '>=', $desde))
            ->orderBy('id')
            ->chunkById(200, function ($conversas) use ($lista, $soAnuncio, &$entraram) {
                foreach ($conversas as $conv) {
                    if ($soAnuncio && ! LeadsDeAnuncio::daConversa($conv)) {
                        continue;
                    }

                    $r = $this->adicionar($lista, $conv);
                    if ($r && $r['entrou']) {
                        $entraram++;
                    }
                }
            });

        $lista->forceFill(['synced_at' => now()])->save();

        return $entraram;
    }

    /**
     * Filtro de triagem do critério, aplicado igual na criação da lista e no sync —
     * se os dois divergirem, a lista nasce com uma régua e cresce com outra.
     *
     * `null`/ausente = não filtra (comportamento das listas antigas, que nem sabiam
     * o que era triagem). `sem` é diferente de desqualificado: metade dos leads de
     * anúncio nunca disse nada, e tratar silêncio como "ruim" inventaria número.
     */
    public static function filtroQualificacao(Builder $query, array $criteria): Builder
    {
        $qual = $criteria['qualified'] ?? null;
        if ($qual === null || $qual === '') {
            return $query;
        }

        return $qual === 'sem'
            ? $query->whereNull('qualified')
            : $query->where('qualified', in_array($qual, ['1', 1, true], true));
    }

    /**
     * Garante o contato do lead e o coloca na lista.
     *
     * Devolve `entrou` (passou a fazer parte da lista agora) e `contato_novo` (não
     * existia na agenda) — quem chama conta uma coisa ou outra. `null` quando a
     * conversa não tem telefone aproveitável (@lid órfã, por exemplo).
     *
     * @return array{entrou:bool, contato_novo:bool}|null
     */
    public function adicionar(ContactList $lista, Conversation $conv): ?array
    {
        $tel = Csv::telefone((string) $conv->phone);
        if ($tel === '') {
            return null;
        }

        $contato = Contact::where('phone', $tel)->first();
        $novo = ! $contato;
        if (! $contato) {
            $contato = Contact::create(['name' => $conv->name ?: '+'.$tel, 'phone' => $tel]);
        }

        // O pivô tem chave única: quem já está na lista não duplica nem conta.
        if ($lista->contacts()->whereKey($contato->id)->exists()) {
            return ['entrou' => false, 'contato_novo' => $novo];
        }

        $lista->contacts()->syncWithoutDetaching([$contato->id]);

        return ['entrou' => true, 'contato_novo' => $novo];
    }

    /**
     * Lead acabou de ser qualificado → entra NA HORA nas listas de qualificados.
     *
     * O `lists:sync` roda a cada 15 min e sozinho já daria conta, mas quem qualifica
     * costuma querer disparar em seguida — esperar o tick faz a lista parecer quebrada
     * na única hora em que alguém olha para ela.
     *
     * Best-effort de propósito: nenhuma falha aqui pode derrubar a triagem (nem o clique
     * no chip, nem a rodada da IA).
     */
    public function matricularQualificado(Conversation $conv): int
    {
        $entraram = 0;

        foreach (ContactList::where('auto', true)->get() as $lista) {
            $criteria = (array) ($lista->criteria ?? []);
            if (($criteria['qualified'] ?? null) !== '1') {
                continue;
            }
            // A etapa do funil também vale aqui: "qualificados em negociação" não pode
            // receber quem foi qualificado mas está em outra etapa.
            if (! empty($criteria['stage']) && $conv->stage !== $criteria['stage']) {
                continue;
            }
            if (($criteria['somente_anuncio'] ?? false) && ! LeadsDeAnuncio::daConversa($conv)) {
                continue;
            }

            $r = $this->adicionar($lista, $conv);
            if ($r && $r['entrou']) {
                $entraram++;
            }
        }

        return $entraram;
    }
}
