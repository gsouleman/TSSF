<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/financials_import.php';

if (empty($_SESSION['name'])) {
    header('Location: index.php');
    exit;
}
hms_require_permission($connection, 'financials.read');

$fid = hms_current_facility_id();
$uid = (int) ($_SESSION['user_id'] ?? 0);
$finOk = function_exists('hms_fin_tables_ok') && hms_fin_tables_ok($connection);
$canWrite = function_exists('hms_fin_can_write') && hms_fin_can_write($connection);

$msg = '';
$err = '';

if ($finOk && $canWrite && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && isset($_POST['import_csv'])) {
    if (!hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
        $err = 'Jeton de sécurité invalide.';
    } else {
        $raw = (string) ($_POST['csv'] ?? '');
        $parsed = hms_fin_parse_journal_csv($raw);
        if (!$parsed['ok']) {
            $err = implode(' ', $parsed['errors']);
        } else {
            $ok = 0;
            foreach ($parsed['batches'] as $batch) {
                $lines = $batch['lines'];
                $nar = (string) ($batch['narration'] ?? '');
                $ref = (string) ($batch['reference'] ?? '');
                $d = (string) ($batch['date'] ?? '');
                if (hms_fin_journal_post_manual($connection, $fid, $d, $ref, $nar, $uid, $lines)) {
                    $ok++;
                }
            }
            $msg = $ok . ' écriture(s) importée(s).';
        }
    }
}

$recent = $finOk ? hms_fin_journal_recent_headers($connection, $fid, 60) : [];

include __DIR__ . '/header.php';
?>
        <div class="page-wrapper">
            <div class="content hms-module">
                <?php
                hms_ui_page_header('Chargeur de journal — import d’écritures (OHADA)', [
                    'subtitle' => 'Import CSV équilibré vers le grand livre (source manual_import).',
                    'breadcrumbs' => [['Home', 'dashboard.php'], ['Financials', 'financials.php'], ['Journal loader', '']],
                    'back' => 'financials.php',
                ]);
                ?>
                <?php if (!$finOk) { ?>
                <div class="alert alert-warning">Journal GL indisponible.</div>
                <?php } elseif (!$canWrite) { ?>
                <div class="alert alert-danger">Permission requise : <code>financials.write</code> ou rôle autorisé à la facturation.</div>
                <?php } else { ?>
                <?php if ($msg !== '') { ?><div class="alert alert-success"><?php echo hms_h($msg); ?></div><?php } ?>
                <?php if ($err !== '') { ?><div class="alert alert-danger"><?php echo hms_h($err); ?></div><?php } ?>

                <div class="card border-0 shadow-sm mb-3">
                    <div class="card-body">
                        <h2 class="h6 font-weight-bold">Format CSV (une ligne par ligne d’écriture)</h2>
                        <p class="text-muted small mb-2"><strong>7 colonnes</strong>, dans l’ordre ci-dessous. Séparateur <strong>virgule</strong> ou <strong>point-virgule</strong> (détection automatique par ligne). Les lignes avec la <strong>même date</strong> et la <strong>même référence</strong> forment une écriture ; total débit = total crédit pour ce groupe.</p>
                        <div class="table-responsive mb-3">
                            <table class="table table-sm table-bordered bg-white small mb-0">
                                <thead class="thead-light">
                                    <tr>
                                        <th>#</th>
                                        <th>Colonne</th>
                                        <th>Description</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr><td>1</td><td><code>date</code></td><td>AAAA-MM-JJ (ex. <code>2026-04-15</code>)</td></tr>
                                    <tr><td>2</td><td><code>référence</code></td><td>Identifiant d’écriture (identique sur toutes les lignes du même journal)</td></tr>
                                    <tr><td>3</td><td><code>libellé</code></td><td>Libellé de l’écriture (narration)</td></tr>
                                    <tr><td>4</td><td><code>compte</code></td><td>Code compte OHADA (ex. <code>601000</code>)</td></tr>
                                    <tr><td>5</td><td><code>libellé compte</code></td><td>Libellé du compte</td></tr>
                                    <tr><td>6</td><td><code>débit</code></td><td>Montant ou <code>0</code></td></tr>
                                    <tr><td>7</td><td><code>crédit</code></td><td>Montant ou <code>0</code> (une seule des deux colonnes 6–7 non nulle par ligne)</td></tr>
                                </tbody>
                            </table>
                        </div>
                        <p class="text-muted small mb-2">Une <strong>ligne d’en-tête</strong> (titres de colonnes) en première ligne est acceptée et ignorée automatiquement si elle est détectée.</p>
                        <pre class="bg-light p-2 small rounded mb-0">2026-04-15,JNL-01,Achat consommables,601000,Fournitures médicales,150000,0
2026-04-15,JNL-01,Achat consommables,521000,Banque,0,150000</pre>
                        <form method="post">
                            <?php echo hms_csrf_field(); ?>
                            <input type="hidden" name="import_csv" value="1">
                            <div class="form-group">
                                <label for="csv">Fichier texte / CSV collé</label>
                                <textarea class="form-control font-monospace" id="csv" name="csv" rows="12" placeholder="Collez ici..."></textarea>
                            </div>
                            <button type="submit" class="btn btn-primary">Importer les écritures</button>
                        </form>
                    </div>
                </div>

                <div class="card border-0 shadow-sm">
                    <div class="card-body">
                        <h2 class="h6 font-weight-bold mb-3">Dernières écritures journal</h2>
                        <div class="table-responsive">
                            <table class="table table-sm table-hover mb-0">
                                <thead>
                                    <tr>
                                        <th>Date</th>
                                        <th>Réf.</th>
                                        <th>Source</th>
                                        <th class="text-right">Lignes</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($recent as $r) { ?>
                                    <tr>
                                        <td><?php echo hms_h($r['entry_date']); ?></td>
                                        <td><?php echo hms_h($r['reference']); ?></td>
                                        <td><code><?php echo hms_h($r['source_type']); ?></code></td>
                                        <td class="text-right"><?php echo (int) $r['line_count']; ?></td>
                                    </tr>
                                    <?php } ?>
                                    <?php if ($recent === []) { ?>
                                    <tr><td colspan="4" class="text-muted">Aucune écriture.</td></tr>
                                    <?php } ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
                <?php } ?>
            </div>
        </div>
<?php include __DIR__ . '/footer.php';
