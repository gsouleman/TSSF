<?php
declare(strict_types=1);
require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) { header('Location: index.php'); exit; }

if (function_exists('hms_can') && !hms_can($connection, 'billing.read') && !hms_can($connection, 'inventory.read')) {
    http_response_code(403);
    exit('Forbidden: billing or inventory access required for the service catalog.');
}

$fid     = hms_current_facility_id();
$isAdmin = (string)($_SESSION['role'] ?? '') === '1';
$catOk   = hms_db_table_exists($connection, 'tbl_service_catalog');
$flash   = '';
$flashType = 'success';

// --- Handle POST (Add / Edit / Delete) ---
if ($catOk && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'delete' && $isAdmin) {
        $delId = (int)($_POST['item_id'] ?? 0);
        $st = mysqli_prepare($connection, 'DELETE FROM tbl_service_catalog WHERE id = ? AND (facility_id = ? OR facility_id = 0) LIMIT 1');
        if ($st) { mysqli_stmt_bind_param($st,'ii',$delId,$fid); mysqli_stmt_execute($st); mysqli_stmt_close($st); }
        $flash = 'Item deleted.'; $flashType = 'danger';

    } elseif (in_array($action, ['add','edit'], true)) {
        $id       = (int)($_POST['item_id'] ?? 0);
        $cat      = trim((string)($_POST['category']    ?? ''));
        $subcat   = trim((string)($_POST['subcategory'] ?? ''));
        $name     = trim((string)($_POST['name']        ?? ''));
        $desc     = trim((string)($_POST['description'] ?? ''));
        $cpt      = trim((string)($_POST['cpt_code']    ?? ''));
        $price    = max(0.0, (float)($_POST['price']    ?? 0));
        $status   = (int)($_POST['status']              ?? 1);
        $sort     = (int)($_POST['sort_order']          ?? 0);

        $allowed = ['consultation','laboratory','radiology','service','pharmacy','ward'];
        if (!in_array($cat, $allowed, true)) { $flash = 'Invalid category.'; $flashType = 'warning'; goto render; }
        if ($name === '') { $flash = 'Name is required.'; $flashType = 'warning'; goto render; }

        if ($action === 'add') {
            $st = mysqli_prepare($connection,
                'INSERT INTO tbl_service_catalog (facility_id,category,subcategory,name,description,cpt_code,price,status,sort_order) VALUES (?,?,?,?,?,?,?,?,?)');
            if ($st) {
                mysqli_stmt_bind_param($st,'isssssdii',$fid,$cat,$subcat,$name,$desc,$cpt,$price,$status,$sort);
                mysqli_stmt_execute($st); mysqli_stmt_close($st);
                hms_audit_log($connection,'service_catalog.create','service_catalog',(int)mysqli_insert_id($connection));
                $flash = 'Service added successfully.';
            }
        } else {
            $st = mysqli_prepare($connection,
                'UPDATE tbl_service_catalog SET category=?,subcategory=?,name=?,description=?,cpt_code=?,price=?,status=?,sort_order=? WHERE id=? AND (facility_id=? OR facility_id=0) LIMIT 1');
            if ($st) {
                $st->bind_param('sssssdiiii',$cat,$subcat,$name,$desc,$cpt,$price,$status,$sort,$id,$fid);
                mysqli_stmt_execute($st); mysqli_stmt_close($st);
                hms_audit_log($connection,'service_catalog.update','service_catalog',$id);
                $flash = 'Service updated successfully.';
            }
        }
    }
}
render:

// --- Fetch all items grouped by category ---
$items = ['consultation'=>[], 'laboratory'=>[], 'radiology'=>[], 'service'=>[], 'pharmacy'=>[], 'ward'=>[]];
if ($catOk) {
    $q = mysqli_query($connection,
        "SELECT * FROM tbl_service_catalog WHERE (facility_id=".(int)$fid." OR facility_id=0) ORDER BY category, subcategory, sort_order, name");
    while ($q && $row = mysqli_fetch_assoc($q)) {
        $c = (string)($row['category'] ?? 'service');
        if (!isset($items[$c])) $items[$c] = [];
        $items[$c][] = $row;
    }
}

$catLabels = ['consultation'=>'Consultations','laboratory'=>'Laboratory','radiology'=>'Radiology & Imaging','service'=>'Other Services','pharmacy'=>'Pharmacy & medication prices','ward'=>'Wards & Hospitalisation'];
$catIcons  = ['consultation'=>'fa-stethoscope','laboratory'=>'fa-flask','radiology'=>'fa-film','service'=>'fa-cogs','pharmacy'=>'fa-medkit','ward'=>'fa-bed'];
$catColors = ['consultation'=>'#1a6bd8','laboratory'=>'#8b5cf6','radiology'=>'#0891b2','service'=>'#f59e0b','pharmacy'=>'#10b981','ward'=>'#f43f5e'];

// Predefined ward list — same as the Add Bed form in Ward and Bed MGT
$wardTypesList = [
    'ICU Ward',
    'Pediatrics Wards',
    'Maternity/Labour Wards',
    'Emergency Wards',
    'Geriatric Wards',
    'Oncology Wards',
    'Orthopedics Wards',
    'Isolation Wards',
];

// Fetch existing ward CPT codes so JS can ensure uniqueness
$existingWardCpts = [];
if ($catOk) {
    $cq = mysqli_query($connection, "SELECT cpt_code FROM tbl_service_catalog WHERE category='ward' AND (facility_id=".(int)$fid." OR facility_id=0)");
    while ($cq && $cr = mysqli_fetch_assoc($cq)) {
        $code = trim((string)($cr['cpt_code'] ?? ''));
        if ($code !== '') $existingWardCpts[] = $code;
    }
}

$activeTab = in_array($_GET['tab'] ?? '', array_keys($catLabels)) ? $_GET['tab'] : 'consultation';

include 'header.php';
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <?php
        $primaryBtnLabel = ($activeTab === 'ward') ? 'Add Rate' : 'Add Service';
        hms_ui_page_header('Service Price Catalog', [
            'subtitle'    => 'Set and manage prices for consultations, laboratory, imaging, wards, and the pharmacy formulary. Pharmacy sell prices link to inventory stock when you run migration 037 and use Inventory → Seed formulary & stock.',
            'breadcrumbs' => [['Home','dashboard.php'],['Manage',''],['Service Catalog','']],
            'primary'     => $isAdmin ? ['label'=>$primaryBtnLabel,'url'=>'#','icon'=>'fa-plus','id'=>'btnAddService'] : null,
        ]); ?>

        <?php if (!$catOk) { ?>
        <div class="alert alert-warning">
            <strong>Setup required:</strong> Run <code>database/migrations/012_service_catalog.sql</code> to create the price catalog table.
        </div>
        <?php } else { ?>

        <?php if ($flash !== '') { ?>
        <div class="alert alert-<?php echo $flashType; ?> alert-dismissible fade show">
            <?php echo hms_h($flash); ?>
            <button type="button" class="close" data-dismiss="alert">&times;</button>
        </div>
        <?php } ?>

        <!-- Summary Stat Row -->
        <div class="row mb-4">
            <?php foreach ($catLabels as $catKey => $catName) {
                $cnt = count($items[$catKey] ?? []);
                $col = $catColors[$catKey]; $ic = $catIcons[$catKey];
            ?>
            <div class="col-6 col-md-3 mb-3">
                <div class="card border-0 shadow-sm" style="border-left:4px solid <?php echo $col; ?> !important;">
                    <div class="card-body d-flex align-items-center py-3">
                        <span class="d-flex align-items-center justify-content-center rounded-circle mr-3" style="width:42px;height:42px;background:<?php echo $col; ?>18;flex-shrink:0;">
                            <i class="fa <?php echo $ic; ?>" style="color:<?php echo $col; ?>;font-size:1.1rem;"></i>
                        </span>
                        <div>
                            <div style="font-size:1.5rem;font-weight:800;color:#1e293b;line-height:1;"><?php echo $cnt; ?></div>
                            <div class="text-muted" style="font-size:.78rem;"><?php echo hms_h($catName); ?></div>
                        </div>
                    </div>
                </div>
            </div>
            <?php } ?>
        </div>

        <!-- Tabs -->
        <ul class="nav nav-tabs mb-0" id="catalogTabs" role="tablist" style="border-bottom:none;">
            <?php foreach ($catLabels as $catKey => $catName) { ?>
            <li class="nav-item">
                <a class="nav-link<?php echo $catKey===$activeTab?' active':''; ?>"
                   href="?tab=<?php echo $catKey; ?>"
                   style="<?php echo $catKey===$activeTab ? 'color:'.$catColors[$catKey].';border-color:#e2e8f0 #e2e8f0 #fff;font-weight:700;' : ''; ?>">
                    <i class="fa <?php echo $catIcons[$catKey]; ?> mr-1"></i><?php echo hms_h($catName); ?>
                    <span class="badge badge-light ml-1"><?php echo count($items[$catKey] ?? []); ?></span>
                </a>
            </li>
            <?php } ?>
        </ul>

        <!-- Tab content (active category) -->
        <div class="card border-0 shadow-sm" style="border-top-left-radius:0;">
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-hover mb-0" id="catalogTable">
                        <thead class="thead-light">
                            <tr>
                                <th style="width:60px;">Code</th>
                                <th>Service Name</th>
                                <th>Sub-category</th>
                                <th class="text-right">Price (FCFA)</th>
                                <th>Status</th>
                                <?php if ($isAdmin) { ?><th class="text-right">Actions</th><?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                        <?php
                        $catItems = $items[$activeTab] ?? [];
                        if (empty($catItems)) {
                            $span = $isAdmin ? 6 : 5;
                            if ($activeTab === 'ward') {
                                $emptyHint = 'No ward rates yet. Click <strong>Add Rate</strong> to create one.';
                            } elseif ($activeTab === 'pharmacy') {
                                $emptyHint = 'No pharmacy price rows yet. Use <strong>Add Service</strong> (category Pharmacy) for manual items, or open <a href="inventory.php" class="alert-link font-weight-bold">Inventory &amp; stock</a> and run <strong>Seed formulary &amp; stock</strong> after migration <code>037_pharmacy_inventory_catalog_link.sql</code>.';
                            } else {
                                $emptyHint = 'No services yet. Click <strong>Add Service</strong> to create one.';
                            }
                            echo '<tr><td colspan="'.$span.'" class="text-center text-muted py-5">
                                <i class="fa fa-inbox fa-2x mb-2 d-block" style="color:#cbd5e1;"></i>
                                '.$emptyHint.'
                            </td></tr>';
                        } else {
                            $lastSub = null;
                            foreach ($catItems as $item) {
                                if ($item['subcategory'] !== $lastSub) {
                                    $lastSub = $item['subcategory'];
                                    $span = $isAdmin ? 6 : 5;
                                    if ($lastSub !== '') {
                                        echo '<tr style="background:#f8fafc;"><td colspan="'.$span.'" class="py-1 px-3" style="font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;color:#94a3b8;">'
                                            .hms_h((string)$lastSub).'</td></tr>';
                                    }
                                }
                                $priceFormatted = number_format((float)$item['price'], 0, '.', ' ');
                                $statusBadge = (int)$item['status'] === 1
                                    ? '<span class="badge badge-success">Active</span>'
                                    : '<span class="badge badge-secondary">Inactive</span>';
                                echo '<tr>
                                    <td class="small text-muted font-weight-bold">'.hms_h((string)$item['cpt_code']).'</td>
                                    <td class="font-weight-600">'.hms_h((string)$item['name']).'</td>
                                    <td class="small text-muted">'.hms_h((string)$item['subcategory']).'</td>
                                    <td class="text-right font-weight-bold" style="color:#1a6bd8;">'.$priceFormatted.' <small class="text-muted font-weight-normal">FCFA</small></td>
                                    <td>'.$statusBadge.'</td>';
                                if ($isAdmin) {
                                    echo '<td class="text-right">
                                        <button class="btn btn-sm btn-outline-primary mr-1 btn-edit-service"
                                            data-id="'.hms_h((string)$item['id']).'"
                                            data-name="'.hms_h((string)$item['name']).'"
                                            data-cat="'.hms_h((string)$item['category']).'"
                                            data-subcat="'.hms_h((string)$item['subcategory']).'"
                                            data-cpt="'.hms_h((string)$item['cpt_code']).'"
                                            data-price="'.hms_h((string)$item['price']).'"
                                            data-desc="'.hms_h((string)$item['description']).'"
                                            data-status="'.hms_h((string)$item['status']).'"
                                            data-sort="'.hms_h((string)$item['sort_order']).'">
                                            <i class="fa fa-pencil"></i>
                                        </button>
                                        <form method="post" class="d-inline" onsubmit="return confirm(\'Delete this service?\');">
                                            '.hms_csrf_field().'
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="item_id" value="'.(int)$item['id'].'">
                                            <input type="hidden" name="tab" value="'.$activeTab.'">
                                            <button class="btn btn-sm btn-outline-danger"><i class="fa fa-trash-o"></i></button>
                                        </form>
                                    </td>';
                                }
                                echo '</tr>';
                            }
                        }
                        ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php if ($isAdmin) { ?>
            <div class="card-footer bg-white border-top text-right py-2">
                <button class="btn btn-primary btn-sm" id="btnAddService2"><i class="fa fa-plus mr-1"></i><?php echo ($activeTab === 'ward') ? 'Add Rate' : 'Add Service'; ?></button>
            </div>
            <?php } ?>
        </div>

        <?php if ($isAdmin) { ?>
        <!-- Add / Edit Modal -->
        <div class="modal fade" id="serviceModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" id="serviceForm">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="action" id="svc_action" value="add">
                        <input type="hidden" name="item_id" id="svc_id" value="0">
                        <input type="hidden" name="tab" value="<?php echo hms_h($activeTab); ?>">
                        <div class="modal-header" style="background:#1a6bd8;">
                            <h5 class="modal-title text-white" id="serviceModalTitle">Add Service</h5>
                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Category <span class="text-danger">*</span></label>
                                    <select name="category" id="svc_category" class="form-control" required>
                                        <?php foreach ($catLabels as $ck => $cl) {
                                            $sel = $ck === $activeTab ? ' selected' : '';
                                            echo '<option value="'.hms_h($ck).'"'.$sel.'>'.hms_h($cl).'</option>';
                                        } ?>
                                    </select>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>Sub-category</label>
                                    <input type="text" name="subcategory" id="svc_subcat" class="form-control" placeholder="e.g. Hematology, Emergency">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Service Name <span class="text-danger">*</span></label>
                                <input type="text" name="name" id="svc_name" class="form-control" required placeholder="e.g. Consultation Cardiologue">
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-5">
                                    <label>Price (FCFA) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="price" id="svc_price" class="form-control" min="0" step="100" required placeholder="0">
                                        <div class="input-group-append"><span class="input-group-text">FCFA</span></div>
                                    </div>
                                </div>
                                <div class="form-group col-md-4">
                                    <label>CPT Code</label>
                                    <input type="text" name="cpt_code" id="svc_cpt" class="form-control" placeholder="e.g. C001">
                                </div>
                                <div class="form-group col-md-3">
                                    <label>Sort</label>
                                    <input type="number" name="sort_order" id="svc_sort" class="form-control" value="0" min="0">
                                </div>
                            </div>
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" id="svc_desc" class="form-control" rows="2" placeholder="Optional notes about this service"></textarea>
                            </div>
                            <div class="form-group mb-0">
                                <label>Status</label>
                                <select name="status" id="svc_status" class="form-control">
                                    <option value="1">Active</option>
                                    <option value="0">Inactive</option>
                                </select>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary"><i class="fa fa-save mr-1"></i>Save Service</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php } ?>

        <?php if ($isAdmin && $activeTab === 'ward') { ?>
        <!-- Ward Rate Modal -->
        <div class="modal fade" id="wardRateModal" tabindex="-1">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="post" id="wardRateForm">
                        <?php echo hms_csrf_field(); ?>
                        <input type="hidden" name="action" id="wr_action" value="add">
                        <input type="hidden" name="item_id" id="wr_id" value="0">
                        <input type="hidden" name="category" value="ward">
                        <input type="hidden" name="tab" value="ward">
                        <div class="modal-header" style="background:#f43f5e;">
                            <h5 class="modal-title text-white" id="wardRateModalTitle">Add Ward Rate</h5>
                            <button type="button" class="close text-white" data-dismiss="modal">&times;</button>
                        </div>
                        <div class="modal-body">
                            <div class="form-group">
                                <label>Ward <span class="text-danger">*</span></label>
                                <select name="name" id="wr_ward" class="form-control" required onchange="hmsCatalogWardChanged(this.value)">
                                    <option value="">Select Ward</option>
                                    <?php foreach ($wardTypesList as $wt) { ?>
                                    <option value="<?php echo hms_h($wt); ?>"><?php echo hms_h($wt); ?></option>
                                    <?php } ?>
                                    <option value="Other">Other...</option>
                                </select>
                            </div>
                            <div class="form-group" id="wr_custom_ward_wrap" style="display:none;">
                                <label>Custom Ward Name <span class="text-danger">*</span></label>
                                <input type="text" id="wr_custom_ward" class="form-control" placeholder="e.g. Medical Ward" maxlength="120">
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6">
                                    <label>Price Per Night (FCFA) <span class="text-danger">*</span></label>
                                    <div class="input-group">
                                        <input type="number" name="price" id="wr_price" class="form-control" min="0" step="100" required placeholder="0">
                                        <div class="input-group-append"><span class="input-group-text">FCFA</span></div>
                                    </div>
                                </div>
                                <div class="form-group col-md-6">
                                    <label>CPT Code <small class="text-muted">(auto)</small></label>
                                    <input type="text" name="cpt_code" id="wr_cpt" class="form-control" readonly style="background:#f1f5f9;cursor:not-allowed;">
                                </div>
                            </div>
                            <input type="hidden" name="subcategory" id="wr_subcat" value="Hospitalisation">
                            <div class="form-group">
                                <label>Description</label>
                                <textarea name="description" id="wr_desc" class="form-control" rows="2" placeholder="e.g. Nightly rate for ICU Ward"></textarea>
                            </div>
                            <div class="form-row">
                                <div class="form-group col-md-6 mb-0">
                                    <label>Status</label>
                                    <select name="status" id="wr_status" class="form-control">
                                        <option value="1">Active</option>
                                        <option value="0">Inactive</option>
                                    </select>
                                </div>
                                <div class="form-group col-md-6 mb-0">
                                    <label>Sort</label>
                                    <input type="number" name="sort_order" id="wr_sort" class="form-control" value="0" min="0">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary" style="background:#f43f5e;border-color:#f43f5e;"><i class="fa fa-save mr-1"></i>Save Rate</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <?php } ?>

        <?php } // end catOk ?>
    </div>
</div>

<script>
// Ward CPT auto-generation — must be global for inline onchange
var _hmsExistingCpts = <?php echo json_encode($existingWardCpts); ?>;
var _hmsWardAbbrMap = {
    'ICU Ward': 'ICU',
    'Pediatrics Wards': 'PED',
    'Maternity/Labour Wards': 'MAT',
    'Emergency Wards': 'EMG',
    'Geriatric Wards': 'GER',
    'Oncology Wards': 'ONC',
    'Orthopedics Wards': 'ORT',
    'Isolation Wards': 'ISO'
};

function hmsGenerateWardCpt(wardName) {
    var abbr = _hmsWardAbbrMap[wardName];
    if (!abbr) {
        // Custom ward: take first 3 uppercase letters
        abbr = wardName.replace(/[^A-Za-z]/g, '').substring(0, 3).toUpperCase();
        if (abbr === '') abbr = 'WRD';
    }
    var base = 'W-' + abbr;
    var candidate = base;
    var n = 2;
    while (_hmsExistingCpts.indexOf(candidate) !== -1) {
        candidate = base + '-' + n;
        n++;
    }
    return candidate;
}

function hmsCatalogWardChanged(val) {
    var customWrap = document.getElementById('wr_custom_ward_wrap');
    var customInput = document.getElementById('wr_custom_ward');
    var cptField = document.getElementById('wr_cpt');
    if (val === 'Other') {
        customWrap.style.display = 'block';
        cptField.value = '';
    } else {
        customWrap.style.display = 'none';
        customInput.value = '';
        if (val && val !== '') {
            cptField.value = hmsGenerateWardCpt(val);
        } else {
            cptField.value = '';
        }
    }
}

// Auto-gen CPT when typing a custom ward name
document.addEventListener('DOMContentLoaded', function () {
    var customInput = document.getElementById('wr_custom_ward');
    if (customInput) {
        customInput.addEventListener('input', function () {
            var v = (this.value || '').trim();
            var cptField = document.getElementById('wr_cpt');
            if (v.length >= 2) {
                cptField.value = hmsGenerateWardCpt(v);
            } else {
                cptField.value = '';
            }
        });
    }
});

(function () {
    var isWardTab = <?php echo json_encode($activeTab === 'ward'); ?>;
    var predefinedWards = <?php echo json_encode($wardTypesList); ?>;

    // Open Add modal — ward tab uses wardRateModal, others use serviceModal
    function openAdd() {
        if (isWardTab) {
            var wrForm = document.getElementById('wardRateForm');
            if (wrForm) {
                wrForm.reset();
                document.getElementById('wr_action').value = 'add';
                document.getElementById('wr_id').value = '0';
                document.getElementById('wardRateModalTitle').textContent = 'Add Ward Rate';
                document.getElementById('wr_custom_ward_wrap').style.display = 'none';
                document.getElementById('wr_custom_ward').value = '';
                document.getElementById('wr_cpt').value = '';
                $('#wardRateModal').modal('show');
            }
        } else {
            document.getElementById('svc_action').value = 'add';
            document.getElementById('svc_id').value = '0';
            document.getElementById('serviceModalTitle').textContent = 'Add Service';
            document.getElementById('serviceForm').reset();
            $('#serviceModal').modal('show');
        }
    }
    var btnAdd = document.getElementById('btnAddService');
    var btnAdd2 = document.getElementById('btnAddService2');
    if (btnAdd)  btnAdd.addEventListener('click', function(e){ e.preventDefault(); openAdd(); });
    if (btnAdd2) btnAdd2.addEventListener('click', openAdd);

    // Ward rate form: swap "Other" with custom name on submit
    var wardForm = document.getElementById('wardRateForm');
    if (wardForm) {
        wardForm.addEventListener('submit', function (e) {
            var sel = document.getElementById('wr_ward');
            var custom = document.getElementById('wr_custom_ward');
            if (sel.value === 'Other') {
                var cv = (custom.value || '').trim();
                if (cv === '') {
                    e.preventDefault();
                    custom.focus();
                    alert('Please enter a custom ward name.');
                    return;
                }
                sel.value = 'Other';
                var hiddenInput = document.createElement('input');
                hiddenInput.type = 'hidden';
                hiddenInput.name = 'name';
                hiddenInput.value = cv;
                wardForm.appendChild(hiddenInput);
                sel.removeAttribute('name');
            }
        });
    }

    // Open Edit modal
    document.querySelectorAll('.btn-edit-service').forEach(function(btn) {
        btn.addEventListener('click', function () {
            var cat = this.dataset.cat || '';
            if (cat === 'ward') {
                document.getElementById('wr_action').value = 'edit';
                document.getElementById('wr_id').value     = this.dataset.id || '';
                document.getElementById('wr_cpt').value    = this.dataset.cpt || '';
                document.getElementById('wr_price').value  = this.dataset.price || '';
                document.getElementById('wr_desc').value   = this.dataset.desc || '';
                document.getElementById('wr_status').value = this.dataset.status || '1';
                document.getElementById('wr_sort').value   = this.dataset.sort || '0';
                document.getElementById('wardRateModalTitle').textContent = 'Edit Ward Rate';

                var wardName = this.dataset.name || '';
                var sel = document.getElementById('wr_ward');
                var customWrap = document.getElementById('wr_custom_ward_wrap');
                var customInput = document.getElementById('wr_custom_ward');
                if (predefinedWards.indexOf(wardName) !== -1) {
                    sel.value = wardName;
                    customWrap.style.display = 'none';
                    customInput.value = '';
                } else {
                    sel.value = 'Other';
                    customWrap.style.display = 'block';
                    customInput.value = wardName;
                }
                $('#wardRateModal').modal('show');
            } else {
                document.getElementById('svc_action').value  = 'edit';
                document.getElementById('svc_id').value      = this.dataset.id || '';
                document.getElementById('svc_category').value= cat;
                document.getElementById('svc_subcat').value  = this.dataset.subcat || '';
                document.getElementById('svc_name').value    = this.dataset.name || '';
                document.getElementById('svc_cpt').value     = this.dataset.cpt || '';
                document.getElementById('svc_price').value   = this.dataset.price || '';
                document.getElementById('svc_desc').value    = this.dataset.desc || '';
                document.getElementById('svc_status').value  = this.dataset.status || '1';
                document.getElementById('svc_sort').value    = this.dataset.sort || '0';
                document.getElementById('serviceModalTitle').textContent = 'Edit Service';
                $('#serviceModal').modal('show');
            }
        });
    });
})();
</script>
<?php include 'footer.php'; ?>
