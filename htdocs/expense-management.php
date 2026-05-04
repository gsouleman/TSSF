<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
if (!function_exists('hms_expenses_can_read') || !hms_expenses_can_read($connection)) {
    http_response_code(403);
    exit('Forbidden.');
}

$fid = hms_current_facility_id();
$tableOk = function_exists('hms_expenses_ready') && hms_expenses_ready($connection);
$rows = [];
$err = '';

if ($tableOk && function_exists('hms_expense_rows_for_facility')) {
    $pack = hms_expense_rows_for_facility($connection, $fid);
    $rows = $pack['rows'];
    if (!$pack['ok']) {
        $err = $pack['error'] !== '' ? $pack['error'] : 'Could not load expenses.';
    }
}

$extra_footer_html = <<<'HTML'
<style>
.hms-modern-card {
    background: #fff;
    border-radius: 12px;
    border: none;
    box-shadow: 0 5px 15px rgba(0,0,0,0.05);
    overflow: hidden;
}
.hms-modern-card .card-body {
    padding: 1.5rem;
}
.hms-modern-card .table {
    margin-bottom: 0;
}
.hms-modern-card .table thead th {
    background-color: #f8f9fa;
    border-top: none;
    border-bottom: 2px solid #e9ecef;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.8rem;
    letter-spacing: 0.5px;
    color: #495057;
    padding: 12px 15px;
}
.hms-modern-card .table td {
    vertical-align: middle;
    padding: 12px 15px;
    border-top: 1px solid #f1f3f5;
}
.hms-modern-card .table tbody tr:hover {
    background-color: #f8f9fa;
}
.dt-buttons .btn {
    border-radius: 6px;
    font-weight: 500;
    margin-right: 5px;
    padding: 6px 14px;
    transition: all 0.2s;
    background-color: #fff;
    border: 1px solid #ddd;
    color: #555;
    margin-bottom: 10px;
}
.dt-buttons .btn:hover {
    background-color: #f1f3f5;
    box-shadow: 0 4px 6px rgba(0,0,0,0.05);
    transform: translateY(-1px);
}
.dataTables_wrapper .dataTables_filter input {
    border-radius: 20px;
    padding: 6px 16px;
    border: 1px solid #ced4da;
    outline: none;
    transition: border-color .15s ease-in-out,box-shadow .15s ease-in-out;
}
.dataTables_wrapper .dataTables_filter input:focus {
    border-color: #80bdff;
    box-shadow: 0 0 0 0.2rem rgba(0,123,255,.25);
}
.dataTables_wrapper .row {
    align-items: center;
}
.dataTables_wrapper .dataTables_info {
    padding-top: 0.85em;
    color: #6c757d;
}
</style>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/dataTables.buttons.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/jszip/3.1.3/jszip.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/pdfmake.min.js"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/pdfmake/0.1.53/vfs_fonts.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.html5.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.print.min.js"></script>
<script src="https://cdn.datatables.net/buttons/2.3.6/js/buttons.bootstrap4.min.js"></script>
<script>
$(document).ready(function() {
    $.fn.dataTable.ext.search.push(
        function( settings, data, dataIndex ) {
            if (settings.nTable.id !== 'expensesTable') {
                return true;
            }

            // Parse Date
            var minDateStr = $('#minDate').val();
            var maxDateStr = $('#maxDate').val();
            var rowDateStr = (data[0] || '').split(' ')[0];
            var minDate = minDateStr ? new Date(minDateStr) : null;
            var maxDate = maxDateStr ? new Date(maxDateStr) : null;
            var rowDate = new Date(rowDateStr);

            if (minDate && rowDate < minDate) {
                return false;
            }
            if (maxDate && rowDate > maxDate) {
                return false;
            }

            // Parse Amount
            var minAmtStr = $('#minAmount').val();
            var maxAmtStr = $('#maxAmount').val();
            var minAmt = minAmtStr !== '' ? parseFloat(minAmtStr) : null;
            var maxAmt = maxAmtStr !== '' ? parseFloat(maxAmtStr) : null;
            
            var rowNode = settings.aoData[dataIndex].nTr;
            var amountStr = $(rowNode).find('td:eq(3)').attr('data-amount');
            var rowAmt = parseFloat(amountStr);
            if (isNaN(rowAmt)) rowAmt = 0;

            if (minAmt !== null && rowAmt < minAmt) {
                return false;
            }
            if (maxAmt !== null && rowAmt > maxAmt) {
                return false;
            }

            return true;
        }
    );

    if ($.fn.DataTable.isDataTable('#expensesTable')) {
        $('#expensesTable').DataTable().destroy();
    }
    var table = $('#expensesTable').DataTable({
        "order": [[ 0, "desc" ]],
        "pageLength": 25,
        "language": {
            "search": "",
            "searchPlaceholder": "Search expenses..."
        },
        "dom": '<"row mb-3"<"col-sm-12 col-md-6"B><"col-sm-12 col-md-6 text-right"f>>' +
               '<"row"<"col-sm-12"tr>>' +
               '<"row mt-3"<"col-sm-12 col-md-5"i><"col-sm-12 col-md-7"p>>',
        "buttons": [
            { extend: 'copyHtml5', className: 'btn btn-sm', text: '<i class="fa fa-copy text-muted"></i> Copy' },
            { extend: 'csvHtml5', className: 'btn btn-sm', text: '<i class="fa fa-file-text-o text-info"></i> CSV' },
            { extend: 'excelHtml5', className: 'btn btn-sm', text: '<i class="fa fa-file-excel-o text-success"></i> Excel' },
            { extend: 'pdfHtml5', className: 'btn btn-sm', text: '<i class="fa fa-file-pdf-o text-danger"></i> PDF', orientation: 'landscape', pageSize: 'A4' },
            { extend: 'print', className: 'btn btn-sm', text: '<i class="fa fa-print text-primary"></i> Print' }
        ]
    });

    $('#minDate, #maxDate, #minAmount, #maxAmount').on('change keyup', function() {
        table.draw();
    });

    $('#clearFilters').on('click', function(e) {
        e.preventDefault();
        $('#minDate, #maxDate, #minAmount, #maxAmount').val('');
        table.search('');
        table.draw();
    });
});
</script>
HTML;

include 'header.php';
?>
        <div class="page-wrapper"><div class="content hms-module hms-accounting">
            <?php
            hms_ui_page_header('Expense management', [
                'subtitle' => 'Record facility operating expenses (supplies, utilities, external services).',
                'breadcrumbs' => [['Accounting', null], ['Expenses', '']],
                'back' => 'billing-payments.php',
                'primary' => ($tableOk && function_exists('hms_expenses_can_write') && hms_expenses_can_write($connection))
                    ? ['label' => 'New expense', 'url' => 'expense-management-new.php', 'icon' => 'fa-plus']
                    : null,
            ]);
            ?>
            <?php if (!$tableOk) { ?>
            <div class="alert alert-warning">
                The expenses table is missing. Run <code>hms/database/migrations/026_expense_management.sql</code> in phpMyAdmin, then reload this page.
            </div>
            <?php } elseif ($err !== '') { ?>
            <div class="alert alert-danger"><?php echo hms_h($err); ?></div>
            <?php } else { ?>
            <div class="card hms-modern-card mb-4">
                <div class="card-body">
                    <?php if ($rows === []) { ?>
                    <div class="text-center py-5">
                        <i class="fa fa-file-text-o fa-3x text-light mb-3"></i>
                        <p class="text-muted mb-0">No expenses recorded yet.</p>
                    </div>
                    <?php } else { ?>
                    <div class="row align-items-end mb-4 bg-light p-3 rounded border">
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="font-weight-bold small text-muted">From Date</label>
                            <input type="date" id="minDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-3 col-sm-6 mb-2 mb-md-0">
                            <label class="font-weight-bold small text-muted">To Date</label>
                            <input type="date" id="maxDate" class="form-control form-control-sm">
                        </div>
                        <div class="col-md-4 col-sm-6 mb-2 mb-md-0">
                            <label class="font-weight-bold small text-muted">Amount Range (FCFA)</label>
                            <div class="input-group input-group-sm">
                                <input type="number" id="minAmount" class="form-control" placeholder="Min">
                                <div class="input-group-prepend input-group-append"><span class="input-group-text border-left-0 border-right-0">-</span></div>
                                <input type="number" id="maxAmount" class="form-control" placeholder="Max">
                            </div>
                        </div>
                        <div class="col-md-2 col-sm-6">
                            <button id="clearFilters" class="btn btn-sm btn-outline-secondary w-100">Clear</button>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="expensesTable" class="table table-hover w-100">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Category</th>
                                    <th>Description</th>
                                    <th class="text-right">Amount</th>
                                    <th>Payment</th>
                                    <th>Vendor / ref.</th>
                                    <th>Recorded</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($rows as $r) {
                                    $amt = (int) ($r['amount_xaf'] ?? 0);
                                    $ref = trim((string) ($r['reference'] ?? ''));
                                    $ven = trim((string) ($r['vendor'] ?? ''));
                                    $vr = $ven !== '' ? $ven : '';
                                    if ($ref !== '') {
                                        $vr = $vr !== '' ? $vr . ' · ' . $ref : $ref;
                                    }
                                    $dShow = trim((string) ($r['expense_date'] ?? ''));
                                    if ($dShow === '') {
                                        $dShow = trim((string) ($r['created_at'] ?? ''));
                                    }
                                    ?>
                                <tr>
                                    <td class="text-nowrap"><?php echo hms_h($dShow !== '' ? $dShow : '—'); ?></td>
                                    <td><?php echo hms_h((string) ($r['category'] ?? '')); ?></td>
                                    <td class="small"><?php echo hms_h((string) ($r['description'] ?? '')); ?></td>
                                    <td class="text-right text-nowrap" data-amount="<?php echo hms_h((string)$amt); ?>"><?php echo hms_h(hms_format_xaf((float) $amt)); ?></td>
                                    <td class="small"><?php echo hms_h((string) ($r['payment_method'] ?? '')); ?></td>
                                    <td class="small"><?php echo hms_h($vr); ?></td>
                                    <td class="small text-muted text-nowrap"><?php echo hms_h((string) ($r['created_at'] ?? '')); ?>
                                        <?php if (trim((string) ($r['created_by_name'] ?? '')) !== '') { ?>
                                        <span class="d-block"><?php echo hms_h((string) $r['created_by_name']); ?></span>
                                        <?php } ?>
                                    </td>
                                </tr>
                                <?php } ?>
                            </tbody>
                        </table>
                    </div>
                    <?php } ?>
                </div>
            </div>
            <?php } ?>
        </div></div>
<?php include 'footer.php'; ?>
