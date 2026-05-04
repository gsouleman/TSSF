<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}

if (!isset($connection) || !($connection instanceof mysqli)) {
    http_response_code(503);
    exit('Database connection is not available.');
}

hms_require_permission($connection, 'financials.read');

/**
 * Run SELECT; avoids uncaught mysqli_sql_exception (PHP 8.1+ strict mysqli).
 *
 * @return mysqli_result|false
 */
function hms_tb_mysqli_query(mysqli $connection, string $sql)
{
    try {
        return mysqli_query($connection, $sql);
    } catch (Throwable $e) {
        if (function_exists('error_log')) {
            error_log('financials-trial-balance SQL: ' . $e->getMessage());
        }

        return false;
    }
}

/**
 * Local GL aggregates (self-contained; avoids host-specific issues with shared helpers).
 *
 * @return list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float}>
 */
function hms_tb_fetch_movements(mysqli $connection, int $facilityId, string $dateFrom, string $dateTo): array
{
    $fid = (int) $facilityId;
    $d1 = mysqli_real_escape_string($connection, $dateFrom);
    $d2 = mysqli_real_escape_string($connection, $dateTo);
    $sql = 'SELECT jl.account_code AS c, MAX(jl.account_label) AS lbl,
            SUM(jl.debit) AS tdr, SUM(jl.credit) AS tcr
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header j ON j.id = jl.journal_id
        WHERE j.facility_id = ' . $fid . " AND j.entry_date BETWEEN '" . $d1 . "' AND '" . $d2 . "'
        GROUP BY jl.account_code
        ORDER BY jl.account_code";
    $q = hms_tb_mysqli_query($connection, $sql);
    if ($q === false) {
        if (function_exists('error_log')) {
            error_log('financials-trial-balance: ' . mysqli_error($connection));
        }

        return [];
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $dr = round((float) ($row['tdr'] ?? 0), 2);
        $cr = round((float) ($row['tcr'] ?? 0), 2);
        $code = (string) ($row['c'] ?? '');
        $out[] = [
            'account_code' => $code,
            'account_label' => (string) ($row['lbl'] ?? ''),
            'total_debit' => $dr,
            'total_credit' => $cr,
            'balance' => round($dr - $cr, 2),
        ];
    }
    mysqli_free_result($q);

    return $out;
}

/**
 * @return list<array{account_code:string,account_label:string,total_debit:float,total_credit:float,balance:float}>
 */
function hms_tb_fetch_balances_to_date(mysqli $connection, int $facilityId, string $asOfDateInclusive): array
{
    $fid = (int) $facilityId;
    $dEsc = mysqli_real_escape_string($connection, $asOfDateInclusive);
    $sql = 'SELECT jl.account_code AS c, MAX(jl.account_label) AS lbl,
            SUM(jl.debit) AS tdr, SUM(jl.credit) AS tcr
        FROM tbl_fin_journal_line jl
        INNER JOIN tbl_fin_journal_header j ON j.id = jl.journal_id
        WHERE j.facility_id = ' . $fid . " AND j.entry_date <= '" . $dEsc . "'
        GROUP BY jl.account_code
        ORDER BY jl.account_code";
    $q = hms_tb_mysqli_query($connection, $sql);
    if ($q === false) {
        if (function_exists('error_log')) {
            error_log('financials-trial-balance: ' . mysqli_error($connection));
        }

        return [];
    }
    $out = [];
    while ($row = mysqli_fetch_assoc($q)) {
        $dr = round((float) ($row['tdr'] ?? 0), 2);
        $cr = round((float) ($row['tcr'] ?? 0), 2);
        $code = (string) ($row['c'] ?? '');
        $out[] = [
            'account_code' => $code,
            'account_label' => (string) ($row['lbl'] ?? ''),
            'total_debit' => $dr,
            'total_credit' => $cr,
            'balance' => round($dr - $cr, 2),
        ];
    }
    mysqli_free_result($q);

    return $out;
}

$fid = hms_current_facility_id();
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);

$d1 = trim((string) ($_GET['d1'] ?? date('Y-m-01')));
$d2 = trim((string) ($_GET['d2'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d1)) {
    $d1 = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d2)) {
    $d2 = date('Y-m-d');
}

$periodRows = [];
$closingRows = [];

if ($finOk) {
    $periodRows = hms_tb_fetch_movements($connection, $fid, $d1, $d2);
    $closingRows = hms_tb_fetch_balances_to_date($connection, $fid, $d2);
}

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Balance des comptes - Balance generale (OHADA)', [
                    'subtitle' => 'Grand livre synthetique par compte pour la periode (comptes classes 1-7).',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Trial balance', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">Journal GL indisponible. Executez <code>database/migrations/019_credit_receivables.sql</code>.</div>
                <?php } else { ?>
                <form method="get" class="card border-0 shadow-sm mb-3 no-print">
                    <div class="card-body row align-items-end">
                        <div class="form-group col-md-3 mb-0">
                            <label for="d1">Du</label>
                            <input type="date" class="form-control" id="d1" name="d1" value="<?php echo hms_h($d1); ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <label for="d2">Au</label>
                            <input type="date" class="form-control" id="d2" name="d2" value="<?php echo hms_h($d2); ?>">
                        </div>
                        <div class="form-group col-md-3 mb-0">
                            <button type="submit" class="btn btn-primary">Actualiser</button>
                            <button type="button" class="btn btn-outline-secondary" onclick="window.print()">Imprimer</button>
                        </div>
                    </div>
                </form>

                <div class="hms-ohada-report">
                    <div class="hms-ohada-report__head">
                        <div class="hms-ohada-report__title">Balance generale aux comptes</div>
                        <div class="hms-ohada-report__sub">Referentiel OHADA - montants en <?php echo hms_h(hms_currency_label()); ?></div>
                        <div class="hms-ohada-report__meta mt-2">Periode : <?php echo hms_h($d1); ?> &rarr; <?php echo hms_h($d2); ?> - Etablissement #<?php echo (int) $fid; ?></div>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead>
                                <tr>
                                    <th>Compte</th>
                                    <th>Libelle</th>
                                    <th class="hms-ohada-num">Mouvt debit</th>
                                    <th class="hms-ohada-num">Mouvt credit</th>
                                    <th class="hms-ohada-num">Solde periode</th>
                                    <th class="hms-ohada-num">Solde au <?php echo hms_h($d2); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php
                                $byCode = [];
                                foreach ($closingRows as $r) {
                                    $byCode[$r['account_code']] = $r;
                                }
                                $movMap = [];
                                foreach ($periodRows as $r) {
                                    $movMap[$r['account_code']] = $r;
                                }
                                $codes = array_unique(array_merge(array_keys($movMap), array_keys($byCode)));
                                sort($codes, SORT_STRING);
                                $td = 0.0;
                                $tc = 0.0;
                                foreach ($codes as $code) {
                                    $m = $movMap[$code] ?? null;
                                    $cl = $byCode[$code] ?? null;
                                    $md = $m ? (float) $m['total_debit'] : 0.0;
                                    $mc = $m ? (float) $m['total_credit'] : 0.0;
                                    $sb = $cl ? (float) $cl['balance'] : 0.0;
                                    $lbl = (is_array($m) ? (string) ($m['account_label'] ?? '') : '');
                                    if ($lbl === '' && is_array($cl)) {
                                        $lbl = (string) ($cl['account_label'] ?? '');
                                    }
                                    $sp = $m ? (float) $m['balance'] : 0.0;
                                    $td += $md;
                                    $tc += $mc;
                                    ?>
                                <tr>
                                    <td><code><?php echo hms_h($code); ?></code></td>
                                    <td><?php echo hms_h($lbl); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $md, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $mc, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $sp, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $sb, false); ?></td>
                                </tr>
                                    <?php
                                }
                                ?>
                                <tr class="font-weight-bold">
                                    <td colspan="2">Totaux mouvements</td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $td, false); ?></td>
                                    <td class="hms-ohada-num"><?php echo hms_format_xaf((float) $tc, false); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                    <p class="hms-ohada-disclaimer mb-0">Document de travail issu des ecritures enregistrees dans HMS. Controle d'equilibre : tout ecart doit etre analyse (arrondis, comptes non lettres). Validation expert-comptable recommandee.</p>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
