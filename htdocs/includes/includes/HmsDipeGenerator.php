<?php
declare(strict_types=1);

/**
 * Fixed-width DIPE-style export (indicative layout for e-CNPS workflows).
 * Data source: tbl_hms_payroll_record + tbl_employee for the current facility.
 */
final class HmsDipeGenerator
{
    private mysqli $conn;
    private int $facilityId;
    /** @var array<string, mixed>|null */
    private ?array $employer = null;

    public function __construct(mysqli $connection, int $facilityId)
    {
        $this->conn = $connection;
        $this->facilityId = $facilityId;
        $this->loadEmployer();
    }

    private function loadEmployer(): void
    {
        $stmt = mysqli_prepare(
            $this->conn,
            'SELECT * FROM tbl_hms_payroll_settings WHERE facility_id = ? ORDER BY tax_year DESC LIMIT 1'
        );
        if (!$stmt) {
            return;
        }
        mysqli_stmt_bind_param($stmt, 'i', $this->facilityId);
        mysqli_stmt_execute($stmt);
        $this->employer = hms_stmt_fetch_assoc($stmt);
        mysqli_stmt_close($stmt);
    }

    public function generateMonthlyDIPE(int $month, int $year): string
    {
        $hasCnpsCol = hms_db_column_exists($this->conn, 'tbl_employee', 'tax_cnps_number');
        $cnpsExpr = $hasCnpsCol ? 'COALESCE(NULLIF(TRIM(e.tax_cnps_number), \'\'), e.employee_id)' : 'e.employee_id';
        $sql = 'SELECT e.id, e.first_name, e.last_name, e.employee_id, '
            . $cnpsExpr . ' AS cnps_ref, '
            . 'COALESCE(p.gross_salary, 0) AS gross, COALESCE(p.cnps_employee, 0) AS cnps, '
            . 'COALESCE(p.cimr_employee, 0) AS cimr, COALESCE(p.income_tax, 0) AS irpp, '
            . 'COALESCE(p.council_tax_deduction, 0) AS council '
            . 'FROM tbl_employee e '
            . 'LEFT JOIN tbl_hms_payroll_record p ON e.id = p.employee_id AND p.month = ? AND p.year = ? AND p.facility_id = ? '
            . 'WHERE e.status = 1 '
            . 'HAVING gross > 0 '
            . 'ORDER BY e.id';
        $stmt = mysqli_prepare($this->conn, $sql);
        if (!$stmt) {
            return '';
        }
        mysqli_stmt_bind_param($stmt, 'iii', $month, $year, $this->facilityId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $lines = [];
        $i = 1;
        if ($res) {
            while ($e = mysqli_fetch_assoc($res)) {
                $lines[] = $this->formatLine($e, $month, $year, $i);
                $i++;
            }
        }
        mysqli_stmt_close($stmt);

        return implode("\r\n", $lines);
    }

    /**
     * @param array<string, mixed> $e
     */
    private function formatLine(array $e, int $month, int $year, int $i): string
    {
        $emp = $this->employer ?? [];
        $niu = str_pad(substr(preg_replace('/\D/', '', (string) ($emp['employer_niu'] ?? '')), 0, 14), 14, ' ', STR_PAD_RIGHT);
        $empNum = str_pad(substr(preg_replace('/\D/', '', (string) ($emp['employer_cnps_number'] ?? '')), 0, 10), 10, '0', STR_PAD_LEFT);
        $assure = str_pad(substr(preg_replace('/\D/', '', (string) ($e['cnps_ref'] ?? '')), 0, 10), 10, '0', STR_PAD_LEFT);
        $workingDays = 26;
        $gross = (int) round((float) ($e['gross'] ?? 0));
        $cnps = (float) ($e['cnps'] ?? 0);
        $cimr = (float) ($e['cimr'] ?? 0);
        $taxable = (int) round($gross - $cnps - $cimr);
        $cotisable = min($gross, 750000);
        $reg = (string) ($emp['cnps_regime'] ?? '1');
        $reg = strlen($reg) === 1 ? $reg : '1';
        $code = substr(preg_replace('/\s+/', '', (string) ($e['employee_id'] ?? (string) ($e['id'] ?? ''))), 0, 14);
        $code = str_pad($code, 14, ' ');

        return 'C04'
            . str_pad((string) $i, 5, '0', STR_PAD_LEFT)
            . '0'
            . $niu
            . '01'
            . $empNum
            . '0'
            . $reg
            . (string) $year
            . $assure
            . '0'
            . str_pad((string) $workingDays, 2, '0', STR_PAD_LEFT)
            . str_pad((string) $gross, 10, '0', STR_PAD_LEFT)
            . str_repeat('0', 10)
            . str_pad((string) max(0, $taxable), 10, '0', STR_PAD_LEFT)
            . str_pad((string) $cotisable, 10, '0', STR_PAD_LEFT)
            . str_pad((string) $cotisable, 10, '0', STR_PAD_LEFT)
            . str_pad((string) max(0, (int) round((float) ($e['irpp'] ?? 0))), 8, '0', STR_PAD_LEFT)
            . str_pad((string) max(0, (int) round((float) ($e['council'] ?? 0))), 6, '0', STR_PAD_LEFT)
            . str_pad((string) min(99, $i), 2, '0', STR_PAD_LEFT)
            . $code
            . ' ';
    }

    /**
     * @return array{filename: string, path: string, relative: string, id: int}|null
     */
    public function saveDIPEFile(int $month, int $year, int $generatedBy): ?array
    {
        $content = $this->generateMonthlyDIPE($month, $year);
        if ($content === '') {
            return null;
        }
        $root = dirname(__DIR__);
        $dir = $root . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'dipe' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return null;
        }
        $file = 'DIPE_' . $this->facilityId . '_' . date('Ym', mktime(0, 0, 0, $month, 1, $year)) . '_' . date('YmdHis') . '.txt';
        $full = $dir . $file;
        if (file_put_contents($full, $content) === false) {
            return null;
        }
        $rel = 'exports/dipe/' . $file;
        $stmt = mysqli_prepare(
            $this->conn,
            'INSERT INTO tbl_hms_dipe_history (facility_id, month, year, filename, file_path, generated_by) VALUES (?,?,?,?,?,?)'
        );
        if (!$stmt) {
            return null;
        }
        mysqli_stmt_bind_param(
            $stmt,
            'iiissi',
            $this->facilityId,
            $month,
            $year,
            $file,
            $rel,
            $generatedBy
        );
        mysqli_stmt_execute($stmt);
        $newId = (int) mysqli_insert_id($this->conn);
        mysqli_stmt_close($stmt);

        return ['filename' => $file, 'path' => $full, 'relative' => $rel, 'id' => $newId];
    }
}
