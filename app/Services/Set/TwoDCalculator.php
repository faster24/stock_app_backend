<?php

namespace App\Services\Set;

/**
 * Derives the Myanmar 2D number from SET index figures. Authoritative source of
 * the formula (the Node scraper carries a copy only for stabilization).
 *
 *   Digit 1 = last decimal digit of the SET index (e.g. 1644.39 -> 9)
 *   Digit 2 = last integer digit of the SET total value (e.g. 89284959005 -> 5)
 *   Result  = "95"
 */
class TwoDCalculator
{
    public function calculate(string|int|float $indexValue, string|int|float $totalValue): string
    {
        return $this->indexDigit($indexValue).$this->valueDigit($totalValue);
    }

    /** Last decimal digit of the index value; 0 when there is no fractional part. */
    public function indexDigit(string|int|float $indexValue): string
    {
        $normalized = $this->normalize($indexValue);

        $dot = strpos($normalized, '.');

        if ($dot === false) {
            return '0';
        }

        $fraction = preg_replace('/\D/', '', substr($normalized, $dot + 1));

        return $fraction === '' ? '0' : substr($fraction, -1);
    }

    /** Last digit of the integer part of the total value. */
    public function valueDigit(string|int|float $totalValue): string
    {
        $normalized = $this->normalize($totalValue);

        $integerPart = preg_replace('/\D/', '', explode('.', $normalized)[0]);

        return $integerPart === '' ? '0' : substr($integerPart, -1);
    }

    /** Strip thousands separators and surrounding whitespace to a bare numeric string. */
    private function normalize(string|int|float $value): string
    {
        if (is_float($value)) {
            // Avoid scientific notation / locale formatting for large/precise values.
            $value = rtrim(rtrim(sprintf('%.10F', $value), '0'), '.');
        }

        return str_replace(',', '', trim((string) $value));
    }
}
