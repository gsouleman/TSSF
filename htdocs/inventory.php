<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory_helpers.php';
require_once __DIR__ . '/includes/medical_supply_catalog.php';
require_once __DIR__ . '/includes/purchase_order_helpers.php';
require_once __DIR__ . '/includes/pharmacy_inventory_seed.php';
require_once __DIR__ . '/includes/inventory_stock_search.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'inventory.read');
$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$tableOk = hms_db_table_exists($connection, 'tbl_inventory_item');
$catOk = hms_inventory_category_table_ok($connection);
$movOk = hms_inventory_movement_table_ok($connection);
$invCtx = hms_inventory_product_context($connection);

$flash = isset($_SESSION['inventory_flash']) ? (string) $_SESSION['inventory_flash'] : '';
unset($_SESSION['inventory_flash']);

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null) && hms_can($connection, 'inventory.write')) {
    if (isset($_POST['add_category'])) {
        $cn = trim((string) ($_POST['new_category_name'] ?? ''));
        if ($cn !== '' && $catOk) {
            $nid = hms_inventory_register_category($connection, $fid, $cn);
            $_SESSION['inventory_flash'] = $nid ? 'Category saved.' : 'Could not save category (duplicate?).';
        } else {
            $_SESSION['inventory_flash'] = 'Enter a category name.';
        }
        header('Location: inventory.php');
        exit;
    }
    if (isset($_POST['adj'])) {
        $iid = (int) ($_POST['item_id'] ?? 0);
        $delta = (int) ($_POST['delta'] ?? 0);
        if ($iid > 0 && $delta !== 0) {
            $note = trim((string) ($_POST['adj_note'] ?? ''));
            $n = $note !== '' ? $note : null;
            $r = hms_inventory_apply_qty_delta($connection, $fid, $iid, $delta, 'adjustment', $n, null, null, $uid > 0 ? $uid : null);
            if ($r !== null) {
                hms_audit_log($connection, 'inventory.adjust', 'inventory_item', $iid, ['delta' => $delta]);
                $_SESSION['inventory_flash'] = 'Quantity updated.';
            } else {
                $_SESSION['inventory_flash'] = 'Could not adjust stock.';
            }
        }
        header('Location: inventory.php');
        exit;
    }
    if (isset($_POST['stock_in'])) {
        $iid = (int) ($_POST['stock_item_id'] ?? 0);
        $qty = max(0, (int) ($_POST['stock_in_qty'] ?? 0));
        if ($iid > 0 && $qty > 0) {
            $note = trim((string) ($_POST['stock_in_note'] ?? ''));
            $n = $note !== '' ? ('Receive: ' . $note) : 'Stock receive';
            $r = hms_inventory_apply_qty_delta($connection, $fid, $iid, $qty, 'purchase', $n, null, null, $uid > 0 ? $uid : null);
            if ($r !== null) {
                hms_audit_log($connection, 'inventory.receive', 'inventory_item', $iid, ['qty' => $qty]);
                $_SESSION['inventory_flash'] = 'Stock received.';
            } else {
                $_SESSION['inventory_flash'] = 'Could not receive stock.';
            }
        } else {
            $_SESSION['inventory_flash'] = 'Choose an item and quantity.';
        }
        header('Location: inventory.php');
        exit;
    }
    if (isset($_POST['generate_auto_po'])) {
        if (hms_db_table_exists($connection, 'tbl_purchase_order')) {
            $lowStockRows = $tableOk ? hms_inventory_low_stock_rows($connection, $fid) : [];
            if (count($lowStockRows) > 0) {
                $poNum = 'PO-' . date('Ymd-His');
                $insPo = mysqli_prepare($connection, 'INSERT INTO tbl_purchase_order (facility_id, po_number, created_by) VALUES (?,?,?)');
                if ($insPo) {
                    mysqli_stmt_bind_param($insPo, 'isi', $fid, $poNum, $uid);
                    mysqli_stmt_execute($insPo);
                    $poId = mysqli_insert_id($connection);
                    mysqli_stmt_close($insPo);
                    
                    $insLine = mysqli_prepare($connection, 'INSERT INTO tbl_purchase_order_line (purchase_order_id, inventory_item_id, quantity) VALUES (?,?,?)');
                    if ($insLine) {
                        foreach ($lowStockRows as $ls) {
                            $itemid = (int)$ls['id'];
                            $reqQty = max(1, ((int)$ls['reorder_level'] * 2) - (int)$ls['quantity']);
                            mysqli_stmt_bind_param($insLine, 'iii', $poId, $itemid, $reqQty);
                            mysqli_stmt_execute($insLine);
                        }
                        mysqli_stmt_close($insLine);
                    }
                    $_SESSION['inventory_flash'] = 'Generated Auto-PO: ' . $poNum . ' for ' . count($lowStockRows) . ' items.';
                }
            } else {
                $_SESSION['inventory_flash'] = 'No low stock items to order.';
            }
        } else {
            $_SESSION['inventory_flash'] = 'Run migration 034 to enable Purchase Orders.';
        }
        header('Location: inventory.php');
        exit;
    }
    if (isset($_POST['seed_pharmacy_formulary'])) {
        if (!hms_db_table_exists($connection, 'tbl_service_catalog')) {
            $_SESSION['inventory_flash'] = 'Service catalog missing. Run migration 012_service_catalog.sql.';
        } else {
            $st = hms_pharmacy_seed_inventory_and_prices($connection, $fid, $uid > 0 ? $uid : null);
            $msg = 'Pharmacy seed: ' . $st['catalog_inserted'] . ' new price rows, ' . $st['inventory_inserted'] . ' new stock items.';
            if ($st['catalog_reused'] > 0) {
                $msg .= ' Reused ' . $st['catalog_reused'] . ' existing catalog match(es) by name.';
            }
            if ($st['inventory_skipped'] > 0) {
                $msg .= ' Skipped ' . $st['inventory_skipped'] . ' item(s) already in inventory.';
            }
            if ($st['errors'] !== []) {
                $msg .= ' ' . implode(' ', array_slice($st['errors'], 0, 6));
            }
            $_SESSION['inventory_flash'] = $msg;
            hms_audit_log($connection, 'inventory.seed_pharmacy', 'facility', $fid, $st);
        }
        header('Location: inventory.php');
        exit;
    }
    if (isset($_POST['add_item'])) {
        $name = trim((string) ($_POST['name'] ?? ''));
        $newCat = trim((string) ($_POST['new_category_name_inline'] ?? ''));
        $catId = (int) ($_POST['category_id'] ?? 0);
        $qty = max(0, (int) ($_POST['quantity'] ?? 0));
        $reorder = max(0, (int) ($_POST['reorder_level'] ?? 0));
        if ($name === '') {
            $_SESSION['inventory_flash'] = 'Product name is required.';
            header('Location: inventory.php');
            exit;
        }
        if ($newCat !== '') {
            if ($catOk) {
                $reg = hms_inventory_register_category($connection, $fid, $newCat);
                $catId = $reg ?? 0;
            }
            if ($catId < 1) {
                $catId = 0;
            }
        }
        if ($catOk && $catId < 1 && $newCat === '') {
            $_SESSION['inventory_flash'] = 'Choose a category from the list or enter a new category name.';
            header('Location: inventory.php');
            exit;
        }
        $catLabel = 'General';
        if ($catId > 0 && $catOk) {
            $cq = mysqli_query($connection, 'SELECT name FROM tbl_inventory_category WHERE id = ' . $catId . ' AND facility_id = ' . $fid . ' LIMIT 1');
            if ($cq && $cr = mysqli_fetch_assoc($cq)) {
                $catLabel = (string) ($cr['name'] ?? 'General');
            }
        } elseif ($newCat !== '') {
            $catLabel = trim($newCat);
        }
        $tmpSku = 'TMP-' . strtoupper(bin2hex(random_bytes(6)));
        $hasCatCol = hms_db_column_exists($connection, 'tbl_inventory_item', 'category_id');
        $startQty = ($movOk && $qty > 0) ? 0 : $qty;
        if ($hasCatCol && $catId > 0) {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_inventory_item (facility_id, sku, name, category, category_id, quantity, reorder_level) VALUES (?,?,?,?,?,?,?)'
            );
            if ($st) {
                mysqli_stmt_bind_param($st, 'isssiii', $fid, $tmpSku, $name, $catLabel, $catId, $startQty, $reorder);
                if (mysqli_stmt_execute($st)) {
                    $newId = (int) mysqli_insert_id($connection);
                    $sku = hms_inventory_format_sku($fid, $newId);
                    mysqli_stmt_close($st);
                    mysqli_query(
                        $connection,
                        'UPDATE tbl_inventory_item SET sku = \'' . mysqli_real_escape_string($connection, $sku) . '\' WHERE id = ' . $newId . ' LIMIT 1'
                    );
                    hms_inventory_ensure_name_catalog($connection, $fid, $name);
                    if ($movOk && $qty > 0) {
                        hms_inventory_apply_qty_delta($connection, $fid, $newId, $qty, 'purchase', 'Opening / initial quantity', null, null, $uid > 0 ? $uid : null);
                    }
                    hms_audit_log($connection, 'inventory.create', 'inventory_item', $newId);
                    $_SESSION['inventory_flash'] = 'Item added. SKU: ' . $sku;
                } else {
                    mysqli_stmt_close($st);
                    $_SESSION['inventory_flash'] = 'Could not add item.';
                }
            }
        } else {
            $st = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_inventory_item (facility_id, sku, name, category, quantity, reorder_level) VALUES (?,?,?,?,?,?)'
            );
            if ($st) {
                $sq = $movOk && $qty > 0 ? 0 : $qty;
                mysqli_stmt_bind_param($st, 'isssii', $fid, $tmpSku, $name, $catLabel, $sq, $reorder);
                if (mysqli_stmt_execute($st)) {
                    $newId = (int) mysqli_insert_id($connection);
                    $sku = hms_inventory_format_sku($fid, $newId);
                    mysqli_stmt_close($st);
                    mysqli_query(
                        $connection,
                        'UPDATE tbl_inventory_item SET sku = \'' . mysqli_real_escape_string($connection, $sku) . '\' WHERE id = ' . $newId . ' LIMIT 1'
                    );
                    hms_inventory_ensure_name_catalog($connection, $fid, $name);
                    if ($movOk && $qty > 0) {
                        hms_inventory_apply_qty_delta($connection, $fid, $newId, $qty, 'purchase', 'Opening / initial quantity', null, null, $uid > 0 ? $uid : null);
                    }
                    hms_audit_log($connection, 'inventory.create', 'inventory_item', $newId);
                    $_SESSION['inventory_flash'] = 'Item added. SKU: ' . $sku;
                } else {
                    mysqli_stmt_close($st);
                    $_SESSION['inventory_flash'] = 'Could not add item.';
                }
            }
        }
        header('Location: inventory.php');
        exit;
    }
}

if ($tableOk && $catOk) {
    hms_inventory_seed_medical_supply_catalog($connection, $fid);
}
$categories = hms_inventory_categories_for_facility($connection, $fid);
$nameSuggestions = hms_inventory_name_suggestions($connection, $fid);

$catalogKeys = array_keys(hms_medical_supply_catalog());
$standardCats = [];
$otherCats = [];
foreach ($categories as $c) {
    $match = false;
    $cn = trim((string) ($c['name'] ?? ''));
    foreach ($catalogKeys as $ck) {
        if (strcasecmp($ck, $cn) === 0) {
            $match = true;
            break;
        }
    }
    if ($match) {
        $standardCats[] = $c;
    } else {
        $otherCats[] = $c;
    }
}
usort($standardCats, static function ($a, $b) {
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});
usort($otherCats, static function ($a, $b) {
    return strcasecmp((string) ($a['name'] ?? ''), (string) ($b['name'] ?? ''));
});

$allCatalogProductNames = [];
foreach (hms_medical_supply_catalog() as $prods) {
    foreach ($prods as $p) {
        $t = trim((string) $p);
        if ($t !== '') {
            $allCatalogProductNames[$t] = true;
        }
    }
}
$supplyByCatId = [];
foreach ($categories as $c) {
    $cid = (int) ($c['id'] ?? 0);
    if ($cid < 1) {
        continue;
    }
    $list = hms_medical_supply_products_for_category_name((string) ($c['name'] ?? ''));
    if (hms_db_column_exists($connection, 'tbl_inventory_item', 'category_id')) {
        $nq = mysqli_query(
            $connection,
            'SELECT DISTINCT TRIM(name) AS n FROM tbl_inventory_item WHERE facility_id = ' . (int) $fid
            . ' AND category_id = ' . $cid . " AND TRIM(name) <> '' LIMIT 300"
        );
        while ($nq && $nr = mysqli_fetch_assoc($nq)) {
            $nn = trim((string) ($nr['n'] ?? ''));
            if ($nn !== '') {
                $list[] = $nn;
            }
        }
    }
    $list = array_values(array_unique($list));
    sort($list);
    $supplyByCatId[$cid] = $list;
}

$fallbackNames = array_keys($allCatalogProductNames);
foreach ($nameSuggestions as $ns) {
    $fallbackNames[] = $ns;
}
$fallbackNames = array_values(array_unique($fallbackNames));
sort($fallbackNames);

$supplyByCatIdJson = json_encode($supplyByCatId, JSON_UNESCAPED_UNICODE);
$fallbackNamesJson = json_encode($fallbackNames, JSON_UNESCAPED_UNICODE);

$lowStock = $tableOk ? hms_inventory_low_stock_rows($connection, $fid) : [];
$movements = $movOk ? hms_inventory_recent_movements($connection, $fid, 75) : [];
$invKpiSkus = 0;
$invKpiUnits = 0;
if ($tableOk) {
    $invKq = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c, COALESCE(SUM(quantity),0) AS u FROM tbl_inventory_item WHERE facility_id = ' . (int) $fid
    );
    if ($invKq && $invKr = mysqli_fetch_assoc($invKq)) {
        $invKpiSkus = (int) ($invKr['c'] ?? 0);
        $invKpiUnits = (int) ($invKr['u'] ?? 0);
    }
}

/** @var list<array{id:int,po_number:string,status:string,created_at:string,lines:list<array<string,mixed>>}> */
$purchaseOrders = [];
$poTableOk = $tableOk && hms_db_table_exists($connection, 'tbl_purchase_order')
    && hms_db_table_exists($connection, 'tbl_purchase_order_line');
if ($poTableOk) {
    try {
        $poq = mysqli_query(
            $connection,
            'SELECT id, po_number, status, created_at, supplier_name FROM tbl_purchase_order WHERE facility_id = ' . (int) $fid . ' ORDER BY created_at DESC LIMIT 25'
        );
        if ($poq) {
            while ($pr = mysqli_fetch_assoc($poq)) {
                $poId = (int) ($pr['id'] ?? 0);
                $lines = [];
                if ($poId > 0) {
                    $lq = mysqli_query(
                        $connection,
                        'SELECT l.quantity, l.inventory_item_id, i.sku, i.name FROM tbl_purchase_order_line l
                         LEFT JOIN tbl_inventory_item i ON i.id = l.inventory_item_id AND i.facility_id = ' . (int) $fid . '
                         WHERE l.purchase_order_id = ' . $poId . ' ORDER BY l.id ASC'
                    );
                    while ($lq && $lr = mysqli_fetch_assoc($lq)) {
                        $lines[] = $lr;
                    }
                }
                $pr['lines'] = $lines;
                $purchaseOrders[] = $pr;
            }
        }
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('inventory PO list: ' . $e->getMessage());
        }
    }
}

$invPriceLink = $tableOk && hms_inventory_has_service_catalog_link($connection);

$invSecondary = [];
if ($tableOk && !empty($invCtx['catalog_on'])) {
    $invSecondary[] = ['label' => 'Service catalog', 'url' => 'service-catalog.php?tab=pharmacy', 'icon' => 'fa-tags'];
}
if (!empty($invCtx['healthcare_on']) && hms_can($connection, 'pharmacy.read')) {
    $invSecondary[] = ['label' => 'Pharmacy', 'url' => 'pharmacy.php', 'icon' => 'fa-medkit'];
}
if (!empty($invCtx['procurement_on'])) {
    $invSecondary[] = ['label' => 'Procurement', 'url' => 'procurement-home.php', 'icon' => 'fa-shopping-basket'];
}
if ($tableOk) {
    $invSecondary[] = ['label' => 'PDF — Catalog', 'url' => 'inventory-report-pdf.php?type=catalog', 'icon' => 'fa-file-pdf-o'];
    $invSecondary[] = ['label' => 'PDF — Movements', 'url' => 'inventory-report-pdf.php?type=movements', 'icon' => 'fa-file-pdf-o'];
    $invSecondary[] = ['label' => 'PDF — Low stock', 'url' => 'inventory-report-pdf.php?type=lowstock', 'icon' => 'fa-file-pdf-o'];
}

if ($tableOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'GET' && (string) ($_GET['ajax'] ?? '') === 'stock') {
    hms_csrf_seed();
    $stockQ = trim((string) ($_GET['q'] ?? ''));
    $stockRows = hms_inventory_stock_ajax_rows($connection, $fid, $stockQ, 500, $invPriceLink);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode(
        [
            'ok' => true,
            'rows' => $stockRows,
            'csrf' => hms_csrf_token(),
            'priceColumn' => $invPriceLink,
            'canAdjust' => hms_can($connection, 'inventory.write'),
            'truncated' => count($stockRows) >= 500,
        ],
        JSON_UNESCAPED_UNICODE
    );
    exit;
}

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module hms-inventory">
            <?php
            $invSubtitle = !empty($invCtx['healthcare_on'])
                ? 'Catalog-linked pricing, automatic SKUs, movements, reorder alerts, purchase orders, and PDF exports — integrated with clinical workflows.'
                : 'Standalone stock control: catalog & SKUs, receipts, adjustments, reorder alerts, purchase orders, and audit-friendly movement history.';
            hms_ui_page_header('Inventory & stock', [
                'subtitle' => $invSubtitle,
                'breadcrumbs' => !empty($invCtx['healthcare_on'])
                    ? [['Dashboard', 'dashboard.php'], ['Inventory', '']]
                    : [['Inventory', '']],
                'secondary' => $invSecondary,
            ]);
            ?>
            <?php if ($flash !== '') { ?><div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($flash); ?></div><?php } ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning border-0 shadow-sm">Run <code>hms/database/migrations/001_multi_site_platform.sql</code> (inventory table), then <code>027_inventory_stock_categories.sql</code> for categories, movements, and alerts.</div>
            <?php } else { ?>
            <div class="hms-inv-hero">
                <div class="hms-inv-hero-inner">
                    <span class="hms-inv-hero-eyebrow">Stock control</span>
                    <h1 class="hms-inv-hero-title">Inventory hub</h1>
                    <p class="hms-inv-hero-lead">One screen for on-hand balances, receiving, low-stock signals, and PO follow-up. Use the search below to filter thousands of SKUs instantly.</p>
                </div>
            </div>
            <div class="hms-inv-kpi-row">
                <div class="hms-inv-kpi">
                    <div class="hms-inv-kpi-label">Active SKUs</div>
                    <div class="hms-inv-kpi-value"><?php echo (int) $invKpiSkus; ?></div>
                </div>
                <div class="hms-inv-kpi">
                    <div class="hms-inv-kpi-label">Total units on hand</div>
                    <div class="hms-inv-kpi-value"><?php echo (int) $invKpiUnits; ?></div>
                </div>
                <div class="hms-inv-kpi<?php echo count($lowStock) > 0 ? ' hms-inv-kpi--warn' : ''; ?>">
                    <div class="hms-inv-kpi-label">Below reorder</div>
                    <div class="hms-inv-kpi-value"><?php echo count($lowStock); ?></div>
                </div>
                <div class="hms-inv-kpi">
                    <div class="hms-inv-kpi-label">Categories</div>
                    <div class="hms-inv-kpi-value"><?php echo count($categories); ?></div>
                </div>
            </div>

            <?php if (hms_can($connection, 'inventory.write') && hms_db_table_exists($connection, 'tbl_service_catalog')) { ?>
            <div class="card border-0 shadow-sm mb-4" style="border-left:4px solid #10b981!important;">
                <div class="card-body py-3 px-4 d-flex flex-wrap align-items-center justify-content-between">
                    <div class="pr-3 mb-2 mb-md-0">
                        <h2 class="h6 font-weight-bold mb-1 text-dark"><i class="fa fa-medkit text-success mr-2"></i>Pharmacy formulary &amp; stock seed</h2>
                        <p class="small text-muted mb-0">Creates <strong>facility-scoped</strong> pharmacy prices in the <a href="service-catalog.php?tab=pharmacy">service catalog</a> and matching <strong>inventory lines</strong> (medications, equipment, full medical-supply catalogue). Skips rows that already exist by exact product name.<?php echo !empty($invCtx['healthcare_on'])
                            ? ' When clinical modules are enabled, pharmacy dispensing can use the same prices.'
                            : ' In a stock-only deployment, this still builds your formulary prices for future expansion.'; ?> Run <code>037_pharmacy_inventory_catalog_link.sql</code> so each stock line can link to its sell price.</p>
                    </div>
                    <form method="post" class="m-0" onsubmit="return confirm('Seed pharmacy catalog and stock for this facility? Existing lines with the same name are skipped.');">
                        <?php echo hms_csrf_field(); ?>
                        <button type="submit" name="seed_pharmacy_formulary" value="1" class="btn btn-success font-weight-bold"><i class="fa fa-database mr-1"></i>Seed formulary &amp; stock</button>
                    </form>
                </div>
            </div>
            <?php } ?>

            <?php if ($lowStock !== []) { ?>
            <div class="alert alert-warning border-0 shadow-sm mb-4">
                <div class="d-flex align-items-center justify-content-between flex-wrap">
                    <strong><i class="fa fa-exclamation-triangle mr-2"></i>Low stock alerts</strong>
                    <span class="badge badge-dark"><?php echo count($lowStock); ?> item(s) at or below reorder level</span>
                </div>
                <ul class="mb-0 mt-2 pl-3 small">
                    <?php foreach (array_slice($lowStock, 0, 12) as $ls) {
                        $lid = (int) ($ls['id'] ?? 0);
                        ?>
                    <li><strong><?php echo hms_h((string) ($ls['name'] ?? '')); ?></strong> (<?php echo hms_h((string) ($ls['sku'] ?? '')); ?>)
                        — Qty <strong><?php echo (int) ($ls['quantity'] ?? 0); ?></strong>, reorder at <?php echo (int) ($ls['reorder_level'] ?? 0); ?></li>
                    <?php } ?>
                </ul>
                <?php if (count($lowStock) > 12) { ?><p class="mb-0 mt-1 small text-muted">…and more. Use the PDF low-stock report for the full list.</p><?php } ?>
            </div>
            <?php } ?>

            <?php if (hms_can($connection, 'inventory.write')) { ?>
            <div class="row">
                <div class="col-lg-7 mb-4">
                    <div class="card border-0 shadow-sm hms-form-card h-100">
                        <div class="card-header bg-white font-weight-bold"><i class="fa fa-plus-circle mr-1 text-primary"></i> Add inventory item</div>
                        <div class="card-body small">
                            <p class="text-muted">SKU is generated automatically. <strong>Choose a category first</strong> — product names are suggested from a standard medical-supply catalogue for that category. You can still type any new name or category.</p>
                            <form method="post" class="row" id="hms_inv_add_form">
                                <?php echo hms_csrf_field(); ?>
                                <div class="col-md-6 mb-2">
                                    <label class="mb-0 font-weight-bold">Category <span class="text-danger">*</span></label>
                                    <?php if ($catOk && $categories !== []) { ?>
                                    <select class="form-control" name="category_id" id="hms_inv_cat_select">
                                        <option value="">— Select category —</option>
                                        <?php if ($standardCats !== []) { ?>
                                        <optgroup label="Standard medical supplies">
                                        <?php foreach ($standardCats as $c) { ?>
                                        <option value="<?php echo (int) $c['id']; ?>"><?php echo hms_h($c['name']); ?></option>
                                        <?php } ?>
                                        </optgroup>
                                        <?php } ?>
                                        <?php if ($otherCats !== []) { ?>
                                        <optgroup label="Other / custom categories">
                                        <?php foreach ($otherCats as $c) { ?>
                                        <option value="<?php echo (int) $c['id']; ?>"><?php echo hms_h($c['name']); ?></option>
                                        <?php } ?>
                                        </optgroup>
                                        <?php } ?>
                                    </select>
                                    <?php } else { ?>
                                    <input type="hidden" name="category_id" value="0">
                                    <p class="text-muted small mb-1">Categories table not installed — use new category field below.</p>
                                    <?php } ?>
                                    <label class="mt-2 mb-0 small text-muted">New category (if not listed)</label>
                                    <input class="form-control" name="new_category_name_inline" maxlength="128" placeholder="e.g. Surgical supplies" autocomplete="off">
                                </div>
                                <div class="col-md-6 mb-2">
                                    <label class="mb-0 font-weight-bold">Product name <span class="text-danger">*</span></label>
                                    <input class="form-control" id="hms_inv_product_name" name="name" required maxlength="250" list="hms_inv_name_list" placeholder="Pick category first, then type or select…" autocomplete="off">
                                    <datalist id="hms_inv_name_list"></datalist>
                                    <small class="form-text text-muted">Suggestions match the selected category’s medical-supply list; typing adds a custom product name.</small>
                                </div>
                                <div class="col-md-3 mb-2">
                                    <label class="mb-0">Initial qty</label>
                                    <input class="form-control" name="quantity" type="number" min="0" value="0">
                                </div>
                                <div class="col-md-3 mb-2">
                                    <label class="mb-0">Reorder level</label>
                                    <input class="form-control" name="reorder_level" type="number" min="0" value="0" title="Alert when quantity falls to this level or below">
                                </div>
                                <div class="col-md-6 mb-2 d-flex align-items-end">
                                    <button class="btn btn-primary" type="submit" name="add_item" value="1"><i class="fa fa-save mr-1"></i>Save item</button>
                                </div>
                            </form>
                        </div>
                    </div>
                </div>
                <div class="col-lg-5 mb-4">
                    <div class="card border-0 shadow-sm hms-form-card h-100">
                        <div class="card-header bg-white font-weight-bold"><i class="fa fa-arrow-down mr-1 text-success"></i> Receive stock</div>
                        <div class="card-body small">
                            <div class="form-group mb-3 pb-3 border-bottom">
                                <label class="mb-0 font-weight-bold text-muted"><i class="fa fa-barcode"></i> GS1 Scanner Input</label>
                                <input type="text" id="gs1_scan_input" class="form-control form-control-sm" placeholder="Scan Barcode... e.g. (01)123(10)BATCH">
                                <div class="small text-info mt-1" id="gs1_scan_feedback"></div>
                            </div>
                            <form method="post">
                                <?php echo hms_csrf_field(); ?>
                                <div class="form-group mb-2">
                                    <label class="mb-0">Item</label>
                                    <select name="stock_item_id" class="form-control" required>
                                        <option value="">— Select —</option>
                                        <?php
                                        $iq = mysqli_query($connection, 'SELECT id, sku, name FROM tbl_inventory_item WHERE facility_id = ' . (int) $fid . ' ORDER BY name ASC LIMIT 500');
                                        while ($iq && $ir = mysqli_fetch_assoc($iq)) {
                                            echo '<option value="' . (int) $ir['id'] . '">' . hms_h((string) $ir['name']) . ' (' . hms_h((string) $ir['sku']) . ')</option>';
                                        }
                                        ?>
                                    </select>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="mb-0">Quantity in</label>
                                    <input type="number" name="stock_in_qty" class="form-control" min="1" value="1" required>
                                </div>
                                <div class="form-group mb-2">
                                    <label class="mb-0">Note (optional)</label>
                                    <input type="text" name="stock_in_note" class="form-control" maxlength="200" placeholder="Supplier, batch, invoice #…">
                                </div>
                                <button type="submit" name="stock_in" value="1" class="btn btn-success btn-sm">Post receipt</button>
                            </form>
                            <hr class="my-3">
                            <p class="text-muted small mb-2 font-weight-bold">Add category only</p>
                            <form method="post" class="form-inline flex-wrap">
                                <?php echo hms_csrf_field(); ?>
                                <input type="text" name="new_category_name" class="form-control form-control-sm mb-2 mr-sm-2" placeholder="New category name" maxlength="128" style="min-width:12rem;">
                                <button type="submit" name="add_category" value="1" class="btn btn-outline-secondary btn-sm mb-2"<?php echo $catOk ? '' : ' disabled title="Run migration 027"'; ?>>Save category</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>

            <div class="card border-0 shadow-sm hms-data-card mb-4">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center flex-wrap">
                    <span><i class="fa fa-cubes mr-1"></i> Stock on hand</span>
                    <div class="d-flex align-items-center">
                        <span class="small text-muted mr-3">SKU format: HMS-&lt;site&gt;-I&lt;id&gt;</span>
                        <?php if (count($lowStock) > 0 && hms_can($connection, 'inventory.write')) { ?>
                        <form method="post" class="m-0" onsubmit="return confirm('Generate Purchase Orders for <?php echo count($lowStock); ?> low stock items?');">
                            <?php echo hms_csrf_field(); ?>
                            <button type="submit" name="generate_auto_po" class="btn btn-sm btn-outline-danger"><i class="fa fa-file-text-o mr-1"></i> Generate Auto-PO</button>
                        </form>
                        <?php } ?>
                    </div>
                </div>
                <div class="card-body p-0" id="hms-inv-stock-card" data-price-col="<?php echo $invPriceLink ? '1' : '0'; ?>" data-can-adjust="<?php echo hms_can($connection, 'inventory.write') ? '1' : '0'; ?>">
                    <div class="px-3 py-2 hms-inv-stock-search">
                        <label class="small font-weight-bold text-muted mb-1 d-block" for="hms-inv-stock-q">Search stock on hand</label>
                        <input type="search" id="hms-inv-stock-q" class="form-control form-control-sm" placeholder="Filter by name, SKU, or category…" autocomplete="off" maxlength="200" aria-describedby="hms-inv-stock-hint">
                        <small id="hms-inv-stock-hint" class="form-text text-muted mb-0">Results update as you type.</small>
                    </div>
                    <div class="table-responsive">
                        <table class="table mb-0 table-hover">
                            <thead class="thead-light"><tr><th>SKU</th><th>Name</th><th>Category</th><?php if ($invPriceLink) { ?><th class="text-right">List price</th><?php } ?><th class="text-right">Qty</th><th class="text-right">Reorder</th><th>Status</th><?php if (hms_can($connection, 'inventory.write')) { ?><th>Adjust</th><?php } ?></tr></thead>
                            <tbody id="hms-inv-stock-tbody">
                            <?php
                            $invSql = 'SELECT i.id, i.sku, i.name, i.category, i.category_id, i.quantity, i.reorder_level';
                            if ($invPriceLink) {
                                $invSql .= ', sc.price AS catalog_unit_price';
                            }
                            $invSql .= ' FROM tbl_inventory_item i ';
                            if ($invPriceLink) {
                                $invSql .= 'LEFT JOIN tbl_service_catalog sc ON sc.id = i.service_catalog_id ';
                            }
                            $invSql .= 'WHERE i.facility_id = ' . (int) $fid . ' ORDER BY i.name ASC';
                            $q = mysqli_query($connection, $invSql);
                            while ($q && $r = mysqli_fetch_assoc($q)) {
                                $catDisp = hms_inventory_category_label_for_item($connection, $r);
                                if ($catDisp === '') {
                                    $catDisp = (string) ($r['category'] ?? '');
                                }
                                $qr = (int) ($r['quantity'] ?? 0);
                                $rlo = (int) ($r['reorder_level'] ?? 0);
                                $low = $rlo > 0 && $qr <= $rlo;
                                echo '<tr' . ($low ? ' class="table-warning"' : '') . '>';
                                echo '<td class="text-monospace small">' . hms_h((string) $r['sku']) . '</td>';
                                echo '<td>' . hms_h((string) $r['name']) . '</td>';
                                echo '<td>' . hms_h($catDisp) . '</td>';
                                if ($invPriceLink) {
                                    $pu = isset($r['catalog_unit_price']) ? (float) $r['catalog_unit_price'] : 0.0;
                                    echo '<td class="text-right small text-nowrap">';
                                    echo $pu > 0 ? hms_h(number_format($pu, 0, '.', ' ')) . ' <span class="text-muted">FCFA</span>' : '<span class="text-muted">—</span>';
                                    echo '</td>';
                                }
                                echo '<td class="text-right font-weight-bold">' . $qr . '</td>';
                                echo '<td class="text-right">' . $rlo . '</td>';
                                echo '<td>';
                                if ($low) {
                                    echo '<span class="badge badge-warning">Low</span>';
                                } elseif ($qr === 0) {
                                    echo '<span class="badge badge-danger">Out</span>';
                                } else {
                                    echo '<span class="badge badge-light border">OK</span>';
                                }
                                echo '</td>';
                                if (hms_can($connection, 'inventory.write')) {
                                    echo '<td><form method="post" class="form-inline flex-wrap align-items-center">';
                                    echo hms_csrf_field();
                                    echo '<input type="hidden" name="item_id" value="' . (int) $r['id'] . '">';
                                    echo '<input type="number" name="delta" class="form-control form-control-sm mr-1 mb-1" style="width:5rem" value="0" title="Negative removes stock">';
                                    echo '<input type="text" name="adj_note" class="form-control form-control-sm mr-1 mb-1" style="min-width:7rem;max-width:10rem" placeholder="Note" maxlength="120">';
                                    echo '<button class="btn btn-sm btn-outline-primary mb-1" name="adj" value="1">Apply</button></form></td>';
                                }
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                    <p class="small text-muted px-3 py-2 mb-0 d-none border-top" id="hms-inv-stock-trunc">Showing the first 500 matches. Refine your search.</p>
                </div>
            </div>
            <script>
            (function () {
                var card = document.getElementById('hms-inv-stock-card');
                var inp = document.getElementById('hms-inv-stock-q');
                var tb = document.getElementById('hms-inv-stock-tbody');
                var trunc = document.getElementById('hms-inv-stock-trunc');
                if (!card || !inp || !tb) {
                    return;
                }
                var priceCol = card.getAttribute('data-price-col') === '1';
                var canAdjust = card.getAttribute('data-can-adjust') === '1';
                var debMs = 280;
                var tmr = null;
                var csrfToken = '';
                var esc = function (s) {
                    var d = document.createElement('div');
                    d.textContent = String(s);
                    return d.innerHTML;
                };
                var fmtPrice = function (n) {
                    return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                };
                var statusBadge = function (st) {
                    if (st === 'low') {
                        return '<span class="badge badge-warning">Low</span>';
                    }
                    if (st === 'out') {
                        return '<span class="badge badge-danger">Out</span>';
                    }
                    return '<span class="badge badge-light border">OK</span>';
                };
                var adjustForm = function (r) {
                    if (!canAdjust || !csrfToken) {
                        return '';
                    }
                    return '<td><form method="post" class="form-inline flex-wrap align-items-center">'
                        + '<input type="hidden" name="hms_csrf" value="' + esc(csrfToken) + '">'
                        + '<input type="hidden" name="item_id" value="' + esc(String(r.id)) + '">'
                        + '<input type="number" name="delta" class="form-control form-control-sm mr-1 mb-1" style="width:5rem" value="0" title="Negative removes stock">'
                        + '<input type="text" name="adj_note" class="form-control form-control-sm mr-1 mb-1" style="min-width:7rem;max-width:10rem" placeholder="Note" maxlength="120">'
                        + '<button class="btn btn-sm btn-outline-primary mb-1" name="adj" value="1">Apply</button></form></td>';
                };
                var colCount = function () {
                    return 6 + (priceCol ? 1 : 0) + (canAdjust ? 1 : 0);
                };
                var render = function (data) {
                    if (!data || !data.ok || !data.rows) {
                        tb.innerHTML = '<tr><td colspan="' + colCount() + '" class="text-center text-muted py-4">Could not load results.</td></tr>';
                        return;
                    }
                    csrfToken = data.csrf || csrfToken;
                    if (typeof data.priceColumn === 'boolean') {
                        priceCol = data.priceColumn;
                    }
                    if (typeof data.canAdjust === 'boolean') {
                        canAdjust = data.canAdjust;
                    }
                    var rows = data.rows;
                    if (rows.length === 0) {
                        tb.innerHTML = '<tr><td colspan="' + colCount() + '" class="text-center text-muted py-5">No items match your search.</td></tr>';
                    } else {
                        var html = '';
                        for (var i = 0; i < rows.length; i++) {
                            var r = rows[i];
                            var trCls = r.status === 'low' ? ' class="table-warning"' : '';
                            html += '<tr' + trCls + '>';
                            html += '<td class="text-monospace small">' + esc(r.sku) + '</td>';
                            html += '<td>' + esc(r.name) + '</td>';
                            html += '<td>' + esc(r.category) + '</td>';
                            if (priceCol) {
                                html += '<td class="text-right small text-nowrap">';
                                if (r.listPrice != null && r.listPrice > 0) {
                                    html += esc(fmtPrice(r.listPrice)) + ' <span class="text-muted">FCFA</span>';
                                } else {
                                    html += '<span class="text-muted">—</span>';
                                }
                                html += '</td>';
                            }
                            html += '<td class="text-right font-weight-bold">' + esc(String(r.qty)) + '</td>';
                            html += '<td class="text-right">' + esc(String(r.reorder)) + '</td>';
                            html += '<td>' + statusBadge(r.status) + '</td>';
                            html += adjustForm(r);
                            html += '</tr>';
                        }
                        tb.innerHTML = html;
                    }
                    if (trunc) {
                        if (data.truncated) {
                            trunc.classList.remove('d-none');
                        } else {
                            trunc.classList.add('d-none');
                        }
                    }
                };
                var runFetch = function () {
                    var q = inp.value.trim();
                    fetch('inventory.php?ajax=stock&q=' + encodeURIComponent(q), { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(render)
                        .catch(function () {
                            tb.innerHTML = '<tr><td colspan="' + colCount() + '" class="text-center text-danger py-4">Search failed. Try again.</td></tr>';
                        });
                };
                var schedule = function () {
                    if (tmr) {
                        clearTimeout(tmr);
                    }
                    tmr = setTimeout(runFetch, debMs);
                };
                inp.addEventListener('input', schedule);
                inp.addEventListener('search', schedule);
            })();
            </script>

            <?php if ($poTableOk) { ?>
            <div class="card border-0 shadow-sm hms-data-card mb-4">
                <div class="card-header bg-white font-weight-bold d-flex justify-content-between align-items-center flex-wrap">
                    <span><i class="fa fa-file-text-o mr-1"></i> Purchase orders</span>
                    <span class="small text-muted">Auto-PO uses number format <code class="small">PO-YYYYMMDD-His</code></span>
                </div>
                <div class="card-body p-0">
                    <?php if ($purchaseOrders === []) { ?>
                    <p class="text-muted small mb-0 px-3 py-4 text-center">No purchase orders yet. When items are low on stock, click <strong>Generate Auto-PO</strong> above to create one here.</p>
                    <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table mb-0 table-sm">
                            <thead class="thead-light">
                                <tr>
                                    <th>PO number</th>
                                    <th>Vendor</th>
                                    <th>Created</th>
                                    <th>Status</th>
                                    <th>Lines</th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php foreach ($purchaseOrders as $po) {
                                $poid = (int) ($po['id'] ?? 0);
                                $pnum = (string) ($po['po_number'] ?? '');
                                $pst = (string) ($po['status'] ?? 'draft');
                                $pdt = (string) ($po['created_at'] ?? '');
                                $pvend = trim((string) ($po['supplier_name'] ?? ''));
                                $plines = $po['lines'] ?? [];
                                ?>
                                <tr>
                                    <td class="font-weight-bold text-monospace">
                                        <a href="purchase-order.php?id=<?php echo $poid; ?>"><?php echo hms_h($pnum); ?></a>
                                    </td>
                                    <td class="small"><?php echo $pvend !== '' ? hms_h($pvend) : '<span class="text-muted">—</span>'; ?></td>
                                    <td class="text-nowrap small"><?php echo hms_h($pdt); ?></td>
                                    <td><span class="badge badge-light border"><?php echo hms_h(hms_po_status_label($pst)); ?></span></td>
                                    <td class="small"><?php echo count($plines); ?> item(s)</td>
                                </tr>
                                <?php if ($plines !== []) { ?>
                                <tr class="bg-light">
                                    <td colspan="5" class="pt-0 pb-3 border-0">
                                        <table class="table table-borderless table-sm mb-0 small">
                                            <thead><tr class="text-muted"><th>SKU</th><th>Product</th><th class="text-right">Qty</th></tr></thead>
                                            <tbody>
                                            <?php foreach ($plines as $ln) {
                                                $sku = (string) ($ln['sku'] ?? '');
                                                $nm = (string) ($ln['name'] ?? '');
                                                if ($sku === '' && $nm === '') {
                                                    $nm = 'Item #' . (int) ($ln['inventory_item_id'] ?? 0);
                                                }
                                                ?>
                                            <tr>
                                                <td class="text-monospace"><?php echo hms_h($sku !== '' ? $sku : '—'); ?></td>
                                                <td><?php echo hms_h($nm); ?></td>
                                                <td class="text-right font-weight-bold"><?php echo (int) ($ln['quantity'] ?? 0); ?></td>
                                            </tr>
                                            <?php } ?>
                                            </tbody>
                                        </table>
                                    </td>
                                </tr>
                                <?php } ?>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>

            <?php if ($movOk) { ?>
            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-header bg-white font-weight-bold"><i class="fa fa-exchange mr-1"></i> Recent stock movements</div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table mb-0 table-sm">
                            <thead class="thead-light"><tr><th>When</th><th>Item</th><th>Type</th><th class="text-right">Δ</th><th class="text-right">Balance</th><th>Note</th></tr></thead>
                            <tbody>
                            <?php foreach ($movements as $m) { ?>
                                <tr>
                                    <td class="text-nowrap small"><?php echo hms_h((string) ($m['created_at'] ?? '')); ?></td>
                                    <td class="small"><?php echo hms_h((string) ($m['item_name'] ?? '')); ?><br><span class="text-muted"><?php echo hms_h((string) ($m['sku'] ?? '')); ?></span></td>
                                    <td><span class="badge badge-light border"><?php echo hms_h((string) ($m['movement_type'] ?? '')); ?></span></td>
                                    <td class="text-right font-weight-bold"><?php echo (int) ($m['qty_delta'] ?? 0); ?></td>
                                    <td class="text-right"><?php echo (int) ($m['quantity_after'] ?? 0); ?></td>
                                    <td class="small text-muted"><?php echo hms_h((string) ($m['note'] ?? '')); ?></td>
                                </tr>
                            <?php } ?>
                            <?php if ($movements === []) { ?>
                                <tr><td colspan="6" class="text-muted text-center py-3">No movements recorded yet.</td></tr>
                            <?php } ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php } else { ?>
            <p class="text-muted small">Run migration <code>027_inventory_stock_categories.sql</code> to enable movement history.</p>
            <?php } ?>

            <?php } ?>
        </div></div>
<?php
if ($tableOk && hms_can($connection, 'inventory.write')) {
    ?>
<script>
(function () {
    var byId = <?php echo $supplyByCatIdJson; ?>;
    var fallback = <?php echo $fallbackNamesJson; ?>;
    var dl = document.getElementById('hms_inv_name_list');
    var sel = document.getElementById('hms_inv_cat_select');
    function fill(list) {
        if (!dl) {
            return;
        }
        dl.innerHTML = '';
        (list || []).forEach(function (n) {
            if (!n) {
                return;
            }
            var o = document.createElement('option');
            o.value = n;
            dl.appendChild(o);
        });
    }
    function refresh() {
        if (!sel || !dl) {
            fill(fallback);
            return;
        }
        var v = parseInt(sel.value, 10);
        var list = (v && byId[v]) ? byId[v] : ((v && byId[String(v)]) ? byId[String(v)] : null);
        if (list && list.length) {
            fill(list);
        } else {
            fill(fallback);
        }
    }
    if (sel) {
        sel.addEventListener('change', refresh);
    }
    refresh();

    // GS1 Parser Listener
    var gs1Input = document.getElementById('gs1_scan_input');
    if (gs1Input) {
        gs1Input.addEventListener('input', function(e) {
            let scanData = e.target.value.trim();
            if(scanData.length > 5 && scanData.includes('(10)')) {
                let batchMatch = scanData.match(/\(10\)([^\(]+)/);
                let expMatch = scanData.match(/\(17\)(\d+)/);
                let fback = document.getElementById('gs1_scan_feedback');
                
                let notesArr = [];
                if(batchMatch) notesArr.push("Grp: " + batchMatch[1]);
                if(expMatch) notesArr.push("Exp: " + expMatch[1]);
                
                if (notesArr.length > 0) {
                    let combined = "GS1 Scanned - " + notesArr.join(" | ");
                    document.querySelector('[name="stock_in_note"]').value = combined;
                    fback.innerText = "Captured: " + combined;
                    fback.classList.add('text-success');
                }
                setTimeout(() => { e.target.value = ''; }, 2000);
            }
        });
    }
})();
</script>
<?php } ?>
<?php include 'footer.php'; ?>
