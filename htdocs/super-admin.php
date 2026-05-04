<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_super_admin($connection);

$msg = '';
$err = '';
$modes = hms_app_product_modes();
$dpReady = function_exists('hms_deployment_profile_table_ready') && hms_deployment_profile_table_ready($connection);
$profileParam = isset($_GET['profile']) ? trim((string) $_GET['profile']) : '';
$editRow = null;
if ($dpReady && $profileParam !== '' && $profileParam !== 'new') {
    $eid = (int) $profileParam;
    if ($eid > 0 && function_exists('hms_deployment_profile_fetch')) {
        $editRow = hms_deployment_profile_fetch($connection, $eid);
        if ($editRow === null) {
            $err = 'Deployment profile not found.';
            $profileParam = '';
        }
    }
}
$isFormView = $dpReady && ($profileParam === 'new' || $editRow !== null);

// --- POST: delete profile ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['delete_deployment_profile'])) {
    if (!$dpReady) {
        $err = 'Run migration 045_deployment_profiles.sql first.';
    } elseif (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $did = (int) ($_POST['profile_id'] ?? 0);
        if ($did < 1) {
            $err = 'Invalid profile.';
        } else {
            $activeQ = mysqli_query($connection, 'SELECT active_deployment_profile_id FROM tbl_app_settings WHERE id = 1 LIMIT 1');
            $ar = $activeQ ? mysqli_fetch_assoc($activeQ) : null;
            if ($ar && (int) ($ar['active_deployment_profile_id'] ?? 0) === $did) {
                mysqli_query($connection, 'UPDATE tbl_app_settings SET active_deployment_profile_id = NULL WHERE id = 1 LIMIT 1');
            }
            $dst = mysqli_prepare($connection, 'DELETE FROM tbl_hms_deployment_profile WHERE id = ? LIMIT 1');
            if ($dst) {
                mysqli_stmt_bind_param($dst, 'i', $did);
                mysqli_stmt_execute($dst);
                mysqli_stmt_close($dst);
                $msg = 'Deployment profile deleted.';
                hms_audit_log($connection, 'deployment_profile.delete', 'tbl_hms_deployment_profile', $did);
            }
            header('Location: super-admin.php');
            exit;
        }
    }
}

// --- POST: set active profile ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['set_active_deployment_profile'])) {
    if (!$dpReady) {
        $err = 'Run migration 045_deployment_profiles.sql first.';
    } elseif (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (!hms_app_settings_table_ready($connection)) {
        $err = 'tbl_app_settings missing.';
    } else {
        $aid = (int) ($_POST['active_deployment_profile_id'] ?? -1);
        if ($aid === 0 || $aid === -1) {
            mysqli_query($connection, 'UPDATE tbl_app_settings SET active_deployment_profile_id = NULL WHERE id = 1 LIMIT 1');
            $msg = 'Active profile cleared. The legacy global slice settings below now apply.';
            hms_audit_log($connection, 'deployment_profile.active', 'tbl_app_settings', 0);
        } else {
            $chk = hms_deployment_profile_fetch($connection, $aid);
            if ($chk === null) {
                $err = 'Unknown deployment profile.';
            } else {
                $st = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_app_settings SET active_deployment_profile_id = ? WHERE id = 1 LIMIT 1'
                );
                if ($st) {
                    mysqli_stmt_bind_param($st, 'i', $aid);
                    mysqli_stmt_execute($st);
                    mysqli_stmt_close($st);
                    $msg = 'Active deployment profile updated.';
                    hms_audit_log($connection, 'deployment_profile.active', 'tbl_app_settings', $aid);
                }
            }
        }
    }
}

// --- POST: save named profile ---
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['save_deployment_profile'])) {
    if (!$dpReady) {
        $err = 'Run migration 045_deployment_profiles.sql first.';
    } elseif (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } else {
        $pid = (int) ($_POST['profile_id'] ?? 0);
        $name = trim((string) ($_POST['profile_name'] ?? ''));
        $rawSlices = $_POST['profile_slices'] ?? [];
        if (!is_array($rawSlices)) {
            $rawSlices = [];
        }
        $sel = [];
        foreach ($rawSlices as $v) {
            $x = strtolower(trim((string) $v));
            if (in_array($x, $modes, true)) {
                $sel[] = $x;
            }
        }
        $sel = array_values(array_unique($sel));
        if (in_array('full', $sel, true)) {
            $sel = ['full'];
        }
        $useCustom = !empty($_POST['use_custom_modules']);
        $applyRefine = !empty($_POST['apply_sidebar_link_refine']);
        $modulesJson = null;
        if ($useCustom) {
            $mods = hms_nav_mask_blank();
            $nm = $_POST['nav_module'] ?? [];
            if (is_array($nm)) {
                foreach (hms_nav_module_key_list() as $k) {
                    $mods[$k] = isset($nm[$k]) && (string) $nm[$k] === '1';
                }
            }
            $any = false;
            foreach ($mods as $on) {
                if ($on) {
                    $any = true;
                    break;
                }
            }
            if (!$any) {
                $err = 'Custom module list: tick at least one module, or turn off custom override.';
            } else {
                $itemsSparse = [];
                if (function_exists('hms_sidebar_item_ids')) {
                    $ni = $_POST['nav_item'] ?? [];
                    if (!is_array($ni)) {
                        $ni = [];
                    }
                    foreach (hms_sidebar_item_ids() as $iid) {
                        $onIt = isset($ni[$iid]) && (string) $ni[$iid] === '1';
                        if (!$onIt) {
                            $itemsSparse[$iid] = false;
                        }
                    }
                }
                $payload = ['nav' => $mods];
                if ($itemsSparse !== []) {
                    $payload['items'] = $itemsSparse;
                }
                $enc = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $modulesJson = $enc === false ? null : $enc;
                if ($modulesJson === null) {
                    $err = 'Could not encode module overrides.';
                }
            }
        } elseif ($applyRefine && function_exists('hms_sidebar_item_ids') && function_exists('hms_nav_mask_from_slices')) {
            $sliceMask = hms_nav_mask_from_slices($sel);
            $itemsSparse = [];
            $ni = $_POST['nav_item'] ?? [];
            if (!is_array($ni)) {
                $ni = [];
            }
            foreach (hms_sidebar_item_ids() as $iid) {
                $onIt = isset($ni[$iid]) && (string) $ni[$iid] === '1';
                if ($onIt || !function_exists('hms_sidebar_item_slice_parent_on')) {
                    continue;
                }
                if (hms_sidebar_item_slice_parent_on($sliceMask, $iid)) {
                    $itemsSparse[$iid] = false;
                }
            }
            if ($itemsSparse !== []) {
                $enc = json_encode(['items' => $itemsSparse], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $modulesJson = $enc === false ? null : $enc;
                if ($modulesJson === null) {
                    $err = 'Could not encode link visibility.';
                }
            }
        }
        if ($err === '' && $name === '') {
            $err = 'Profile name is required.';
        }
        if ($err === '' && $sel === []) {
            $err = 'Select at least one product slice (or Full suite).';
        }
        if ($err === '') {
            $slicesJson = json_encode($sel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($slicesJson === false) {
                $err = 'Could not encode slices.';
            } elseif ($pid < 1) {
                $st = mysqli_prepare(
                    $connection,
                    'INSERT INTO tbl_hms_deployment_profile (name, slices_json, modules_json) VALUES (?,?,?)'
                );
                if ($st) {
                    mysqli_stmt_bind_param($st, 'sss', $name, $slicesJson, $modulesJson);
                    if (mysqli_stmt_execute($st)) {
                        $msg = 'Deployment profile created.';
                        hms_audit_log($connection, 'deployment_profile.create', 'tbl_hms_deployment_profile', (int) mysqli_insert_id($connection));
                    } else {
                        $err = 'Could not create profile.';
                    }
                    mysqli_stmt_close($st);
                }
            } else {
                $st = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_hms_deployment_profile SET name = ?, slices_json = ?, modules_json = ? WHERE id = ? LIMIT 1'
                );
                if ($st) {
                    mysqli_stmt_bind_param($st, 'sssi', $name, $slicesJson, $modulesJson, $pid);
                    if (mysqli_stmt_execute($st)) {
                        $msg = 'Deployment profile updated.';
                        hms_audit_log($connection, 'deployment_profile.update', 'tbl_hms_deployment_profile', $pid);
                    } else {
                        $err = 'Could not update profile.';
                    }
                    mysqli_stmt_close($st);
                }
            }
            if ($err === '') {
                header('Location: super-admin.php');
                exit;
            }
        }
    }
}

// --- Legacy global slices (tbl_app_settings) ---
$hasSlicesCol = function_exists('hms_db_column_exists') && hms_db_column_exists($connection, 'tbl_app_settings', 'product_slices');
$currentSlices = hms_app_product_slices($connection);
$activeProfileId = 0;
if ($dpReady && hms_app_settings_table_ready($connection)) {
    $aq = mysqli_query($connection, 'SELECT active_deployment_profile_id FROM tbl_app_settings WHERE id = 1 LIMIT 1');
    $arw = $aq ? mysqli_fetch_assoc($aq) : null;
    $activeProfileId = (int) ($arw['active_deployment_profile_id'] ?? 0);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['hms_product_mode_save'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Invalid security token.';
    } elseif (!hms_app_settings_table_ready($connection)) {
        $err = 'Database table tbl_app_settings is missing.';
    } else {
        $raw = $_POST['product_slices'] ?? [];
        if (!is_array($raw)) {
            $raw = [];
        }
        $sel = [];
        foreach ($raw as $v) {
            $x = strtolower(trim((string) $v));
            if (in_array($x, $modes, true)) {
                $sel[] = $x;
            }
        }
        $sel = array_values(array_unique($sel));
        if ($sel === []) {
            $err = 'Select at least one product (or Full suite).';
        } elseif (in_array('full', $sel, true)) {
            $sel = ['full'];
        }
        if ($err === '') {
            $primary = $sel[0];
            $json = json_encode($sel, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                $err = 'Could not encode selection.';
            } elseif ($hasSlicesCol) {
                $st = mysqli_prepare(
                    $connection,
                    'UPDATE tbl_app_settings SET product_slices = ?, product_mode = ? WHERE id = 1'
                );
                if ($st) {
                    mysqli_stmt_bind_param($st, 'ss', $json, $primary);
                    if (mysqli_stmt_execute($st)) {
                        $msg = 'Legacy global deployment updated.';
                        $currentSlices = $sel;
                        hms_audit_log($connection, 'app.product_slices', 'tbl_app_settings', 1);
                    } else {
                        $err = 'Could not save settings.';
                    }
                    mysqli_stmt_close($st);
                }
            } else {
                $st = mysqli_prepare($connection, 'UPDATE tbl_app_settings SET product_mode = ? WHERE id = 1');
                if ($st) {
                    mysqli_stmt_bind_param($st, 's', $primary);
                    if (mysqli_stmt_execute($st)) {
                        $msg = 'Product mode saved. Run migration 044_product_slices_multiselect.sql for multi-select storage.';
                        $currentSlices = $sel;
                    } else {
                        $err = 'Could not save settings.';
                    }
                    mysqli_stmt_close($st);
                }
            }
        }
    }
}

$profileList = [];
if ($dpReady) {
    $lq = mysqli_query($connection, 'SELECT id, name, slices_json, modules_json FROM tbl_hms_deployment_profile ORDER BY name ASC');
    while ($lq && $rw = mysqli_fetch_assoc($lq)) {
        $profileList[] = $rw;
    }
}

include 'header.php';

$navLabels = function_exists('hms_nav_module_labels') ? hms_nav_module_labels() : [];
$editSlices = $editRow ? hms_deployment_profile_parse_slices_json((string) ($editRow['slices_json'] ?? '[]')) : [];
$editNavOverride = false;
$editApplyRefine = false;
$editModules = hms_nav_mask_blank();
$editItemOn = function_exists('hms_sidebar_item_ids') ? array_fill_keys(hms_sidebar_item_ids(), true) : [];
$mjRaw = $editRow ? trim((string) ($editRow['modules_json'] ?? '')) : '';
if ($mjRaw !== '') {
    $mj = json_decode($mjRaw, true);
    if (is_array($mj)) {
        $navPart = function_exists('hms_profile_modules_extract_nav_array') ? hms_profile_modules_extract_nav_array($mj) : null;
        if ($navPart !== null) {
            $editNavOverride = true;
            $editModules = hms_nav_normalize_mask($navPart);
        } else {
            $flipNav = array_flip(hms_nav_module_key_list());
            $onlyNav = true;
            foreach ($mj as $mk => $_) {
                $mks = (string) $mk;
                if ($mks === 'items') {
                    continue;
                }
                if (!isset($flipNav[$mks])) {
                    $onlyNav = false;
                    break;
                }
            }
            if ($onlyNav && $mj !== [] && !array_key_exists('nav', $mj) && !array_key_exists('items', $mj)) {
                $editNavOverride = true;
                $editModules = hms_nav_normalize_mask($mj);
            }
        }
        $ir = function_exists('hms_profile_modules_extract_items_raw') ? hms_profile_modules_extract_items_raw($mj) : null;
        $hasItemPicks = is_array($ir) && $ir !== [];
        if ($hasItemPicks) {
            foreach ($ir as $kid => $v) {
                $ks = (string) $kid;
                if (!array_key_exists($ks, $editItemOn)) {
                    continue;
                }
                if ($v === false || $v === 0 || $v === '0') {
                    $editItemOn[$ks] = false;
                }
            }
            if (!$editNavOverride) {
                $editApplyRefine = true;
            }
        }
    }
}
$previewSliceMask = function_exists('hms_nav_mask_from_slices')
    ? hms_nav_mask_from_slices($editSlices === [] ? ['full'] : $editSlices)
    : array_fill_keys(hms_nav_module_key_list(), true);
?>
<div class="page-wrapper">
    <div class="content hms-module">
        <?php
        hms_ui_page_header('Super Admin — product configuration', [
            'subtitle' => $isFormView
                ? ($profileParam === 'new' ? 'Create a named deployment profile' : 'Edit deployment profile')
                : 'Named profiles, optional per-module overrides, and legacy global slices.',
            'breadcrumbs' => [['Dashboard', 'dashboard.php'], ['Super Admin', '']],
        ]);
        if ($err !== '') {
            echo '<div class="alert alert-danger border-0 shadow-sm">' . hms_h($err) . '</div>';
        }
        if ($msg !== '') {
            echo '<div class="alert alert-success border-0 shadow-sm">' . hms_h($msg) . '</div>';
        }
        if (!$dpReady) {
            echo '<div class="alert alert-warning border-0 shadow-sm">Named deployment profiles require <code>database/migrations/045_deployment_profiles.sql</code>. Until then, only legacy global settings are available.</div>';
        }
        ?>

        <?php if ($isFormView) { ?>
        <p class="mb-3"><a href="super-admin.php" class="btn btn-sm btn-outline-secondary">&larr; Back to list</a></p>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-body">
                <form method="post" class="mb-0" id="hmsProfileForm">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="save_deployment_profile" value="1">
                    <input type="hidden" name="profile_id" value="<?php echo $editRow ? (int) $editRow['id'] : 0; ?>">
                    <div class="form-group">
                        <label for="profile_name">Profile name</label>
                        <input type="text" class="form-control" id="profile_name" name="profile_name" required maxlength="160"
                               value="<?php echo hms_h($editRow ? (string) $editRow['name'] : ''); ?>"
                               placeholder="e.g. SOA Hospital + Stock">
                    </div>
                    <h3 class="h6 font-weight-bold mt-4">Product slices</h3>
                    <p class="small text-muted">These combine the same way as the legacy global checkboxes (union of modules). <strong>Full suite</strong> clears the others.</p>
                    <div class="list-group mb-3">
                        <?php foreach ($modes as $m) {
                            $id = 'pf_slice_' . preg_replace('/[^a-z0-9_]/', '_', $m);
                            $checked = in_array($m, $editSlices, true) ? ' checked' : '';
                            ?>
                        <label class="list-group-item d-flex align-items-start mb-0" for="<?php echo hms_h($id); ?>">
                            <input class="mt-1 mr-3 pf-slice-cb" type="checkbox" name="profile_slices[]" id="<?php echo hms_h($id); ?>" value="<?php echo hms_h($m); ?>"<?php echo $checked; ?>>
                            <span><span class="font-weight-bold d-block"><?php echo hms_h(hms_app_product_mode_label($m)); ?></span></span>
                        </label>
                        <?php } ?>
                    </div>
                    <div class="form-check mb-3">
                        <input class="form-check-input" type="checkbox" name="use_custom_modules" id="use_custom_modules" value="1"<?php echo $editNavOverride ? ' checked' : ''; ?>>
                        <label class="form-check-label font-weight-bold" for="use_custom_modules">Override: pick top-level modules</label>
                        <p class="small text-muted mb-0">When checked, the list below replaces the merged slice layout for sidebar <strong>sections</strong>. You can still hide individual links in the granular section.</p>
                    </div>
                    <div id="hmsCustomModulesWrap" class="border rounded p-3 mb-3<?php echo $editNavOverride ? '' : ' d-none'; ?>">
                        <span class="small font-weight-bold text-muted d-block mb-2">Sidebar modules (top-level)</span>
                        <?php foreach (hms_nav_module_key_list() as $nk) {
                            $lab = $navLabels[$nk] ?? $nk;
                            $on = !empty($editModules[$nk]);
                            ?>
                        <div class="form-check">
                            <input class="form-check-input hms-nav-module-cb" type="checkbox" name="nav_module[<?php echo hms_h($nk); ?>]" id="nm_<?php echo hms_h($nk); ?>" value="1"<?php echo $on ? ' checked' : ''; ?>>
                            <label class="form-check-label small" for="nm_<?php echo hms_h($nk); ?>"><?php echo hms_h($lab); ?></label>
                        </div>
                        <?php } ?>
                    </div>
                    <?php if (!$editNavOverride) { ?>
                    <div class="form-check mb-3" id="hmsRefineToggleWrap">
                        <input class="form-check-input" type="checkbox" name="apply_sidebar_link_refine" id="apply_sidebar_link_refine" value="1"<?php echo $editApplyRefine ? ' checked' : ''; ?>>
                        <label class="form-check-label font-weight-bold" for="apply_sidebar_link_refine">Granular: hide specific sidebar links (keep slices above)</label>
                        <p class="small text-muted mb-0">Trim individual links under each product slice. No top-level override — module areas still come only from the checked slices.</p>
                    </div>
                    <?php } ?>
                    <div id="hmsLinkPicksWrap" class="border rounded p-3 mb-3<?php echo ($editNavOverride || $editApplyRefine) ? '' : ' d-none'; ?>">
                        <?php if (function_exists('hms_sidebar_nav_item_groups') && function_exists('hms_sidebar_group_slice_on')) { ?>
                        <span class="small font-weight-bold text-muted d-block mb-1">Sub-modules / sidebar links</span>
                        <p class="small text-muted mb-2">Uncheck a link to hide it. RBAC still applies. With <strong>override</strong>, groups follow the top-level checkboxes; with <strong>granular (slices only)</strong>, groups follow the product slices you selected.</p>
                        <?php foreach (hms_sidebar_nav_item_groups() as $grp) {
                            $pid = (string) $grp['parent'];
                            $pAny = $grp['parent_any'] ?? null;
                            $dataAny = is_array($pAny) && $pAny !== [] ? hms_h(implode(',', $pAny)) : '';
                            $sliceOn = hms_sidebar_group_slice_on($previewSliceMask, $grp);
                            ?>
                        <div class="mb-3 hms-nav-item-group" data-parent="<?php echo hms_h($pid); ?>"<?php echo $dataAny !== '' ? ' data-parent-any="' . $dataAny . '"' : ''; ?> data-slice-on="<?php echo $sliceOn ? '1' : '0'; ?>">
                            <span class="small font-weight-bold d-block mb-1"><?php echo hms_h((string) $grp['title']); ?></span>
                            <?php foreach ($grp['items'] as $it) {
                                $iid = (string) $it['id'];
                                $ion = !empty($editItemOn[$iid]);
                                ?>
                            <div class="form-check">
                                <input class="form-check-input hms-nav-item-cb" type="checkbox" name="nav_item[<?php echo hms_h($iid); ?>]" id="ni_<?php echo hms_h($iid); ?>" value="1"<?php echo $ion ? ' checked' : ''; ?>>
                                <label class="form-check-label small" for="ni_<?php echo hms_h($iid); ?>"><?php echo hms_h((string) $it['label']); ?></label>
                            </div>
                            <?php } ?>
                        </div>
                        <?php } ?>
                        <?php } ?>
                    </div>
                    <button type="submit" class="btn btn-primary font-weight-bold">Save profile</button>
                    <a href="super-admin.php" class="btn btn-outline-secondary ml-2">Cancel</a>
                </form>
                <script>
                (function () {
                    var form = document.getElementById('hmsProfileForm');
                    if (!form) return;
                    var full = document.getElementById('pf_slice_full');
                    var cbs = form.querySelectorAll('input.pf-slice-cb[name="profile_slices[]"]');
                    var custom = document.getElementById('use_custom_modules');
                    var wrap = document.getElementById('hmsCustomModulesWrap');
                    var refine = document.getElementById('apply_sidebar_link_refine');
                    var refineWrap = document.getElementById('hmsRefineToggleWrap');
                    var linkWrap = document.getElementById('hmsLinkPicksWrap');
                    if (full && cbs.length) {
                        full.addEventListener('change', function () {
                            if (full.checked) cbs.forEach(function (b) { if (b !== full) b.checked = false; });
                        });
                        cbs.forEach(function (b) {
                            if (b === full) return;
                            b.addEventListener('change', function () { if (b.checked) full.checked = false; });
                        });
                    }
                    function syncNavItemGroups() {
                        if (!form) return;
                        var customOn = custom && custom.checked;
                        var refineOn = refine && refine.checked;
                        form.querySelectorAll('.hms-nav-item-group').forEach(function (g) {
                            var ok = false;
                            if (customOn) {
                                var any = g.getAttribute('data-parent-any');
                                if (any) {
                                    any.split(',').forEach(function (k) {
                                        var cb = form.querySelector('#nm_' + k.trim());
                                        if (cb && cb.checked) ok = true;
                                    });
                                } else {
                                    var p = g.getAttribute('data-parent');
                                    var cb = p ? form.querySelector('#nm_' + p) : null;
                                    ok = !!(cb && cb.checked);
                                }
                            } else if (refineOn) {
                                ok = g.getAttribute('data-slice-on') === '1';
                            }
                            g.querySelectorAll('input.hms-nav-item-cb').forEach(function (ic) {
                                ic.disabled = !ok;
                            });
                        });
                    }
                    function syncPanels() {
                        var cOn = custom && custom.checked;
                        var rOn = refine && refine.checked;
                        if (wrap) wrap.classList.toggle('d-none', !cOn);
                        if (linkWrap) linkWrap.classList.toggle('d-none', !cOn && !rOn);
                        if (refine && cOn) refine.checked = false;
                        if (refineWrap) refineWrap.classList.toggle('d-none', !!cOn);
                        syncNavItemGroups();
                    }
                    if (custom) custom.addEventListener('change', syncPanels);
                    if (refine) refine.addEventListener('change', syncPanels);
                    if (form) {
                        form.querySelectorAll('input.hms-nav-module-cb').forEach(function (b) {
                            b.addEventListener('change', syncNavItemGroups);
                        });
                        syncPanels();
                    }
                })();
                </script>
            </div>
        </div>

        <?php } else { ?>

        <?php if ($dpReady) { ?>
        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white font-weight-bold">Active deployment profile</div>
            <div class="card-body">
                <p class="small text-muted mb-3">When a profile is active, its slices (and optional custom modules) drive the sidebar for all users. Choose <strong>None (legacy global)</strong> to use the “Legacy global deployment” section at the bottom of this page.</p>
                <form method="post" class="form-inline flex-wrap align-items-end">
                    <?php echo hms_csrf_field(); ?>
                    <input type="hidden" name="set_active_deployment_profile" value="1">
                    <label class="mr-2 font-weight-bold" for="active_deployment_profile_id">Profile</label>
                    <select name="active_deployment_profile_id" id="active_deployment_profile_id" class="form-control mr-2 mb-2">
                        <option value="0"<?php echo $activeProfileId < 1 ? ' selected' : ''; ?>>None (legacy global slices)</option>
                        <?php foreach ($profileList as $pr) { ?>
                        <option value="<?php echo (int) $pr['id']; ?>"<?php echo (int) $pr['id'] === $activeProfileId ? ' selected' : ''; ?>><?php echo hms_h((string) $pr['name']); ?></option>
                        <?php } ?>
                    </select>
                    <button type="submit" class="btn btn-primary mb-2">Apply</button>
                </form>
            </div>
        </div>

        <div class="card border-0 shadow-sm mb-4">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <span class="font-weight-bold">Deployment profiles</span>
                <a href="super-admin.php?profile=new" class="btn btn-sm btn-primary">New profile</a>
            </div>
            <div class="card-body p-0">
                <?php if ($profileList === []) { ?>
                    <p class="p-3 mb-0 text-muted small">No profiles yet. Create one to save a reusable combination of products (and optional module overrides).</p>
                <?php } else { ?>
                <div class="table-responsive">
                    <table class="table table-hover mb-0">
                        <thead class="thead-light"><tr><th>Name</th><th>Slices</th><th>Modules</th><th class="text-right">Actions</th></tr></thead>
                        <tbody>
                        <?php foreach ($profileList as $pr) {
                            $sl = hms_deployment_profile_parse_slices_json((string) ($pr['slices_json'] ?? '[]'));
                            $slDisp = $sl === [] ? '—' : implode(', ', array_map(static function ($c) {
                                return hms_app_product_mode_label($c);
                            }, $sl));
                            $hasM = trim((string) ($pr['modules_json'] ?? '')) !== '';
                            $hasLinks = false;
                            $hasNavOverride = false;
                            if ($hasM) {
                                $pj = json_decode(trim((string) ($pr['modules_json'] ?? '')), true);
                                $hasLinks = is_array($pj) && array_key_exists('items', $pj) && is_array($pj['items']) && $pj['items'] !== [];
                                if (is_array($pj) && function_exists('hms_profile_modules_extract_nav_array')) {
                                    $hasNavOverride = hms_profile_modules_extract_nav_array($pj) !== null;
                                }
                            }
                            $modCol = 'From slices';
                            if ($hasM) {
                                if ($hasLinks && !$hasNavOverride) {
                                    $modCol = 'Granular links only';
                                } elseif ($hasLinks) {
                                    $modCol = 'Override + link picks';
                                } else {
                                    $modCol = 'Override';
                                }
                            }
                            ?>
                        <tr>
                            <td class="font-weight-bold"><?php echo hms_h((string) $pr['name']); ?><?php echo (int) $pr['id'] === $activeProfileId ? ' <span class="badge badge-success">Active</span>' : ''; ?></td>
                            <td class="small"><?php echo hms_h($slDisp); ?></td>
                            <td class="small"><?php echo hms_h($modCol); ?></td>
                            <td class="text-right text-nowrap">
                                <a class="btn btn-sm btn-outline-primary" href="super-admin.php?profile=<?php echo (int) $pr['id']; ?>">Edit</a>
                                <form method="post" class="d-inline" onsubmit="return confirm('Delete this profile?');">
                                    <?php echo hms_csrf_field(); ?>
                                    <input type="hidden" name="delete_deployment_profile" value="1">
                                    <input type="hidden" name="profile_id" value="<?php echo (int) $pr['id']; ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Delete</button>
                                </form>
                            </td>
                        </tr>
                        <?php } ?>
                        </tbody>
                    </table>
                </div>
                <?php } ?>
            </div>
        </div>
        <?php } ?>

        <div class="row">
            <div class="col-lg-8">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-body">
                        <h2 class="h5 font-weight-bold mb-3">Legacy global deployment</h2>
                        <p class="text-muted small mb-3">
                            Used only when <strong>no named profile</strong> is active. Merges checked products for all users (Super Admin still sees the full menu).
                            <?php if (!$hasSlicesCol) { ?>Run <code>044_product_slices_multiselect.sql</code> for multi-select storage.<?php } ?>
                        </p>
                        <?php if (!hms_app_settings_table_ready($connection)) { ?>
                            <div class="alert alert-warning">Run <code>042_super_admin_product_mode.sql</code>.</div>
                        <?php } else { ?>
                        <form method="post" class="mb-0" id="hmsProductSlicesForm">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="hms_product_mode_save" value="1">
                            <div class="list-group">
                                <?php foreach ($modes as $m) {
                                    $id = 'hms_mode_' . preg_replace('/[^a-z0-9_]/', '_', $m);
                                    $checked = in_array($m, $currentSlices, true) ? ' checked' : '';
                                    ?>
                                <label class="list-group-item d-flex align-items-start mb-0" for="<?php echo hms_h($id); ?>">
                                    <input class="mt-1 mr-3 hms-product-slice-cb" type="checkbox" name="product_slices[]" id="<?php echo hms_h($id); ?>" value="<?php echo hms_h($m); ?>"<?php echo $checked; ?>>
                                    <span>
                                        <span class="font-weight-bold d-block"><?php echo hms_h(hms_app_product_mode_label($m)); ?></span>
                                        <?php
                                        $help = [
                                            'full' => 'All modules visible. Selecting this clears other products.',
                                            'hms' => 'Clinical operations; hides accounting, tax, inventory, procurement, HR payroll bundles, and self-service links.',
                                            'accounting' => 'Financials and expense management only.',
                                            'leave_attendance' => 'Staff, leave, attendance, holidays, self-service.',
                                            'tax_cameroon' => 'Tax module plus staff list.',
                                            'payroll' => 'Payroll-focused bundle; affects landing priority when combined.',
                                            'inventory' => 'Catalog + inventory.',
                                            'procurement' => 'Catalog + inventory + purchase orders.',
                                        ];
                                    echo '<span class="small text-muted">' . hms_h($help[$m] ?? '') . '</span>';
                                    ?>
                                    </span>
                                </label>
                                <?php } ?>
                            </div>
                            <div class="mt-4">
                                <button type="submit" class="btn btn-primary font-weight-bold">Save legacy global</button>
                                <a href="dashboard.php" class="btn btn-outline-secondary ml-2">Open dashboard</a>
                            </div>
                        </form>
                        <script>
                        (function () {
                            var form = document.getElementById('hmsProductSlicesForm');
                            if (!form) return;
                            var full = document.getElementById('hms_mode_full');
                            var cbs = form.querySelectorAll('input.hms-product-slice-cb[name="product_slices[]"]');
                            if (!full || !cbs.length) return;
                            full.addEventListener('change', function () {
                                if (full.checked) cbs.forEach(function (b) { if (b !== full) b.checked = false; });
                            });
                            cbs.forEach(function (b) {
                                if (b === full) return;
                                b.addEventListener('change', function () { if (b.checked) full.checked = false; });
                            });
                        })();
                        </script>
                        <?php } ?>
                    </div>
                </div>
            </div>
            <div class="col-lg-4">
                <div class="card border-0 shadow-sm mb-4">
                    <div class="card-header bg-white font-weight-bold">Super Admin account</div>
                    <div class="card-body small text-muted">
                        <p class="mb-2">Username: <strong class="text-dark">super</strong></p>
                        <p class="mb-0">Change password under <a href="my-profile.php">My profile</a>. Normal Admin cannot open this page.</p>
                    </div>
                </div>
            </div>
        </div>
        <?php } ?>
    </div>
</div>
<?php
include 'footer.php';
