<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/clinical_result_report.php';
require_once __DIR__ . '/includes/billing_document_pdf.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

$type = strtolower(trim((string) ($_GET['type'] ?? '')));
$id = (int) ($_GET['id'] ?? 0);
$download = isset($_GET['download']) && (string) $_GET['download'] === '1';

if ($id < 1 || !in_array($type, ['lab', 'rad'], true)) {
    http_response_code(400);
    exit('Invalid request.');
}

$canClinical = hms_can($connection, 'clinical.read');
$canLab = hms_can($connection, 'lab.read');
$canRad = hms_can($connection, 'radiology.read');
if ($type === 'lab' && !$canClinical && !$canLab) {
    http_response_code(403);
    exit('Access denied.');
}
if ($type === 'rad' && !$canClinical && !$canRad) {
    http_response_code(403);
    exit('Access denied.');
}

$fid = hms_current_facility_id();
$ms = hms_multi_site_enabled($connection);

$facilityName = '';
if (function_exists('hms_current_facility_name')) {
    try {
        $facilityName = (string) hms_current_facility_name($connection);
    } catch (Throwable $e) {
        $facilityName = '';
    }
}
$siteLine = $ms ? ('Site ID: ' . (string) $fid) : '';

$row = null;
if ($type === 'lab') {
    if (!hms_db_table_exists($connection, 'tbl_lab_result')) {
        http_response_code(404);
        exit('Lab module unavailable.');
    }
    $sql = 'SELECT lr.*, p.first_name AS p_fn, p.last_name AS p_ln FROM tbl_lab_result lr INNER JOIN tbl_patient p ON p.id = lr.patient_id WHERE lr.id = ?';
    $sql .= $ms ? ' AND lr.facility_id = ? LIMIT 1' : ' LIMIT 1';
    $st = mysqli_prepare($connection, $sql);
    if ($st) {
        if ($ms) {
            mysqli_stmt_bind_param($st, 'ii', $id, $fid);
        } else {
            mysqli_stmt_bind_param($st, 'i', $id);
        }
        mysqli_stmt_execute($st);
        $row = hms_stmt_fetch_assoc($st);
        mysqli_stmt_close($st);
    }
} else {
    if (!hms_db_table_exists($connection, 'tbl_radiology_result')) {
        http_response_code(404);
        exit('Radiology module unavailable.');
    }
    $sql = 'SELECT rr.*, p.first_name AS p_fn, p.last_name AS p_ln FROM tbl_radiology_result rr INNER JOIN tbl_patient p ON p.id = rr.patient_id WHERE rr.id = ?';
    $sql .= $ms ? ' AND rr.facility_id = ? LIMIT 1' : ' LIMIT 1';
    $st = mysqli_prepare($connection, $sql);
    if ($st) {
        if ($ms) {
            mysqli_stmt_bind_param($st, 'ii', $id, $fid);
        } else {
            mysqli_stmt_bind_param($st, 'i', $id);
        }
        mysqli_stmt_execute($st);
        $row = hms_stmt_fetch_assoc($st);
        mysqli_stmt_close($st);
    }
}

if (!$row) {
    http_response_code(404);
    exit('Result not found.');
}

$patient = ['p_fn' => $row['p_fn'] ?? '', 'p_ln' => $row['p_ln'] ?? ''];

if ($type === 'lab') {
    $bodyHtml = hms_clinical_lab_report_html($row, $patient, $facilityName, $siteLine);
    $kind = 'Lab';
    $fnBase = 'lab-result-' . $id . '-' . hms_clinical_result_safe_filename_part((string) ($row['p_ln'] ?? 'patient'));
} else {
    $bodyHtml = hms_clinical_rad_report_html($row, $patient, $facilityName, $siteLine);
    $kind = 'Radiology';
    $fnBase = 'radiology-result-' . $id . '-' . hms_clinical_result_safe_filename_part((string) ($row['p_ln'] ?? 'patient'));
}

$pdfTitle = $kind . ' result #' . $id . ' — ' . hms_clinical_result_patient_line($patient);

if ($download) {
    $htmlDoc = hms_clinical_result_pdf_wrap_html($pdfTitle, $bodyHtml);
    $pdf = hms_billing_html_to_pdf_bytes($htmlDoc);
    if ($pdf === false) {
        http_response_code(503);
        header('Content-Type: text/html; charset=utf-8');
        $viewUrl = 'clinical-result-report.php?' . http_build_query(['type' => $type, 'id' => $id]);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PDF unavailable</title></head><body style="font-family:sans-serif;padding:24px">';
        echo '<h1>PDF engine not available</h1>';
        echo '<p>Install PHP dependencies from the <code>hms</code> folder:</p><pre>composer install</pre>';
        echo '<p>Or open the <a href="' . hms_h($viewUrl) . '">report view</a> and use Print → Save as PDF in your browser.</p>';
        echo '</body></html>';
        exit;
    }
    $fname = str_replace('"', '', $fnBase . '.pdf');
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $fname . '"');
    header('Cache-Control: private, max-age=0, must-revalidate');
    header('Content-Length: ' . (string) strlen($pdf));
    echo $pdf;
    exit;
}

$pageTitle = $kind . ' result — ' . hms_clinical_result_patient_line($patient);
include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <div class="d-flex flex-wrap justify-content-between align-items-center mb-3">
                    <div>
                        <h1 class="h4 mb-0 font-weight-bold"><?php echo hms_h($kind); ?> result</h1>
                        <p class="text-muted small mb-0">Structured template and notes — view, print, or download as PDF.</p>
                    </div>
                    <div class="btn-group mb-2 d-print-none">
                        <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print();"><i class="fa fa-print mr-1"></i>Print</button>
                        <a class="btn btn-outline-primary btn-sm" href="clinical-result-report.php?<?php echo hms_h(http_build_query(['type' => $type, 'id' => $id, 'download' => '1'])); ?>"><i class="fa fa-file-pdf-o mr-1"></i>Download PDF</a>
                    </div>
                </div>
                <div class="card border-0 shadow-sm">
                    <div class="card-body" id="hmsClinicalReportPrint">
                        <?php echo $bodyHtml; ?>
                        <p class="small text-muted border-top pt-3 mt-3 mb-0">Generated <?php echo hms_h(date('c')); ?> — HMS clinical result report.</p>
                    </div>
                </div>
                <p class="mt-3 mb-0 d-print-none"><a href="javascript:history.back();" class="btn btn-light border">&larr; Back</a></p>
            </div>
        </div>
        <style media="print">
            .hms-topbar, .sidebar, .d-print-none { display: none !important; }
            #hmsClinicalReportPrint { box-shadow: none !important; border: none !important; }
        </style>
<?php include __DIR__ . '/footer.php'; ?>
