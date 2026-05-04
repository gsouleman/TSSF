<?php
declare(strict_types=1);

/**
 * Sidebar link IDs for deployment profiles (modules_json.items).
 * Each id maps to one parent nav flag from hms_nav_module_key_list().
 *
 * @return list<array{parent:string,title:string,parent_any?:list<string>,items:list<array{id:string,label:string}>}>
 */
function hms_sidebar_nav_item_groups(): array
{
    return [
        [
            'parent' => 'portals',
            'title' => 'Portals',
            'items' => [
                ['id' => 'portal_front_desk', 'label' => 'Front Desk'],
                ['id' => 'portal_patient', 'label' => 'Patient portal'],
                ['id' => 'portal_doctors', 'label' => 'Doctor portal'],
                ['id' => 'portal_nursing', 'label' => 'Nurse portal'],
                ['id' => 'portal_laboratory', 'label' => 'Laboratory portal'],
                ['id' => 'portal_pharmacy', 'label' => 'Pharmacy portal'],
                ['id' => 'portal_radiology', 'label' => 'Radiology portal'],
                ['id' => 'portal_accountant', 'label' => 'Accountant portal'],
                ['id' => 'portal_cashier', 'label' => 'Cashier portal'],
            ],
        ],
        [
            'parent' => 'healthcare',
            'title' => 'Healthcare',
            'items' => [
                ['id' => 'hc_patient_list', 'label' => 'Patient — list'],
                ['id' => 'hc_patient_add', 'label' => 'Patient — add'],
                ['id' => 'hc_doctors', 'label' => 'Doctor'],
                ['id' => 'hc_nursing', 'label' => 'Nurse (healthcare group)'],
                ['id' => 'hc_requests', 'label' => 'Requests'],
                ['id' => 'hc_appt_calendar', 'label' => 'Appointments — calendar'],
                ['id' => 'hc_appt_list', 'label' => 'Appointments — list'],
                ['id' => 'hc_appt_consult', 'label' => 'Appointments — consultations'],
                ['id' => 'hc_visits', 'label' => 'Visits'],
                ['id' => 'hc_lab', 'label' => 'Laboratory — results'],
                ['id' => 'hc_radiology', 'label' => 'Radiology — results'],
                ['id' => 'hc_pharmacy', 'label' => 'Pharmacy'],
                ['id' => 'hc_billing', 'label' => 'Billing'],
                ['id' => 'hc_insurance', 'label' => 'Insurance — carriers'],
                ['id' => 'hc_insurance_claims', 'label' => 'Insurance — claims'],
                ['id' => 'hc_cashier', 'label' => 'Cashier'],
                ['id' => 'hc_credit', 'label' => 'Credit & receivables'],
                ['id' => 'hc_ward', 'label' => 'Ward & bed management'],
                ['id' => 'hc_wallet', 'label' => 'Wallet / top-up'],
            ],
        ],
        [
            'parent' => 'accounting',
            'title' => 'Accounting',
            'items' => [
                ['id' => 'acct_financials', 'label' => 'Financials'],
                ['id' => 'acct_trial_balance', 'label' => 'Trial balance'],
                ['id' => 'acct_general_ledger', 'label' => 'General ledger'],
                ['id' => 'acct_cash_flow', 'label' => 'Cash flow'],
                ['id' => 'acct_ar', 'label' => 'Accounts receivable'],
                ['id' => 'acct_ap', 'label' => 'Accounts payable'],
                ['id' => 'acct_bank_rec', 'label' => 'Bank reconciliation'],
                ['id' => 'acct_balance_sheet', 'label' => 'Balance sheet'],
                ['id' => 'acct_sync_gl', 'label' => 'Sync to GL'],
                ['id' => 'acct_journal_loader', 'label' => 'Journal loader'],
                ['id' => 'acct_monthly_pl', 'label' => 'Monthly P&L'],
                ['id' => 'acct_annual_pl', 'label' => 'Annual P&L'],
                ['id' => 'acct_monthly_review', 'label' => 'Monthly review'],
                ['id' => 'acct_annual_review', 'label' => 'Annual review'],
                ['id' => 'acct_expenses', 'label' => 'Expense management'],
            ],
        ],
        [
            'parent' => 'tax',
            'title' => 'Tax (Cameroon)',
            'items' => [
                ['id' => 'tax_home', 'label' => 'Tax home'],
                ['id' => 'tax_declarations', 'label' => 'VAT & declarations'],
                ['id' => 'tax_payroll_settings', 'label' => 'Payroll tax settings'],
                ['id' => 'tax_payroll_lines', 'label' => 'Payroll lines'],
                ['id' => 'tax_cnps', 'label' => 'CNPS DIPE'],
                ['id' => 'tax_dgi', 'label' => 'DGI CSV'],
                ['id' => 'tax_compliance', 'label' => 'Compliance'],
                ['id' => 'tax_reports', 'label' => 'Tax reports'],
            ],
        ],
        [
            'parent' => 'manage_catalog',
            'title' => 'Manage — Service catalog',
            'items' => [
                ['id' => 'm_service_catalog', 'label' => 'Service catalog'],
            ],
        ],
        [
            'parent' => 'manage_inventory',
            'parent_any' => ['manage_inventory', 'manage_procurement'],
            'title' => 'Manage — Inventory / procurement',
            'items' => [
                ['id' => 'm_inventory', 'label' => 'Inventory (hub)'],
            ],
        ],
        [
            'parent' => 'manage_schedule',
            'title' => 'Manage — Schedule',
            'items' => [
                ['id' => 'm_doctor_schedule', 'label' => 'Doctor schedule'],
            ],
        ],
        [
            'parent' => 'manage_departments',
            'title' => 'Manage — Departments',
            'items' => [
                ['id' => 'm_departments', 'label' => 'Departments'],
            ],
        ],
        [
            'parent' => 'manage_staff',
            'title' => 'Manage — Staff',
            'items' => [
                ['id' => 'm_staffs', 'label' => 'Staff directory'],
            ],
        ],
        [
            'parent' => 'manage_payroll',
            'title' => 'Manage — Payroll',
            'items' => [
                ['id' => 'm_payroll', 'label' => 'Payroll'],
                ['id' => 'm_pay_profiles', 'label' => 'Pay profiles'],
            ],
        ],
        [
            'parent' => 'manage_leave_admin',
            'title' => 'Manage — Leave & attendance (admin)',
            'items' => [
                ['id' => 'm_leave_approvals', 'label' => 'Leave approvals'],
                ['id' => 'm_attendance', 'label' => 'Attendance'],
            ],
        ],
        [
            'parent' => 'manage_holidays',
            'title' => 'Manage — Holidays',
            'items' => [
                ['id' => 'm_holidays', 'label' => 'Holidays'],
            ],
        ],
        [
            'parent' => 'manage_self',
            'title' => 'Manage — Self-service',
            'items' => [
                ['id' => 'm_request_leave', 'label' => 'Request leave'],
                ['id' => 'm_my_payslips', 'label' => 'My payslips'],
                ['id' => 'm_my_attendance', 'label' => 'My attendance'],
                ['id' => 'm_leave_balance', 'label' => 'Leave balance'],
            ],
        ],
        [
            'parent' => 'access_control',
            'title' => 'Manage — Access control',
            'items' => [
                ['id' => 'm_access_control', 'label' => 'Access control'],
            ],
        ],
    ];
}

/** @return list<string> */
function hms_sidebar_item_ids(): array
{
    static $ids = null;
    if ($ids !== null) {
        return $ids;
    }
    $ids = [];
    foreach (hms_sidebar_nav_item_groups() as $g) {
        foreach ($g['items'] as $it) {
            $ids[] = $it['id'];
        }
    }

    return $ids;
}

/** @return array<string,string> id => parent nav key */
function hms_sidebar_item_parent_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (hms_sidebar_nav_item_groups() as $g) {
        $p = (string) $g['parent'];
        foreach ($g['items'] as $it) {
            $map[(string) $it['id']] = $p;
        }
    }

    return $map;
}

function hms_sidebar_item_parent(string $itemId): ?string
{
    $m = hms_sidebar_item_parent_map();

    return $m[$itemId] ?? null;
}

/** Whether a sidebar link group's parent area is enabled in a merged slice mask. */
function hms_sidebar_group_slice_on(array $sliceMask, array $grp): bool
{
    $pAny = $grp['parent_any'] ?? null;
    if (is_array($pAny) && $pAny !== []) {
        foreach ($pAny as $pk) {
            if (!empty($sliceMask[(string) $pk])) {
                return true;
            }
        }

        return false;
    }

    return !empty($sliceMask[(string) $grp['parent']]);
}

/** Whether an item's parent area is on in a slice-derived mask (for saving refine-only picks). */
function hms_sidebar_item_slice_parent_on(array $sliceMask, string $itemId): bool
{
    if ($itemId === 'm_inventory') {
        return !empty($sliceMask['manage_inventory']) || !empty($sliceMask['manage_procurement']);
    }
    $p = hms_sidebar_item_parent($itemId);

    return $p !== null && !empty($sliceMask[$p]);
}

function hms_nav_sidebar_item_visible(mysqli $connection, array $hmsSn, string $itemId): bool
{
    if (function_exists('hms_is_super_admin') && hms_is_super_admin()) {
        return true;
    }
    if ($itemId === 'm_inventory') {
        if (empty($hmsSn['manage_inventory']) && empty($hmsSn['manage_procurement'])) {
            return false;
        }
    } else {
        $parent = hms_sidebar_item_parent($itemId);
        if ($parent === null || $parent === '' || empty($hmsSn[$parent])) {
            return false;
        }
    }
    static $gateDone = false;
    static $useItems = false;
    static $itemsData = [];
    if (!$gateDone) {
        $gateDone = true;
        $row = function_exists('hms_active_deployment_profile_row') ? hms_active_deployment_profile_row($connection) : null;
        if (is_array($row)) {
            $raw = trim((string) ($row['modules_json'] ?? ''));
            if ($raw !== '' && $raw !== 'null') {
                $j = json_decode($raw, true);
                if (is_array($j) && array_key_exists('items', $j) && is_array($j['items']) && $j['items'] !== []) {
                    $flip = array_flip(hms_sidebar_item_ids());
                    foreach ($j['items'] as $k => $v) {
                        $ks = (string) $k;
                        if (isset($flip[$ks])) {
                            $itemsData[$ks] = $v;
                        }
                    }
                    $useItems = $itemsData !== [];
                }
            }
        }
    }
    if (!$useItems) {
        return true;
    }
    if (!array_key_exists($itemId, $itemsData)) {
        return true;
    }
    $v = $itemsData[$itemId];
    if ($v === false || $v === 0 || $v === '0') {
        return false;
    }

    return (bool) $v;
}
