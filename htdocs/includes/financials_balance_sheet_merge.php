<?php
declare(strict_types=1);

/**
 * Balance sheet row expansion (OHADA classes 1–5 skeleton + GL merge).
 * Loaded from financials-balance-sheet.php so hosting deploys stay in sync with the page.
 */

if (!function_exists('hms_fin_balance_sheet_skeleton_pairs')) {
    /**
     * SYSCOHADA-style skeleton (classes 1–5) so dormant accounts appear at 0.00.
     *
     * @return list<array{code:string,label:string}>
     */
    function hms_fin_balance_sheet_skeleton_pairs(): array
    {
        return [
            ['code' => '101000', 'label' => 'Share capital'],
            ['code' => '111000', 'label' => 'Share premium'],
            ['code' => '121000', 'label' => 'Legal reserves'],
            ['code' => '129000', 'label' => 'Retained earnings / carried forward'],
            ['code' => '131000', 'label' => 'Investment grants (if any)'],
            ['code' => '139000', 'label' => 'Subsidies recognized to P&L'],
            ['code' => '211000', 'label' => 'Land'],
            ['code' => '213000', 'label' => 'Buildings'],
            ['code' => '215000', 'label' => 'Medical & technical equipment'],
            ['code' => '218000', 'label' => 'Other tangible fixed assets'],
            ['code' => '244000', 'label' => 'Transport equipment'],
            ['code' => '281200', 'label' => 'Accumulated depreciation — buildings'],
            ['code' => '281300', 'label' => 'Accumulated depreciation — equipment'],
            ['code' => '311000', 'label' => 'Pharmaceutical & medical supplies inventory'],
            ['code' => '371000', 'label' => 'Merchandise inventory'],
            ['code' => '401000', 'label' => 'Suppliers — trade payables'],
            ['code' => '408000', 'label' => 'Suppliers — invoices not yet received'],
            ['code' => '411000', 'label' => 'Trade receivables — patients'],
            ['code' => '421000', 'label' => 'Personnel — wages payable'],
            ['code' => '431000', 'label' => 'Social security & payroll taxes payable'],
            ['code' => '441000', 'label' => 'State — taxes and duties payable'],
            ['code' => '444000', 'label' => 'State — VAT (net position)'],
            ['code' => '462000', 'label' => 'Receivables from staff / other debtors'],
            ['code' => '467000', 'label' => 'Other creditors / accruals'],
            ['code' => '511000', 'label' => 'Internal transfers / clearing'],
            ['code' => '521000', 'label' => 'Banks — patient collection'],
            ['code' => '522000', 'label' => 'Banks — operating'],
            ['code' => '531000', 'label' => 'Short-term investments'],
            ['code' => '571000', 'label' => 'Cash — patient collection'],
            ['code' => '581000', 'label' => 'Accrued interest / bank in transit'],
        ];
    }
}

if (!function_exists('hms_fin_balance_sheet_rows_merged')) {
    /**
     * Merge GL balances (current vs prior as-of) with the skeleton chart.
     *
     * @param list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float,class:int}> $rowsCurrent
     * @param list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float,class:int}> $rowsPrior
     *
     * @return list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float,class:int,total_debit_prior:float,total_credit_prior:float,balance_prior:float}>
     */
    function hms_fin_balance_sheet_rows_merged(array $rowsCurrent, array $rowsPrior, bool $includeZeroBothPeriods = true): array
    {
        $skeleton = hms_fin_balance_sheet_skeleton_pairs();
        $skLabel = [];
        foreach ($skeleton as $p) {
            $code = (string) ($p['code'] ?? '');
            if ($code !== '') {
                $skLabel[$code] = (string) ($p['label'] ?? '');
            }
        }
        $cur = [];
        foreach ($rowsCurrent as $r) {
            $code = trim((string) ($r['account_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $cl = (int) ($r['class'] ?? hms_fin_ohada_class_from_code($code));
            if ($cl < 1 || $cl > 5) {
                continue;
            }
            $cur[$code] = $r;
        }
        $pri = [];
        foreach ($rowsPrior as $r) {
            $code = trim((string) ($r['account_code'] ?? ''));
            if ($code === '') {
                continue;
            }
            $cl = (int) ($r['class'] ?? hms_fin_ohada_class_from_code($code));
            if ($cl < 1 || $cl > 5) {
                continue;
            }
            $pri[$code] = $r;
        }
        $orderedCodes = [];
        for ($cl = 1; $cl <= 5; $cl++) {
            $seen = [];
            $bucket = [];
            foreach ($skeleton as $p) {
                $code = (string) ($p['code'] ?? '');
                if ($code === '' || hms_fin_ohada_class_from_code($code) !== $cl) {
                    continue;
                }
                $bucket[] = $code;
                $seen[$code] = true;
            }
            $extra = [];
            foreach (array_merge(array_keys($cur), array_keys($pri)) as $code) {
                if (isset($seen[$code])) {
                    continue;
                }
                if (hms_fin_ohada_class_from_code($code) !== $cl) {
                    continue;
                }
                $extra[$code] = true;
            }
            $extraCodes = array_keys($extra);
            sort($extraCodes, SORT_STRING);
            foreach (array_merge($bucket, $extraCodes) as $code) {
                $orderedCodes[] = $code;
            }
        }
        $out = [];
        foreach ($orderedCodes as $code) {
            $cRow = $cur[$code] ?? null;
            $pRow = $pri[$code] ?? null;
            $tdr = $cRow !== null ? round((float) ($cRow['total_debit'] ?? 0), 2) : 0.0;
            $tcr = $cRow !== null ? round((float) ($cRow['total_credit'] ?? 0), 2) : 0.0;
            $bal = $cRow !== null ? round((float) ($cRow['balance'] ?? 0), 2) : 0.0;
            $tdrP = $pRow !== null ? round((float) ($pRow['total_debit'] ?? 0), 2) : 0.0;
            $tcrP = $pRow !== null ? round((float) ($pRow['total_credit'] ?? 0), 2) : 0.0;
            $balP = $pRow !== null ? round((float) ($pRow['balance'] ?? 0), 2) : 0.0;
            if (!$includeZeroBothPeriods
                && abs($bal) < 0.00001 && abs($balP) < 0.00001
                && abs($tdr) < 0.00001 && abs($tcr) < 0.00001
                && abs($tdrP) < 0.00001 && abs($tcrP) < 0.00001) {
                continue;
            }
            $labC = $cRow !== null ? trim((string) ($cRow['account_label'] ?? '')) : '';
            $labP = $pRow !== null ? trim((string) ($pRow['account_label'] ?? '')) : '';
            $label = $labC !== '' ? $labC : ($labP !== '' ? $labP : ($skLabel[$code] ?? $code));

            $out[] = [
                'account_code' => $code,
                'account_label' => $label,
                'total_debit' => $tdr,
                'total_credit' => $tcr,
                'balance' => $bal,
                'class' => hms_fin_ohada_class_from_code($code),
                'total_debit_prior' => $tdrP,
                'total_credit_prior' => $tcrP,
                'balance_prior' => $balP,
            ];
        }

        return $out;
    }
}
