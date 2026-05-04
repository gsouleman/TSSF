<?php
declare(strict_types=1);

/** Gap 10 — minimal i18n hook (extend with more keys / locales). */
function hms_lang(): string
{
    $l = $_SESSION['hms_lang'] ?? 'en';

    return preg_match('/^[a-z]{2}$/', (string) $l) ? (string) $l : 'en';
}

function hms_set_lang(string $code): void
{
    if (preg_match('/^[a-z]{2}$/', $code)) {
        $_SESSION['hms_lang'] = $code;
    }
}

/** @param array<string, string> $params */
function __hms(string $key, array $params = []): string
{
    $lang = hms_lang();
    $catalog = [
        'en' => [
            'app.title' => 'TSSF Solidarity of Hearts Hospital SOA — HMS (XAF)',
            'nav.platform' => 'Enterprise',
            'nav.clinical' => 'Clinical',
            'nav.operations' => 'Operations',
            'nav.finance' => 'Finance',
            'nav.analytics' => 'Analytics',
        ],
        'fr' => [
            'app.title' => 'Système de gestion hospitalière — Cameroun (XAF)',
            'nav.platform' => 'Plateforme',
            'nav.clinical' => 'Clinique',
            'nav.operations' => 'Opérations',
            'nav.finance' => 'Finance',
            'nav.analytics' => 'Analytique',
        ],
    ];
    $text = $catalog[$lang][$key] ?? $catalog['en'][$key] ?? $key;
    foreach ($params as $k => $v) {
        $text = str_replace(':' . $k, (string) $v, $text);
    }

    return $text;
}
