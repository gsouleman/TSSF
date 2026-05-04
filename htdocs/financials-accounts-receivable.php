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
$asOf = trim((string) ($_GET['asof'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $asOf)) {
    $asOf = date('Y-m-d');
}

$arOk = hms_db_table_exists($connection, 'tbl_credit_account') && hms_db_table_exists($connection, 'tbl_patient');
$arRows = [];

$sumGross = 0.0;
$sumPaid = 0.0;
$sumAdj = 0.0;
$sumNet = 0.0;

if ($arOk) {
    $sql = "SELECT ca.id, ca.status, ca.invoice_due_date, p.first_name, p.last_name,
            (SELECT COALESCE(SUM(amount),0) FROM tbl_charge WHERE credit_account_id = ca.id AND on_credit = 1 AND DATE(posted_at) <= ?) AS gross_charges,
            (SELECT COALESCE(SUM(amount),0) FROM tbl_credit_payment WHERE credit_account_id = ca.id AND DATE(created_at) <= ?) AS total_paid,
            (SELECT COALESCE(SUM(amount),0) FROM tbl_credit_adjustment WHERE credit_account_id = ca.id AND DATE(created_at) <= ?) AS total_adj
            FROM tbl_credit_account ca
            INNER JOIN tbl_patient p ON p.id = ca.patient_id
            WHERE ca.facility_id = ?
            ORDER BY p.first_name, p.last_name";
            
    $stmt = $connection->prepare($sql);
    if ($stmt) {
        $stmt->bind_param("sssi", $asOf, $asOf, $asOf, $fid);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $g = (float) ($row['gross_charges'] ?? 0);
            $p = (float) ($row['total_paid'] ?? 0);
            $a = (float) ($row['total_adj'] ?? 0);
            $net = $g - $p - $a;
            
            if ($net != 0 || $g != 0) { // Keep if there's history or active balance
                $row['net_balance'] = $net;
                
                // Determine Aging Bucket
                $bucket = 'Current';
                if ($net > 0.001) {
                    $due = $row['invoice_due_date'] ?? null;
                    if ($due) {
                        $diff = (int) floor((strtotime($asOf) - strtotime($due)) / 86400);
                        if ($diff > 90) $bucket = '> 90 Days';
                        elseif ($diff > 60) $bucket = '61-90 Days';
                        elseif ($diff > 30) $bucket = '31-60 Days';
                        elseif ($diff > 0) $bucket = '1-30 Days';
                    }
                }
                $row['bucket'] = $bucket;
                
                $arRows[] = $row;
                
                $sumGross += $g;
                $sumPaid += $p;
                $sumAdj += $a;
                $sumNet += $net;
            }
        }
        $stmt->close();
    }
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Accounts receivable — detail', [
                    'subtitle' => 'Solidarity of Hearts Hospital — comprehensive patient ledger mapping.',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Accounts receivable', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-2 mb-0">
                            <label for="asof">As at</label>
                            <input type="date" class="form-control" id="asof" name="asof" value="<?php echo hms_h($asOf); ?>">
                        </div>
                        <div class="form-group col-md-4 mb-0">
                            <button type="submit" class="btn btn-primary">Refresh</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Print</button>
                        </div>
                    </div>
                </form>

                <?php if (!$arOk) { ?>
                <div class="alert alert-warning">Credit / patient receivable tables are not available. Install credit billing (see Help &amp; setup) to use this report.</div>
                <?php } else { ?>
                <?php
                $hms_fin_report_document_title = 'ACCOUNTS RECEIVABLE (DETAIL)';
                $hms_fin_report_meta_primary = [
                    'Company' => hms_fin_report_org_name(),
                    'As at' => date('d-m-Y', strtotime($asOf)),
                    'Currency' => hms_currency_label(),
                    'Prepared by' => '________________',
                ];
                $hms_fin_report_meta_secondary = [
                    'Facility' => '#' . (string) $fid,
                    'Report date' => date('d-m-Y'),
                    'Report ref.' => 'ARD-' . str_replace('-', '', $asOf),
                ];
                ?>
                <div class="hms-fin-report hms-ohada-report hms-fin-report--corp">
                    <?php include __DIR__ . '/includes/partials/financial_report_masthead.php'; ?>

                    <p class="hms-fin-section-bar mb-0">Detailed patient credit ledger</p>
                    <div class="table-responsive hms-fin-table-wrap">
                        <table class="table hms-fin-table hms-fin-table--striped mb-3">
                            <thead>
                                <tr>
                                    <th style="width: 20%">Patient Name</th>
                                    <th class="hms-ohada-num">Gross Charges</th>
                                    <th class="hms-ohada-num">Payments Made</th>
                                    <th class="hms-ohada-num">Assoc. Adj.</th>
                                    <th class="hms-ohada-num" style="background-color: #f8f9fa;">Net Outstanding</th>
                                    <th>Aging</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($arRows as $r) { 
                                    $name = trim($r['first_name'] . ' ' . $r['last_name']);
                                ?>
                                <tr>
                                    <td><?php echo hms_h($name); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $r['gross_charges'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $r['total_paid'], false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $r['total_adj'], false); ?></td>
                                    <td class="hms-ohada-num font-weight-bold" style="background-color: #f8f9fa;"><?php echo hms_format_xaf((float) $r['net_balance'], false); ?></td>
                                    <td><?php echo hms_h($r['bucket']); ?></td>
                                </tr>
                                <?php } ?>
                                <?php if (count($arRows) === 0) { ?>
                                <tr><td colspan="6" class="text-muted font-italic">No outstanding balances.</td></tr>
                                <?php } ?>
                                <tr class="hms-fin-total-row">
                                    <td>Aggregated Portfolio</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($sumGross); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($sumPaid); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf($sumAdj); ?></td>
                                    <td class="hms-ohada-num" style="color: #2c3e50; font-size: 1.1em;"><?php echo hms_format_xaf($sumNet); ?></td>
                                    <td>—</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">This ledger enumerates the complete patient receivable breakdown, projecting Gross accrued medical charges against collected unapplied credits. Due classifications are computed dynamically from aging rules.</p>
                    <div class="hms-fin-doc__footer-bar">
                        <span>Confidential — internal use</span>
                        <span>Accounts receivable detail</span>
                        <span>Page 1</span>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
