<?php
declare(strict_types=1);

require_once __DIR__ . '/medical_supply_catalog.php';
require_once __DIR__ . '/pharmacy_seed_medications.php';

/**
 * Whether inventory rows can reference tbl_service_catalog for sell price.
 */
function hms_inventory_has_service_catalog_link(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_inventory_item')
        && hms_db_column_exists($connection, 'tbl_inventory_item', 'service_catalog_id');
}

/**
 * Build deterministic price/qty for each medical-supply catalogue line (XAF, stock).
 *
 * @return list<array{name:string,subcategory:string,price:int,qty:int,reorder:int}>
 */
function hms_pharmacy_seed_supply_rows_from_catalog(): array
{
    $out = [];
    foreach (hms_medical_supply_catalog() as $subcategory => $names) {
        foreach ($names as $name) {
            $name = trim((string) $name);
            if ($name === '') {
                continue;
            }
            $h = crc32($name . '|' . $subcategory);
            $price = 200 + ($h % 9800);
            $price = (int) (round($price / 50) * 50);
            $qty = 80 + ($h % 920);
            $reorder = max(10, (int) round($qty * 0.15));
            $out[] = [
                'name' => $name,
                'subcategory' => $subcategory,
                'price' => $price,
                'qty' => $qty,
                'reorder' => $reorder,
            ];
        }
    }

    return $out;
}

/**
 * @return list<array{name:string,subcategory:string,price:int,qty:int,reorder:int}>
 */
function hms_pharmacy_seed_all_product_rows(): array
{
    return array_merge(
        hms_pharmacy_seed_medication_rows(),
        hms_pharmacy_seed_equipment_rows(),
        hms_pharmacy_seed_supply_rows_from_catalog()
    );
}

/**
 * Seed pharmacy price catalog + on-hand stock for one facility (idempotent by product name).
 *
 * @return array{catalog_inserted:int,catalog_reused:int,inventory_inserted:int,inventory_skipped:int,errors:list<string>}
 */
function hms_pharmacy_seed_inventory_and_prices(mysqli $connection, int $facilityId, ?int $createdByEmployeeId): array
{
    $stats = [
        'catalog_inserted' => 0,
        'catalog_reused' => 0,
        'inventory_inserted' => 0,
        'inventory_skipped' => 0,
        'errors' => [],
    ];
    if ($facilityId < 1) {
        $stats['errors'][] = 'Invalid facility.';

        return $stats;
    }
    if (!hms_db_table_exists($connection, 'tbl_service_catalog') || !hms_db_table_exists($connection, 'tbl_inventory_item')) {
        $stats['errors'][] = 'Run migrations 012 (service catalog) and 001 (inventory).';

        return $stats;
    }

    $movOk = hms_inventory_movement_table_ok($connection);
    $catOk = hms_inventory_category_table_ok($connection);
    $hasSvcLink = hms_inventory_has_service_catalog_link($connection);
    $hasCatCol = hms_db_column_exists($connection, 'tbl_inventory_item', 'category_id');
    $uid = $createdByEmployeeId !== null && $createdByEmployeeId > 0 ? $createdByEmployeeId : null;

    $seq = 0;
    foreach (hms_pharmacy_seed_all_product_rows() as $row) {
        $seq++;
        $name = trim((string) ($row['name'] ?? ''));
        $sub = trim((string) ($row['subcategory'] ?? 'General'));
        if ($name === '') {
            continue;
        }
        $price = max(0.0, (float) ($row['price'] ?? 0));
        $qty = max(0, (int) ($row['qty'] ?? 0));
        $reorder = max(0, (int) ($row['reorder'] ?? 0));

        $chkInv = mysqli_prepare(
            $connection,
            'SELECT id, service_catalog_id FROM tbl_inventory_item WHERE facility_id = ? AND name = ? LIMIT 1'
        );
        $existingInvId = 0;
        $existingInvSvc = null;
        if ($chkInv) {
            mysqli_stmt_bind_param($chkInv, 'is', $facilityId, $name);
            mysqli_stmt_execute($chkInv);
            $ir = hms_stmt_fetch_assoc($chkInv);
            mysqli_stmt_close($chkInv);
            if ($ir) {
                $existingInvId = (int) ($ir['id'] ?? 0);
                $existingInvSvc = isset($ir['service_catalog_id']) ? (int) ($ir['service_catalog_id'] ?? 0) : null;
            }
        }

        $cpt = 'PHF' . $facilityId . 'N' . str_pad((string) $seq, 6, '0', STR_PAD_LEFT);
        $catalogId = 0;

        $chkCat = mysqli_prepare(
            $connection,
            "SELECT id FROM tbl_service_catalog WHERE facility_id = ? AND LOWER(category) = 'pharmacy' AND name = ? LIMIT 1"
        );
        if ($chkCat) {
            mysqli_stmt_bind_param($chkCat, 'is', $facilityId, $name);
            mysqli_stmt_execute($chkCat);
            $cr = hms_stmt_fetch_assoc($chkCat);
            mysqli_stmt_close($chkCat);
            if ($cr) {
                $catalogId = (int) ($cr['id'] ?? 0);
            }
        }

        if ($catalogId < 1) {
            $insC = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_service_catalog (facility_id, category, subcategory, name, description, cpt_code, price, currency, status, sort_order)
                 VALUES (?,\'pharmacy\',?,?,?,?,?,\'XAF\',1,?)'
            );
            if (!$insC) {
                $stats['errors'][] = 'Could not prepare catalog insert.';

                continue;
            }
            $desc = 'Seeded formulary / stock item';
            $sort = min(99999, $seq);
            mysqli_stmt_bind_param($insC, 'issssdi', $facilityId, $sub, $name, $desc, $cpt, $price, $sort);
            if (!mysqli_stmt_execute($insC)) {
                mysqli_stmt_close($insC);
                $stats['errors'][] = 'Catalog insert failed for: ' . $name;

                continue;
            }
            mysqli_stmt_close($insC);
            $catalogId = (int) mysqli_insert_id($connection);
            $stats['catalog_inserted']++;
        } else {
            $stats['catalog_reused']++;
        }

        if ($existingInvId > 0) {
            if ($hasSvcLink && $catalogId > 0 && ($existingInvSvc === null || $existingInvSvc < 1)) {
                mysqli_query(
                    $connection,
                    'UPDATE tbl_inventory_item SET service_catalog_id = ' . (int) $catalogId
                    . ' WHERE id = ' . $existingInvId . ' AND facility_id = ' . (int) $facilityId . ' LIMIT 1'
                );
            }
            $stats['inventory_skipped']++;

            continue;
        }

        $catIdForItem = 0;
        $catLabel = $sub;
        if ($catOk) {
            $reg = hms_inventory_register_category($connection, $facilityId, $sub);
            $catIdForItem = $reg ?? 0;
        }

        $tmpSku = 'TMP-' . strtoupper(bin2hex(random_bytes(5)));
        $startQty = ($movOk && $qty > 0) ? 0 : $qty;
        $newId = 0;

        if ($hasCatCol && $catIdForItem > 0) {
            $cols = 'facility_id, sku, name, category, category_id, quantity, reorder_level';
            $vals = '?,?,?,?,?,?,?';
            if ($hasSvcLink) {
                $cols .= ', service_catalog_id';
                $vals .= ',?';
            }
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_inventory_item (' . $cols . ') VALUES (' . $vals . ')'
            );
            if ($st) {
                if ($hasSvcLink) {
                    mysqli_stmt_bind_param($st, 'isssiiii', $facilityId, $tmpSku, $name, $catLabel, $catIdForItem, $startQty, $reorder, $catalogId);
                } else {
                    mysqli_stmt_bind_param($st, 'isssiii', $facilityId, $tmpSku, $name, $catLabel, $catIdForItem, $startQty, $reorder);
                }
                if (mysqli_stmt_execute($st)) {
                    $newId = (int) mysqli_insert_id($connection);
                }
                mysqli_stmt_close($st);
            }
        } else {
            $cols = 'facility_id, sku, name, category, quantity, reorder_level';
            $vals = '?,?,?,?,?,?';
            if ($hasSvcLink) {
                $cols .= ', service_catalog_id';
                $vals .= ',?';
            }
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_inventory_item (' . $cols . ') VALUES (' . $vals . ')'
            );
            if ($st) {
                $sq = $movOk && $qty > 0 ? 0 : $qty;
                if ($hasSvcLink) {
                    mysqli_stmt_bind_param($st, 'isssiii', $facilityId, $tmpSku, $name, $catLabel, $sq, $reorder, $catalogId);
                } else {
                    mysqli_stmt_bind_param($st, 'isssii', $facilityId, $tmpSku, $name, $catLabel, $sq, $reorder);
                }
                if (mysqli_stmt_execute($st)) {
                    $newId = (int) mysqli_insert_id($connection);
                }
                mysqli_stmt_close($st);
            }
        }

        if ($newId < 1) {
            $stats['errors'][] = 'Inventory insert failed for: ' . $name;

            continue;
        }

        $sku = hms_inventory_format_sku($facilityId, $newId);
        mysqli_query(
            $connection,
            'UPDATE tbl_inventory_item SET sku = \'' . mysqli_real_escape_string($connection, $sku) . '\' WHERE id = ' . $newId . ' LIMIT 1'
        );
        hms_inventory_ensure_name_catalog($connection, $facilityId, $name);

        if ($movOk && $qty > 0) {
            hms_inventory_apply_qty_delta($connection, $facilityId, $newId, $qty, 'purchase', 'Opening stock (pharmacy seed)', null, null, $uid);
        }

        $stats['inventory_inserted']++;
    }

    return $stats;
}
