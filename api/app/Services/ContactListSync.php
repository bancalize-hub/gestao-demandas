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
        $this->removerQuemNaoCasaMais($lista, $criteria);

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
     * A conversa casa com o critério da lista?
     *
     * Régua única do entra-e-sai: se a checagem do add divergir da do remove, o lead
     * fica pingando entre dentro e fora a cada rodada.
     */
    public function combina(ContactList $lista, Conversation $conv): bool
    {
        $criteria = (array) ($lista->criteria ?? []);

        if (! empty($criteria['stage']) && $conv->stage !== $criteria['stage']) {
            return false;
        }

        $qual = $criteria['qualified'] ?? null;
        if ($qual !== null && $qual !== '') {
            if ($qual === 'sem') {
                if ($conv->qualified !== null) {
                    return false;
                }
            } else {
                // Sem triagem NÃO é desqualificado: `null` não pode casar com o critério
                // "0", senão a lista de leads ruins engoliria toda a base muda.
                if ($conv->qualified === null || $conv->qualified !== in_array($qual, ['1', 1, true], true)) {
                    return false;
                }
            }
        }

        if (($criteria['somente_anuncio'] ?? false) && ! LeadsDeAnuncio::daConversa($conv)) {
            return false;
        }

        return true;
    }

    /**
     * A triagem ou a etiqueta da conversa mudou → acerta a lista NA HORA: entra em quem
     * passou a casar, sai de quem deixou de casar.
     *
     * O `lists:sync` roda a cada 15 min e sozinho já daria conta, mas quem move a etiqueta
     * costuma querer disparar em seguida — esperar o tick faz a lista parecer quebrada na
     * única hora em que alguém olha para ela.
     *
     * Best-effort de propósito: nenhuma falha aqui pode derrubar a triagem (nem o clique
     * no chip, nem a rodada da IA, nem o arrastar do card no funil).
     *
     * @return array{entraram:int, sairam:int}
     */
    public function reconciliar(Conversation $conv): array
    {
        $entraram = 0;
        $sairam = 0;

        foreach (ContactList::where('auto', true)->get() as $lista) {
            if (! $this->temRegraDeEntrada($lista)) {
                continue;
            }

            if ($this->combina($lista, $conv)) {
                $r = $this->adicionar($lista, $conv);
                if ($r && $r['entrou']) {
                    $entraram++;
                }
            } elseif ($this->remover($lista, $conv)) {
                $sairam++;
            }
        }

        return ['entraram' => $entraram, 'sairam' => $sairam];
    }

    /**
     * Tira o contato da lista — a não ser que OUTRA conversa do mesmo telefone ainda
     * case com o critério. Sem essa checagem, desqualificar uma conversa duplicada
     * (@lid × telefone) derrubaria o lead da lista mesmo com a conversa boa qualificada.
     */
    private function remover(ContactList $lista, Conversation $conv): bool
    {
        $tel = Csv::telefone((string) $conv->phone);
        if ($tel === '') {
            return false;
        }

        $contato = Contact::where('phone', $tel)->first();
        if (! $contato || ! $lista->contacts()->whereKey($contato->id)->exists()) {
            return false;
        }

        $gemeas = Conversation::where('id', '!=', $conv->id)
            ->whereNotNull('phone')
            ->where('phone', 'like', '%'.substr($tel, -8))
            ->get();

        foreach ($gemeas as $outra) {
            if (Csv::telefone((string) $outra->phone) === $tel && $this->combina($lista, $outra)) {
                return false;
            }
        }

        $lista->contacts()->detach($contato->id);

        return true;
    }

    /**
     * Varre a lista inteira e tira quem já não casa com o critério (etiqueta mudou,
     * lead foi desqualificado).
     *
     * Só vale para lista com regra de ENTRADA declarada. Numa lista sem filtro — ou de
     * anúncio, cujo critério é a origem e essa não muda nunca — "não casa" não quer dizer
     * nada, e limpar ali apagaria quem foi posto na mão ou veio de planilha.
     */
    private function removerQuemNaoCasaMais(ContactList $lista, array $criteria): void
    {
        if (! $this->temRegraDeEntrada($lista)) {
            return;
        }

        // Conjunto COMPLETO de quem casa hoje (sem o recorte incremental do add: aqui a
        // pergunta é "quem sobra", e um recorte por data responderia errado).
        $telefones = Conversation::query()
            ->where('origin', 'WhatsApp')
            ->whereNotNull('phone')
            ->when($criteria['stage'] ?? null, fn ($q) => $q->where('stage', $criteria['stage']))
            ->tap(fn ($q) => self::filtroQualificacao($q, $criteria))
            ->pluck('phone')
            ->map(fn ($p) => Csv::telefone((string) $p))
            ->filter()
            ->unique();

        $sobrando = $lista->contacts()
            ->whereNotIn('contacts.phone', $telefones)
            ->pluck('contacts.id');

        if ($sobrando->isNotEmpty()) {
            $lista->contacts()->detach($sobrando->all());
        }
    }

    /**
     * A lista tem uma regra de entrada de verdade (etapa do funil ou triagem)?
     *
     * `somente_anuncio` de propósito NÃO conta: a origem do lead não muda com o tempo,
     * então ali nunca há o que tirar — e varrer a lista de anúncio custaria uma consulta
     * de mensagens por conversa (`LeadsDeAnuncio::daConversa`).
     */
    private function temRegraDeEntrada(ContactList $lista): bool
    {
        $criteria = (array) ($lista->criteria ?? []);

        return ! empty($criteria['stage']) || ! in_array($criteria['qualified'] ?? null, [null, ''], true);
    }
}
