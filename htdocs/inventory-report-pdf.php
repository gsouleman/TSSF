<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/inventory_helpers.php';
require_once __DIR__ . '/includes/billing_document_pdf.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'inventory.read');
$fid = hms_current_facility_id();
$type = strtolower(trim((string) ($_GET['type'] ?? 'catalog')));
if (!in_array($type, ['catalog', 'movements', 'lowstock'], true)) {
    $type = 'catalog';
}

if (!hms_billing_pdf_available()) {
    http_response_code(503);
    exit('PDF engine unavailable. Run composer install in the hms folder (dompdf).');
}

$facName = 'Facility #' . $fid;
if (hms_db_table_exists($connection, 'tbl_facility')) {
    $fq = mysqli_query($connection, 'SELECT name FROM tbl_facility WHERE id = ' . (int) $fid . ' LIMIT 1');
    if ($fq && $fr = mysqli_fetch_assoc($fq)) {
        $facName = (string) ($fr['name'] ?? $facName);
    }
}

$title = 'Inventory report';
$body = '';
$generated = date('Y-m-d H:i');

if ($type === 'catalog') {
    $title = 'Inventory catalog — ' . $facName;
    $rows = [];
    $q = mysqli_query(
        $connection,
        'SELECT id, sku, name, category, category_id, quantity, reorder_level FROM tbl_inventory_item WHERE facility_id = ' . (int) $fid . ' ORDER BY name ASC'
    );
    while ($q && $r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }
    ob_start();
    ?>
    <h2 style="font-size:14px;margin:0 0 8px;">Stock on hand</h2>
    <table style="width:100%;border-collapse:collapse;font-size:9px;">
        <thead>
            <tr style="background:#f1f5f9;">
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:left;">SKU</th>
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:left;">Name</th>
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:left;">Category</th>
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:right;">Qty</th>
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:right;">Reorder</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r) {
            $cd = hms_inventory_category_label_for_item($connection, $r);
            if ($cd === '') {
                $cd = (string) ($r['category'] ?? '');
            }
            ?>
            <tr>
                <td style="border:1px solid #e2e8f0;padding:3px;"><?php echo hms_h((string) ($r['sku'] ?? '')); ?></td>
                <td style="border:1px solid #e2e8f0;padding:3px;"><?php echo hms_h((string) ($r['name'] ?? '')); ?></td>
                <td style="border:1px solid #e2e8f0;padding:3px;"><?php echo hms_h($cd); ?></td>
                <td style="border:1px solid #e2e8f0;padding:3px;text-align:right;"><?php echo (int) ($r['quantity'] ?? 0); ?></td>
                <td style="border:1px solid #e2e8f0;padding:3px;text-align:right;"><?php echo (int) ($r['reorder_level'] ?? 0); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php
    $body = (string) ob_get_clean();
} elseif ($type === 'lowstock') {
    $title = 'Low stock — ' . $facName;
    $rows = hms_inventory_low_stock_rows($connection, $fid);
    ob_start();
    ?>
    <h2 style="font-size:14px;margin:0 0 8px;">Items at or below reorder level</h2>
    <table style="width:100%;border-collapse:collapse;font-size:9px;">
        <thead>
            <tr style="background:#fef3c7;">
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:left;">SKU</th>
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:left;">Name</th>
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:right;">Qty</th>
                <th style="border:1px solid #cbd5e1;padding:4px;text-align:right;">Reorder at</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r) { ?>
            <tr>
                <td style="border:1px solid #e2e8f0;padding:3px;"><?php echo hms_h((string) ($r['sku'] ?? '')); ?></td>
                <td style="border:1px solid #e2e8f0;padding:3px;"><?php echo hms_h((string) ($r['name'] ?? '')); ?></td>
                <td style="border:1px solid #e2e8f0;padding:3px;text-align:right;"><?php echo (int) ($r['quantity'] ?? 0); ?></td>
                <td style="border:1px solid #e2e8f0;padding:3px;text-align:right;"><?php echo (int) ($r['reorder_level'] ?? 0); ?></td>
            </tr>
        <?php } ?>
        <?php if ($rows === []) { ?>
            <tr><td colspan="4" style="padding:8px;">No low-stock items (or reorder levels not set).</td></tr>
        <?php } ?>
        </tbody>
    </table>
    <?php
    $body = (string) ob_get_clean();
} else {
    $title = 'Stock movements — ' . $facName;
    $rows = hms_inventory_recent_movements($connection, $fid, 400);
    ob_start();
    ?>
    <h2 style="font-size:14px;margin:0 0 8px;">Recent movements (latest 400)</h2>
    <table style="width:100%;border-collapse:collapse;font-size:8px;">
        <thead>
            <tr style="background:#f1f5f9;">
                <th style="border:1px solid #cbd5e1;padding:3px;">When</th>
                <th style="border:1px solid #cbd5e1;padding:3px;">Item / SKU</th>
                <th style="border:1px solid #cbd5e1;padding:3px;">Type</th>
                <th style="border:1px solid #cbd5e1;padding:3px;text-align:right;">Δ</th>
                <th style="border:1px solid #cbd5e1;padding:3px;text-align:right;">Bal</th>
                <th style="border:1px solid #cbd5e1;padding:3px;">Note</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $m) { ?>
            <tr>
                <td style="border:1px solid #e2e8f0;padding:2px;"><?php echo hms_h((string) ($m['created_at'] ?? '')); ?></td>
                <td style="border:1px solid #e2e8f0;padding:2px;"><?php echo hms_h((string) ($m['item_name'] ?? '')); ?><br><span style="color:#64748b;"><?php echo hms_h((string) ($m['sku'] ?? '')); ?></span></td>
                <td style="border:1px solid #e2e8f0;padding:2px;"><?php echo hms_h((string) ($m['movement_type'] ?? '')); ?></td>
                <td style="border:1px solid #e2e8f0;padding:2px;text-align:right;"><?php echo (int) ($m['qty_delta'] ?? 0); ?></td>
                <td style="border:1px solid #e2e8f0;padding:2px;text-align:right;"><?php echo (int) ($m['quantity_after'] ?? 0); ?></td>
                <td style="border:1px solid #e2e8f0;padding:2px;"><?php echo hms_h((string) ($m['note'] ?? '')); ?></td>
            </tr>
        <?php } ?>
        <?php if ($rows === []) { ?>
            <tr><td colspan="6" style="padding:8px;">No movements recorded. Run migration 027.</td></tr>
        <?php } ?>
        </tbody>
    </table>
    <?php
    $body = (string) ob_get_clean();
}

$html = '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>' . hms_h($title) . '</title></head><body style="font-family:Helvetica,Arial,sans-serif;color:#0f172a;">'
    . '<p style="font-size:10px;color:#64748b;margin:0 0 12px;">' . hms_h($facName) . ' · Generated ' . hms_h($generated) . '</p>'
    . '<h1 style="font-size:16px;margin:0 0 12px;">' . hms_h($title) . '</h1>'
    . $body
    . '</body></html>';

$pdf = hms_billing_html_to_pdf_bytes($html);
if ($pdf === false) {
    http_response_code(500);
    exit('PDF render failed.');
}

$fname = 'inventory-' . $type . '-' . date('Ymd-His') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: inline; filename="' . str_replace('"', '', $fname) . '"');
header('Content-Length: ' . (string) strlen($pdf));
echo $pdf;
