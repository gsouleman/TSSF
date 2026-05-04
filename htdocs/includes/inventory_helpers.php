<?php
declare(strict_types=1);

function hms_inventory_movement_table_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_inventory_stock_movement');
}

function hms_inventory_category_table_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_inventory_category');
}

/**
 * @return list<array{id:int,name:string}>
 */
function hms_inventory_categories_for_facility(mysqli $connection, int $facilityId): array
{
    if (!hms_inventory_category_table_ok($connection) || $facilityId < 1) {
        return [];
    }
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT id, name FROM tbl_inventory_category WHERE facility_id = ' . (int) $facilityId . ' ORDER BY name ASC'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = ['id' => (int) $r['id'], 'name' => (string) $r['name']];
    }

    return $rows;
}

/**
 * @return list<string>
 */
function hms_inventory_name_suggestions(mysqli $connection, int $facilityId): array
{
    if ($facilityId < 1) {
        return [];
    }
    $seen = [];
    $q = mysqli_query(
        $connection,
        'SELECT DISTINCT TRIM(name) AS n FROM tbl_inventory_item WHERE facility_id = ' . (int) $facilityId . ' AND TRIM(name) <> \'\' ORDER BY n ASC LIMIT 500'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $n = trim((string) ($r['n'] ?? ''));
        if ($n !== '') {
            $seen[$n] = true;
        }
    }
    if (hms_db_table_exists($connection, 'tbl_inventory_name_catalog')) {
        $q2 = mysqli_query(
            $connection,
            'SELECT name FROM tbl_inventory_name_catalog WHERE facility_id = ' . (int) $facilityId . ' ORDER BY name ASC LIMIT 500'
        );
        while ($q2 && $r2 = mysqli_fetch_assoc($q2)) {
            $n = trim((string) ($r2['name'] ?? ''));
            if ($n !== '') {
                $seen[$n] = true;
            }
        }
    }
    $out = array_keys($seen);
    sort($out);

    return $out;
}

function hms_inventory_register_category(mysqli $connection, int $facilityId, string $name): ?int
{
    if (!hms_inventory_category_table_ok($connection) || $facilityId < 1) {
        return null;
    }
    $name = trim($name);
    if ($name === '') {
        return null;
    }
    $st = mysqli_prepare(
        $connection,
        'INSERT IGNORE INTO tbl_inventory_category (facility_id, name) VALUES (?,?)'
    );
    if (!$st) {
        return null;
    }
    mysqli_stmt_bind_param($st, 'is', $facilityId, $name);
    mysqli_stmt_execute($st);
    mysqli_stmt_close($st);
    $st2 = mysqli_prepare(
        $connection,
        'SELECT id FROM tbl_inventory_category WHERE facility_id = ? AND name = ? LIMIT 1'
    );
    if (!$st2) {
        return null;
    }
    mysqli_stmt_bind_param($st2, 'is', $facilityId, $name);
    mysqli_stmt_execute($st2);
    $row = hms_stmt_fetch_assoc($st2);
    mysqli_stmt_close($st2);

    return $row ? (int) ($row['id'] ?? 0) : null;
}

function hms_inventory_ensure_name_catalog(mysqli $connection, int $facilityId, string $name): void
{
    if (!hms_db_table_exists($connection, 'tbl_inventory_name_catalog') || $facilityId < 1) {
        return;
    }
    $name = trim($name);
    if ($name === '') {
        return;
    }
    mysqli_query(
        $connection,
        'INSERT IGNORE INTO tbl_inventory_name_catalog (facility_id, name) VALUES (' . (int) $facilityId . ", '" . mysqli_real_escape_string($connection, $name) . "')"
    );
}

function hms_inventory_format_sku(int $facilityId, int $itemId): string
{
    return 'HMS-' . str_pad((string) $facilityId, 4, '0', STR_PAD_LEFT) . '-I' . str_pad((string) $itemId, 8, '0', STR_PAD_LEFT);
}

/**
 * Apply quantity change and optionally log movement. Returns new quantity or null on failure.
 */
function hms_inventory_apply_qty_delta(
    mysqli $connection,
    int $facilityId,
    int $itemId,
    int $delta,
    string $movementType,
    ?string $note,
    ?string $refTable,
    ?int $refId,
    ?int $createdByEmployeeId
): ?int {
    if ($itemId < 1 || $facilityId < 1 || $delta === 0) {
        return null;
    }
    mysqli_query(
        $connection,
        'UPDATE tbl_inventory_item SET quantity = GREATEST(0, quantity + ' . (int) $delta . ') WHERE id = ' . (int) $itemId . ' AND facility_id = ' . (int) $facilityId . ' LIMIT 1'
    );
    if (mysqli_affected_rows($connection) < 1) {
        return null;
    }
    $q = mysqli_query(
        $connection,
        'SELECT quantity FROM tbl_inventory_item WHERE id = ' . (int) $itemId . ' LIMIT 1'
    );
    $row = $q ? mysqli_fetch_assoc($q) : null;
    $newQty = $row ? (int) ($row['quantity'] ?? 0) : 0;

    if (hms_inventory_movement_table_ok($connection)) {
        $mt = preg_replace('/[^a-z_]/i', '', $movementType);
        if ($mt === '') {
            $mt = 'adjustment';
        }
        $noteEsc = $note === null ? 'NULL' : ("'" . mysqli_real_escape_string($connection, $note) . "'");
        $refT = $refTable === null ? 'NULL' : ("'" . mysqli_real_escape_string($connection, $refTable) . "'");
        $refI = $refId === null ? 'NULL' : (string) (int) $refId;
        $cb = $createdByEmployeeId === null || $createdByEmployeeId < 1 ? 'NULL' : (string) (int) $createdByEmployeeId;
        mysqli_query(
            $connection,
            'INSERT INTO tbl_inventory_stock_movement (facility_id, inventory_item_id, qty_delta, quantity_after, movement_type, note, ref_table, ref_id, created_by) VALUES ('
            . (int) $facilityId . ',' . (int) $itemId . ',' . (int) $delta . ',' . (int) $newQty . ", '" . mysqli_real_escape_string($connection, $mt) . "', "
            . $noteEsc . ',' . $refT . ',' . $refI . ',' . $cb . ')'
        );
    }

    return $newQty;
}

/**
 * After prescription dispense: stock already decremented in caller; log movement only.
 */
function hms_inventory_log_dispense_movement(
    mysqli $connection,
    int $facilityId,
    int $itemId,
    int $qtyDispensed,
    int $prescriptionId,
    int $lineId,
    int $createdByEmployeeId
): void {
    if (!hms_inventory_movement_table_ok($connection) || $itemId < 1 || $qtyDispensed < 1) {
        return;
    }
    $q = mysqli_query(
        $connection,
        'SELECT quantity FROM tbl_inventory_item WHERE id = ' . (int) $itemId . ' AND facility_id = ' . (int) $facilityId . ' LIMIT 1'
    );
    $row = $q ? mysqli_fetch_assoc($q) : null;
    if (!$row) {
        return;
    }
    $bal = (int) ($row['quantity'] ?? 0);
    $note = 'Prescription dispense (Rx #' . $prescriptionId . ')';
    $delta = -$qtyDispensed;
    $cbSql = $createdByEmployeeId > 0 ? (string) (int) $createdByEmployeeId : 'NULL';
    mysqli_query(
        $connection,
        'INSERT INTO tbl_inventory_stock_movement (facility_id, inventory_item_id, qty_delta, quantity_after, movement_type, note, ref_table, ref_id, created_by) VALUES ('
        . (int) $facilityId . ',' . (int) $itemId . ',' . (int) $delta . ',' . (int) $bal . ", 'dispense', '"
        . mysqli_real_escape_string($connection, $note) . "', 'prescription_line', " . (int) $lineId . ',' . $cbSql . ')'
    );
}

/**
 * @return list<array<string,mixed>>
 */
function hms_inventory_low_stock_rows(mysqli $connection, int $facilityId): array
{
    if ($facilityId < 1) {
        return [];
    }
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT id, sku, name, category, quantity, reorder_level FROM tbl_inventory_item WHERE facility_id = ' . (int) $facilityId
        . ' AND reorder_level > 0 AND quantity <= reorder_level ORDER BY quantity ASC, name ASC LIMIT 200'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

/**
 * @return list<array<string,mixed>>
 */
function hms_inventory_recent_movements(mysqli $connection, int $facilityId, int $limit = 100): array
{
    if (!hms_inventory_movement_table_ok($connection) || $facilityId < 1) {
        return [];
    }
    $limit = max(1, min(500, $limit));
    $rows = [];
    $sql = 'SELECT m.id, m.created_at, m.qty_delta, m.quantity_after, m.movement_type, m.note, m.ref_table, m.ref_id,
            i.sku, i.name AS item_name
            FROM tbl_inventory_stock_movement m
            INNER JOIN tbl_inventory_item i ON i.id = m.inventory_item_id AND i.facility_id = m.facility_id
            WHERE m.facility_id = ' . (int) $facilityId . '
            ORDER BY m.id DESC LIMIT ' . $limit;
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }

    return $rows;
}

function hms_inventory_category_label_for_item(mysqli $connection, array $itemRow): string
{
    $cid = (int) ($itemRow['category_id'] ?? 0);
    if ($cid > 0 && hms_inventory_category_table_ok($connection)) {
        $q = mysqli_query(
            $connection,
            'SELECT name FROM tbl_inventory_category WHERE id = ' . $cid . ' LIMIT 1'
        );
        if ($q && $r = mysqli_fetch_assoc($q)) {
            return (string) ($r['name'] ?? '');
        }
    }

    return trim((string) ($itemRow['category'] ?? ''));
}

/**
 * Seed standard medical-supply categories and name hints for this facility (INSERT IGNORE — safe to re-run).
 */
function hms_inventory_seed_medical_supply_catalog(mysqli $connection, int $facilityId): void
{
    if ($facilityId < 1 || !hms_inventory_category_table_ok($connection)) {
        return;
    }
    require_once __DIR__ . '/medical_supply_catalog.php';
    foreach (hms_medical_supply_catalog() as $catName => $products) {
        hms_inventory_register_category($connection, $facilityId, $catName);
        if (!hms_db_table_exists($connection, 'tbl_inventory_name_catalog')) {
            continue;
        }
        foreach ($products as $pname) {
            $pname = trim((string) $pname);
            if ($pname === '') {
                continue;
            }
            mysqli_query(
                $connection,
                'INSERT IGNORE INTO tbl_inventory_name_catalog (facility_id, name) VALUES ('
                . (int) $facilityId . ", '" . mysqli_real_escape_string($connection, $pname) . "')"
            );
        }
    }
}

/**
 * Product slice context for inventory UI (links, optional pharmacy copy).
 *
 * @return array{healthcare_on:bool, procurement_on:bool, catalog_on:bool}
 */
function hms_inventory_product_context(mysqli $connection): array
{
    if (!function_exists('hms_nav_sidebar_modules')) {
        return ['healthcare_on' => true, 'procurement_on' => false, 'catalog_on' => true];
    }
    $sn = hms_nav_sidebar_modules($connection);

    return [
        'healthcare_on' => !empty($sn['healthcare']),
        'procurement_on' => !empty($sn['manage_procurement']),
        'catalog_on' => !empty($sn['manage_catalog']),
    ];
}
