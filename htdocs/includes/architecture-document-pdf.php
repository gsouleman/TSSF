<?php
declare(strict_types=1);

/**
 * HMS — Architecture design document (PDF via Dompdf).
 * Open in browser while signed in; use browser print to save if needed.
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
$phpVer = PHP_VERSION;
$migrationCount = 28;

$html = <<<'HTML'
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>HMS — Architecture Design Document</title>
<style>
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
.box { background: #f8fafc; border-left: 3px solid #0c8b8b; padding: 10px 12px; margin: 10px 0; font-size: 9.5pt; }
.page-break { page-break-before: always; }
.small { font-size: 9pt; color: #475569; }
</style>
</head>
<body>

<h1>Hospital Management System (HMS)</h1>
<p class="meta"><strong>Architecture Design Document</strong><br>
Version 1.0 · Generated at 

HTML;
$html .= htmlspecialchars($generated, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html .= ' · PHP ';
$html .= htmlspecialchars($phpVer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$html .= <<<'HTML'

</p>

<p>This document describes the logical architecture of the HMS PHP application: layers, major modules, data stores, security controls, integrations, and deployment considerations. It is derived from the codebase layout and migration inventory.</p>

<h2>1. Executive summary</h2>
<p>HMS is a <strong>server-rendered web application</strong> built with <strong>PHP</strong> and <strong>MySQL/MariaDB</strong> (via <span class="code">mysqli</span>). The browser receives HTML from PHP entry scripts; business rules live primarily in <span class="code">hms/includes/*.php</span>. Optional <strong>REST-style JSON APIs</strong> under <span class="code">hms/api/v1/</span> complement the web UI. <strong>Multi-facility</strong> operation is supported through facility-scoped rows and session-bound facility context. Feature breadth spans <strong>patient registration, OPD queue, consultations, prescriptions, laboratory and radiology workflows, cashier/receipts, insurance coverage, inventory, credit/receivables, expenses, audit</strong>, and <strong>patient/staff portals</strong>.</p>

<h2>2. System context</h2>
<div class="box">
<strong>Actors:</strong> clinical staff, front desk, nursing, laboratory, radiology, pharmacy, cashier, accountant/admin, patients (portal), and optional external systems via API or FHIR sample endpoint.<br>
<strong>Primary data store:</strong> relational database (tables created and evolved through SQL migrations).<br>
<strong>Document output:</strong> PDF generation via Dompdf (receipts, clinical reports, inventory reports, this document).
</div>

<h2>3. High-level architecture (logical layers)</h2>
<table>
<tr><th>Layer</th><th>Responsibility</th><th>Typical location</th></tr>
<tr><td>Presentation</td><td>HTTP entry points, forms, tables, navigation</td><td><span class="code">hms/*.php</span>, <span class="code">header.php</span> / <span class="code">footer.php</span></td></tr>
<tr><td>Application / domain services</td><td>Workflow helpers, pricing, tickets, vitals, clinical aggregates</td><td><span class="code">hms/includes/*.php</span></td></tr>
<tr><td>Data access</td><td>Prepared statements, facility-scoped queries</td><td>Embedded in pages and helpers; <span class="code">connection.php</span></td></tr>
<tr><td>Infrastructure</td><td>Session, CSRF, RBAC, audit, i18n, config</td><td><span class="code">bootstrap.php</span> chain</td></tr>
<tr><td>Integration</td><td>REST v1, FHIR sample, file uploads</td><td><span class="code">api/v1/</span>, <span class="code">fhir.php</span></td></tr>
</table>

<h2>4. Technology stack</h2>
<ul>
<li><strong>Runtime:</strong> PHP 7.4+ (see <span class="code">composer.json</span>).</li>
<li><strong>Database:</strong> MySQL/MariaDB; migrations in <span class="code">hms/database/migrations/</span> (

HTML;
$html .= (string) $migrationCount;
$html .= <<<'HTML'

+ SQL files, applied in order per platform documentation).</li>
<li><strong>HTTP:</strong> Traditional form posts and redirects; JSON for APIs.</li>
<li><strong>PDF:</strong> <span class="code">dompdf/dompdf</span> via <span class="code">hms/includes/billing_document_pdf.php</span>.</li>
<li><strong>Front-end:</strong> Server-rendered HTML, CSS/JS assets under <span class="code">hms/assets/</span> (see layout in <span class="code">header.php</span>).</li>
</ul>

<div class="page-break"></div>
<h2>5. Application modules (functional map)</h2>
<table>
<tr><th>Domain</th><th>Capabilities (representative)</th><th>Representative scripts / areas</th></tr>
<tr><td>Identity &amp; facility</td><td>Staff login, roles, facility switch, multi-site</td><td><span class="code">index.php</span>, <span class="code">facilities.php</span>, <span class="code">switch-facility.php</span>, <span class="code">includes/facility.php</span></td></tr>
<tr><td>Patients &amp; MPI</td><td>Registration, edit, demographics, merge queue</td><td><span class="code">patients.php</span>, <span class="code">edit-patient.php</span>, <span class="code">mpi-workqueue.php</span></td></tr>
<tr><td>ADT / visits</td><td>OPD queue, visits, admissions</td><td><span class="code">opd-queue.php</span>, <span class="code">visits.php</span>, <span class="code">adt-board.php</span>, <span class="code">includes/opd_queue.php</span></td></tr>
<tr><td>Clinical</td><td>Consultations, clinical chart, vitals, allergies/meds</td><td><span class="code">consultation-new.php</span>, <span class="code">patient-chart.php</span>, <span class="code">vitals-enter.php</span>, <span class="code">includes/clinical_chart.php</span></td></tr>
<tr><td>Orders &amp; results</td><td>Lab/radiology catalog lines, result workflows, PDF reports</td><td><span class="code">lab-result-workflow.php</span>, <span class="code">radiology-result-workflow.php</span>, <span class="code">clinical-result-report.php</span></td></tr>
<tr><td>Pharmacy</td><td>Prescriptions, dispense, inventory link</td><td><span class="code">prescription.php</span>, <span class="code">pharmacy.php</span>, <span class="code">prescriptions.php</span></td></tr>
<tr><td>Billing &amp; cashier</td><td>Service catalog, charges, receipts/invoices, payment tickets, transactions</td><td><span class="code">cashier.php</span>, <span class="code">billing-payments.php</span>, <span class="code">service-catalog.php</span>, <span class="code">includes/payment_ticket.php</span>, <span class="code">includes/transactions_ledger.php</span></td></tr>
<tr><td>Insurance</td><td>Carriers, patient policies, insurer % at cashier</td><td><span class="code">insurance.php</span>, <span class="code">includes/patient_insurance.php</span>, <span class="code">includes/insurance_catalog.php</span></td></tr>
<tr><td>Inventory</td><td>Items, stock movements, categories, low-stock alerts, PDF reports</td><td><span class="code">inventory.php</span>, <span class="code">inventory-report-pdf.php</span>, <span class="code">includes/inventory_helpers.php</span></td></tr>
<tr><td>Finance extensions</td><td>Credit/receivables, expenses, journal hooks</td><td><span class="code">credit-receivables.php</span>, <span class="code">expense-management.php</span>, <span class="code">includes/credit_receivables.php</span></td></tr>
<tr><td>Portals</td><td>Role landing pages (doctors, lab, rad, pharmacy, nursing, cashier, …)</td><td><span class="code">portal-*.php</span></td></tr>
<tr><td>Patient portal</td><td>Separate session model, bookings, notices</td><td><span class="code">patient-portal*.php</span>, <span class="code">includes/patient_portal*.php</span></td></tr>
<tr><td>Compliance &amp; ops</td><td>Audit log, security center, analytics</td><td><span class="code">audit-log.php</span>, <span class="code">security-center.php</span>, <span class="code">analytics-dashboard.php</span></td></tr>
</table>

<h2>6. Security architecture</h2>
<ul>
<li><strong>Authentication:</strong> PHP session (<span class="code">$_SESSION</span>) after login; cookie flags set in <span class="code">bootstrap.php</span>.</li>
<li><strong>CSRF:</strong> Token seed and validation (<span class="code">includes/csrf.php</span>) on mutating requests.</li>
<li><strong>Authorization:</strong> Role string on <span class="code">tbl_employee</span>; optional ACL tables (<span class="code">tbl_acl_permission</span>, <span class="code">tbl_acl_role_permission</span>) with <span class="code">hms_can()</span> / <span class="code">hms_require_permission()</span>.</li>
<li><strong>Audit:</strong> <span class="code">hms_audit_log()</span> for sensitive actions.</li>
<li><strong>Data scope:</strong> Facility id on many tables; pages use <span class="code">hms_current_facility_id()</span>.</li>
</ul>

<h2>7. Data &amp; schema evolution</h2>
<p>Schema is delivered as <strong>ordered SQL migrations</strong> (e.g. <span class="code">001_multi_site_platform.sql</span> foundation, then clinical, OPD, billing, lab/rad, insurance, payment tickets, result workflow, inventory extensions). Idempotent patterns (e.g. <span class="code">ADD COLUMN</span> only if missing) are used where noted in migration headers. Application code tests for table/column presence with helpers such as <span class="code">hms_db_table_exists()</span> to degrade gracefully when migrations are partial.</p>

<div class="page-break"></div>
<h2>8. Integrations &amp; APIs</h2>
<ul>
<li><strong>REST API v1:</strong> JSON endpoints under <span class="code">hms/api/v1/</span> (auth, facilities, patients, appointments, schedule, departments, ping) with shared bootstrap and CORS headers.</li>
<li><strong>FHIR:</strong> Sample read-only Patient resource (<span class="code">fhir.php</span>) for demonstration.</li>
<li><strong>Files:</strong> Uploads for doctors, patient external documents, etc., under controlled paths.</li>
</ul>

<h2>9. Deployment &amp; operations</h2>
<ul>
<li><strong>Web server:</strong> Any PHP-capable host (Apache/Nginx + PHP-FPM).</li>
<li><strong>Configuration:</strong> <span class="code">includes/config.php</span> / <span class="code">config.local.php</span> for DB credentials (not committed).</li>
<li><strong>Dependencies:</strong> <span class="code">composer install</span> for Dompdf and autoloading.</li>
<li><strong>Localization:</strong> English/French toggles via <span class="code">set-lang.php</span> / <span class="code">includes/i18n.php</span>; Cameroon-specific helpers for money and geography where applicable.</li>
</ul>

<h2>10. Future / extension points</h2>
<ul>
<li>Expand API coverage and authentication hardening for external systems.</li>
<li>Centralized service layer extraction for the largest scripts (optional refactor).</li>
<li>Automated tests (PHPUnit) and CI — not assumed in current tree.</li>
<li>Caching and read replicas — application-agnostic; not required for baseline.</li>
</ul>

<p class="small"><strong>Disclaimer:</strong> This document is generated from application structure and conventions in the repository; it is not a substitute for environment-specific runbooks or security review.</p>

</body>
</html>
HTML;

$pdf = hms_billing_html_to_pdf_bytes($html);
if ($pdf === false) {
    http_response_code(500);
    exit('PDF render failed.');
}

$fname = 'HMS_Architecture_Design_' . date('Y-m-d') . '.pdf';
header('Content-Type: application/pdf');
header('Content-Disposition: attachment; filename="' . str_replace('"', '', $fname) . '"');
header('Content-Length: ' . (string) strlen($pdf));
echo $pdf;
