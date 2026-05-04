<?php
declare(strict_types=1);

require_once __DIR__ . '/inventory_helpers.php';

/**
 * Rows for the Inventory "Stock on hand" table / live search (JSON).
 *
 * @return list<array{id:int,sku:string,name:string,category:string,listPrice:?float,qty:int,reorder:int,status:string}>
 */
function hms_inventory_stock_ajax_rows(
    mysqli $connection,
    int $facilityId,
    string $searchQ,
    int $limit,
    bool $priceLink
): array {
    if ($facilityId < 1 || !hms_db_table_exists($connection, 'tbl_inventory_item')) {
        return [];
    }
    $limit = max(1, min(2000, $limit));
    $searchQ = trim($searchQ);

    $hasCatId = hms_db_column_exists($connection, 'tbl_inventory_item', 'category_id');
    $hasSvc = $priceLink && hms_db_column_exists($connection, 'tbl_inventory_item', 'service_catalog_id');

    $join = '';
    if ($hasSvc) {
        $join .= 'LEFT JOIN tbl_service_catalog sc ON sc.id = i.service_catalog_id ';
    }
    if ($hasCatId) {
        $join .= 'LEFT JOIN tbl_inventory_category c ON c.id = i.category_id AND c.facility_id = i.facility_id ';
    }

    $where = ['i.facility_id = ' . (int) $facilityId];
    if ($searchQ !== '') {
        $e = mysqli_real_escape_string($connection, $searchQ);
        $like = '\'%' . $e . "%'";
        $catPart = $hasCatId ? ' OR c.name LIKE ' . $like : '';
        $where[] = '(i.name LIKE ' . $like . ' OR i.sku LIKE ' . $like . ' OR i.category LIKE ' . $like . $catPart . ')';
    }

    $sql = 'SELECT i.id, i.sku, i.name, i.category, i.category_id, i.quantity, i.reorder_level';
    if ($hasSvc) {
        $sql .= ', sc.price AS catalog_unit_price';
    }
    if ($hasCatId) {
        $sql .= ', c.name AS inv_cat_name';
    }
    $sql .= ' FROM tbl_inventory_item i ' . $join . ' WHERE ' . implode(' AND ', $where)
        . ' ORDER BY i.name ASC LIMIT ' . $limit;

    $out = [];
    $q = mysqli_query($connection, $sql);
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $catDisp = isset($r['inv_cat_name']) && trim((string) ($r['inv_cat_name'] ?? '')) !== ''
            ? (string) $r['inv_cat_name']
            : hms_inventory_category_label_for_item($connection, $r);
        if ($catDisp === '') {
            $catDisp = (string) ($r['category'] ?? '');
        }
        $qr = (int) ($r['quantity'] ?? 0);
        $rlo = (int) ($r['reorder_level'] ?? 0);
        if ($qr === 0) {
            $st = 'out';
        } elseif ($rlo > 0 && $qr <= $rlo) {
            $st = 'low';
        } else {
            $st = 'ok';
        }
        $lp = null;
        if ($hasSvc && isset($r['catalog_unit_price'])) {
            $v = (float) $r['catalog_unit_price'];
            $lp = $v > 0 ? $v : null;
        }
        $out[] = [
            'id' => (int) ($r['id'] ?? 0),
            'sku' => (string) ($r['sku'] ?? ''),
            'name' => (string) ($r['name'] ?? ''),
            'category' => $catDisp,
            'listPrice' => $lp,
            'qty' => $qr,
            'reorder' => $rlo,
            'status' => $st,
        ];
    }

    return $out;
}
