<?php

namespace App\Support;

/**
 * DDD do telefone → UF e região.
 *
 * É a ÚNICA demografia que esta base tem para cruzar com qualificação real: o WhatsApp
 * não entrega idade nem gênero, e o breakdown de região do Facebook não devolve
 * conversão — só "conversa iniciada".
 *
 * RESSALVA QUE ANDA JUNTO DO NÚMERO: o DDD é do CELULAR, não da sede do CNPJ. Número é
 * portável e o decisor costuma carregar o DDD de onde morou. E a segmentação do Facebook
 * é por LOCALIZAÇÃO DO DISPOSITIVO, não por DDD — então este corte serve para ENTENDER
 * de onde vem lead bom, não para recortar público no gerenciador. Quem usar isto para
 * cortar região está cortando uma coisa medindo outra.
 */
class Ddd
{
    /** @var array<int, string> DDD => UF */
    private const UF = [
        11 => 'SP', 12 => 'SP', 13 => 'SP', 14 => 'SP', 15 => 'SP', 16 => 'SP', 17 => 'SP', 18 => 'SP', 19 => 'SP',
        21 => 'RJ', 22 => 'RJ', 24 => 'RJ', 27 => 'ES', 28 => 'ES',
        31 => 'MG', 32 => 'MG', 33 => 'MG', 34 => 'MG', 35 => 'MG', 37 => 'MG', 38 => 'MG',
        41 => 'PR', 42 => 'PR', 43 => 'PR', 44 => 'PR', 45 => 'PR', 46 => 'PR',
        47 => 'SC', 48 => 'SC', 49 => 'SC',
        51 => 'RS', 53 => 'RS', 54 => 'RS', 55 => 'RS',
        61 => 'DF', 62 => 'GO', 63 => 'TO', 64 => 'GO', 65 => 'MT', 66 => 'MT', 67 => 'MS',
        68 => 'AC', 69 => 'RO',
        71 => 'BA', 73 => 'BA', 74 => 'BA', 75 => 'BA', 77 => 'BA', 79 => 'SE',
        81 => 'PE', 82 => 'AL', 83 => 'PB', 84 => 'RN', 85 => 'CE', 86 => 'PI', 87 => 'PE', 88 => 'CE', 89 => 'PI',
        91 => 'PA', 92 => 'AM', 93 => 'PA', 94 => 'PA', 95 => 'RR', 96 => 'AP', 97 => 'AM', 98 => 'MA', 99 => 'MA',
    ];

    /** @var array<string, string> UF => região */
    private const REGIAO = [
        'AC' => 'Norte', 'AP' => 'Norte', 'AM' => 'Norte', 'PA' => 'Norte', 'RO' => 'Norte', 'RR' => 'Norte', 'TO' => 'Norte',
        'AL' => 'Nordeste', 'BA' => 'Nordeste', 'CE' => 'Nordeste', 'MA' => 'Nordeste', 'PB' => 'Nordeste',
        'PE' => 'Nordeste', 'PI' => 'Nordeste', 'RN' => 'Nordeste', 'SE' => 'Nordeste',
        'DF' => 'Centro-Oeste', 'GO' => 'Centro-Oeste', 'MT' => 'Centro-Oeste', 'MS' => 'Centro-Oeste',
        'ES' => 'Sudeste', 'MG' => 'Sudeste', 'RJ' => 'Sudeste', 'SP' => 'Sudeste',
        'PR' => 'Sul', 'RS' => 'Sul', 'SC' => 'Sul',
    ];

    /**
     * O DDD de um telefone como ele está guardado (5541995797386, +55 41 99579-7386, …).
     *
     * Só aceita número BRASILEIRO: em telefone estrangeiro os dois dígitos após o código
     * do país não são DDD nenhum, e tratá-los como se fossem inventaria um estado.
     */
    public static function de(?string $telefone): ?int
    {
        $so = preg_replace('/\D+/', '', (string) $telefone);
        if ($so === '' || ! str_starts_with($so, '55')) {
            return null;
        }

        // 55 + DDD(2) + número(8 ou 9).
        $resto = substr($so, 2);
        if (strlen($resto) < 10 || strlen($resto) > 11) {
            return null;
        }

        $ddd = (int) substr($resto, 0, 2);

        return isset(self::UF[$ddd]) ? $ddd : null;
    }

    public static function uf(?string $telefone): ?string
    {
        $ddd = self::de($telefone);

        return $ddd ? self::UF[$ddd] : null;
    }

    public static function regiao(?string $telefone): ?string
    {
        $uf = self::uf($telefone);

        return $uf ? (self::REGIAO[$uf] ?? null) : null;
    }
}
