<?php
declare(strict_types=1);

/**
 * HTML for billing receipt / invoice (print view and PDF).
 *
 * @param array<string,mixed> $doc
 * @param list<array<string,mixed>> $lines
 */
function hms_billing_document_full_html(
    array $doc,
    array $lines,
    string $facName,
    string $facAddr,
    bool $includeNoPrintControls
): string {
    $isInv = ($doc['doc_type'] ?? '') === 'invoice';
    $title = $isInv ? 'Invoice' : 'Receipt';
    $docNum = (string) ($doc['doc_number'] ?? '');
    $created = (string) ($doc['created_at'] ?? '');
    $payM = trim((string) ($doc['payment_method'] ?? ''));
    $companySnap = (string) ($doc['company_snapshot'] ?? '');
    $payerSnap = (string) ($doc['payer_snapshot'] ?? '');
    $notes = (string) ($doc['notes'] ?? '');
    $tax = (float) ($doc['tax_amount'] ?? 0);
    $total = (float) ($doc['total_amount'] ?? 0);
    $srcMod = (string) ($doc['source_module'] ?? '');
    $srcPk = (int) ($doc['source_pk'] ?? 0);
    $paymentTicketCode = trim((string) ($doc['payment_ticket_code'] ?? ''));
    if ($paymentTicketCode === '' && $srcMod === 'payment_ticket' && $notes !== '' && preg_match('/Payment code:\s*([A-Za-z0-9\-]+)/i', $notes, $pm)) {
        $paymentTicketCode = trim((string) ($pm[1]));
    }

    $brand = $facName !== '' ? $facName : (function_exists('hms_default_primary_facility_name') ? hms_default_primary_facility_name() : 'TSSF Solidarity of Hearts Hospital SOA');
    $controls = '';
    if ($includeNoPrintControls) {
        $controls = '<div class="no-print">'
            . '<a class="btn" href="billing-document-pdf.php?id=' . (int) ($doc['id'] ?? 0) . '">Download PDF</a> '
            . '<button type="button" class="btn" onclick="window.print()">Print</button> '
            . '<a href="billing-payments.php">Back to billing</a>'
            . '</div>';
    }

    ob_start();
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title><?php echo hms_h($title . ' ' . $docNum); ?></title>
    <style>
        body { font-family: Helvetica, Arial, sans-serif; color: #111; margin: 24px; font-size: 12px; }
        .hms-doc-head { border-bottom: 2px solid #0c8b8b; padding-bottom: 12px; margin-bottom: 16px; }
        .hms-doc-head h1 { margin: 0 0 4px; font-size: 16px; }
        .muted { color: #555; font-size: 11px; }
        table.lines { width: 100%; border-collapse: collapse; margin-top: 12px; }
        table.lines th, table.lines td { border: 1px solid #ccc; padding: 6px 8px; text-align: left; }
        table.lines th { background: #f4f6f9; }
        .text-right { text-align: right; }
        .tot { margin-top: 12px; text-align: right; font-size: 13px; font-weight: 700; }
        .no-print { margin: 12px 0; }
        .btn { display: inline-block; padding: 6px 12px; margin-right: 8px; background: #0c8b8b; color: #fff; text-decoration: none; border: 0; border-radius: 4px; font-size: 12px; cursor: pointer; }
        .hms-pay-code { margin: 14px 0; padding: 12px 14px; border: 2px dashed #0c8b8b; background: #f0faf9; border-radius: 6px; }
        .hms-pay-code .code { font-size: 18px; font-weight: 700; letter-spacing: 0.04em; font-family: Consolas, Menlo, monospace; color: #0a5c5c; }
        @media print { .no-print { display: none !important; } body { margin: 12px; } }
    </style>
</head>
<body>
    <?php echo $controls; ?>
    <div class="hms-doc-head">
        <h1><?php echo hms_h($brand); ?></h1>
        <?php if ($facAddr !== '') { ?><div class="muted"><?php echo nl2br(hms_h($facAddr)); ?></div><?php } ?>
        <div style="margin-top:8px"><strong><?php echo hms_h($title); ?></strong> · <?php echo hms_h($docNum); ?></div>
        <div class="muted">Issued <?php echo hms_h($created); ?>
            <?php if ($payM !== '') { ?> · Payment: <?php echo hms_h($payM); ?><?php } ?>
        </div>
    </div>
    <?php if ($isInv) { ?>
        <p><strong>Bill to (company):</strong> <?php echo hms_h($companySnap !== '' ? $companySnap : '—'); ?></p>
    <?php } ?>
    <?php if ($payerSnap !== '') { ?>
        <p><strong>Patient / payer:</strong> <?php echo hms_h($payerSnap); ?></p>
    <?php } ?>
    <?php if ($paymentTicketCode !== '') { ?>
    <div class="hms-pay-code">
        <div class="muted" style="margin-bottom:4px">Payment code (give to staff / use at consultation)</div>
        <div class="code"><?php echo hms_h($paymentTicketCode); ?></div>
    </div>
    <?php } ?>
    <?php if ($notes !== '') { ?>
        <p class="muted"><?php echo hms_h($notes); ?></p>
    <?php } ?>
    <?php
    $opdEp = (int) ($doc['opd_visit_id'] ?? 0);
    $faEp = (int) ($doc['facility_admission_id'] ?? 0);
    $hzEp = (int) ($doc['hospitalization_id'] ?? 0);
    if ($opdEp > 0 || $faEp > 0 || $hzEp > 0) {
        $bits = [];
        if ($opdEp > 0) {
            $bits[] = 'OPD visit #' . $opdEp;
        }
        if ($faEp > 0) {
            $bits[] = 'Facility admission (arrival) #' . $faEp;
        }
        if ($hzEp > 0) {
            $bits[] = 'Hospitalization / bed stay #' . $hzEp;
        }
        ?>
    <p class="muted"><strong>Billing context:</strong> <?php echo hms_h(implode(' · ', $bits)); ?></p>
    <?php } ?>
    <table class="lines">
        <thead><tr><th>Description</th><th class="text-right">Qty</th><th class="text-right">Unit</th><th class="text-right">Total</th></tr></thead>
        <tbody>
        <?php foreach ($lines as $ln) {
            $q = (float) ($ln['quantity'] ?? 1);
            $u = (float) ($ln['unit_price'] ?? 0);
            $t = (float) ($ln['line_total'] ?? 0);
            ?>
            <tr>
                <td><?php echo hms_h((string) ($ln['description'] ?? '')); ?></td>
                <td class="text-right"><?php echo hms_h((string) $q); ?></td>
                <td class="text-right"><?php echo hms_h(hms_format_xaf($u, false)); ?></td>
                <td class="text-right"><?php echo hms_h(hms_format_xaf($t, false)); ?></td>
            </tr>
        <?php } ?>
        </tbody>
    </table>
    <?php if ($tax > 0.0001) { ?>
        <div class="tot">Tax: <?php echo hms_h(hms_format_xaf($tax)); ?></div>
    <?php } ?>
    <div class="tot">Total: <?php echo hms_h(hms_format_xaf($total)); ?></div>
    <p class="muted" style="margin-top:24px">
        <?php if ($srcMod === 'payment_ticket' && $paymentTicketCode !== '') { ?>
        Payment ticket · code <?php echo hms_h($paymentTicketCode); ?>
        <?php } else { ?>
        Source: <?php echo hms_h($srcMod); ?> #<?php echo (int) $srcPk; ?>
        <?php } ?>
    </p>
</body>
</html>
    <?php

    return (string) ob_get_clean();
}

/**
 * Safe ASCII-ish filename for Content-Disposition.
 */
function hms_billing_document_pdf_filename(array $doc): string
{
    $num = (string) ($doc['doc_number'] ?? 'document');
    $num = preg_replace('/[^A-Za-z0-9._-]+/', '_', $num) ?? 'document';

    return $num . '.pdf';
}

function hms_billing_document_pdf_href(int $documentId): string
{
    return 'billing-document-pdf.php?id=' . $documentId;
}
