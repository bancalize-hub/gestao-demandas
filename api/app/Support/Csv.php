<?php

namespace App\Support;

/**
 * Leitura de planilha colada/enviada como CSV (contatos, campanhas).
 * Estava duplicado dentro do CampaignController; agora as duas telas usam o mesmo.
 */
class Csv
{
    private const COLUNAS_TELEFONE = ['telefone', 'phone', 'celular', 'whatsapp', 'fone', 'numero', 'número'];

    private const COLUNAS_NOME = ['nome', 'name', 'contato', 'cliente'];

    /** Linhas do CSV (aceita vírgula ou ponto-e-vírgula, respeitando aspas). */
    public static function linhas(string $raw): array
    {
        $delim = (substr_count($raw, ';') > substr_count($raw, ',')) ? ';' : ',';
        $rows = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) as $line) {
            if (trim($line) !== '') {
                $rows[] = str_getcsv($line, $delim);
            }
        }

        return $rows;
    }

    public static function temCabecalho(array $header): bool
    {
        foreach ($header as $h) {
            if (in_array($h, array_merge(self::COLUNAS_TELEFONE, self::COLUNAS_NOME), true)) {
                return true;
            }
        }

        return false;
    }

    public static function coluna(array $header, array $candidatas): ?int
    {
        foreach ($candidatas as $c) {
            $i = array_search($c, $header, true);
            if ($i !== false) {
                return (int) $i;
            }
        }

        return null;
    }

    /** Telefone só com dígitos; sem DDI assume Brasil. */
    public static function telefone(string $raw): string
    {
        $d = preg_replace('/\D/', '', $raw);
        if ($d === '') {
            return '';
        }

        return strlen($d) <= 11 ? '55'.$d : $d;
    }

    /**
     * Normaliza a planilha inteira: devolve [['nome'=>, 'telefone'=>, 'vars'=>[]], ...].
     * Sem cabeçalho reconhecível, assume 1ª coluna = telefone e 2ª = nome.
     */
    public static function contatos(string $raw): array
    {
        $rows = self::linhas($raw);
        if (! $rows) {
            return [];
        }

        $header = array_map(fn ($h) => mb_strtolower(trim((string) $h)), $rows[0]);
        $comCabecalho = self::temCabecalho($header);
        $iTel = ($comCabecalho ? self::coluna($header, self::COLUNAS_TELEFONE) : 0) ?? 0;
        $iNome = $comCabecalho ? self::coluna($header, self::COLUNAS_NOME) : 1;

        $saida = [];
        foreach ($comCabecalho ? array_slice($rows, 1) : $rows as $cols) {
            $tel = self::telefone((string) ($cols[$iTel] ?? ''));
            if ($tel === '') {
                continue;
            }

            $vars = [];
            foreach ($cols as $i => $val) {
                $val = trim((string) $val);
                if ($i === $iTel || $i === $iNome || $val === '') {
                    continue;
                }
                $vars[$comCabecalho ? ($header[$i] ?? 'col'.$i) : 'col'.$i] = $val;
            }

            $saida[] = [
                'nome' => $iNome !== null ? trim((string) ($cols[$iNome] ?? '')) : '',
                'telefone' => $tel,
                'vars' => $vars,
            ];
        }

        return $saida;
    }
}
