<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/billing_document_html.php';
require_once __DIR__ . '/includes/billing_document_pdf.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'billing.read');

$fid = hms_current_facility_id();
$id = (int) ($_GET['id'] ?? 0);
$inline = isset($_GET['inline']) && (string) $_GET['inline'] === '1';

$data = $id > 0 ? hms_billing_get_document_with_lines($connection, $id, $fid) : null;
if (!$data) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
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

$html = hms_billing_document_full_html($doc, $lines, $facName, $facAddr, false);
$pdf = hms_billing_html_to_pdf_bytes($html);

if ($pdf === false) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    $printUrl = 'billing-document-print.php?id=' . $id;
    echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>PDF unavailable</title></head><body style="font-family:sans-serif;padding:24px">';
    echo '<h1>PDF engine not installed</h1>';
    echo '<p>Install dependencies from the <code>hms</code> folder:</p><pre>composer install</pre>';
    echo '<p>Or use the <a href="' . hms_h($printUrl) . '">HTML print view</a> and choose &ldquo;Save as PDF&rdquo; in your browser.</p>';
    echo '</body></html>';
    exit;
}

$fname = hms_billing_document_pdf_filename($doc);
$disp = $inline ? 'inline' : 'attachment';

header('Content-Type: application/pdf');
header('Content-Disposition: ' . $disp . '; filename="' . str_replace('"', '', $fname) . '"');
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: public');
header('Content-Length: ' . (string) strlen($pdf));

echo $pdf;
