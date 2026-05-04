<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
include 'header.php';
$isAdmin = isset($_SESSION['role']) && (string) $_SESSION['role'] === '1';
?>
        <div class="page-wrapper"><div class="content hms-module">
            <?php
            hms_ui_page_header('Help & database setup', [
                'subtitle' => 'Enterprise modules use the left navigation. Run SQL migrations if tables are missing.',
                'breadcrumbs' => [['Home', 'dashboard.php'], ['Setup', '']],
            ]);
            ?>
            <div class="row">
                <div class="col-12 col-lg-8 mb-4">
                    <div class="card border-0 shadow-sm hms-data-card">
                        <div class="card-body">
                            <div class="alert alert-info mb-0">
                                <strong>Run the migration once</strong> (phpMyAdmin cannot be triggered from this app).
                                <ol class="mb-0 mt-2 pl-3">
                                    <li>Open <code>hms/database/migrations/001_multi_site_platform.sql</code> in your project folder.</li>
                                    <li>Optional: <code>hms/database/migrations/002_patient_portal.sql</code> — two plain <code>ALTER TABLE</code> lines (no <code>information_schema</code>; works on InfinityFree). Enables patient sign-in at <code>patient-portal-login.php</code>.</li>
                                    <li>After <code>001</code>: <code>hms/database/migrations/003_clinical_workflow.sql</code> — lab catalogue, consultations (dynamic parameters), prescriptions (lab + medication lines), and pharmacy queue.</li>
                                    <li>Pharmacy pricing &amp; stock link: <code>hms/database/migrations/012_service_catalog.sql</code> (price catalog) and <code>027_inventory_stock_categories.sql</code> (categories/movements). Then <code>hms/database/migrations/037_pharmacy_inventory_catalog_link.sql</code> — links each inventory row to a pharmacy catalog price and optional formulary link on prescription lines. Use <strong>Inventory → Seed formulary &amp; stock</strong> to load medications, equipment, and the full medical-supply catalogue with opening quantities.</li>
                                    <li>Optional removal (after backup): <code>hms/database/migrations/013_remove_encounters_clinical_orders.sql</code> — drops <code>tbl_encounter</code>, <code>tbl_clinical_order</code>, <code>tbl_order_result</code>, removes <code>encounter_id</code> / <code>clinical_order_id</code> links. Use when your site no longer uses encounters or CPOE orders (matches current HMS navigation).</li>
                                    <li>After <code>003</code> (or <code>001</code> if you skip <code>003</code>): <code>hms/database/migrations/004_opd_queue_admission.sql</code> — OPD visit queue (<code>opd-queue.php</code>) and extra admission/discharge fields on <code>tbl_admission</code>. Uses <code>ADD COLUMN IF NOT EXISTS</code> (MariaDB) so it does not query <code>information_schema</code> (required for some free hosts).</li>
                                    <li>Optional demo data: <code>hms/database/migrations/005_cameroon_sample_patients_doctors.sql</code> — sample patients and doctors (Cameroonian names; généralistes &amp; spécialistes). Uses <code>information_schema</code> once for <code>facility_id</code>. Requires <code>001</code> for <code>tbl_user_facility</code> doctor links.</li>
                                    <li>Insurance foundation: <code>hms/database/migrations/006_insurance_and_payments_core.sql</code> — carriers and <code>tbl_patient_insurance</code> for coverage (used by <code>insurance.php</code>). Requires <code>001</code> (<code>tbl_facility</code>).</li>
                                    <li>Receipts &amp; invoices: <code>hms/database/migrations/011_receipt_invoice_module.sql</code> — <code>tbl_billing_company</code>, <code>tbl_billing_document</code>, <code>tbl_billing_document_line</code>. Receipts are issued when consultation fees are paid, charges are posted, transactions are completed (with <code>010_transactions_table.sql</code>), pharmacy collects a fee on dispense, or lab fees are entered on new lab results; company invoices from <code>invoice-create.php</code>. Requires <code>001</code>.</li>
                                    <li>Optional ledger link: <code>hms/database/migrations/017_transactions_ledger_link.sql</code> — links receipts to <code>tbl_transaction</code> when present. After <code>010</code>/<code>011</code>.</li>
                                    <li>Facility admission &amp; billing anchors: <code>hms/database/migrations/018_facility_admission_billing_episode.sql</code> — <code>tbl_facility_admission</code> (arrival on site), <code>tbl_billing_document</code> columns <code>opd_visit_id</code>, <code>facility_admission_id</code>, <code>hospitalization_id</code> (open <code>tbl_admission</code>), links on <code>tbl_opd_visit</code> and <code>tbl_admission</code>. Run after <code>004</code> and <code>011</code>.</li>
                                    <li>Credit &amp; receivables + simple OHADA journal: <code>hms/database/migrations/019_credit_receivables.sql</code> — patient credit accounts, on-credit charges, payments, installment plans, follow-up log, and <code>tbl_fin_journal_*</code> for accrual (DR receivable / CR revenue) and collection (DR cash-bank / CR receivable) when receipts are posted from <code>credit-account.php</code>. Run after <code>001</code> and <code>011</code>.</li>
                                    <li>Vitals triage metadata: <code>hms/database/migrations/020_vitals_recorder_and_station.sql</code> — adds <code>recorded_by</code> and <code>source_station</code> on <code>tbl_vital_sign</code> (front desk / nursing / chart). Run after <code>001</code>. Use <code>vitals-enter.php</code> from the front desk or nursing portal; consultation pre-fills from the latest vitals.</li>
                                    <li>Optional anthropometrics: <code>hms/database/migrations/021_vitals_weight_height_waist.sql</code> — adds <code>weight_kg</code>, <code>height_cm</code>, and <code>waist_cm</code> (nullable) on <code>tbl_vital_sign</code>. Run after <code>001</code> (and <code>020</code> if you use recorder columns).</li>
                                    <li>Consult billing exception permission: <code>hms/database/migrations/022_consult_billing_override_permission.sql</code> — adds ACL permission <code>consult.billing_override</code> so supervisors can approve hospital billing exceptions on <code>consultation-new.php</code> (waive or defer consultation fee when not emergency and no cashier receipt). Requires <code>001</code> and ACL tables.</li>
                                    <li>Cashier payment codes: <code>hms/database/migrations/023_payment_ticket_cashier.sql</code> — <code>tbl_payment_ticket</code> stores a unique code per site; consultation completion can generate a code bundling unpaid consultation fee plus prescribed laboratory and radiology catalog lines. <code>cashier.php</code> loads the basket by code and issues receipts. Grants ACL <code>cashier.write</code>. Run after <code>001</code>, <code>003</code>, and <code>011</code>.</li>
                                    <li>Lab &amp; radiology result workflow: <code>hms/database/migrations/024_lab_radiology_result_workflow.sql</code> — links results to payment codes/lines, structured JSON templates, conclusions, and <code>tbl_result_shared_notice</code> for patient portal and referring doctor. Run after <code>013</code>, <code>016</code>, <code>023</code>.</li>
                                    <li>Insurance share &amp; external documents: <code>hms/database/migrations/025_insurance_coverage_external_docs.sql</code> — <code>insurer_covered_percent</code> on <code>tbl_patient_insurance</code> (cashier patient co-pay) and <code>tbl_patient_external_document</code> for uploads (<code>patient-external-docs.php</code>). Run after <code>006</code>.</li>
                                    <li>Expense register: <code>hms/database/migrations/026_expense_management.sql</code> — <code>tbl_expense</code> and ACL <code>expenses.read</code> / <code>expenses.write</code> for <code>expense-management.php</code>. Run after <code>001</code>.</li>
                                    <li>OHADA reporting &amp; Cameroon tax: <code>hms/database/migrations/029_ohada_reporting_tax.sql</code> — <code>tbl_fin_tax_setting</code>, <code>tbl_fin_tax_declaration</code>, ACL <code>financials.read</code> / <code>financials.write</code>. Run after <code>019</code> (journal GL).</li>
                                    <li>Optional: <code>hms/database/migrations/038_fin_tax_setting_value_widen.sql</code> — widens <code>tbl_fin_tax_setting.setting_value</code> for longer tax notes on <code>tax-declarations.php</code>. Run after <code>029</code>.</li>
                                    <li>Tax module (payroll / CNPS / DGI aids): <code>hms/database/migrations/039_tax_payroll_cnps_dipe.sql</code> — <code>tbl_hms_payroll_settings</code>, <code>tbl_hms_payroll_record</code>, <code>tbl_hms_dipe_history</code> for sidebar <strong>Tax</strong> (<code>tax/</code> pages). Run after <code>001</code>.</li>
                                    <li>HR (pay profiles, leave, attendance): <code>hms/database/migrations/040_hr_payroll_leave_attendance.sql</code> — extends payroll lines and adds <code>tbl_hms_pay_profile</code>, leave/attendance/holiday tables. Run after <code>039</code>.</li>
                                    <li>ACL <code>employee.read</code>: <code>hms/database/migrations/041_acl_employee_read.sql</code> — grants staff roles access to the directory and HR self-service pages. Run after <code>001</code> (if <code>employee.read</code> is missing).</li>
                                    <li>For each migration you run, copy the <strong>entire</strong> file contents (run <code>001</code> first, then optional <code>002</code>, then <code>003</code>, then <code>004</code>).</li>
                                    <li><strong>InfinityFree:</strong> Control Panel → <strong>phpMyAdmin</strong> → your database → <strong>SQL</strong> → paste → <strong>Go</strong>.</li>
                                </ol>
                                <p class="mb-0 small mt-2 text-muted">Re-running is safe for column/index steps. If something fails, note the exact error line.</p>
                                <hr class="my-3">
                                <strong class="d-block mb-2">End-to-end workflow (cashier, clinician, service desks)</strong>
                                <ol class="mb-0 pl-3 small">
                                    <li><strong>Clinical chart &amp; consultation</strong> — The doctor records the visit; prescribed laboratory and radiology catalog lines are stored on the consultation. The chart (<code>patient-chart.php</code>) shows consultations, prescribed tests, linked lab/radiology results when available, prescriptions for pharmacy, <strong>primary insurance %</strong> (patient co-pay at the cashier), and <strong>external documents</strong> (lab/imaging/pharmacy files from outside facilities).</li>
                                    <li><strong>Cashier &amp; partial payment</strong> — After consultation, a <em>payment code</em> may bundle consultation fee, lab, radiology (and pharmacy lines when present on the ticket). On <code>cashier.php</code>, the patient may pay only selected lines now (e.g. all laboratory) and return later with the <strong>same code</strong> for the remainder (e.g. radiology). Each receipt repeats the code for the departments. Lines marked <strong>external</strong> in the consultation require no in-hospital payment; the primary policy’s <strong>insurer %</strong> (0–100, any split such as 70/30 or 50/50) sets how much of the <strong>listed</strong> fee is owed by the patient at the desk.</li>
                                    <li><strong>External care &amp; pending codes</strong> — When tests or medicines are done outside, staff can <strong>cancel</strong> a pending cashier code (reason recorded) or rely on zero–patient-due tickets when everything is external/insured. Upload PDFs or images on <code>patient-external-docs.php</code> so doctors still see reports on the chart.</li>
                                    <li><strong>Service verification &amp; Proceed</strong> — From the Laboratory or Radiology portal, open <code>service-code-verify.php?portal=laboratory</code> or <code>?portal=radiology</code>, verify the code, then use <strong>Proceed</strong> on lines for your specialty. That opens <code>lab-result-workflow.php</code> or <code>radiology-result-workflow.php</code> with a structured template and conclusion (e.g. positive/negative). <strong>Finalize</strong> publishes notices to the <strong>patient portal</strong> and the <strong>referring doctor</strong> (doctor portal + email when addresses exist). Pharmacy may still use payment verification and the existing dispense receipt flow.</li>
                                </ol>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="col-12 col-lg-4">
                    <div class="card border-0 shadow-sm hms-form-card mb-3">
                        <div class="card-header bg-white font-weight-bold">Language</div>
                        <div class="card-body">
                            <form method="post" action="set-lang.php">
                                <?php echo hms_csrf_field(); ?>
                                <div class="form-group mb-2">
                                    <select name="lang" class="form-control">
                                        <option value="en"<?php echo hms_lang() === 'en' ? ' selected' : ''; ?>>English</option>
                                        <option value="fr"<?php echo hms_lang() === 'fr' ? ' selected' : ''; ?>>Français</option>
                                    </select>
                                </div>
                                <button class="btn btn-primary btn-block" type="submit">Apply</button>
                            </form>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm hms-data-card mb-3">
                        <div class="card-header bg-white font-weight-bold">Documentation &amp; training</div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">PDFs require sign-in; demo deck opens in the browser.</p>
                            <a href="docs/user-guide-pdf.php" class="btn btn-outline-primary btn-sm mb-2 d-block"><i class="fa fa-book mr-1"></i>User Guide (PDF)</a>
                            <a href="docs/users-manual-pdf.php" class="btn btn-outline-primary btn-sm mb-2 d-block"><i class="fa fa-list-alt mr-1"></i>Users Manual (PDF)</a>
                            <a href="docs/architecture-document-pdf.php" class="btn btn-outline-primary btn-sm mb-2 d-block"><i class="fa fa-file-pdf-o mr-1"></i>Architecture design (PDF)</a>
                            <a href="docs/workflow-document-pdf.php" class="btn btn-outline-primary btn-sm mb-2 d-block"><i class="fa fa-sitemap mr-1"></i>Workflow document (PDF)</a>
                            <a href="docs/demo-presentation.html" target="_blank" class="btn btn-outline-secondary btn-sm d-block"><i class="fa fa-television mr-1"></i>Demo presentation (HTML)</a>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm hms-data-card mb-3">
                        <div class="card-header bg-white font-weight-bold">FHIR sample</div>
                        <div class="card-body">
                            <p class="small text-muted">Read-only Patient (session).</p>
                            <a href="fhir.php?resource=Patient&amp;id=1" target="_blank" class="btn btn-outline-primary btn-sm">Open Patient/1</a>
                        </div>
                    </div>
                    <?php if ($isAdmin) { ?>
                    <div class="card border-0 shadow-sm hms-data-card mb-3">
                        <div class="card-header bg-white font-weight-bold">Sites</div>
                        <div class="card-body">
                            <a href="facilities.php" class="btn btn-outline-primary btn-sm">Manage facilities</a>
                        </div>
                    </div>
                    <div class="card border-0 shadow-sm hms-data-card">
                        <div class="card-header bg-white font-weight-bold">Demo data (Cameroon)</div>
                        <div class="card-body">
                            <p class="small text-muted mb-2">Extra staff, patients, operating expenses (expense register + optional GL), and ~24 months of sample activity for accounting reports. Tagged for one-click cleanup.</p>
                            <a href="demo-seed-cameroon-2yr.php" class="btn btn-outline-primary btn-sm">Open 2-year demo seed</a>
                        </div>
                    </div>
                    <?php } ?>
                </div>
            </div>
        </div></div>
<?php include 'footer.php'; ?>
