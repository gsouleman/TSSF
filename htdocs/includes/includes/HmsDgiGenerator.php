<?php
declare(strict_types=1);

/**
 * Indicative CSV summaries for IRPP / payroll remittance tracking (not a DGI file format).
 */
final class HmsDgiGenerator
{
    private mysqli $conn;
    private int $facilityId;
    /** @var array<string, mixed>|null */
    private ?array $employer = null;

    public function __construct(mysqli $connection, int $facilityId)
    {
        $this->conn = $connection;
        $this->facilityId = $facilityId;
        $stmt = mysqli_prepare(
            $this->conn,
            'SELECT * FROM tbl_hms_payroll_settings WHERE facility_id = ? ORDER BY tax_year DESC LIMIT 1'
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $this->facilityId);
            mysqli_stmt_execute($stmt);
            $this->employer = hms_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);
        }
    }

    /**
     * @return array{csv: list<list<string|int|float|null>>, filename: string, summary: array<string, mixed>}
     */
    public function generateMonthlyTaxDeclaration(int $month, int $year): array
    {
        $stmt = mysqli_prepare(
            $this->conn,
            'SELECT SUM(gross_salary) AS tg, SUM(cnps_employee) AS cnps, SUM(cimr_employee) AS cimr, SUM(crtv_deduction) AS crtv, '
            . 'SUM(council_tax_deduction) AS council, SUM(development_tax_deduction) AS dev, SUM(cnhc_deduction) AS cnhc, '
            . 'SUM(income_tax) AS irpp, SUM(net_salary) AS net, COUNT(*) AS emp_cnt '
            . 'FROM tbl_hms_payroll_record WHERE month = ? AND year = ? AND facility_id = ?'
        );
        $sum = [
            'tg' => null, 'cnps' => null, 'cimr' => null, 'crtv' => null, 'council' => null,
            'dev' => null, 'cnhc' => null, 'irpp' => null, 'net' => null, 'emp_cnt' => 0,
        ];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'iii', $month, $year, $this->facilityId);
            mysqli_stmt_execute($stmt);
            $row = hms_stmt_fetch_assoc($stmt);
            mysqli_stmt_close($stmt);
            if (is_array($row)) {
                $sum = array_merge($sum, $row);
            }
        }
        $niu = (string) ($this->employer['employer_niu'] ?? '');
        $csv = [];
        $csv[] = ['Type', 'NIU Employeur', 'Mois', 'Année', 'Date', 'Nb Salariés', 'Masse Brute', 'CNPS', 'CIMR', 'CRTV', 'Taxe Communale', 'Taxe Développement', 'CNHC', 'IRPP', 'Masse Nette'];
        $csv[] = [
            'SOMMAIRE',
            $niu,
            (string) $month,
            (string) $year,
            date('Y-m-d'),
            (string) ($sum['emp_cnt'] ?? 0),
            $sum['tg'],
            $sum['cnps'],
            $sum['cimr'],
            $sum['crtv'],
            $sum['council'],
            $sum['dev'],
            $sum['cnhc'],
            $sum['irpp'],
            $sum['net'],
        ];
        $csv[] = [];
        $csv[] = ['DETAIL PAR SALARIÉ', '', '', '', '', 'Salaire Brut', 'IRPP', 'Salaire Net'];
        $q = mysqli_prepare(
            $this->conn,
            'SELECT e.first_name, e.last_name, e.employee_id, p.gross_salary, p.income_tax, p.net_salary '
            . 'FROM tbl_hms_payroll_record p JOIN tbl_employee e ON p.employee_id = e.id '
            . 'WHERE p.month = ? AND p.year = ? AND p.facility_id = ? ORDER BY e.last_name, e.first_name'
        );
        if ($q) {
            mysqli_stmt_bind_param($q, 'iii', $month, $year, $this->facilityId);
            mysqli_stmt_execute($q);
            $dr = mysqli_stmt_get_result($q);
            if ($dr) {
                while ($d = mysqli_fetch_assoc($dr)) {
                    $csv[] = [
                        (string) ($d['employee_id'] ?? ''),
                        '',
                        (string) ($d['last_name'] ?? ''),
                        (string) ($d['first_name'] ?? ''),
                        '',
                        $d['gross_salary'],
                        $d['income_tax'],
                        $d['net_salary'],
                    ];
                }
            }
            mysqli_stmt_close($q);
        }

        return [
            'csv' => $csv,
            'filename' => 'DGI_DECL_' . $this->facilityId . '_' . date('Ym', mktime(0, 0, 0, $month, 1, $year)) . '.csv',
            'summary' => $sum,
        ];
    }

    /**
     * @return array{csv: list<list<string|int|float|null>>, filename: string}
     */
    public function generateAnnualTaxSummary(int $year): array
    {
        $hasNiu = hms_db_column_exists($this->conn, 'tbl_employee', 'tax_niu');
        $sql = 'SELECT e.employee_id, e.first_name, e.last_name, '
            . ($hasNiu ? 'e.tax_niu AS niu' : "'' AS niu") . ', '
            . 'SUM(p.gross_salary) AS annual_gross, SUM(p.cnps_employee) AS annual_cnps, SUM(p.cimr_employee) AS annual_cimr, '
            . 'SUM(p.income_tax) AS annual_irpp, SUM(p.net_salary) AS annual_net '
            . 'FROM tbl_hms_payroll_record p JOIN tbl_employee e ON p.employee_id = e.id '
            . 'WHERE p.year = ? AND p.facility_id = ? '
            . 'GROUP BY e.id, e.employee_id, e.first_name, e.last_name'
            . ($hasNiu ? ', e.tax_niu' : '');
        $stmt = mysqli_prepare($this->conn, $sql);
        $csv = [['Matricule', 'NIU', 'Nom', 'Prénom', 'Salaire Brut Annuel', 'CNPS Annuel', 'CIMR Annuel', 'IRPP Annuel', 'Salaire Net Annuel']];
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'ii', $year, $this->facilityId);
            mysqli_stmt_execute($stmt);
            $res = mysqli_stmt_get_result($stmt);
            if ($res) {
                while ($e = mysqli_fetch_assoc($res)) {
                    $csv[] = [
                        (string) ($e['employee_id'] ?? ''),
                        (string) ($e['niu'] ?? ''),
                        (string) ($e['last_name'] ?? ''),
                        (string) ($e['first_name'] ?? ''),
                        $e['annual_gross'],
                        $e['annual_cnps'],
                        $e['annual_cimr'],
                        $e['annual_irpp'],
                        $e['annual_net'],
                    ];
                }
            }
            mysqli_stmt_close($stmt);
        }

        return ['csv' => $csv, 'filename' => 'DGI_ANNUEL_' . $this->facilityId . '_' . $year . '.csv'];
    }

    /**
     * @param array{csv: list<list<string|int|float|null>>, filename: string} $data
     */
    public function saveCSV(array $data): string
    {
        $root = dirname(__DIR__);
        $dir = $root . DIRECTORY_SEPARATOR . 'exports' . DIRECTORY_SEPARATOR . 'dgi' . DIRECTORY_SEPARATOR;
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            return '';
        }
        $filename = (string) ($data['filename'] ?? 'export.csv');
        $path = $dir . $filename;
        $f = fopen($path, 'wb');
        if ($f === false) {
            return '';
        }
        foreach ($data['csv'] as $row) {
            fputcsv($f, $row, ';');
        }
        fclose($f);

        return 'exports/dgi/' . $filename;
    }
}
