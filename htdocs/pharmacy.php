<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory_helpers.php';
require_once __DIR__ . '/includes/pharmacy_stock_query.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'pharmacy.read');
$fid = hms_current_facility_id();
$ok = hms_workflow_table_ok($connection, 'tbl_prescription_line');
$invOk = hms_db_table_exists($connection, 'tbl_inventory_item');

$searchQ = trim((string) ($_GET['q'] ?? ''));
$avFilter = (string) ($_GET['av'] ?? 'all');
if (!in_array($avFilter, ['all', 'ok', 'low', 'out'], true)) {
    $avFilter = 'all';
}

/** @var array{total:int,ok:int,low:int,out:int} */
$invStats = ['total' => 0, 'ok' => 0, 'low' => 0, 'out' => 0];
/** @var list<array<string,mixed>> */
$invRows = [];
$invPriceJoin = false;

if ($invOk) {
    $st = mysqli_query(
        $connection,
        'SELECT COUNT(*) AS c,
                SUM(CASE WHEN quantity = 0 THEN 1 ELSE 0 END) AS n_out,
                SUM(CASE WHEN quantity > 0 AND reorder_level > 0 AND quantity <= reorder_level THEN 1 ELSE 0 END) AS n_low,
                SUM(CASE WHEN quantity > 0 AND (reorder_level < 1 OR quantity > reorder_level) THEN 1 ELSE 0 END) AS n_ok
         FROM tbl_inventory_item WHERE facility_id = ' . (int) $fid
    );
    if ($st && $sr = mysqli_fetch_assoc($st)) {
        $invStats['total'] = (int) ($sr['c'] ?? 0);
        $invStats['out'] = (int) ($sr['n_out'] ?? 0);
        $invStats['low'] = (int) ($sr['n_low'] ?? 0);
        $invStats['ok'] = (int) ($sr['n_ok'] ?? 0);
    }

    $invPriceJoin = hms_db_column_exists($connection, 'tbl_inventory_item', 'service_catalog_id');

    if ((string) ($_GET['ajax'] ?? '') === '1') {
        $rows = hms_pharmacy_stock_rows_json_encodeable($connection, $fid, $searchQ, $avFilter, 500);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode(
            [
                'ok' => true,
                'rows' => $rows,
                'priceColumn' => $invPriceJoin,
                'truncated' => count($rows) >= 500,
            ],
            JSON_UNESCAPED_UNICODE
        );
        exit;
    }

    $invRows = hms_pharmacy_stock_query_rows($connection, $fid, $searchQ, $avFilter, 500);
}

include 'header.php';
$phSecondary = [
    ['label' => 'Prescriptions', 'url' => 'prescriptions.php', 'icon' => 'fa-file-text-o'],
    ['label' => 'Medication prices', 'url' => 'service-catalog.php?tab=pharmacy', 'icon' => 'fa-tags'],
    ['label' => 'Inventory', 'url' => 'inventory.php', 'icon' => 'fa-cubes'],
];
$canInv = hms_can($connection, 'inventory.read');
?>
        <div class="page-wrapper"><div class="content hms-module hms-pharmacy-page">
            <style>
                .hms-pharmacy-page .hms-ph-stock-toolbar { background: #f8fafc; border: 1px solid #e2e8f0; border-radius: .5rem; padding: 1rem 1.25rem; }
                .hms-pharmacy-page .hms-ph-stat { border-radius: .5rem; border: 1px solid #e2e8f0; background: #fff; padding: .75rem 1rem; height: 100%; }
                .hms-pharmacy-page .hms-ph-stat .hms-ph-stat-value { font-size: 1.35rem; font-weight: 700; line-height: 1.2; color: #0f172a; }
                .hms-pharmacy-page .hms-ph-stat .hms-ph-stat-label { font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; color: #64748b; font-weight: 600; }
                .hms-pharmacy-page .badge-hms-ok { background: #ecfdf5; color: #047857; border: 1px solid #a7f3d0; }
                .hms-pharmacy-page .badge-hms-low { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
                .hms-pharmacy-page .badge-hms-out { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
                .hms-pharmacy-page .hms-ph-filter-pill { border-radius: 999px; padding: .35rem .85rem; font-size: .8125rem; font-weight: 600; border: 1px solid #cbd5e1; color: #475569; background: #fff; display: inline-block; margin: 0 .35rem .35rem 0; }
                .hms-pharmacy-page .hms-ph-filter-pill:hover { border-color: #94a3b8; color: #0f172a; text-decoration: none; }
                .hms-pharmacy-page .hms-ph-filter-pill.active { background: #0f172a; border-color: #0f172a; color: #fff; }
                .hms-pharmacy-page .table thead th { border-top: 0; font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: #64748b; font-weight: 600; }
            </style>
            <?php
            hms_ui_page_header('Pharmacy', [
                'subtitle' => 'Look up medication and supply stock, then open prescriptions to dispense.',
                'breadcrumbs' => [['Operations', null], ['Pharmacy', '']],
                'secondary' => $phSecondary,
            ]);
            ?>

            <?php if (!$invOk) { ?>
            <div class="alert alert-warning border-0 shadow-sm">Inventory is not available. Run <code>001_multi_site_platform.sql</code> to create stock tables.</div>
            <?php } else { ?>
            <div class="card border-0 shadow-sm mb-4">
                <div class="card-body p-0">
                        <div class="px-4 py-3 border-bottom bg-white d-flex flex-wrap align-items-center justify-content-between">
                        <div>
                            <h2 class="h5 mb-1 font-weight-bold text-dark">Stock &amp; availability</h2>
                            <p class="mb-0 small text-muted">Type to search instantly (name, SKU, or category). List prices come from the <a href="service-catalog.php?tab=pharmacy">pharmacy catalog</a> when each stock line is linked (migration <code>037</code> + seed from Inventory). Quantities are for this facility only.</p>
                        </div>
                        <?php if ($canInv) { ?>
                        <a href="inventory.php" class="btn btn-outline-secondary btn-sm mt-2 mt-md-0"><i class="fa fa-cubes mr-1"></i>Full inventory</a>
                        <?php } ?>
                    </div>
                    <div class="p-4">
                        <div class="row mb-4">
                            <div class="col-6 col-md-3 mb-3 mb-md-0">
                                <div class="hms-ph-stat">
                                    <div class="hms-ph-stat-label">Catalog items</div>
                                    <div class="hms-ph-stat-value"><?php echo (int) $invStats['total']; ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3 mb-md-0">
                                <div class="hms-ph-stat">
                                    <div class="hms-ph-stat-label">In stock</div>
                                    <div class="hms-ph-stat-value text-success"><?php echo (int) $invStats['ok']; ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3 mb-3 mb-md-0">
                                <div class="hms-ph-stat">
                                    <div class="hms-ph-stat-label">Low stock</div>
                                    <div class="hms-ph-stat-value" style="color:#b45309;"><?php echo (int) $invStats['low']; ?></div>
                                </div>
                            </div>
                            <div class="col-6 col-md-3">
                                <div class="hms-ph-stat">
                                    <div class="hms-ph-stat-label">Out of stock</div>
                                    <div class="hms-ph-stat-value text-danger"><?php echo (int) $invStats['out']; ?></div>
                                </div>
                            </div>
                        </div>

                        <div class="hms-ph-stock-toolbar mb-3" id="hms-ph-toolbar" data-price-col="<?php echo $invPriceJoin ? '1' : '0'; ?>">
                            <form method="get" class="row align-items-end" action="pharmacy.php" id="hms-ph-stock-form">
                                <div class="col-md-8 mb-2 mb-md-0">
                                    <label class="small font-weight-bold text-muted mb-1" for="hms-ph-live-q">Search</label>
                                    <div class="input-group">
                                        <div class="input-group-prepend"><span class="input-group-text bg-white border-right-0"><i class="fa fa-search text-muted"></i></span></div>
                                        <input type="text" name="q" id="hms-ph-live-q" class="form-control border-left-0" placeholder="e.g. Paracetamol, gauze, HMS-0001…" value="<?php echo hms_h($searchQ); ?>" autocomplete="off" maxlength="200" aria-describedby="hms-ph-search-hint">
                                        <div class="input-group-append">
                                            <button class="btn btn-outline-secondary px-3" type="submit" title="Reload page with current filters">Go</button>
                                        </div>
                                    </div>
                                    <small id="hms-ph-search-hint" class="form-text text-muted mb-0">Results update as you type.</small>
                                </div>
                                <div class="col-md-4">
                                    <label class="small font-weight-bold text-muted mb-1" for="hms-ph-av">Availability</label>
                                    <select class="form-control" name="av" id="hms-ph-av">
                                        <option value="all"<?php echo $avFilter === 'all' ? ' selected' : ''; ?>>All items</option>
                                        <option value="ok"<?php echo $avFilter === 'ok' ? ' selected' : ''; ?>>In stock only</option>
                                        <option value="low"<?php echo $avFilter === 'low' ? ' selected' : ''; ?>>Low stock</option>
                                        <option value="out"<?php echo $avFilter === 'out' ? ' selected' : ''; ?>>Out of stock</option>
                                    </select>
                                </div>
                            </form>
                            <div class="mt-3 pt-2 border-top">
                                <span class="small text-muted font-weight-bold mr-2">Quick:</span>
                                <?php
                                $basePills = ['all' => 'All', 'ok' => 'In stock', 'low' => 'Low', 'out' => 'Out'];
                                foreach ($basePills as $k => $lab) {
                                    $cls = 'hms-ph-filter-pill hms-ph-pill' . ($avFilter === $k ? ' active' : '');
                                    echo '<button type="button" class="' . $cls . '" data-av="' . hms_h($k) . '">' . hms_h($lab) . '</button>';
                                }
                                ?>
                            </div>
                        </div>

                        <div class="table-responsive border rounded" style="border-color:#e2e8f0!important;">
                            <table class="table table-hover table-sm mb-0">
                                <thead class="thead-light"><tr>
                                    <th>Item</th>
                                    <th class="text-nowrap">SKU</th>
                                    <th>Category</th>
                                    <?php if ($invPriceJoin) { ?><th class="text-right text-nowrap">List price</th><?php } ?>
                                    <th class="text-right text-nowrap">On hand</th>
                                    <th class="text-right text-nowrap d-none d-md-table-cell">Reorder at</th>
                                    <th class="text-nowrap">Status</th>
                                </tr></thead>
                                <tbody id="hms-ph-stock-tbody">
                                <?php
                                if ($invRows === []) {
                                    echo '<tr><td colspan="' . ($invPriceJoin ? '7' : '6') . '" class="text-center text-muted py-5">';
                                    if ($searchQ !== '' || $avFilter !== 'all') {
                                        echo 'No items match your filters. Try a broader search or set availability to <strong>All</strong>.';
                                    } else {
                                        echo 'No inventory rows for this facility yet.';
                                    }
                                    echo '</td></tr>';
                                } else {
                                    foreach ($invRows as $ir) {
                                        $qty = (int) ($ir['quantity'] ?? 0);
                                        $reord = (int) ($ir['reorder_level'] ?? 0);
                                        if ($qty === 0) {
                                            $badge = '<span class="badge badge-hms-out">Out of stock</span>';
                                        } elseif ($reord > 0 && $qty <= $reord) {
                                            $badge = '<span class="badge badge-hms-low">Low stock</span>';
                                        } else {
                                            $badge = '<span class="badge badge-hms-ok">Available</span>';
                                        }
                                        $catDisp = isset($ir['inv_cat_name']) && trim((string) $ir['inv_cat_name']) !== ''
                                            ? (string) $ir['inv_cat_name']
                                            : hms_inventory_category_label_for_item($connection, $ir);
                                        if ($catDisp === '') {
                                            $catDisp = (string) ($ir['category'] ?? '—');
                                        }
                                        echo '<tr>';
                                        echo '<td class="align-middle font-weight-bold text-dark">' . hms_h((string) ($ir['name'] ?? '')) . '</td>';
                                        echo '<td class="align-middle small text-monospace text-muted">' . hms_h((string) ($ir['sku'] ?? '')) . '</td>';
                                        echo '<td class="align-middle small">' . hms_h($catDisp) . '</td>';
                                        if ($invPriceJoin) {
                                            $lp = isset($ir['list_price']) ? (float) $ir['list_price'] : 0.0;
                                            $lc = trim((string) ($ir['list_currency'] ?? 'XAF'));
                                            echo '<td class="align-middle text-right small">';
                                            if ($lp > 0) {
                                                echo hms_h(number_format($lp, 0, '.', ' ')) . ' <span class="text-muted">' . hms_h($lc !== '' ? $lc : 'XAF') . '</span>';
                                            } else {
                                                echo '<span class="text-muted">—</span>';
                                            }
                                            echo '</td>';
                                        }
                                        echo '<td class="align-middle text-right font-weight-bold">' . $qty . '</td>';
                                        echo '<td class="align-middle text-right small text-muted d-none d-md-table-cell">' . ($reord > 0 ? (string) $reord : '—') . '</td>';
                                        echo '<td class="align-middle">' . $badge . '</td>';
                                        echo '</tr>';
                                    }
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                        <p class="small text-muted mt-2 mb-0<?php echo count($invRows) >= 500 ? '' : ' d-none'; ?>" id="hms-ph-trunc-hint">Showing the first 500 matches. Refine your search for a shorter list.</p>
                    </div>
                </div>
            </div>
            <script>
            (function () {
                var inp = document.getElementById('hms-ph-live-q');
                var sel = document.getElementById('hms-ph-av');
                var tb = document.getElementById('hms-ph-stock-tbody');
                var toolbar = document.getElementById('hms-ph-toolbar');
                var trunc = document.getElementById('hms-ph-trunc-hint');
                if (!inp || !sel || !tb || !toolbar) {
                    return;
                }
                var priceCol = toolbar.getAttribute('data-price-col') === '1';
                var debMs = 280;
                var tmr = null;
                var esc = function (s) {
                    var d = document.createElement('div');
                    d.textContent = String(s);
                    return d.innerHTML;
                };
                var fmtPrice = function (n) {
                    return Math.round(n).toString().replace(/\B(?=(\d{3})+(?!\d))/g, ' ');
                };
                var badge = function (st) {
                    if (st === 'out') {
                        return '<span class="badge badge-hms-out">Out of stock</span>';
                    }
                    if (st === 'low') {
                        return '<span class="badge badge-hms-low">Low stock</span>';
                    }
                    return '<span class="badge badge-hms-ok">Available</span>';
                };
                var colspan = priceCol ? 7 : 6;
                var setPills = function (av) {
                    document.querySelectorAll('.hms-ph-pill').forEach(function (b) {
                        var v = b.getAttribute('data-av') || '';
                        if (v === av) {
                            b.classList.add('active');
                        } else {
                            b.classList.remove('active');
                        }
                    });
                };
                var render = function (data) {
                    if (!data || !data.ok || !data.rows) {
                        tb.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center text-muted py-4">Could not load results.</td></tr>';
                        return;
                    }
                    var rows = data.rows;
                    if (rows.length === 0) {
                        tb.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center text-muted py-5">No items match your filters.</td></tr>';
                    } else {
                        var html = '';
                        for (var i = 0; i < rows.length; i++) {
                            var r = rows[i];
                            html += '<tr>';
                            html += '<td class="align-middle font-weight-bold text-dark">' + esc(r.name) + '</td>';
                            html += '<td class="align-middle small text-monospace text-muted">' + esc(r.sku) + '</td>';
                            html += '<td class="align-middle small">' + esc(r.category) + '</td>';
                            if (priceCol) {
                                html += '<td class="align-middle text-right small">';
                                if (r.listPrice != null && r.listPrice > 0) {
                                    html += esc(fmtPrice(r.listPrice)) + ' <span class="text-muted">' + esc(r.listCurrency || 'XAF') + '</span>';
                                } else {
                                    html += '<span class="text-muted">—</span>';
                                }
                                html += '</td>';
                            }
                            html += '<td class="align-middle text-right font-weight-bold">' + esc(String(r.qty)) + '</td>';
                            html += '<td class="align-middle text-right small text-muted d-none d-md-table-cell">';
                            html += (r.reorder > 0) ? esc(String(r.reorder)) : '—';
                            html += '</td>';
                            html += '<td class="align-middle">' + badge(r.status) + '</td>';
                            html += '</tr>';
                        }
                        tb.innerHTML = html;
                    }
                    if (trunc) {
                        if (data.truncated) {
                            trunc.classList.remove('d-none');
                            trunc.textContent = 'Showing the first 500 matches. Refine your search for a shorter list.';
                        } else {
                            trunc.classList.add('d-none');
                        }
                    }
                };
                var runFetch = function () {
                    var q = inp.value.trim();
                    var av = sel.value || 'all';
                    var url = 'pharmacy.php?ajax=1&q=' + encodeURIComponent(q) + '&av=' + encodeURIComponent(av);
                    fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
                        .then(function (r) { return r.json(); })
                        .then(function (data) {
                            render(data);
                            if (typeof history.replaceState === 'function') {
                                var u = new URL(window.location.href);
                                u.searchParams.set('q', q);
                                u.searchParams.set('av', av);
                                history.replaceState({}, '', u);
                            }
                            setPills(av);
                        })
                        .catch(function () {
                            tb.innerHTML = '<tr><td colspan="' + colspan + '" class="text-center text-danger py-4">Search failed. Check your connection and try again.</td></tr>';
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
                sel.addEventListener('change', function () {
                    if (tmr) {
                        clearTimeout(tmr);
                    }
                    runFetch();
                });
                document.querySelectorAll('.hms-ph-pill').forEach(function (btn) {
                    btn.addEventListener('click', function () {
                        var av = btn.getAttribute('data-av');
                        if (!av || !sel) {
                            return;
                        }
                        sel.value = av;
                        if (tmr) {
                            clearTimeout(tmr);
                        }
                        runFetch();
                    });
                });
            })();
            </script>
            <?php } ?>

            <div class="card border-0 shadow-sm hms-data-card">
                <div class="card-header bg-white border-bottom py-3 d-flex flex-wrap justify-content-between align-items-center">
                    <div>
                        <h2 class="h6 mb-0 font-weight-bold">Dispensing queue</h2>
                        <span class="small text-muted">Medication lines awaiting dispense</span>
                    </div>
                </div>
                <div class="card-body p-0">
            <?php if (!$ok) { ?>
                    <div class="p-4"><div class="alert alert-warning mb-0">Run migration <code>003_clinical_workflow.sql</code>.</div></div>
            <?php } else { ?>
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead class="thead-light"><tr>
                                <th>Rx</th>
                                <th>Patient</th>
                                <?php if (hms_can($connection, 'clinical.read')) { ?><th class="text-nowrap">Chart</th><?php } ?>
                                <th>Medication</th>
                                <th>Dose / route</th>
                                <th>Status</th>
                                <th></th>
                            </tr></thead>
                            <tbody>
                            <?php
                            $sql = 'SELECT pl.id AS line_id, pl.prescription_id, pl.medication_name, pl.medication_dose, pl.medication_route,
                                    pl.dispense_status, pl.dispensed_qty,
                                    r.patient_id, p.first_name, p.last_name
                                    FROM tbl_prescription_line pl
                                    INNER JOIN tbl_prescription r ON r.id = pl.prescription_id AND r.facility_id = ' . (int) $fid . "
                                    INNER JOIN tbl_patient p ON p.id = r.patient_id
                                    WHERE pl.line_type = 'medication' AND pl.dispense_status <> 'dispensed'
                                    ORDER BY pl.id DESC LIMIT 120";
                            $q = mysqli_query($connection, $sql);
                            while ($q && $rw = mysqli_fetch_assoc($q)) {
                                echo '<tr>';
                                echo '<td class="align-middle font-weight-bold">' . (int) $rw['prescription_id'] . '</td>';
                                echo '<td class="align-middle">' . hms_h($rw['first_name'] . ' ' . $rw['last_name']) . '</td>';
                                if (hms_can($connection, 'clinical.read')) {
                                    $phpid = (int) $rw['patient_id'];
                                    echo '<td class="small text-nowrap align-middle"><a href="patient-chart.php?id=' . $phpid . '">Chart</a></td>';
                                }
                                echo '<td class="align-middle">' . hms_h((string) $rw['medication_name']) . '</td>';
                                echo '<td class="small align-middle">' . hms_h((string) $rw['medication_dose']) . ' / ' . hms_h((string) $rw['medication_route']) . '</td>';
                                echo '<td class="align-middle"><span class="badge badge-secondary">' . hms_h((string) $rw['dispense_status']) . '</span></td>';
                                echo '<td class="align-middle text-right"><a class="btn btn-sm btn-primary" href="prescription.php?id=' . (int) $rw['prescription_id'] . '">Open Rx</a></td>';
                                echo '</tr>';
                            }
                            ?>
                            </tbody>
                        </table>
                    </div>
                    <div class="px-3 py-2 border-top bg-light small text-muted">Open a prescription to record dispense and optional stock deduction.</div>
            <?php } ?>
                </div>
            </div>
        </div></div>
<?php include 'footer.php'; ?>
