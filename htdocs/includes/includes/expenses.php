<?php
declare(strict_types=1);

/**
 * Expense management module (see expense-management.php).
 */

function hms_expenses_ready(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_expense');
}

/**
 * Read access: migration 026 adds expenses.read; before that, billing.read covers accountants.
 */
function hms_expenses_can_read(mysqli $connection): bool
{
    if (empty($_SESSION['name'])) {
        return false;
    }
    if ((string) ($_SESSION['role'] ?? '') === '1') {
        return true;
    }
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return true;
    }

    return hms_can($connection, 'expenses.read') || hms_can($connection, 'billing.read');
}

/**
 * Write access: expenses.write or billing.write (same fallback as read).
 */
function hms_expenses_can_write(mysqli $connection): bool
{
    if (empty($_SESSION['name'])) {
        return false;
    }
    if ((string) ($_SESSION['role'] ?? '') === '1') {
        return true;
    }
    if (!hms_db_table_exists($connection, 'tbl_acl_permission')) {
        return true;
    }

    return hms_can($connection, 'expenses.write') || hms_can($connection, 'billing.write');
}

/**
 * Categories for expense forms: suggested defaults merged with distinct values already used at this facility.
 *
 * @return list<string>
 */
function hms_expense_category_choices(mysqli $connection, int $facilityId): array
{
    $seen = [];
    $out = [];
    $add = static function (string $c) use (&$seen, &$out): void {
        $c = trim($c);
        if ($c === '') {
            return;
        }
        $k = function_exists('mb_strtolower') ? mb_strtolower($c, 'UTF-8') : strtolower($c);
        if (isset($seen[$k])) {
            return;
        }
        $seen[$k] = true;
        $out[] = $c;
    };

    $defaults = [
        'Utilities',
        'Supplies',
        'Salaries & wages',
        'Rent',
        'Transport',
        'Communications',
        'Maintenance',
        'Professional fees',
        'Insurance',
        'Taxes & duties',
        'Bank charges',
        'Equipment',
        'Other',
    ];
    foreach ($defaults as $d) {
        $add($d);
    }

    if (hms_expenses_ready($connection) && $facilityId > 0) {
        $q = mysqli_query(
            $connection,
            'SELECT DISTINCT TRIM(category) AS c FROM tbl_expense WHERE facility_id = ' . (int) $facilityId
            . " AND TRIM(category) <> '' ORDER BY c ASC"
        );
        if ($q) {
            while ($row = mysqli_fetch_assoc($q)) {
                $add((string) ($row['c'] ?? ''));
            }
            mysqli_free_result($q);
        }
    }

    sort($out, SORT_NATURAL | SORT_FLAG_CASE);

    return $out;
}

/**
 * @return array{ok:bool, rows:list<array<string,mixed>>, error:string}
 */
function hms_expense_rows_for_facility(mysqli $connection, int $facilityId): array
{
    if (!hms_expenses_ready($connection) || $facilityId < 1) {
        return ['ok' => true, 'rows' => [], 'error' => ''];
    }

    $cols = ['e.id', 'e.amount_xaf'];
    if (hms_db_column_exists($connection, 'tbl_expense', 'expense_date')) {
        $cols[] = 'e.expense_date';
    }
    $opt = [
        'category' => 'e.category',
        'description' => 'e.description',
        'payment_method' => 'e.payment_method',
        'reference' => 'e.reference',
        'vendor' => 'e.vendor',
        'created_at' => 'e.created_at',
        'created_by' => 'e.created_by',
    ];
    foreach ($opt as $name => $sqlExpr) {
        if (hms_db_column_exists($connection, 'tbl_expense', $name)) {
            $cols[] = $sqlExpr;
        }
    }

    $orderBy = hms_db_column_exists($connection, 'tbl_expense', 'expense_date')
        ? 'e.expense_date DESC, e.id DESC'
        : 'e.id DESC';

    $nameSql = ', \'\' AS created_by_name';
    $join = '';
    if (
        hms_db_column_exists($connection, 'tbl_expense', 'created_by')
        && hms_db_table_exists($connection, 'tbl_employee')
    ) {
        $join = ' LEFT JOIN tbl_employee emp ON emp.id = e.created_by ';
        $nameSql = ', TRIM(CONCAT(COALESCE(emp.first_name, \'\'), \' \', COALESCE(emp.last_name, \'\'))) AS created_by_name';
    }

    $sql = 'SELECT ' . implode(', ', $cols) . $nameSql
        . ' FROM tbl_expense e ' . $join
        . ' WHERE e.facility_id = ' . (int) $facilityId
        . ' ORDER BY ' . $orderBy . ' LIMIT 500';

    $q = mysqli_query($connection, $sql);
    if (!$q) {
        return ['ok' => false, 'rows' => [], 'error' => 'Could not load expenses.'];
    }
    $rows = [];
    while ($r = mysqli_fetch_assoc($q)) {
        $rows[] = $r;
    }
    mysqli_free_result($q);

    return ['ok' => true, 'rows' => $rows, 'error' => ''];
}
