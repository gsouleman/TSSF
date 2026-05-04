<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_helpers.php';

/**
 * Inventory rows for the pharmacy stock table (same filters as pharmacy.php).
 *
 * @return list<array<string,mixed>>
 */
function hms_pharmacy_stock_query_rows(
    mysqli $connection,
    int $facilityId,
    string $searchQ,
    string $avFilter,
    int $limit = 500
): array {
    if ($facilityId < 1 || !hms_db_table_exists($connection, 'tbl_inventory_item')) {
        return [];
    }
    if (!in_array($avFilter, ['all', 'ok', 'low', 'out'], true)) {
        $avFilter = 'all';
    }
    $limit = max(1, min(2000, $limit));

    $hasCatId = hms_db_column_exists($connection, 'tbl_inventory_item', 'category_id');
    $invPriceJoin = hms_db_column_exists($connection, 'tbl_inventory_item', 'service_catalog_id');
    $join = '';
    if ($hasCatId) {
        $join .= ' LEFT JOIN tbl_inventory_category c ON c.id = i.category_id AND c.facility_id = i.facility_id ';
    }
    if ($invPriceJoin) {
        $join .= ' LEFT JOIN tbl_service_catalog sc ON sc.id = i.service_catalog_id ';
    }

    $where = ['i.facility_id = ' . (int) $facilityId];
    $searchQ = trim($searchQ);
    if ($searchQ !== '') {
        $e = mysqli_real_escape_string($connection, $searchQ);
        $like = '\'%' . $e . "%'";
        $catOr = $hasCatId ? " OR c.name LIKE $like" : '';
        $where[] = '(i.name LIKE ' . $like . ' OR i.sku LIKE ' . $like . ' OR i.category LIKE ' . $like . $catOr . ')';
    }
    if ($avFilter === 'out') {
        $where[] = 'i.quantity = 0';
    } elseif ($avFilter === 'low') {
        $where[] = 'i.reorder_level > 0 AND i.quantity > 0 AND i.quantity <= i.reorder_level';
    } elseif ($avFilter === 'ok') {
        $where[] = 'i.quantity > 0 AND (i.reorder_level < 1 OR i.quantity > i.reorder_level)';
    }

    $sql = 'SELECT i.id, i.sku, i.name, i.category, i.quantity, i.reorder_level';
    if ($hasCatId) {
        $sql .= ', i.category_id, c.name AS inv_cat_name';
    }
    if ($invPriceJoin) {
        $sql .= ', sc.price AS list_price, sc.currency AS list_currency';
    }
    $sql .= ' FROM tbl_inventory_item i ' . $join . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY (i.quantity = 0) ASC, (i.reorder_level > 0 AND i.quantity > 0 AND i.quantity <= i.reorder_level) DESC, i.name ASC LIMIT ' . $limit;

    $rows = [];
    $rq = mysqli_query($connection, $sql);
    while ($rq && $rw = mysqli_fetch_assoc($rq)) {
        $rows[] = $rw;
    }

    return $rows;
}

/**
 * @return list<array{name:string,sku:string,category:string,listPrice:?float,listCurrency:string,qty:int,reorder:int,status:string}>
 */
function hms_pharmacy_stock_rows_json_encodeable(
    mysqli $connection,
    int $facilityId,
    string $searchQ,
    string $avFilter,
    int $limit = 500
): array {
    $raw = hms_pharmacy_stock_query_rows($connection, $facilityId, $searchQ, $avFilter, $limit);
    $out = [];
    foreach ($raw as $ir) {
        $qty = (int) ($ir['quantity'] ?? 0);
        $reord = (int) ($ir['reorder_level'] ?? 0);
        if ($qty === 0) {
            $st = 'out';
        } elseif ($reord > 0 && $qty <= $reord) {
            $st = 'low';
        } else {
            $st = 'ok';
        }
        $catDisp = isset($ir['inv_cat_name']) && trim((string) $ir['inv_cat_name']) !== ''
            ? (string) $ir['inv_cat_name']
            : hms_inventory_category_label_for_item($connection, $ir);
        if ($catDisp === '') {
            $catDisp = (string) ($ir['category'] ?? '—');
        }
        $lp = isset($ir['list_price']) ? (float) $ir['list_price'] : null;
        if ($lp !== null && $lp <= 0) {
            $lp = null;
        }
        $out[] = [
            'name' => (string) ($ir['name'] ?? ''),
            'sku' => (string) ($ir['sku'] ?? ''),
            'category' => $catDisp,
            'listPrice' => $lp,
            'listCurrency' => trim((string) ($ir['list_currency'] ?? 'XAF')) ?: 'XAF',
            'qty' => $qty,
            'reorder' => $reord,
            'status' => $st,
        ];
    }

    return $out;
}
