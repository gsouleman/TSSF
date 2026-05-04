<?php
declare(strict_types=1);

/**
 * HMS — User Guide (task-oriented PDF via Dompdf).
 */
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once dirname(__DIR__) . '/includes/billing_document_pdf.php';

if (empty($_SESSION['name'])) {
    header('Location: ../index.php');
    exit;
}

if (!hms_billing_pdf_available()) {
    http_response_code(503);
    exit('PDF unavailable: run composer install in the hms folder (dompdf).');
}

$generated = date('Y-m-d H:i T');

$css = <<<'CSS'
body { font-family: DejaVu Sans, Helvetica, Arial, sans-serif; font-size: 10.5pt; color: #0f172a; line-height: 1.45; }
h1 { font-size: 18pt; color: #0c8b8b; margin: 0 0 12px; border-bottom: 2px solid #0c8b8b; padding-bottom: 6px; }
h2 { font-size: 13pt; color: #1e293b; margin: 18px 0 8px; page-break-after: avoid; }
h3 { font-size: 11pt; color: #334155; margin: 12px 0 6px; }
p { margin: 0 0 8px; }
ul, ol { margin: 6px 0 10px 18px; padding: 0; }
li { margin-bottom: 4px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9.5pt; }
th, td { border: 1px solid #cbd5e1; padding: 6px 8px; text-align: left; vertical-align: top; }
th { background: #f1f5f9; font-weight: bold; }
.meta { font-size: 9pt; color: #64748b; margin-bottom: 16px; }
.code { font-family: DejaVu Sans Mono, monospace; font-size: 8.5pt; background: #f8fafc; padding: 2px 4px; }
.tip { background: #ecfdf5; border-left: 3px solid #0c8b8b; padding: 8px 10px; margin: 10px 0; font-size: 9.5pt; }
.page-break { page-break-before: always; }
.small { font-size: 9pt; color: #475569; }
CSS;

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>HMS User Guide</title><style>' . $css . '</style></head><body>';

$html .= '<h1>Hospital Management System</h1>';
$html .= '<p class="meta"><strong>User Guide</strong> — Task-oriented help for staff<br>Version 1.0 · Generated ' . htmlspecialchars($generated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';

$html .= '<p>This <strong>User Guide</strong> explains how to perform common tasks in HMS. Your administrator controls which menus and actions you see. If a screen described here is missing, your <strong>role</strong> or <strong>migrations</strong> may not include that module.</p>';

$html .= '<div class="tip"><strong>Tip:</strong> Use the left sidebar to move between areas (Patients, Visits, Consultations, Cashier, etc.). The <strong>Users Manual</strong> (separate PDF) lists modules and terms in reference form.</div>';

$html .= '<h2>1. Signing in &amp; security</h2><ul>';
$html .= '<li>Open your HMS URL and enter your <strong>username</strong> and <strong>password</strong> on <span class="code">index.php</span>.</li>';
$html .= '<li><strong>Sign out</strong> when finished, especially on shared computers (<span class="code">logout.php</span>).</li>';
$html .= '<li>Do not share your credentials. Many actions are written to the <strong>audit log</strong> for accountability.</li>';
$html .= '</ul>';

$html .= '<h2>2. Facility (site) context</h2><p>If your hospital uses <strong>multiple sites</strong>, your session is tied to one <strong>facility</strong> at a time. Use <strong>Switch facility</strong> (when available) to change the active site. Patient lists, charges, and stock are usually scoped to the current facility.</p>';

$html .= '<h2>3. Language</h2><p>English and French may be available via <span class="code">set-lang.php</span> or the language control on <span class="code">platform-overview.php</span> (Help &amp; database setup).</p>';

$html .= '<div class="page-break"></div><h2>4. Quick start by role</h2>';
$html .= '<table><tr><th>Your role</th><th>Start here</th></tr>';
$html .= '<tr><td>Front desk / reception</td><td><span class="code">portal-front-desk.php</span>, <span class="code">patients.php</span>, <span class="code">add-patient.php</span>, <span class="code">appointments.php</span>, <span class="code">opd-queue.php</span></td></tr>';
$html .= '<tr><td>Nursing</td><td><span class="code">portal-nursing.php</span>, <span class="code">vitals-enter.php</span>, <span class="code">opd-queue.php</span>, <span class="code">patient-chart.php</span> (as permitted)</td></tr>';
$html .= '<tr><td>Doctor / clinician</td><td><span class="code">portal-doctors.php</span>, <span class="code">consultations.php</span>, <span class="code">consultation-new.php</span>, <span class="code">patient-chart.php</span></td></tr>';
$html .= '<tr><td>Laboratory</td><td><span class="code">portal-laboratory.php</span>, <span class="code">service-code-verify.php?portal=laboratory</span>, <span class="code">lab-results.php</span></td></tr>';
$html .= '<tr><td>Radiology</td><td><span class="code">portal-radiology.php</span>, <span class="code">service-code-verify.php?portal=radiology</span>, <span class="code">radiology-results.php</span></td></tr>';
$html .= '<tr><td>Pharmacy</td><td><span class="code">portal-pharmacy.php</span>, <span class="code">pharmacy.php</span>, <span class="code">prescriptions.php</span>, <span class="code">prescription.php</span></td></tr>';
$html .= '<tr><td>Cashier / billing</td><td><span class="code">cashier.php</span>, <span class="code">billing-payments.php</span>, <span class="code">receipts-invoices.php</span></td></tr>';
$html .= '<tr><td>Administrator</td><td><span class="code">dashboard.php</span>, <span class="code">employees.php</span>, <span class="code">facilities.php</span>, <span class="code">service-catalog.php</span>, Help &amp; setup</td></tr>';
$html .= '</table>';

$html .= '<h2>5. Patients — register and open chart</h2><ol>';
$html .= '<li>Go to <strong>Patients</strong> → <strong>Add patient</strong> (or search the list).</li>';
$html .= '<li>Complete demographics and save. Use <strong>Clinical chart</strong> from the patient menu to view allergies, vitals, consultations, tests, prescriptions, and insurance.</li>';
$html .= '<li><strong>Edit patient</strong> updates demographics; some fields may be restricted by permission.</li>';
$html .= '</ol>';

$html .= '<h2>6. Visits &amp; OPD</h2><p>Use <strong>Visits</strong> and <strong>OPD queue</strong> to track same-day attendance and queue order. Nursing or front desk may record <strong>vitals</strong> before the doctor sees the patient.</p>';

$html .= '<div class="page-break"></div><h2>7. Consultations</h2><ol>';
$html .= '<li>Open <strong>Consultations</strong> and create or continue a visit record (<span class="code">consultation-new.php</span>).</li>';
$html .= '<li>Add <strong>laboratory</strong> and <strong>radiology</strong> lines from the service catalog where prescribed. Mark <em>external</em> if the patient will use an outside provider for that line.</li>';
$html .= '<li>Complete the consultation when appropriate; your site may generate a <strong>payment ticket / code</strong> for the cashier.</li>';
$html .= '</ol>';

$html .= '<h2>8. Cashier &amp; payment codes</h2><ol>';
$html .= '<li>Open <span class="code">cashier.php</span> and enter the <strong>payment code</strong> from the patient receipt or ticket.</li>';
$html .= '<li>Select lines to pay now; <strong>partial payment</strong> is supported — the same code can be used again later for remaining lines.</li>';
$html .= '<li>Issue a <strong>receipt</strong> or fiscal document per local workflow. Insurance <strong>patient co-pay</strong> depends on the primary policy set on the patient chart.</li>';
$html .= '</ol>';

$html .= '<h2>9. Laboratory &amp; radiology</h2><ol>';
$html .= '<li>From your department portal, open <strong>Verify payment code</strong> (<span class="code">service-code-verify.php</span> with the correct portal).</li>';
$html .= '<li>Use <strong>Proceed</strong> on eligible lines to open the result workflow, enter findings, and <strong>finalize</strong> when ready.</li>';
$html .= '<li>Finalization may notify the patient portal and referring clinician when configured.</li>';
$html .= '</ol>';

$html .= '<h2>10. Pharmacy &amp; dispensing</h2><p>Open a prescription, select dispense quantities, and confirm. If <strong>inventory</strong> is enabled, link stock items so quantities decrement correctly.</p>';

$html .= '<div class="page-break"></div><h2>11. Insurance on the chart</h2><p>On <span class="code">patient-chart.php</span>, set the <strong>primary insurer</strong> and <strong>insurer share %</strong> (when migrations allow). Carriers are maintained under <span class="code">insurance.php</span>. Front desk and nursing roles may see a <strong>limited chart</strong> (vitals, consultations, insurance only) depending on configuration.</p>';

$html .= '<h2>12. External documents</h2><p>If tests or imaging were done outside the hospital, upload PDFs or images under <span class="code">patient-external-docs.php</span> so clinicians can review them on the chart.</p>';

$html .= '<h2>13. Inventory (stock)</h2><p><span class="code">inventory.php</span> — add items (SKU is automatic), receive stock, adjust quantities, and watch <strong>low-stock</strong> alerts. PDF reports are available from the inventory page.</p>';

$html .= '<h2>14. Where to get more help</h2><ul>';
$html .= '<li><strong>Help &amp; database setup</strong> — <span class="code">platform-overview.php</span> (migration list and workflow summary).</li>';
$html .= '<li><strong>Architecture</strong>, <strong>Workflow</strong>, and <strong>Users Manual</strong> PDFs from the same documentation area.</li>';
$html .= '<li><strong>Audit log</strong> — <span class="code">audit-log.php</span> (if you have access) to trace actions.</li>';
$html .= '</ul>';

$html .= '<p class="small"><strong>Disclaimer:</strong> Screen names and steps may vary by deployment. Follow your hospital\'s policies and training.</p>';
$html .= '</body></html>';

$pdf = hms_billing_html_to_pdf_bytes($html);
if ($pdf === false) {
    http_response_code(500);
    exit('PDF render failed.');
}

$fname = 'HMS_User_Guide_' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
header('Content-Length: ' . (string) strlen($pdf));
echo $pdf;
