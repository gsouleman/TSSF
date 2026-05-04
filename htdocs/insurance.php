<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/insurance_catalog.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
$canOpenInsurance = (string) ($_SESSION['role'] ?? '') === '1'
    || hms_can($connection, 'patient.read')
    || hms_can($connection, 'billing.read')
    || hms_can($connection, 'cashier.write');
if (!$canOpenInsurance) {
    http_response_code(403);
    exit('Forbidden.');
}
$fid = hms_current_facility_id();
$canWrite = hms_can($connection, 'facility.admin') || hms_can($connection, 'billing.write');
$tableOk = hms_db_table_exists($connection, 'tbl_insurance_carrier');
if ($tableOk) {
    hms_insurance_seed_cameroon_carriers_for_facility($connection, $fid);
}
$msg = '';

if ($tableOk && $canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['add_carrier'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $msg = 'Invalid security token.';
    } else {
        $choice = trim((string) ($_POST['carrier_choice'] ?? ''));
        $catalog = hms_insurance_catalog_by_code();
        $code = '';
        $name = '';

        if ($choice === '__other__') {
            $name = trim((string) ($_POST['other_name'] ?? ''));
            if ($name === '') {
                $msg = 'Please enter the carrier name for “Other”.';
            } else {
                $code = hms_insurance_generate_other_code($connection, $fid, $name);
            }
        } elseif ($choice !== '' && isset($catalog[$choice])) {
            $code = $choice;
            $name = $catalog[$choice];
        } else {
            $msg = 'Please choose a carrier from the list.';
        }

        if ($msg === '' && $code !== '' && $name !== '') {
            $stmt = mysqli_prepare(
                $connection,
                'INSERT INTO tbl_insurance_carrier (facility_id, code, name, status) VALUES (?,?,?,1)'
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'iss', $fid, $code, $name);
                if (mysqli_stmt_execute($stmt)) {
                    $msg = 'Carrier added.';
                    hms_audit_log($connection, 'insurance.carrier.create', 'insurance_carrier', (int) mysqli_insert_id($connection));
                } else {
                    $msg = 'Could not save (duplicate code for this site?).';
                }
                mysqli_stmt_close($stmt);
            }
        }
    }
}

$catalogRows = hms_insurance_catalog();
include 'header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Insurance', [
                    'subtitle' => 'Payers and coverage — link policies from patient registration workflows.',
                    'breadcrumbs' => [['Billing', 'billing-payments.php'], ['Insurance', '']],
                    'secondary' => [
                        ['label' => 'Billing workspace', 'url' => 'billing-payments.php', 'icon' => 'fa-credit-card'],
                        ['label' => 'Patients', 'url' => 'patients.php', 'icon' => 'fa-users'],
                    ],
                ]);
                if ($msg !== '') {
                    echo '<div class="alert alert-info">' . hms_h($msg) . '</div>';
                }
                if (!$tableOk) {
                    echo '<div class="alert alert-warning">Run <code>hms/database/migrations/006_insurance_and_payments_core.sql</code> to create insurance tables.</div>';
                } else {
                    if ($canWrite) {
                        ?>
                <div class="card border-0 shadow-sm hms-form-card mb-4">
                    <div class="card-header bg-white font-weight-bold">Add carrier (this site)</div>
                    <div class="card-body">
                        <form method="post" id="ic_add_form" class="form-row align-items-end">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="add_carrier" value="1">
                            <div class="form-group col-md-7 col-lg-8">
                                <label for="ic_carrier">Name <span class="text-danger">*</span></label>
                                <select id="ic_carrier" name="carrier_choice" class="form-control hms-insurance-carrier-select" required>
                                    <option value="">— Select a carrier —</option>
                                    <?php foreach ($catalogRows as $row) { ?>
                                    <option value="<?php echo hms_h($row['code']); ?>"><?php echo hms_h($row['name']); ?></option>
                                    <?php } ?>
                                    <option value="__other__">Other (not listed)</option>
                                </select>
                                <small class="form-text text-muted">Search by typing in the box. Pick <strong>Other</strong> for payers not in the catalogue.</small>
                            </div>
                            <div class="form-group col-md-3 col-lg-2">
                                <label for="ic_code_display">Code</label>
                                <input type="text" id="ic_code_display" class="form-control bg-light" readonly autocomplete="off" placeholder="Auto">
                            </div>
                            <div class="form-group col-md-2 col-lg-2">
                                <label class="d-none d-md-block">&nbsp;</label>
                                <button type="submit" class="btn btn-primary btn-block">Save carrier</button>
                            </div>
                            <div class="form-group col-12 d-none" id="ic_other_wrap">
                                <label for="ic_other_name">Other carrier name <span class="text-danger">*</span></label>
                                <input type="text" name="other_name" id="ic_other_name" class="form-control" maxlength="200" placeholder="Full legal name of the payer" autocomplete="organization">
                                <small class="form-text text-muted">The <strong>Code</strong> field will be generated automatically from this name.</small>
                            </div>
                        </form>
                    </div>
                </div>
                <script>
                (function () {
                    var catalog = <?php echo json_encode($catalogRows, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
                    var byCode = {};
                    catalog.forEach(function (r) { byCode[r.code] = r.name; });
                    function slugPreview(name) {
                        var s = (name || '').toUpperCase().replace(/[^A-Z0-9]+/g, '_').replace(/^_+|_+$/g, '');
                        if (!s) return 'OTHER';
                        if (s.length > 24) s = s.substring(0, 24);
                        return s;
                    }
                    function refresh() {
                        var v = document.getElementById('ic_carrier').value;
                        var codeEl = document.getElementById('ic_code_display');
                        var otherWrap = document.getElementById('ic_other_wrap');
                        var otherInput = document.getElementById('ic_other_name');
                        if (v === '' || v === '__other__') {
                            if (v === '__other__') {
                                otherWrap.classList.remove('d-none');
                                otherInput.required = true;
                                codeEl.value = slugPreview(otherInput.value);
                            } else {
                                otherWrap.classList.add('d-none');
                                otherInput.required = false;
                                otherInput.value = '';
                                codeEl.value = '';
                            }
                            if (v !== '__other__') return;
                        } else {
                            otherWrap.classList.add('d-none');
                            otherInput.required = false;
                            otherInput.value = '';
                            codeEl.value = v;
                        }
                    }
                    document.addEventListener('DOMContentLoaded', function () {
                        var $c = window.jQuery ? window.jQuery('#ic_carrier') : null;
                        if ($c && $c.length && typeof $c.select2 === 'function') {
                            $c.select2({
                                width: '100%',
                                placeholder: 'Search insurance carrier…',
                                allowClear: false
                            });
                            $c.on('change', refresh);
                        } else {
                            document.getElementById('ic_carrier').addEventListener('change', refresh);
                        }
                        var otherInput = document.getElementById('ic_other_name');
                        otherInput.addEventListener('input', function () {
                            if (document.getElementById('ic_carrier').value === '__other__') {
                                document.getElementById('ic_code_display').value = slugPreview(otherInput.value);
                            }
                        });
                        refresh();
                    });
                })();
                </script>
                        <?php
                    }
                    ?>
                <div class="card border-0 shadow-sm hms-data-card">
                    <div class="card-header bg-white font-weight-bold">Carriers for this site</div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table mb-0">
                                <thead><tr><th>Code</th><th>Name</th><th>Phone</th><th>Email</th><th>Status</th></tr></thead>
                                <tbody>
                                <?php
                                $q = mysqli_query(
                                    $connection,
                                    'SELECT code, name, phone, email, status FROM tbl_insurance_carrier WHERE facility_id = ' . (int) $fid . ' ORDER BY name'
                                );
                                while ($q && $r = mysqli_fetch_assoc($q)) {
                                    echo '<tr>';
                                    echo '<td>' . hms_h((string) $r['code']) . '</td>';
                                    echo '<td>' . hms_h((string) $r['name']) . '</td>';
                                    echo '<td>' . hms_h((string) ($r['phone'] ?? '')) . '</td>';
                                    echo '<td>' . hms_h((string) ($r['email'] ?? '')) . '</td>';
                                    echo '<td>' . (((int) ($r['status'] ?? 0)) === 1 ? 'Active' : 'Inactive') . '</td>';
                                    echo '</tr>';
                                }
                                ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <p class="text-muted small mt-3 mb-0">Patient-level policies use <code>tbl_patient_insurance</code>. After migration <code>025_insurance_coverage_external_docs.sql</code>, set the primary policy and <strong>insurer covered %</strong> on each patient’s <a href="patients.php">clinical chart</a> (<code>patient-chart.php</code>).</p>
                    <?php
                }
                ?>
            </div>
        </div>
<?php include 'footer.php'; ?>
