<?php
declare(strict_types=1);

/**
 * Purchase orders (tbl_purchase_order) — workflow helpers after migration 036.
 */
function hms_po_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_purchase_order')
        && hms_db_table_exists($connection, 'tbl_purchase_order_line');
}

function hms_po_workflow_ready(mysqli $connection): bool
{
    return hms_po_tables_ok($connection)
        && hms_db_column_exists($connection, 'tbl_purchase_order', 'approved_at');
}

/**
 * @return array<string, mixed>|null
 */
function hms_po_fetch_header(mysqli $connection, int $facilityId, int $poId): ?array
{
    if ($facilityId < 1 || $poId < 1) {
        return null;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_purchase_order WHERE id = ' . $poId . ' AND facility_id = ' . $facilityId . ' LIMIT 1'
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    return null;
}

/**
 * @return list<array<string, mixed>>
 */
function hms_po_fetch_lines(mysqli $connection, int $facilityId, int $poId): array
{
    $out = [];
    if ($poId < 1) {
        return $out;
    }
    $sql = 'SELECT l.id AS line_id, l.quantity, l.unit_price, l.inventory_item_id, i.sku, i.name
            FROM tbl_purchase_order_line l
            LEFT JOIN tbl_inventory_item i ON i.id = l.inventory_item_id AND i.facility_id = ' . (int) $facilityId . '
            WHERE l.purchase_order_id = ' . (int) $poId . ' ORDER BY l.id ASC';
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}

function hms_po_status_label(string $status): string
{
    switch ($status) {
        case 'draft':
            return 'Draft';
        case 'approved':
            return 'Approved';
        case 'issued':
            return 'Issued';
        case 'sent':
            return 'Issued';
        case 'received':
            return 'Received';
        case 'cancelled':
            return 'Cancelled';
        default:
            return $status;
    }
}

/**
 * @return array<string, mixed>|null
 */
function hms_po_employee_row(mysqli $connection, int $employeeId): ?array
{
    if ($employeeId < 1 || !hms_db_table_exists($connection, 'tbl_employee')) {
        return null;
    }
    $q = mysqli_query($connection, 'SELECT id, first_name, last_name FROM tbl_employee WHERE id = ' . $employeeId . ' LIMIT 1');
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    return null;
}

function hms_po_employee_label(?array $empRow): string
{
    if ($empRow === null || $empRow === []) {
        return '';
    }
    $fn = trim((string) ($empRow['first_name'] ?? ''));
    $ln = trim((string) ($empRow['last_name'] ?? ''));

    return trim($fn . ' ' . $ln);
}
