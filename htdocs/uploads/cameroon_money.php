<?php
declare(strict_types=1);

/**
 * Central African CFA franc (BEAC) — official currency in Cameroon.
 */
const HMS_CURRENCY_CODE = 'XAF';

function hms_currency_label(): string
{
    return 'FCFA (XAF)';
}

/**
 * Format an amount as integer XAF (no centimes in common use).
 */
function hms_format_xaf(float $amount, bool $withSymbol = true): string
{
    $neg = $amount < 0;
    $n = (int) round(abs($amount));
    $formatted = number_format($n, 0, ',', "\u{00a0}");
    if ($neg) {
        $formatted = '-' . $formatted;
    }
    return $withSymbol ? $formatted . "\u{00a0}FCFA" : $formatted;
}
