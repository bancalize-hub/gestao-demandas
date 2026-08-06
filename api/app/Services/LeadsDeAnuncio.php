<?php

namespace App\Services;

use App\Models\Contact;
use App\Models\ContactList;
use App\Models\Conversation;
use App\Support\Csv;

/**
 * Lista "Leads de anúncio": quem chegou pelo clique-para-WhatsApp.
 *
 * Duas evidências, porque só uma não cobre o histórico:
 *  - `custom_fields.anuncio` — o `referral` que o webhook OFICIAL entrega. É a prova
 *    boa, mas só existe desde que o número migrou para a Cloud API.
 *  - o texto que a Meta PRÉ-PREENCHE na primeira mensagem do lead ("Olá! Tenho
 *    interesse e queria mais informações, por favor."). É como dá para reconhecer os
 *    ~300 leads que entraram pela Evolution, quando ninguém gravava a origem.
 */
class LeadsDeAnuncio
{
    /** Aberturas pré-preenchidas pelo anúncio. Lead que escreve sozinho não começa assim. */
    private const PREFILL = '/^ol[áa][!,.]?\s+(tenho interesse|posso (ter|saber) mais informa|vi (a p[áa]gina|o an[úu]ncio)|quero (saber|mais)|gostaria de (mais )?informa|tenho uma d[úu]vida sobre)/iu';

    /** A lista da empresa atual (criada na primeira vez que alguém cai nela). */
    public static function lista(): ContactList
    {
        return ContactList::firstOrCreate(['kind' => 'anuncio'], ['name' => 'Leads de anúncio']);
    }

    public static function pareceAnuncio(?string $texto): bool
    {
        $texto = trim((string) $texto);

        return $texto !== '' && (bool) preg_match(self::PREFILL, $texto);
    }

    /** Já sabemos que esta conversa veio de anúncio? (marca do webhook ou 1ª mensagem) */
    public static function daConversa(Conversation $conv): bool
    {
        if (! empty(($conv->custom_fields ?? [])['anuncio'])) {
            return true;
        }

        $primeira = $conv->messages()->reorder()
            ->where('is_out', false)->where('type', 'text')->whereNotNull('text')
            ->orderBy('ts')->orderBy('id')->value('text');

        return self::pareceAnuncio($primeira);
    }

    /**
     * Chamado pelos webhooks a cada mensagem recebida: se for lead de anúncio, o contato
     * entra na lista na hora — é o que faz a lista se manter sozinha.
     *
     * `$temReferral` vem do canal oficial (prova direta). O texto pré-preenchido só conta
     * na conversa recém-criada: numa conversa antiga, a mesma frase seria coincidência.
     */
    public static function registrarSeAnuncio(Conversation $conv, ?string $texto, bool $temReferral = false, bool $conversaNova = false): void
    {
        if (! $temReferral && ! ($conversaNova && self::pareceAnuncio($texto))) {
            return;
        }

        self::registrar($conv);
    }

    /**
     * Detecta o criativo de origem pelo texto pré-preenchido do wa.me (ex.: "Criativo 9").
     * Só age em conversas novas — numa conversa antiga seria coincidência.
     * Salva em custom_fields.criativo para relatórios reais por criativo.
     */
    public static function detectarCriativo(Conversation $conv, ?string $texto, bool $conversaNova): void
    {
        if (! $conversaNova) {
            return;
        }
        $texto = trim((string) $texto);
        if (! preg_match('/^Criativo\s+\d+$/iu', $texto)) {
            return;
        }
        $fields = $conv->custom_fields ?? [];
        if (! empty($fields['criativo'])) {
            return;
        }
        $fields['criativo'] = $texto;
        $conv->forceFill(['custom_fields' => $fields])->save();
    }

    /** Garante o contato do lead dentro da lista de anúncio. Sem telefone não há contato. */
    public static function registrar(Conversation $conv): ?Contact
    {
        $tel = Csv::telefone((string) $conv->phone);
        if ($tel === '') {
            return null;
        }

        $contato = Contact::where('phone', $tel)->first()
            ?? Contact::create(['name' => $conv->name ?: '+'.$tel, 'phone' => $tel]);

        self::lista()->contacts()->syncWithoutDetaching([$contato->id]);

        return $contato;
    }

    /**
     * Varre as conversas que já existem e joga na lista quem veio de anúncio.
     * Repetível: quem já está não duplica (o pivô tem chave única).
     */
    public static function sincronizarHistorico(): array
    {
        $lista = self::lista();
        $adicionados = 0;
        $semTelefone = 0;

        Conversation::where('origin', 'WhatsApp')
            ->orderBy('id')
            ->chunkById(200, function ($conversas) use (&$adicionados, &$semTelefone) {
                foreach ($conversas as $conv) {
                    if (! self::daConversa($conv)) {
                        continue;
                    }
                    // Conversa @lid órfã não tem para onde mandar — não vira contato.
                    self::registrar($conv) ? $adicionados++ : $semTelefone++;
                }
            });

        return [
            'list' => $lista->loadCount('contacts'),
            'adicionados' => $adicionados,
            'sem_telefone' => $semTelefone,
        ];
    }
}
