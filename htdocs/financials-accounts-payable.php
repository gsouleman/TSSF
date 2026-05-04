<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_reports_theme.php';
require_once __DIR__ . '/includes/financials_reports_data.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$d1 = trim((string) ($_GET['d1'] ?? date('Y-m-01')));
$d2 = trim((string) ($_GET['d2'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
    $d1 = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) {
    $d2 = date('Y-m-d');
}

$apOk = hms_db_table_exists($connection, 'tbl_expense');
$apRows = [];
$sumAp = 0.0;

if ($apOk) {
    $hasVendor = hms_db_column_exists($connection, 'tbl_expense', 'vendor');
    $hasDate = hms_db_column_exists($connection, 'tbl_expense', 'expense_date');
    $hasCategory = hms_db_column_exists($connection, 'tbl_expense', 'category');
    $hasDesc = hms_db_column_exists($connection, 'tbl_expense', 'description');

    $colDate = $hasDate ? "expense_date" : "DATE(created_at) AS expense_date";
    $colCat  = $hasCategory ? "category" : "'' AS category";
    $colVend = $hasVendor ? "vendor" : "'' AS vendor";
    $colDesc = $hasDesc ? "description" : "'' AS description";
    $whereDate = $hasDate ? "expense_date" : "DATE(created_at)";
    $orderDate = $hasDate ? "expense_date" : "created_at";

    // Custom query to extract deeply granular line items
    $sql = "SELECT id, $colDate, $colCat, $colVend, $colDesc, amount_xaf 
            FROM tbl_expense 
            WHERE facility_id = ? AND $whereDate >= ? AND $whereDate <= ? 
            ORDER BY $orderDate DESC, id DESC";
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("iss", $fid, $d1, $d2);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $apRows[] = $row;
            $sumAp += (float) ($row['amount_xaf'] ?? 0);
        }
        $stmt->close();
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Accounts payable — detail', [
                    'subtitle' => 'Solidarity of Hearts Hospital — comprehensive chronological transaction detail.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Accounts payable', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="d1">From</label>
                            <input type="date" class="form-control" id="d1" name="d1" value="<?php echo hms_h($d1); ?>">
                        </div>
                        <div class="form-group col-md-2 mb-0">
                            <label for="d2">To</label>
                            <input type="date" class="form-control" id="d2" name="d2" value="<?php echo hms_h($d2); ?>">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php if (!$apOk) { ?>
                <div class="alert alert-warning">Expense data could not be loaded.</div>
                <?php } else { ?>
                <?php
                $hms_fin_report_document_title = 'ACCOUNTS PAYABLE (DETAIL)';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'Period' => date('d-m-Y', strtotime($d1)) . ' — ' . date('d-m-Y', strtotime($d2)),
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('d-m-Y'),
                    'Report ref.' => 'APD-' . str_replace('-', '', $d2),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <p class="hms-fin-section-bar mb-0">Detailed transactional log</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr>
                                    <th style="width: 12%">Date</th>
                                    <th style="width: 15%">Category</th>
                                    <th style="width: 20%">Vendor</th>
                                    <th>Description</th>
                                    <th class="hms-ohada-num" style="width: 15%">Amount</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($apRows as $r) { ?>
                                <tr>
                                    <td><?php echo hms_h(date('d-m-Y', strtotime((string) $r['expense_date']))); ?></td>
                                    <td><?php echo hms_h((string) ($r['category'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($r['vendor'] ?? '')); ?></td>
                                    <td><?php echo hms_h((string) ($r['description'] ?? '')); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) ($r['amount_xaf'] ?? 0)); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (count($apRows) === 0) { ?>
                                <tr><td colspan="5" class="text-muted font-italic">No expenditures recorded within this period.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td colspan="4">Total Expenditures</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($sumAp); ?></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">Chronological transaction register of all accrued expenditures mapping to suppliers or administrative categories.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Accounts payable detail</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
