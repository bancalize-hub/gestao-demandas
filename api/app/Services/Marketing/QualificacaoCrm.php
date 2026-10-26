<?php

namespace App\Services\Marketing;

use Illuminate\Support\Facades\DB;

/**
 * Leads, triados e qualificados por anúncio, direto do CRM: o ad_id que veio no referral
 * do clique (`custom_fields.anuncio.id`). É a única régua de qualidade em que o piloto e o
 * gestor confiam — conversa e CTR da Meta medem curiosidade, não compra.
 */
class QualificacaoCrm
{
    /**
     * @param  list<string>  $adIds
     * @return array<string, array{leads: int, triados: int, qualificados: int, reunioes: int, compareceram: int}>
     */
    public static function porAnuncio(int $empresa, array $adIds, \DateTimeInterface $desde): array
    {
        if (! $adIds) {
            return [];
        }

        return DB::table('conversations')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id')) ad_id,
                COUNT(*) leads, SUM(qualified IS NOT NULL) triados, SUM(qualified = 1) qualificados,
                SUM(EXISTS(SELECT 1 FROM meetings mt WHERE mt.conversation_id = conversations.id)) reunioes,
                SUM(EXISTS(SELECT 1 FROM meetings mt WHERE mt.conversation_id = conversations.id AND mt.attended = 1)) compareceram")
            ->where('company_id', $empresa)
            ->where('created_at', '>=', $desde)
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id'))"), $adIds)
            ->groupBy('ad_id')
            ->get()
            ->mapWithKeys(fn ($l) => [(string) $l->ad_id => [
                'leads' => (int) $l->leads, 'triados' => (int) $l->triados, 'qualificados' => (int) $l->qualificados,
                'reunioes' => (int) $l->reunioes, 'compareceram' => (int) $l->compareceram,
            ]])
            ->all();
    }

    /**
     * Leads e qualificados por anúncio, dia a dia — é a série que mostra o criativo morrendo
     * (qualificação caindo com o gasto estável) antes do total da semana mostrar.
     *
     * @param  list<string>  $adIds
     * @return array<string, array<string, array{leads: int, qualificados: int}>> ad_id => Y-m-d => números
     */
    public static function serieDiaria(int $empresa, array $adIds, int $dias = 7): array
    {
        if (! $adIds) {
            return [];
        }

        $out = [];
        DB::table('conversations')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id')) ad_id, DATE(created_at) dia,
                COUNT(*) leads, SUM(qualified = 1) qualificados")
            ->where('company_id', $empresa)
            ->where('created_at', '>=', now()->subDays($dias)->startOfDay())
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id'))"), $adIds)
            ->groupBy('ad_id', 'dia')
            ->get()
            ->each(function ($l) use (&$out) {
                $out[(string) $l->ad_id][(string) $l->dia] = ['leads' => (int) $l->leads, 'qualificados' => (int) $l->qualificados];
            });

        return $out;
    }

    /**
     * Por que os leads de cada anúncio foram reprovados (o motivo que a triagem escreveu).
     * "Quer empréstimo", "não tem CNPJ", "curioso" — é o que diz se o problema é o criativo
     * atraindo o público errado ou só ruído.
     *
     * @param  list<string>  $adIds
     * @return array<string, list<string>>
     */
    public static function motivosDeReprovacao(int $empresa, array $adIds, int $dias = 7, int $porAnuncio = 6): array
    {
        if (! $adIds) {
            return [];
        }

        return DB::table('conversations')
            ->selectRaw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id')) ad_id, qualified_reason")
            ->where('company_id', $empresa)
            ->where('qualified', 0)
            ->whereNotNull('qualified_reason')
            ->where('created_at', '>=', now()->subDays($dias)->startOfDay())
            ->whereIn(DB::raw("JSON_UNQUOTE(JSON_EXTRACT(custom_fields,'$.anuncio.id'))"), $adIds)
            ->orderByDesc('created_at')
            ->get()
            ->groupBy('ad_id')
            ->map(fn ($g) => $g->pluck('qualified_reason')->map(fn ($m) => mb_substr((string) $m, 0, 140))->take($porAnuncio)->values()->all())
            ->all();
    }
}
