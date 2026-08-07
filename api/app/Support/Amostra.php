<?php

namespace App\Support;

/**
 * O quanto uma taxa medida em poucos casos pode ser confiada.
 *
 * Existe porque esta conta já errou por isso: com 3 dias de dados, "Sudeste qualifica
 * 25% e Nordeste 11%" parecia uma decisão de verba pronta, e o teste mostrou que os dois
 * intervalos se sobrepõem tanto que nem o SINAL da diferença dá para afirmar. Toda taxa
 * que a tela mostrar vem acompanhada do intervalo — sem ele, ruído tem a mesma aparência
 * de sinal, e a decisão sai errada com a mesma confiança.
 */
class Amostra
{
    /** z de 95% bicaudal. */
    public const Z = 1.959964;

    /**
     * Intervalo de Wilson para uma proporção.
     *
     * Wilson e não o intervalo "normal" da escola: com poucos casos — que é o caso aqui —
     * o normal produz absurdos (limite negativo, ou intervalo de largura zero quando a
     * taxa é 0% ou 100%). Wilson se comporta nas pontas, que é justamente onde estas
     * tabelas vivem.
     *
     * @return array{0: float, 1: float} [mínimo, máximo] em 0..1
     */
    public static function wilson(int $sucessos, int $total, ?float $z = null): array
    {
        if ($total <= 0) {
            return [0.0, 1.0];
        }

        $z ??= self::Z;
        $p = $sucessos / $total;
        $z2 = $z ** 2;
        $denominador = 1 + $z2 / $total;
        $centro = ($p + $z2 / (2 * $total)) / $denominador;
        $meio = ($z / $denominador) * sqrt($p * (1 - $p) / $total + $z2 / (4 * $total ** 2));

        return [max(0.0, $centro - $meio), min(1.0, $centro + $meio)];
    }

    /**
     * O z que uma tabela de `$comparacoes` pares exige para manter 95% de confiança no
     * conjunto — correção de Bonferroni.
     *
     * SEM ISTO A TELA MENTE. Numa tabela de 27 estados existem 351 pares; procurar o par
     * mais distante entre eles e declarar "essa diferença é real" acha diferença em dados
     * puramente aleatórios quase sempre. É o mesmo erro que já apareceu nesta conta com o
     * "35-44 feminino a R$ 1,79": o mínimo de 8 células ruidosas, que sumiu quando a
     * correção foi aplicada.
     */
    public static function zCorrigido(int $comparacoes): float
    {
        return self::quantilNormal(1 - 0.05 / (2 * max(1, $comparacoes)));
    }

    /**
     * Quantil da normal padrão (algoritmo de Acklam). Existe só para sustentar o
     * {@see zCorrigido()} — PHP não traz inversa da normal.
     */
    private static function quantilNormal(float $p): float
    {
        $a = [-3.969683028665376e+01, 2.209460984245205e+02, -2.759285104469687e+02, 1.383577518672690e+02, -3.066479806614716e+01, 2.506628277459239e+00];
        $b = [-5.447609879822406e+01, 1.615858368580409e+02, -1.556989798598866e+02, 6.680131188771972e+01, -1.328068155288572e+01];
        $c = [-7.784894002430293e-03, -3.223964580411365e-01, -2.400758277161838e+00, -2.549732539343734e+00, 4.374664141464968e+00, 2.938163982698783e+00];
        $d = [7.784695709041462e-03, 3.224671290700398e-01, 2.445134137142996e+00, 3.754408661907416e+00];

        $p = min(max($p, 1e-15), 1 - 1e-15);
        $baixo = 0.02425;

        if ($p < $baixo) {
            $q = sqrt(-2 * log($p));

            return ((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
                / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
        }

        if ($p <= 1 - $baixo) {
            $q = $p - 0.5;
            $r = $q * $q;

            return ((((($a[0] * $r + $a[1]) * $r + $a[2]) * $r + $a[3]) * $r + $a[4]) * $r + $a[5]) * $q
                / ((((($b[0] * $r + $b[1]) * $r + $b[2]) * $r + $b[3]) * $r + $b[4]) * $r + 1);
        }

        $q = sqrt(-2 * log(1 - $p));

        return -((((($c[0] * $q + $c[1]) * $q + $c[2]) * $q + $c[3]) * $q + $c[4]) * $q + $c[5])
            / (((($d[0] * $q + $d[1]) * $q + $d[2]) * $q + $d[3]) * $q + 1);
    }

    /**
     * Duas linhas são DISTINGUÍVEIS quando os intervalos não se tocam.
     *
     * É um critério conservador de propósito: intervalos que se sobrepõem podem ainda
     * assim ser diferentes num teste formal, mas o erro caro aqui é o contrário — dar por
     * boa uma diferença que não existe e mover verba por causa dela.
     *
     * @param  array{0: float, 1: float}  $a
     * @param  array{0: float, 1: float}  $b
     */
    public static function distinguiveis(array $a, array $b): bool
    {
        return $a[1] < $b[0] || $b[1] < $a[0];
    }

    /**
     * Quantos casos por linha seriam necessários para enxergar uma diferença desse
     * tamanho — a resposta para "então quando eu vou poder decidir isso?".
     *
     * Fórmula de duas proporções, 95% de confiança e 80% de poder.
     */
    public static function amostraNecessaria(float $p1, float $p2): ?int
    {
        $delta = abs($p1 - $p2);
        if ($delta < 0.001) {
            return null; // taxas iguais: nenhuma amostra revela diferença que não existe
        }

        $pMedio = ($p1 + $p2) / 2;
        $n = (self::Z + 0.8416) ** 2 * 2 * $pMedio * (1 - $pMedio) / $delta ** 2;

        return (int) ceil($n);
    }
}
