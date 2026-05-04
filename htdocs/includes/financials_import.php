<?php
declare(strict_types=1);

/**
 * Parse multi-line CSV into balanced journal batches (OHADA account codes).
 * Columns: entry_date, reference, narration, account_code, account_label, debit, credit
 * Separator: comma or semicolon (auto-detected per line).
 * Optional first row: column titles (date + reference) are skipped when detected.
 *
 * @return array{ok:bool, batches:list<array{date:string,reference:string,narration:string,lines:list<array{code:string,label:string,debit:float,credit:float}>}>, errors:list<string>}
 */
function hms_fin_parse_journal_csv(string $raw): array
{
    $errors = [];
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_values(array_filter(array_map('trim', explode("\n", $raw)), static function ($l) {
        return $l !== '';
    }));
    if ($lines === []) {
        return ['ok' => false, 'batches' => [], 'errors' => ['Empty file.']];
    }

    // Optional header row (Excel export often includes column titles).
    if ($lines !== []) {
        $hLine = $lines[0];
        $hDelim = (substr_count($hLine, ';') >= substr_count($hLine, ',')) ? ';' : ',';
        $hParts = str_getcsv($hLine, $hDelim);
        if (count($hParts) >= 7 && !preg_match('/^\d{4}-\d{2}-\d{2}$/', trim((string) $hParts[0]))) {
            $joined = strtolower(implode(' ', array_map('trim', array_slice($hParts, 0, 7))));
            if (strpos($joined, 'date') !== false
                && (strpos($joined, 'réf') !== false || strpos($joined, 'ref') !== false || strpos($joined, 'reference') !== false)) {
                array_shift($lines);
            }
        }
    }

    if ($lines === []) {
        return ['ok' => false, 'batches' => [], 'errors' => ['Empty file after header row.']];
    }

    $rows = [];
    $ln = 0;
    foreach ($lines as $line) {
        $ln++;
        $delim = (substr_count($line, ';') >= substr_count($line, ',')) ? ';' : ',';
        $parts = str_getcsv($line, $delim);
        if (count($parts) < 7) {
            $errors[] = 'Line ' . $ln . ': 7 columns expected (date, ref., entry narration, account, account label, debit, credit).';

            continue;
        }
        $d = trim((string) $parts[0]);
        $ref = trim((string) $parts[1]);
        $nar = trim((string) $parts[2]);
        $code = trim((string) $parts[3]);
        $lab = trim((string) $parts[4]);
        $drS = trim((string) $parts[5]);
        $crS = trim((string) $parts[6]);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            $errors[] = 'Line ' . $ln . ': invalid date (' . $d . ').';

            continue;
        }
        if ($ref === '' || $code === '') {
            $errors[] = 'Line ' . $ln . ': reference and account code are required.';

            continue;
        }
        $dr = (float) preg_replace('/[^\d.-]/', '', str_replace(',', '.', $drS));
        $cr = (float) preg_replace('/[^\d.-]/', '', str_replace(',', '.', $crS));
        if ($dr < 0) {
            $dr = 0.0;
        }
        if ($cr < 0) {
            $cr = 0.0;
        }
        $dr = round($dr, 2);
        $cr = round($cr, 2);
        if ($dr > 0 && $cr > 0) {
            $errors[] = 'Line ' . $ln . ': enter debit OR credit, not both.';

            continue;
        }
        if ($dr <= 0 && $cr <= 0) {
            $errors[] = 'Line ' . $ln . ': zero amount.';

            continue;
        }
        $rows[] = [
            'date' => $d,
            'ref' => substr($ref, 0, 64),
            'narr' => substr($nar, 0, 512),
            'code' => substr($code, 0, 32),
            'label' => substr($lab, 0, 160),
            'debit' => $dr,
            'credit' => $cr,
        ];
    }

    if ($errors !== []) {
        return ['ok' => false, 'batches' => [], 'errors' => $errors];
    }

    $groups = [];
    foreach ($rows as $r) {
        $k = $r['date'] . '|' . $r['ref'];
        if (!isset($groups[$k])) {
            $groups[$k] = [
                'date' => $r['date'],
                'reference' => $r['ref'],
                'narration' => $r['narr'],
                'lines' => [],
            ];
        }
        $groups[$k]['lines'][] = [
            'code' => $r['code'],
            'label' => $r['label'],
            'debit' => $r['debit'],
            'credit' => $r['credit'],
        ];
        if ($r['narr'] !== '') {
            $groups[$k]['narration'] = $r['narr'];
        }
    }

    $batches = [];
    foreach ($groups as $k => $g) {
        $sdr = 0.0;
        $scr = 0.0;
        foreach ($g['lines'] as $ln) {
            $sdr += (float) ($ln['debit'] ?? 0);
            $scr += (float) ($ln['credit'] ?? 0);
        }
        $sdr = round($sdr, 2);
        $scr = round($scr, 2);
        if (abs($sdr - $scr) > 0.02) {
            $errors[] = 'Entry ' . $k . ': total debit ' . $sdr . ' ≠ credit ' . $scr . '.';

            continue;
        }
        if ($sdr <= 0) {
            $errors[] = 'Entry ' . $k . ': zero amounts.';

            continue;
        }
        $batches[] = $g;
    }

    if ($errors !== []) {
        return ['ok' => false, 'batches' => [], 'errors' => $errors];
    }

    return ['ok' => true, 'batches' => array_values($batches), 'errors' => []];
}
