<?php
declare(strict_types=1);

/**
 * HMS — Users Manual (reference PDF via Dompdf).
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
ul { margin: 6px 0 10px 18px; padding: 0; }
li { margin-bottom: 4px; }
table { width: 100%; border-collapse: collapse; margin: 10px 0; font-size: 9pt; }
th, td { border: 1px solid #cbd5e1; padding: 5px 6px; text-align: left; vertical-align: top; }
th { background: #f1f5f9; font-weight: bold; }
.meta { font-size: 9pt; color: #64748b; margin-bottom: 16px; }
.code { font-family: DejaVu Sans Mono, monospace; font-size: 8pt; background: #f8fafc; padding: 1px 3px; }
.page-break { page-break-before: always; }
.small { font-size: 9pt; color: #475569; }
CSS;

$html = '<!DOCTYPE html><html lang="en"><head><meta charset="UTF-8"><title>HMS Users Manual</title><style>' . $css . '</style></head><body>';
$html .= '<h1>Hospital Management System</h1>';
$html .= '<p class="meta"><strong>Users Manual</strong> — Reference edition<br>Version 1.0 · Generated ' . htmlspecialchars($generated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
$html .= '<p>This <strong>Users Manual</strong> is a <em>reference</em>: terminology, modules, navigation concepts, permissions, and troubleshooting. For step-by-step tasks, use the separate <strong>User Guide</strong> PDF.</p>';

$html .= '<h2>1. System requirements (typical)</h2><ul>';
$html .= '<li><strong>Client:</strong> Modern web browser (Chrome, Firefox, Edge, Safari).</li>';
$html .= '<li><strong>Server:</strong> PHP 7.4+ with mysqli; MySQL or MariaDB database.</li>';
$html .= '<li><strong>PDF features:</strong> Composer dependencies installed (<span class="code">composer install</span> in the <span class="code">hms</span> folder) for Dompdf.</li>';
$html .= '</ul>';

$html .= '<h2>2. Terminology</h2><table>';
$html .= '<tr><th>Term</th><th>Meaning</th></tr>';
$html .= '<tr><td>Facility / site</td><td>Hospital unit or location; data is often scoped to the active facility.</td></tr>';
$html .= '<tr><td>OPD</td><td>Outpatient department — same-day visits and queue.</td></tr>';
$html .= '<tr><td>Consultation</td><td>Clinical encounter record; may hold prescribed lab/radiology catalog lines.</td></tr>';
$html .= '<tr><td>Payment ticket / code</td><td>Cashier reference linking consultation and payable lines (supports partial payment).</td></tr>';
$html .= '<tr><td>Receipt / invoice</td><td>Fiscal documents generated from billing tables (see local configuration).</td></tr>';
$html .= '<tr><td>ACL / permission</td><td>Optional fine-grained access (<span class="code">tbl_acl_permission</span>) beyond base role.</td></tr>';
$html .= '<tr><td>Patient portal</td><td>Separate patient login for appointments and notices (optional module).</td></tr>';
$html .= '<tr><td>SKU</td><td>Stock-keeping unit — automatic code for inventory items (<span class="code">inventory.php</span>).</td></tr>';
$html .= '</table>';

$html .= '<div class="page-break"></div><h2>3. Main application areas (reference)</h2>';
$html .= '<table><tr><th>Area</th><th>Typical scripts</th><th>Purpose</th></tr>';
$html .= '<tr><td>Dashboard</td><td><span class="code">dashboard.php</span></td><td>Overview and shortcuts after login.</td></tr>';
$html .= '<tr><td>Patients</td><td><span class="code">patients.php</span>, <span class="code">add-patient.php</span>, <span class="code">edit-patient.php</span></td><td>Register and maintain patient records.</td></tr>';
$html .= '<tr><td>Clinical chart</td><td><span class="code">patient-chart.php</span></td><td>Aggregated view: vitals, consults, results, Rx, insurance.</td></tr>';
$html .= '<tr><td>Consultations</td><td><span class="code">consultations.php</span>, <span class="code">consultation-new.php</span></td><td>Document visits and orders.</td></tr>';
$html .= '<tr><td>Visits / OPD</td><td><span class="code">visits.php</span>, <span class="code">opd-queue.php</span></td><td>Visit tracking and queue.</td></tr>';
$html .= '<tr><td>Laboratory</td><td><span class="code">lab-results.php</span>, workflows</td><td>Results and templates.</td></tr>';
$html .= '<tr><td>Radiology</td><td><span class="code">radiology-results.php</span>, workflows</td><td>Imaging reports.</td></tr>';
$html .= '<tr><td>Pharmacy</td><td><span class="code">pharmacy.php</span>, <span class="code">prescription.php</span></td><td>Dispensing and Rx lines.</td></tr>';
$html .= '<tr><td>Cashier</td><td><span class="code">cashier.php</span></td><td>Load ticket by code; take payment.</td></tr>';
$html .= '<tr><td>Billing</td><td><span class="code">billing-payments.php</span>, charges, receipts</td><td>Financial workspace.</td></tr>';
$html .= '<tr><td>Insurance</td><td><span class="code">insurance.php</span></td><td>Carrier catalogue for the site.</td></tr>';
$html .= '<tr><td>Inventory</td><td><span class="code">inventory.php</span></td><td>Stock, movements, alerts.</td></tr>';
$html .= '<tr><td>Portals</td><td><span class="code">portal-*.php</span></td><td>Role-focused landing pages.</td></tr>';
$html .= '<tr><td>Admin / setup</td><td><span class="code">employees.php</span>, <span class="code">facilities.php</span>, <span class="code">platform-overview.php</span></td><td>Staff, sites, migrations help.</td></tr>';
$html .= '</table>';

$html .= '<h2>4. Roles and access</h2><p>Each user has a <strong>role</strong> (e.g. administrator, doctor, nurse). The sidebar and actions you see depend on role and optional <strong>ACL</strong> permissions. If you cannot access a function, contact your administrator — it is not always a software defect.</p>';

$html .= '<h2>5. Multi-facility behaviour</h2><p>Users linked to multiple facilities may <strong>switch facility</strong>. Patient, billing, and inventory data generally respect the <strong>current facility</strong> in session.</p>';

$html .= '<div class="page-break"></div><h2>6. Clinical chart visibility</h2><p>Some roles (e.g. front desk, nursing) may receive a <strong>reduced chart</strong>: insurance, consultations, and vitals only — other sections hidden by policy. Clinicians with full clinical access see the complete chart when migrations and permissions allow.</p>';

$html .= '<h2>7. Financial documents</h2><p><strong>Receipts</strong> confirm payment; <strong>invoices</strong> may be issued to companies per <span class="code">invoice-create.php</span> and billing setup. Exact numbering and tax rules depend on hospital configuration.</p>';

$html .= '<h2>8. Database setup</h2><p>Features appear only after the correct <strong>SQL migrations</strong> are applied in order. The authoritative list is on <span class="code">platform-overview.php</span> (Help &amp; database setup). Missing tables usually produce inline warnings on the affected page.</p>';

$html .= '<h2>9. Troubleshooting</h2><table>';
$html .= '<tr><th>Issue</th><th>Suggestions</th></tr>';
$html .= '<tr><td>Menu item missing</td><td>Check role/ACL; confirm migration for that module.</td></tr>';
$html .= '<tr><td>PDF will not open</td><td>Ensure <span class="code">composer install</span> ran; check server error logs.</td></tr>';
$html .= '<tr><td>Wrong facility data</td><td>Verify facility switcher; confirm user–facility assignment.</td></tr>';
$html .= '<tr><td>Cannot save form</td><td>Session expired — sign in again; verify CSRF token (refresh page).</td></tr>';
$html .= '</table>';

$html .= '<h2>10. Related documents</h2><ul>';
$html .= '<li><strong>User Guide</strong> — task procedures (<span class="code">docs/user-guide-pdf.php</span>).</li>';
$html .= '<li><strong>Workflow document</strong> — end-to-end flows (<span class="code">docs/workflow-document-pdf.php</span>).</li>';
$html .= '<li><strong>Architecture design</strong> — technical overview (<span class="code">docs/architecture-document-pdf.php</span>).</li>';
$html .= '<li><strong>Demo presentation</strong> — HTML slides (<span class="code">docs/demo-presentation.html</span>).</li>';
$html .= '</ul>';

$html .= '<p class="small"><strong>Disclaimer:</strong> This manual describes the application generically. Local SOPs, training, and legal requirements take precedence.</p>';
$html .= '</body></html>';

$pdf = hms_billing_html_to_pdf_bytes($html);
if ($pdf === false) {
    http_response_code(500);
    exit('PDF render failed.');
}

$fname = 'HMS_Users_Manual_' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
header('Content-Length: ' . (string) strlen($pdf));
echo $pdf;
