<?php
declare(strict_types=1);

/**
 * Resolve billable amounts from tbl_service_catalog for consultations, lab, pharmacy.
 */

/** @return list<string> */
function hms_billing_payment_method_options(): array
{
    return [
        'Cash',
        'MTN MoMo',
        'Orange Money',
        'Mobile money (other)',
        'Card',
        'Bank transfer',
        'Insurance',
    ];
}

/**
 * Full catalog row for validation (category-aware ordering).
 *
 * @return array<string, mixed>|null
 */
function hms_billing_catalog_service_row(mysqli $connection, int $facilityId, int $catalogId): ?array
{
    if ($catalogId < 1 || !hms_db_table_exists($connection, 'tbl_service_catalog')) {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT id, facility_id, category, subcategory, name, cpt_code, price, description
         FROM tbl_service_catalog
         WHERE id = ? AND (facility_id = ? OR facility_id = 0) AND status = 1 LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'ii', $catalogId, $facilityId);
    mysqli_stmt_execute($st);
    $r = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);

    return $r ?: null;
}

/**
 * Build prescribed test lines from POSTed catalog IDs; only includes rows matching $categoryLower (e.g. laboratory, radiology).
 *
 * @param list<mixed> $rawIds
 * @return list<array<string, mixed>>
 */
function hms_billing_collect_prescribed_catalog(
    mysqli $connection,
    int $facilityId,
    array $rawIds,
    string $categoryLower
): array {
    $want = strtolower(trim($categoryLower));
    $out = [];
    foreach ($rawIds as $raw) {
        $id = (int) $raw;
        if ($id < 1) {
            continue;
        }
        $row = hms_billing_catalog_service_row($connection, $facilityId, $id);
        if ($row === null) {
            continue;
        }
        $cat = strtolower(trim((string) ($row['category'] ?? '')));
        if ($cat !== $want) {
            continue;
        }
        $out[] = [
            'catalog_id' => $id,
            'name' => trim((string) ($row['name'] ?? '')),
            'cpt_code' => trim((string) ($row['cpt_code'] ?? '')),
            'subcategory' => trim((string) ($row['subcategory'] ?? '')),
            'price_xaf' => max(0, (int) round((float) ($row['price'] ?? 0))),
            'payment_note' => 'Pay at cashier and present the receipt to the ' . ($want === 'radiology' ? 'Radiology' : 'Laboratory') . ' unit before the exam (emergency / hospital-approved exceptions apply).',
        ];
    }

    return $out;
}

function hms_billing_normalize_payment_method(?string $raw): string
{
    $s = trim((string) $raw);
    foreach (hms_billing_payment_method_options() as $opt) {
        if (strcasecmp($s, $opt) === 0) {
            return $opt;
        }
    }

    return 'Cash';
}

/**
 * Active catalog rows for a category (facility + global templates).
 *
 * @return list<array<string, mixed>>
 */
function hms_billing_catalog_rows_by_category(mysqli $connection, int $facilityId, string $category): array
{
    if (!hms_db_table_exists($connection, 'tbl_service_catalog')) {
        return [];
    }
    $cat = mysqli_real_escape_string($connection, strtolower(trim($category)));
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT id, category, subcategory, name, cpt_code, price, description
         FROM tbl_service_catalog
         WHERE (facility_id = ' . (int) $facilityId . ' OR facility_id = 0) AND status = 1 AND LOWER(category) = \'' . $cat . '\'
         ORDER BY facility_id DESC, sort_order, name LIMIT 500'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

/**
 * @return array{amount:int,label:string,cpt:string}|null
 */
function hms_billing_catalog_row_by_id(mysqli $connection, int $facilityId, int $catalogId): ?array
{
    if ($catalogId < 1) {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'SELECT id, name, cpt_code, price FROM tbl_service_catalog
         WHERE id = ? AND (facility_id = ? OR facility_id = 0) AND status = 1 LIMIT 1'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'ii', $catalogId, $facilityId);
    mysqli_stmt_execute($st);
    $r = hms_stmt_fetch_assoc($st);
    mysqli_stmt_close($st);
    if (!$r) {
        return null;
    }
    $amt = (int) round((float) ($r['price'] ?? 0));

    return [
        'amount' => max(0, $amt),
        'label' => trim((string) ($r['name'] ?? 'Service')),
        'cpt' => trim((string) ($r['cpt_code'] ?? 'SERVICE')),
    ];
}

/**
 * Default consultation fee when catalog has no match (FCFA).
 */
function hms_billing_default_consult_fee_xaf(string $consultationType): int
{
    return $consultationType === 'specialist' ? 10000 : 5000;
}

/**
 * Pick best catalog row for consultation type + optional department hint (e.g. Cardiology).
 *
 * @return array{amount:int,label:string,cpt:string}|null
 */
function hms_billing_resolve_consultation_catalog(
    mysqli $connection,
    int $facilityId,
    string $consultationType,
    int $preferredCatalogId,
    string $departmentHint
): ?array {
    if ($preferredCatalogId > 0) {
        $one = hms_billing_catalog_row_by_id($connection, $facilityId, $preferredCatalogId);
        if ($one !== null) {
            return $one;
        }
    }
    $rows = hms_billing_catalog_rows_by_category($connection, $facilityId, 'consultation');
    if ($rows === []) {
        return null;
    }
    $dept = strtolower(trim($departmentHint));
    if ($dept !== '') {
        foreach ($rows as $r) {
            $sub = strtolower(trim((string) ($r['subcategory'] ?? '')));
            $nm = strtolower(trim((string) ($r['name'] ?? '')));
            if ($sub !== '' && (strpos($sub, $dept) !== false || strpos($dept, $sub) !== false)) {
                return [
                    'amount' => max(0, (int) round((float) ($r['price'] ?? 0))),
                    'label' => trim((string) ($r['name'] ?? 'Consultation')),
                    'cpt' => trim((string) ($r['cpt_code'] ?? 'CONSULT')),
                ];
            }
            if ($nm !== '' && strpos($nm, $dept) !== false) {
                return [
                    'amount' => max(0, (int) round((float) ($r['price'] ?? 0))),
                    'label' => trim((string) ($r['name'] ?? 'Consultation')),
                    'cpt' => trim((string) ($r['cpt_code'] ?? 'CONSULT')),
                ];
            }
        }
    }
    $want = $consultationType === 'specialist' ? 'specialist' : 'general';
    foreach ($rows as $r) {
        $code = strtoupper(trim((string) ($r['cpt_code'] ?? '')));
        if ($want === 'specialist' && (strpos($code, 'SPEC') !== false || strpos($code, 'SPCL') !== false)) {
            return [
                'amount' => max(0, (int) round((float) ($r['price'] ?? 0))),
                'label' => trim((string) ($r['name'] ?? 'Specialist consultation')),
                'cpt' => $code !== '' ? $code : 'CONSULT_SPEC',
            ];
        }
        if ($want === 'general' && (strpos($code, 'GEN') !== false || $code === 'CONSULT' || $code === 'CONSULT_GENERAL')) {
            return [
                'amount' => max(0, (int) round((float) ($r['price'] ?? 0))),
                'label' => trim((string) ($r['name'] ?? 'General consultation')),
                'cpt' => $code !== '' ? $code : 'CONSULT_GEN',
            ];
        }
    }
    $first = $rows[0];

    return [
        'amount' => max(0, (int) round((float) ($first['price'] ?? 0))),
        'label' => trim((string) ($first['name'] ?? 'Consultation')),
        'cpt' => trim((string) ($first['cpt_code'] ?? 'CONSULT')),
    ];
}
