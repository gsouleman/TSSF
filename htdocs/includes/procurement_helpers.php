<?php
declare(strict_types=1);

/**
 * Procurement module (046): RFQ, quotations, vendors, GRN, 3-way match, vendor invoices.
 * Access: procurement.* OR legacy inventory.* for HMS integration.
 */

function hms_procurement_tables_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_procurement_vendor')
        && hms_db_table_exists($connection, 'tbl_procurement_rfq');
}

function hms_procurement_can_read(mysqli $connection): bool
{
    return hms_can($connection, 'procurement.read') || hms_can($connection, 'inventory.read');
}

function hms_procurement_can_write(mysqli $connection): bool
{
    return hms_can($connection, 'procurement.write') || hms_can($connection, 'inventory.write');
}

function hms_procurement_require_read(mysqli $connection): void
{
    if (!hms_procurement_can_read($connection)) {
        http_response_code(403);
        exit('Forbidden: procurement or inventory access required.');
    }
}

function hms_procurement_require_write(mysqli $connection): void
{
    if (!hms_procurement_can_write($connection)) {
        http_response_code(403);
        exit('Forbidden: procurement write or inventory write required.');
    }
}

/** Procurement-only deployment (standalone slice) — still uses catalog + stock; hub is procurement home. */
function hms_procurement_standalone_slice(mysqli $connection): bool
{
    if (!function_exists('hms_app_product_slices')) {
        return false;
    }
    $s = hms_app_product_slices($connection);

    return !in_array('full', $s, true)
        && in_array('procurement', $s, true)
        && !in_array('hms', $s, true);
}

/** CSS class for status chips in procurement UI (see hms-procurement.css). */
function hms_procurement_badge_class(string $status): string
{
    $s = strtolower(trim($status));
    switch ($s) {
        case 'draft':
            return 'hms-proc-badge hms-proc-badge--draft';
        case 'issued':
        case 'submitted':
        case 'pending':
            return 'hms-proc-badge hms-proc-badge--progress';
        case 'closed':
        case 'accepted':
        case 'paid':
        case 'matched':
        case 'received':
            return 'hms-proc-badge hms-proc-badge--ok';
        case 'cancelled':
        case 'rejected':
        case 'variance':
            return 'hms-proc-badge hms-proc-badge--danger';
        case 'approved':
            return 'hms-proc-badge hms-proc-badge--info';
        case 'partial':
            return 'hms-proc-badge hms-proc-badge--warn';
        case 'unpaid':
            return 'hms-proc-badge hms-proc-badge--muted';
        default:
            return 'hms-proc-badge hms-proc-badge--muted';
    }
}

function hms_procurement_next_rfq_number(mysqli $connection, int $facilityId): string
{
    $pfx = 'RFQ-' . date('Y') . '-';
    $q = mysqli_query(
        $connection,
        'SELECT rfq_number FROM tbl_procurement_rfq WHERE facility_id = ' . (int) $facilityId
        . " AND rfq_number LIKE '" . mysqli_real_escape_string($connection, $pfx) . "%' ORDER BY id DESC LIMIT 1"
    );
    $n = 1;
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $last = (string) ($r['rfq_number'] ?? '');
        if (preg_match('/-(\d+)$/', $last, $m)) {
            $n = (int) $m[1] + 1;
        }
    }

    return $pfx . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

function hms_procurement_next_grn_number(mysqli $connection, int $facilityId): string
{
    $pfx = 'GRN-' . date('Y') . '-';
    $q = mysqli_query(
        $connection,
        'SELECT grn_number FROM tbl_procurement_goods_receipt WHERE facility_id = ' . (int) $facilityId
        . " AND grn_number LIKE '" . mysqli_real_escape_string($connection, $pfx) . "%' ORDER BY id DESC LIMIT 1"
    );
    $n = 1;
    if ($q && $r = mysqli_fetch_assoc($q)) {
        $last = (string) ($r['grn_number'] ?? '');
        if (preg_match('/-(\d+)$/', $last, $m)) {
            $n = (int) $m[1] + 1;
        }
    }

    return $pfx . str_pad((string) $n, 4, '0', STR_PAD_LEFT);
}

/** @return list<array<string,mixed>> */
function hms_procurement_vendor_rows(mysqli $connection, int $facilityId, bool $activeOnly = true): array
{
    $out = [];
    if (!hms_db_table_exists($connection, 'tbl_procurement_vendor')) {
        return $out;
    }
    $sql = 'SELECT * FROM tbl_procurement_vendor WHERE facility_id = ' . (int) $facilityId;
    if ($activeOnly) {
        $sql .= ' AND is_active = 1';
    }
    $sql .= ' ORDER BY name ASC';
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}

/** @return array<string,mixed>|null */
function hms_procurement_vendor_fetch(mysqli $connection, int $facilityId, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_procurement_vendor WHERE id = ' . (int) $id . ' AND facility_id = ' . (int) $facilityId . ' LIMIT 1'
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    return null;
}

/** @return list<array<string,mixed>> */
function hms_procurement_rfq_list(mysqli $connection, int $facilityId, int $limit = 100): array
{
    $out = [];
    if (!hms_db_table_exists($connection, 'tbl_procurement_rfq')) {
        return $out;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_procurement_rfq WHERE facility_id = ' . (int) $facilityId
        . ' ORDER BY created_at DESC LIMIT ' . (int) $limit
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}

/** @return array<string,mixed>|null */
function hms_procurement_rfq_fetch(mysqli $connection, int $facilityId, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_procurement_rfq WHERE id = ' . (int) $id . ' AND facility_id = ' . (int) $facilityId . ' LIMIT 1'
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    return null;
}

/** @return list<array<string,mixed>> */
function hms_procurement_rfq_lines(mysqli $connection, int $rfqId): array
{
    $out = [];
    if (!hms_db_table_exists($connection, 'tbl_procurement_rfq_line')) {
        return $out;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_procurement_rfq_line WHERE rfq_id = ' . (int) $rfqId . ' ORDER BY line_no ASC, id ASC'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}

/** @return list<array<string,mixed>> */
function hms_procurement_quotations_for_rfq(mysqli $connection, int $facilityId, int $rfqId): array
{
    $out = [];
    if (!hms_db_table_exists($connection, 'tbl_procurement_quotation')) {
        return $out;
    }
    $q = mysqli_query(
        $connection,
        'SELECT q.*, v.name AS vendor_name FROM tbl_procurement_quotation q
         INNER JOIN tbl_procurement_vendor v ON v.id = q.vendor_id AND v.facility_id = ' . (int) $facilityId . '
         WHERE q.facility_id = ' . (int) $facilityId . ' AND q.rfq_id = ' . (int) $rfqId . ' ORDER BY q.created_at DESC'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}

/** @return array<string,mixed>|null */
function hms_procurement_quotation_fetch(mysqli $connection, int $facilityId, int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $q = mysqli_query(
        $connection,
        'SELECT q.*, v.name AS vendor_name FROM tbl_procurement_quotation q
         INNER JOIN tbl_procurement_vendor v ON v.id = q.vendor_id AND v.facility_id = ' . (int) $facilityId . '
         WHERE q.id = ' . (int) $id . ' AND q.facility_id = ' . (int) $facilityId . ' LIMIT 1'
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    return null;
}

/** @return list<array<string,mixed>> */
function hms_procurement_quotation_lines(mysqli $connection, int $quotationId): array
{
    $out = [];
    if (!hms_db_table_exists($connection, 'tbl_procurement_quotation_line')) {
        return $out;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_procurement_quotation_line WHERE quotation_id = ' . (int) $quotationId . ' ORDER BY id ASC'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}

/** Sum received qty per PO line. @return array<int,float> line_id => qty */
function hms_procurement_grn_qty_by_po_line(mysqli $connection, int $facilityId, int $poId): array
{
    $map = [];
    if (!hms_db_table_exists($connection, 'tbl_procurement_goods_receipt_line')) {
        return $map;
    }
    $sql = 'SELECT gl.purchase_order_line_id AS lid, SUM(gl.quantity_received) AS q
            FROM tbl_procurement_goods_receipt_line gl
            INNER JOIN tbl_procurement_goods_receipt g ON g.id = gl.goods_receipt_id AND g.facility_id = ' . (int) $facilityId . '
            WHERE g.purchase_order_id = ' . (int) $poId . ' GROUP BY gl.purchase_order_line_id';
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $map[(int) $r['lid']] = (float) $r['q'];
    }

    return $map;
}

/** @return array<string,mixed>|null */
function hms_procurement_match_fetch(mysqli $connection, int $facilityId, int $poId): ?array
{
    if (!hms_db_table_exists($connection, 'tbl_procurement_three_way_match')) {
        return null;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_procurement_three_way_match WHERE facility_id = ' . (int) $facilityId
        . ' AND purchase_order_id = ' . (int) $poId . ' LIMIT 1'
    );
    if ($q && $r = mysqli_fetch_assoc($q)) {
        return $r;
    }

    return null;
}

/** @return list<array<string,mixed>> */
function hms_procurement_vendor_invoices_for_po(mysqli $connection, int $facilityId, int $poId): array
{
    $out = [];
    if (!hms_db_table_exists($connection, 'tbl_procurement_vendor_invoice')) {
        return $out;
    }
    $q = mysqli_query(
        $connection,
        'SELECT * FROM tbl_procurement_vendor_invoice WHERE facility_id = ' . (int) $facilityId
        . ' AND purchase_order_id = ' . (int) $poId . ' ORDER BY invoice_date DESC, id DESC'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $out[] = $r;
    }

    return $out;
}
