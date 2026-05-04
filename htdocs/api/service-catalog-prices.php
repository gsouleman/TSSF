<?php
declare(strict_types=1);
/**
 * JSON API: Return active service catalog items for the current facility.
 * Used by invoice-create.php and add-charge.php for dynamic price auto-fill.
 * GET /api/service-catalog-prices.php?category=all|consultation|laboratory|service
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
hms_api_require_auth();

$fid = hms_current_facility_id();
$cat = trim((string)($_GET['category'] ?? 'all'));
$allowed = ['all', 'consultation', 'laboratory', 'service', 'pharmacy'];
if (!in_array($cat, $allowed, true)) $cat = 'all';

if (!hms_db_table_exists($connection, 'tbl_service_catalog')) {
    hms_json_response(['ok' => true, 'items' => []]);
    // hms_json_response() calls exit() — explicit return for clarity
}

$where = '(facility_id = ' . (int)$fid . ' OR facility_id = 0) AND status = 1';
if ($cat !== 'all') {
    $catEsc = mysqli_real_escape_string($connection, $cat);
    $where .= " AND category = '$catEsc'";
}

$q = mysqli_query($connection,
    "SELECT id, category, subcategory, name, cpt_code, price, description
     FROM tbl_service_catalog
     WHERE $where
     ORDER BY category, subcategory, sort_order, name
     LIMIT 500"
);

$items = [];
while ($q && $row = mysqli_fetch_assoc($q)) {
    $items[] = [
        'id'          => (int)$row['id'],
        'category'    => $row['category'],
        'subcategory' => $row['subcategory'],
        'name'        => $row['name'],
        'cpt_code'    => $row['cpt_code'],
        'price'       => (float)$row['price'],
        'description' => $row['description'],
        'label'       => $row['name'] . ' — ' . number_format((float)$row['price'], 0, '.', ' ') . ' FCFA',
    ];
}

hms_json_response(['ok' => true, 'items' => $items]);
