<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/billing_document_html.php';
require_once __DIR__ . '/includes/billing_document_pdf.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
$canPrintDoc = (string) ($_SESSION['role'] ?? '') === '1'
    || hms_can($connection, 'billing.read')
    || hms_can($connection, 'billing.write')
    || hms_can($connection, 'cashier.write');
if (!$canPrintDoc) {
    http_response_code(403);
    exit('Forbidden');
}

$fid = hms_current_facility_id();
$id = (int) ($_GET['id'] ?? 0);
$autoPdf = isset($_GET['autopdf']);
$data = $id > 0 ? hms_billing_get_document_with_lines($connection, $id, $fid) : null;
if (!$data) {
    http_response_code(404);
    echo 'Document not found.';
    exit;
}
$doc = $data['doc'];
$lines = $data['lines'];

$facName = '';
$facAddr = '';
$fq = mysqli_query($connection, 'SELECT name, address FROM tbl_facility WHERE id = ' . (int) $fid . ' LIMIT 1');
if ($fq && $fr = mysqli_fetch_assoc($fq)) {
    $facName = (string) ($fr['name'] ?? '');
    $facAddr = (string) ($fr['address'] ?? '');
}

if ($autoPdf && hms_billing_pdf_available()) {
    $html = hms_billing_document_full_html($doc, $lines, $facName, $facAddr, false);
    $pdf = hms_billing_html_to_pdf_bytes($html);
    if ($pdf !== false) {
        $fname = hms_billing_document_pdf_filename($doc);
        header('Content-Type: application/pdf');
        header('Content-Disposition: inline; filename="' . str_replace('"', '', $fname) . '"');
        header('Cache-Control: private, max-age=0, must-revalidate');
        header('Content-Length: ' . (string) strlen($pdf));
        echo $pdf;
        exit;
    }
}

echo hms_billing_document_full_html($doc, $lines, $facName, $facAddr, true);

$legacyPrint = isset($_GET['autoprint']);
if ($legacyPrint) {
    echo '<script>window.onload=function(){window.print();};</script>';
}
