<?php
declare(strict_types=1);

/**
 * Helpers for clinical result template view/download (lab + radiology).
 * Requires bootstrap (result_workflow.php for summary builders).
 */

/**
 * Human-readable label for stored conclusion_code values.
 */
function hms_clinical_conclusion_display(?string $code): string
{
    $c = strtolower(trim((string) $code));
    $map = [
        'negative' => 'Negative / within reference',
        'positive' => 'Positive / abnormal',
        'inconclusive' => 'Inconclusive',
        'other' => 'Other (see notes)',
    ];

    return $map[$c] ?? ($c !== '' ? $c : '—');
}

/**
 * @param array<string,mixed> $patient
 */
function hms_clinical_result_patient_line(array $patient): string
{
    $fn = trim((string) ($patient['p_fn'] ?? $patient['first_name'] ?? ''));
    $ln = trim((string) ($patient['p_ln'] ?? $patient['last_name'] ?? ''));

    return trim($fn . ' ' . $ln);
}

/**
 * @param array<string,mixed> $row lab row with optional p_fn, p_ln from join
 */
function hms_clinical_lab_report_plaintext(array $row): string
{
    $tplJson = (string) ($row['result_template_json'] ?? '');
    $conc = (string) ($row['conclusion_code'] ?? '');
    $body = hms_lab_template_summary_text($tplJson, $conc);
    $lines = [
        '=== Laboratory result ===',
        'Test: ' . trim((string) ($row['test_name'] ?? '')),
        'Date: ' . trim((string) ($row['appointment_date'] ?? '')),
        'Status: ' . trim((string) ($row['status'] ?? '')),
        '',
    ];
    if ($body !== '') {
        $lines[] = $body;
        $lines[] = '';
    }
    $notes = trim((string) ($row['notes'] ?? ''));
    if ($notes !== '') {
        $lines[] = 'Laboratory notes:';
        $lines[] = $notes;
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * @param array<string,mixed> $row radiology row
 */
function hms_clinical_rad_report_plaintext(array $row): string
{
    $tplJson = (string) ($row['result_template_json'] ?? '');
    $findings = (string) ($row['findings'] ?? '');
    $conc = (string) ($row['conclusion_code'] ?? '');
    $body = hms_rad_template_summary_text($tplJson, $findings, $conc);
    $lines = [
        '=== Radiology / imaging report ===',
        'Exam: ' . trim((string) ($row['exam_name'] ?? '')),
        'Modality: ' . trim((string) ($row['modality'] ?? '')),
        'Body part: ' . trim((string) ($row['body_part'] ?? '')),
        'Date: ' . trim((string) ($row['appointment_date'] ?? '')),
        'Status: ' . trim((string) ($row['status'] ?? '')),
        '',
    ];
    if ($body !== '') {
        $lines[] = $body;
        $lines[] = '';
    }
    $notes = trim((string) ($row['notes'] ?? ''));
    if ($notes !== '') {
        $lines[] = 'Additional notes:';
        $lines[] = $notes;
        $lines[] = '';
    }

    return implode("\n", $lines);
}

/**
 * @param array<string,mixed> $row
 * @param array<string,mixed> $patient
 */
function hms_clinical_lab_report_html(array $row, array $patient, string $facilityName, string $siteLine): string
{
    $name = hms_clinical_result_patient_line($patient);
    $tplJson = (string) ($row['result_template_json'] ?? '');
    $conc = (string) ($row['conclusion_code'] ?? '');
    $summaryBlock = nl2br(hms_h(hms_lab_template_summary_text($tplJson, $conc)));
    $notes = trim((string) ($row['notes'] ?? ''));

    ob_start();
    ?>
    <section class="hms-result-report">
        <h2 class="h5 border-bottom pb-2 mb-3">Laboratory result</h2>
        <?php if ($facilityName !== '') { ?><p class="small text-muted mb-1"><?php echo hms_h($facilityName); ?></p><?php } ?>
        <?php if ($siteLine !== '') { ?><p class="small text-muted mb-2"><?php echo hms_h($siteLine); ?></p><?php } ?>
        <dl class="row small mb-0">
            <dt class="col-sm-3 text-muted">Patient</dt><dd class="col-sm-9"><?php echo hms_h($name); ?></dd>
            <dt class="col-sm-3 text-muted">Test</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['test_name'] ?? '')); ?></dd>
            <dt class="col-sm-3 text-muted">Date</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['appointment_date'] ?? '')); ?></dd>
            <dt class="col-sm-3 text-muted">Status</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['status'] ?? '')); ?></dd>
            <?php if ($conc !== '') { ?>
            <dt class="col-sm-3 text-muted">Conclusion</dt><dd class="col-sm-9"><?php echo hms_h(hms_clinical_conclusion_display($conc)); ?></dd>
            <?php } ?>
        </dl>
        <div class="mt-3 border rounded p-3 bg-light">
            <div class="small font-weight-bold text-muted mb-2">Result details</div>
            <div class="font-monospace small" style="white-space:pre-wrap;"><?php echo $summaryBlock !== '' ? $summaryBlock : '<span class="text-muted">—</span>'; ?></div>
        </div>
        <?php if ($notes !== '') { ?>
        <div class="mt-3">
            <div class="small font-weight-bold text-muted">Laboratory notes</div>
            <div class="small" style="white-space:pre-wrap;"><?php echo nl2br(hms_h($notes)); ?></div>
        </div>
        <?php } ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

/**
 * @param array<string,mixed> $row
 * @param array<string,mixed> $patient
 */
function hms_clinical_rad_report_html(array $row, array $patient, string $facilityName, string $siteLine): string
{
    $name = hms_clinical_result_patient_line($patient);
    $tplJson = (string) ($row['result_template_json'] ?? '');
    $findings = (string) ($row['findings'] ?? '');
    $conc = (string) ($row['conclusion_code'] ?? '');
    $summaryBlock = nl2br(hms_h(hms_rad_template_summary_text($tplJson, $findings, $conc)));
    $notes = trim((string) ($row['notes'] ?? ''));

    ob_start();
    ?>
    <section class="hms-result-report">
        <h2 class="h5 border-bottom pb-2 mb-3">Radiology / imaging report</h2>
        <?php if ($facilityName !== '') { ?><p class="small text-muted mb-1"><?php echo hms_h($facilityName); ?></p><?php } ?>
        <?php if ($siteLine !== '') { ?><p class="small text-muted mb-2"><?php echo hms_h($siteLine); ?></p><?php } ?>
        <dl class="row small mb-0">
            <dt class="col-sm-3 text-muted">Patient</dt><dd class="col-sm-9"><?php echo hms_h($name); ?></dd>
            <dt class="col-sm-3 text-muted">Exam</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['exam_name'] ?? '')); ?></dd>
            <dt class="col-sm-3 text-muted">Modality</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['modality'] ?? '')); ?></dd>
            <dt class="col-sm-3 text-muted">Body part</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['body_part'] ?? '')); ?></dd>
            <dt class="col-sm-3 text-muted">Date</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['appointment_date'] ?? '')); ?></dd>
            <dt class="col-sm-3 text-muted">Status</dt><dd class="col-sm-9"><?php echo hms_h((string) ($row['status'] ?? '')); ?></dd>
            <?php if ($conc !== '') { ?>
            <dt class="col-sm-3 text-muted">Conclusion</dt><dd class="col-sm-9"><?php echo hms_h(hms_clinical_conclusion_display($conc)); ?></dd>
            <?php } ?>
        </dl>
        <div class="mt-3 border rounded p-3 bg-light">
            <div class="small font-weight-bold text-muted mb-2">Report</div>
            <div class="small" style="white-space:pre-wrap;"><?php echo $summaryBlock !== '' ? $summaryBlock : '<span class="text-muted">—</span>'; ?></div>
        </div>
        <?php if ($notes !== '') { ?>
        <div class="mt-3">
            <div class="small font-weight-bold text-muted">Additional notes</div>
            <div class="small" style="white-space:pre-wrap;"><?php echo nl2br(hms_h($notes)); ?></div>
        </div>
        <?php } ?>
    </section>
    <?php

    return (string) ob_get_clean();
}

/**
 * Safe filename segment (ASCII, no path chars).
 */
function hms_clinical_result_safe_filename_part(string $s): string
{
    $s = preg_replace('/[^a-zA-Z0-9_-]+/', '-', trim($s));

    return $s === '' ? 'report' : substr($s, 0, 80);
}

/**
 * Full HTML document for PDF rendering (Dompdf). Wraps fragment from lab/rad report helpers.
 */
function hms_clinical_result_pdf_wrap_html(string $documentTitle, string $bodyHtmlFragment): string
{
    $t = hms_h($documentTitle);

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>' . $t . '</title>'
        . '<style>'
        . 'body{font-family:DejaVu Sans,Helvetica,Arial,sans-serif;font-size:11pt;color:#111;margin:20px;}'
        . 'h2{font-size:13pt;border-bottom:1px solid #ccc;padding-bottom:6px;}'
        . '.text-muted{color:#555;}'
        . 'dl.row{margin-bottom:8px;}'
        . 'dt{font-weight:bold;}'
        . 'dd{margin-left:0;margin-bottom:4px;}'
        . '.border{border:1px solid #ddd;}'
        . '.rounded{border-radius:4px;}'
        . '.p-3{padding:10px;}'
        . '.bg-light{background:#f5f5f5;}'
        . '.small{font-size:9pt;}'
        . '</style></head><body>'
        . $bodyHtmlFragment
        . '<p class="small" style="margin-top:20px;border-top:1px solid #ccc;padding-top:8px;color:#666;">Generated '
        . hms_h(date('c'))
        . ' — HMS clinical result report.</p>'
        . '</body></html>';
}
