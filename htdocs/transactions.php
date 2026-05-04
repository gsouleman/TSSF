<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'billing.read');

$fid = hms_current_facility_id();
$tableOk = hms_db_table_exists($connection, 'tbl_transaction');
$hasTxnFacilityCol = $tableOk && hms_db_column_exists($connection, 'tbl_transaction', 'facility_id');

$today = date('Y-m-d');
$thisWeekStart = date('Y-m-d', strtotime('monday this week'));
$thisWeekEnd = date('Y-m-d', strtotime('sunday this week'));
$lastWeekStart = date('Y-m-d', strtotime('monday last week'));
$lastWeekEnd = date('Y-m-d', strtotime('sunday last week'));
$thisMonthStart = date('Y-m-01');
$thisMonthEnd = date('Y-m-t');
$lastMonthStart = date('Y-m-01', strtotime('-1 month'));
$lastMonthEnd = date('Y-m-t', strtotime('-1 month'));
$yearFilter = (int) ($_GET['year'] ?? (int) date('Y'));
if ($yearFilter < 2020 || $yearFilter > 2050) {
    $yearFilter = (int) date('Y');
}

$txnFlash = isset($_SESSION['txn_flash']) ? (string) $_SESSION['txn_flash'] : '';
unset($_SESSION['txn_flash']);

/* ---------- Stats ---------- */
$statTotal = 0; $statLastMonth = 0; $statThisMonth = 0;
$statLastWeek = 0; $statThisWeek = 0; $statToday = 0;
$monthlyData = array_fill(1, 12, ['inprogress' => 0, 'completed' => 0]);

if ($tableOk) {
    $txnFacSql = $hasTxnFacilityCol ? 'facility_id = ' . (int) $fid : '1=1';
    $base = 'SELECT COALESCE(SUM(amount),0) AS s FROM tbl_transaction WHERE ' . $txnFacSql;
    $fetch = function (string $sql) use ($connection): float {
        $q = mysqli_query($connection, $sql);
        return $q ? (float) (mysqli_fetch_assoc($q)['s'] ?? 0) : 0.0;
    };
    $statTotal     = $fetch($base);
    $statLastMonth = $fetch($base . " AND transaction_date >= '{$lastMonthStart}' AND transaction_date <= '{$lastMonthEnd}'");
    $statThisMonth = $fetch($base . " AND transaction_date >= '{$thisMonthStart}' AND transaction_date <= '{$thisMonthEnd}'");
    $statLastWeek  = $fetch($base . " AND transaction_date >= '{$lastWeekStart}' AND transaction_date <= '{$lastWeekEnd}'");
    $statThisWeek  = $fetch($base . " AND transaction_date >= '{$thisWeekStart}' AND transaction_date <= '{$thisWeekEnd}'");
    $statToday     = $fetch($base . " AND transaction_date = '{$today}'");

    /* monthly chart data for selected year */
    $mq = mysqli_query(
        $connection,
        'SELECT MONTH(transaction_date) AS m, status, COUNT(*) AS c FROM tbl_transaction WHERE ' . $txnFacSql . ' AND YEAR(transaction_date) = ' . (int) $yearFilter . ' GROUP BY m, status ORDER BY m'
    );
    while ($mq && $mr = mysqli_fetch_assoc($mq)) {
        $mon = (int) $mr['m'];
        if ($mon >= 1 && $mon <= 12) {
            $st = (string) $mr['status'];
            if ($st === 'completed') {
                $monthlyData[$mon]['completed'] += (int) $mr['c'];
            } else {
                $monthlyData[$mon]['inprogress'] += (int) $mr['c'];
            }
        }
    }
}

/* ---------- List with pagination ---------- */
$sort = (string) ($_GET['sort'] ?? 'newest');
if (!in_array($sort, ['newest', 'oldest'], true)) {
    $sort = 'newest';
}
$page = max(1, (int) ($_GET['p'] ?? 1));
$perPage = 15;
$total = 0;
$rows = [];

if ($tableOk) {
    $w = $hasTxnFacilityCol ? 't.facility_id = ' . (int) $fid : '1=1';
    $cntQ = mysqli_query($connection, 'SELECT COUNT(*) AS c FROM tbl_transaction t WHERE ' . $w);
    if ($cntQ) {
        $cr = mysqli_fetch_assoc($cntQ);
        $total = (int) ($cr['c'] ?? 0);
    }
    $totalPages = max(1, (int) ceil($total / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $orderSql = $sort === 'oldest' ? 't.transaction_date ASC, t.id ASC' : 't.transaction_date DESC, t.id DESC';
    $dataQ = mysqli_query(
        $connection,
        "SELECT t.id, t.patient_id, t.description, t.amount, t.payment_method, t.status, t.transaction_date, p.first_name, p.last_name FROM tbl_transaction t LEFT JOIN tbl_patient p ON p.id = t.patient_id WHERE {$w} ORDER BY {$orderSql} LIMIT {$offset}, {$perPage}"
    );
    while ($dataQ && $r = mysqli_fetch_assoc($dataQ)) {
        $rows[] = $r;
    }
} else {
    $totalPages = 1;
}

if (!headers_sent()) {
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0, private');
    header('Pragma: no-cache');
}
$txnPrintDoc = hms_billing_take_print_prompt();
include __DIR__ . '/header.php';

$monthLabels = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
$chartInprogress = [];
$chartCompleted = [];
for ($i = 1; $i <= 12; $i++) {
    $chartInprogress[] = $monthlyData[$i]['inprogress'];
    $chartCompleted[] = $monthlyData[$i]['completed'];
}
?>
        <div class="page-wrapper">
            <div class="content hms-module hms-txn-page">
                <div class="d-flex flex-wrap align-items-start justify-content-between mb-3">
                    <div>
                        <h1 class="hms-appts-dreams-title mb-1">Transactions</h1>
                        <nav aria-label="breadcrumb" class="mb-0">
                            <ol class="breadcrumb bg-transparent px-0 py-0 mb-0">
                                <li class="breadcrumb-item"><a href="dashboard.php">Home</a></li>
                                <li class="breadcrumb-item active" aria-current="page">Transactions</li>
                            </ol>
                        </nav>
                    </div>
                    <div class="d-flex flex-wrap align-items-center no-print">
                        <button type="button" class="btn btn-light border rounded-circle hms-visits-icon-btn mr-1" onclick="window.location.reload()" title="Refresh"><i class="fa fa-refresh"></i></button>
                        <button type="button" class="btn btn-light border rounded-circle hms-visits-icon-btn mr-1" onclick="window.print()" title="Print"><i class="fa fa-print"></i></button>
                    </div>
                </div>
                <?php if ($tableOk) { ?>
                <p class="small text-muted no-print mb-3">This list is filled automatically when patient <strong>receipts</strong> are issued (consultation fees, lab, pharmacy, posted charges, etc.). There is no manual entry here.</p>
                <?php } ?>

                <?php if ($txnFlash !== '') { ?>
                <div class="alert alert-info border-0 shadow-sm"><?php echo hms_h($txnFlash); ?></div>
                <?php } ?>
                <?php if ($txnPrintDoc > 0) { ?>
                <div class="alert alert-success border-0 shadow-sm no-print">
                    Payment receipt is ready.
                    <a class="alert-link font-weight-bold" target="_blank" href="billing-document-pdf.php?id=<?php echo (int) $txnPrintDoc; ?>">Download PDF</a>
                    <span class="small">(</span><a class="alert-link small" target="_blank" href="billing-document-print.php?id=<?php echo (int) $txnPrintDoc; ?>">HTML</a><span class="small">)</span>
                </div>
                <iframe title="Receipt PDF" style="position:absolute;width:0;height:0;border:0;clip:rect(0,0,0,0)" src="billing-document-pdf.php?id=<?php echo (int) $txnPrintDoc; ?>"></iframe>
                <?php } ?>

                <?php if (!$tableOk) { ?>
                <div class="alert alert-warning border-0 shadow-sm">
                    Transactions table not found. Run migration <code>014_transactions_table.sql</code> from <a href="platform-overview.php">Help &amp; setup</a>.
                </div>
                <?php } else { ?>

                <!-- Stat Cards -->
                <div class="row mb-4">
                    <?php
                    $cards = [
                        ['label' => 'Total Transactions', 'amount' => $statTotal, 'trend' => '', 'color' => ''],
                        ['label' => 'Last Month', 'amount' => $statLastMonth, 'trend' => '', 'color' => ''],
                        ['label' => 'This Month', 'amount' => $statThisMonth, 'trend' => 'up', 'color' => '#28a745'],
                        ['label' => 'Last Week', 'amount' => $statLastWeek, 'trend' => 'down', 'color' => '#dc3545'],
                        ['label' => 'This Week', 'amount' => $statThisWeek, 'trend' => 'up', 'color' => '#28a745'],
                        ['label' => 'Today', 'amount' => $statToday, 'trend' => 'up', 'color' => '#28a745'],
                    ];
                    foreach ($cards as $c) {
                        $icon = '';
                        if ($c['trend'] === 'up') {
                            $icon = '<span class="ml-1" style="color:' . $c['color'] . '"><i class="fa fa-circle" style="font-size:8px"></i></span>';
                        } elseif ($c['trend'] === 'down') {
                            $icon = '<span class="ml-1" style="color:' . $c['color'] . '"><i class="fa fa-circle" style="font-size:8px"></i></span>';
                        }
                        ?>
                    <div class="col-xl-2 col-lg-4 col-md-4 col-sm-6 mb-3">
                        <div class="card border-0 shadow-sm h-100 hms-txn-stat-card">
                            <div class="card-body text-center py-3">
                                <div class="hms-txn-stat-label text-muted small mb-1"><?php echo hms_h($c['label']); ?></div>
                                <div class="hms-txn-stat-value font-weight-bold" style="color:#1b2559;font-size:1.1rem;">
                                    <?php echo hms_h(hms_format_xaf($c['amount'])); ?><?php echo $icon; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                        <?php
                    }
                    ?>
                </div>

                <!-- Monthly Chart -->
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white d-flex align-items-center justify-content-between py-3 border-bottom-0">
                        <h2 class="h6 font-weight-bold mb-0 text-dark">Transactions</h2>
                        <div class="d-flex align-items-center no-print">
                            <i class="fa fa-calendar mr-1 text-muted small"></i>
                            <select class="form-control form-control-sm" style="width:auto" onchange="window.location='transactions.php?year='+this.value+'&sort=<?php echo hms_h($sort); ?>'">
                                <?php for ($y = (int) date('Y'); $y >= 2020; $y--) { ?>
                                <option value="<?php echo $y; ?>"<?php echo $y === $yearFilter ? ' selected' : ''; ?>><?php echo $y; ?></option>
                                <?php } ?>
                            </select>
                        </div>
                    </div>
                    <div class="card-body pt-0">
                        <canvas id="hmsTxnChart" height="85"></canvas>
                        <div class="d-flex justify-content-center mt-2">
                            <span class="mr-3 small"><span style="display:inline-block;width:12px;height:12px;background:#28a745;border-radius:2px;margin-right:4px;vertical-align:middle"></span> Inprogress</span>
                            <span class="small"><span style="display:inline-block;width:12px;height:12px;background:#e67c49;border-radius:2px;margin-right:4px;vertical-align:middle"></span> Completed</span>
                        </div>
                    </div>
                </div>

                <!-- Transactions Table -->
                <section class="card border-0 shadow-sm mb-3">
                    <div class="card-header bg-white d-flex flex-wrap align-items-center justify-content-between py-3 border-bottom-0">
                        <div class="d-flex align-items-center mb-2 mb-md-0">
                            <h2 class="h6 font-weight-bold mb-0 text-dark mr-2">Total Transactions</h2>
                            <span class="badge badge-primary" style="font-size:.75rem"><?php echo (int) $total; ?></span>
                        </div>
                        <form method="get" class="form-inline no-print mb-0" action="transactions.php">
                            <input type="hidden" name="year" value="<?php echo (int) $yearFilter; ?>">
                            <input type="hidden" name="p" value="1">
                            <label class="small text-muted mr-1 mb-0">Sort By :</label>
                            <select name="sort" class="form-control form-control-sm" onchange="this.form.submit()">
                                <option value="newest"<?php echo $sort === 'newest' ? ' selected' : ''; ?>>Newest</option>
                                <option value="oldest"<?php echo $sort === 'oldest' ? ' selected' : ''; ?>>Oldest</option>
                            </select>
                        </form>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0 hms-txn-table">
                                <thead>
                                    <tr class="text-uppercase small text-muted" style="background:#f8f9fd">
                                        <th style="width:30px"><input type="checkbox" disabled></th>
                                        <th>ID</th>
                                        <th>Patient Name</th>
                                        <th>Description</th>
                                        <th>Transaction Date</th>
                                        <th>Amount</th>
                                        <th>Payment Method</th>
                                        <th>Status</th>
                                        <th style="width:40px"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                <?php if ($rows === []) { ?>
                                    <tr><td colspan="9" class="text-center text-muted py-5">No transactions yet. Completed patient payments will appear here after receipts are issued elsewhere in the app.</td></tr>
                                <?php } ?>
                                <?php foreach ($rows as $r) {
                                    $tid = (int) $r['id'];
                                    $tidLabel = '#TS' . str_pad((string) $tid, 4, '0', STR_PAD_LEFT);
                                    $pname = trim((string) ($r['first_name'] ?? '') . ' ' . (string) ($r['last_name'] ?? ''));
                                    if ($pname === '') $pname = 'Unknown';
                                    $pinit = strtoupper(substr((string) ($r['first_name'] ?? '?'), 0, 1) . substr((string) ($r['last_name'] ?? ''), 0, 1));
                                    $desc = (string) ($r['description'] ?? '—');
                                    $tdate = (string) ($r['transaction_date'] ?? '');
                                    $tdateShow = $tdate !== '' ? date('d M Y', strtotime($tdate)) : '—';
                                    $amt = (float) ($r['amount'] ?? 0);
                                    $payMethod = (string) ($r['payment_method'] ?? 'Cash');
                                    $status = (string) ($r['status'] ?? 'pending');
                                    $pillClass = $status === 'completed' ? 'hms-visit-pill--completed' : ($status === 'cancelled' ? 'hms-visit-pill--cancelled' : 'hms-visit-pill--pending');
                                    $pillLabel = ucfirst($status);
                                    ?>
                                    <tr>
                                        <td class="align-middle"><input type="checkbox" disabled></td>
                                        <td class="align-middle font-weight-bold text-nowrap"><?php echo hms_h($tidLabel); ?></td>
                                        <td class="align-middle">
                                            <div class="d-flex align-items-center">
                                                <span class="hms-visit-avatar hms-visit-avatar--patient hms-visit-avatar--sm mr-2"><?php echo hms_h($pinit); ?></span>
                                                <span class="font-weight-bold text-dark"><?php echo hms_h($pname); ?></span>
                                            </div>
                                        </td>
                                        <td class="align-middle text-muted small"><?php echo hms_h($desc); ?></td>
                                        <td class="align-middle text-muted small text-nowrap"><?php echo hms_h($tdateShow); ?></td>
                                        <td class="align-middle font-weight-bold text-nowrap"><?php echo hms_h(hms_format_xaf($amt)); ?></td>
                                        <td class="align-middle small"><?php echo hms_h($payMethod); ?></td>
                                        <td class="align-middle"><span class="hms-visit-pill <?php echo hms_h($pillClass); ?>"><?php echo hms_h($pillLabel); ?></span></td>
                                        <td class="align-middle text-right">
                                            <div class="dropdown"><button class="btn btn-link text-muted p-0" data-toggle="dropdown"><i class="fa fa-ellipsis-v"></i></button>
                                                <div class="dropdown-menu dropdown-menu-right shadow border-0">
                                                    <a class="dropdown-item small" href="#">View details</a>
                                                </div>
                                            </div>
                                        </td>
                                    </tr>
                                <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                    <?php if ($totalPages > 1) { ?>
                    <div class="card-footer bg-white border-top-0 py-3 no-print">
                        <nav aria-label="Transactions pagination">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <?php
                                $mk = static function (int $p) use ($sort, $yearFilter): string {
                                    return 'transactions.php?' . http_build_query(['year' => $yearFilter, 'sort' => $sort, 'p' => $p]);
                                };
                                $prev = max(1, $page - 1);
                                $next = min($totalPages, $page + 1);
                                ?>
                                <li class="page-item<?php echo $page <= 1 ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page <= 1 ? '#' : hms_h($mk($prev)); ?>">Prev</a></li>
                                <?php
                                $window = 5;
                                $start = max(1, $page - 2);
                                $end = min($totalPages, $start + $window - 1);
                                $start = max(1, $end - $window + 1);
                                for ($pi = $start; $pi <= $end; $pi++) { ?>
                                <li class="page-item<?php echo $pi === $page ? ' active' : ''; ?>"><a class="page-link" href="<?php echo hms_h($mk($pi)); ?>"><?php echo $pi; ?></a></li>
                                <?php } ?>
                                <li class="page-item<?php echo $page >= $totalPages ? ' disabled' : ''; ?>"><a class="page-link" href="<?php echo $page >= $totalPages ? '#' : hms_h($mk($next)); ?>">Next</a></li>
                            </ul>
                        </nav>
                    </div>
                    <?php } ?>
                </section>

                <?php } /* end tableOk */ ?>
            </div>
        </div>
        <script>
        (function () {
            if (typeof Chart === 'undefined') return;
            var ctx = document.getElementById('hmsTxnChart');
            if (!ctx) return;
            new Chart(ctx.getContext('2d'), {
                type: 'bar',
                data: {
                    labels: <?php echo json_encode($monthLabels); ?>,
                    datasets: [
                        {
                            label: 'Inprogress',
                            data: <?php echo json_encode($chartInprogress); ?>,
                            backgroundColor: '#28a745',
                            borderRadius: 4,
                            barPercentage: 0.6,
                            categoryPercentage: 0.5
                        },
                        {
                            label: 'Completed',
                            data: <?php echo json_encode($chartCompleted); ?>,
                            backgroundColor: '#e67c49',
                            borderRadius: 4,
                            barPercentage: 0.6,
                            categoryPercentage: 0.5
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: true,
                    scales: {
                        xAxes: [{gridLines:{display:false}}],
                        yAxes: [{ticks:{beginAtZero:true,precision:0},gridLines:{color:'#f0f0f0'}}]
                    },
                    legend: {display: false},
                    tooltips: {mode: 'index', intersect: false}
                }
            });
        })();
        </script>
<?php include 'footer.php'; ?>
