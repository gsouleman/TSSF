<?php
declare(strict_types=1);

/**
 * Canonical health insurance carriers (Cameroon / CEMAC–focused + pan-African + common international payers).
 * Codes are stable uppercase identifiers for DB storage.
 *
 * @return list<array{code: string, name: string}>
 */
/**
 * Major health / life insurers and payer types commonly used in Cameroon (public + private + mutuelles).
 * Used to auto-seed `tbl_insurance_carrier` per facility so clinical and billing UIs list real options.
 *
 * @return list<array{code: string, name: string}>
 */
function hms_insurance_catalog_cameroon(): array
{
    return [
        ['code' => 'CNPS', 'name' => 'CNPS — Caisse Nationale de Prévoyance Sociale (Cameroun)'],
        ['code' => 'NSIA', 'name' => 'NSIA Assurances'],
        ['code' => 'ACTIVA', 'name' => 'ACTIVA Assurances'],
        ['code' => 'SANLAM', 'name' => 'Sanlam Allianz Cameroun'],
        ['code' => 'AXA', 'name' => 'AXA Cameroun'],
        ['code' => 'SAAR', 'name' => 'SAAR Assurances'],
        ['code' => 'BICEC', 'name' => 'BICEC Assurances'],
        ['code' => 'ATLANTIQUE', 'name' => 'Atlantique Assurance'],
        ['code' => 'CHANE', 'name' => 'Chanas Assurances'],
        ['code' => 'OCEAN', 'name' => 'Ocean Assurance'],
        ['code' => 'SUNU', 'name' => 'SUNU Assurances Cameroun'],
        ['code' => 'SOCAM', 'name' => 'SOCAM / SOGEM (Cameroun)'],
        ['code' => 'LOYALE', 'name' => 'La Loyale Assurance (Cameroun)'],
        ['code' => 'ASSUR_AFRICA', 'name' => 'Assur Africa'],
        ['code' => 'HOLLARD', 'name' => 'Hollard Insurance (Cameroun / région)'],
        ['code' => 'CORIS', 'name' => 'Coris Assurances (CEMAC)'],
        ['code' => 'ZENITHE', 'name' => 'Zenithe Life — Zenithe Assurances (Cameroun)'],
        ['code' => 'BLUECARE', 'name' => 'BLUECARE Cameroun'],
        ['code' => 'LEADWAY', 'name' => 'Leadway Assurance (partenariat régional)'],
        ['code' => 'OLD_MUTUAL', 'name' => 'Old Mutual / UAP (région)'],
        ['code' => 'ALLIANZ', 'name' => 'Allianz Cameroun'],
        ['code' => 'GENERALI', 'name' => 'Generali'],
        ['code' => 'MUTUELLE_SANTE', 'name' => 'Mutuelle santé / association (Cameroun)'],
        ['code' => 'MUTUELLE_ENT', 'name' => 'Mutuelle d’entreprise / comité d’entreprise'],
        ['code' => 'MINSANTE', 'name' => 'Ministère de la Santé publique / établissement public'],
        ['code' => 'ARMY', 'name' => 'Forces armées / police — régime interne (Cameroun)'],
        ['code' => 'COMMUNITY', 'name' => 'Mutuelle communautaire / micro-assurance'],
        ['code' => 'CEMAC_PUBLIC', 'name' => 'Régime public / caisse autre État CEMAC'],
        ['code' => 'AFRICAN_RE', 'name' => 'African Re (réassurance / grands comptes)'],
        ['code' => 'CORP_SELF', 'name' => 'Entreprise — assurance collective (nom interne)'],
    ];
}

/**
 * Insert catalogue rows into `tbl_insurance_carrier` for this facility (INSERT IGNORE — idempotent).
 */
function hms_insurance_seed_carriers_for_facility(mysqli $connection, int $facilityId, array $catalogRows): void
{
    if ($facilityId < 1 || $catalogRows === [] || !hms_db_table_exists($connection, 'tbl_insurance_carrier')) {
        return;
    }
    $st = mysqli_prepare(
        $connection,
        'INSERT IGNORE INTO tbl_insurance_carrier (facility_id, code, name, status) VALUES (?,?,?,1)'
    );
    if (!$st) {
        return;
    }
    foreach ($catalogRows as $row) {
        $code = (string) ($row['code'] ?? '');
        $name = (string) ($row['name'] ?? '');
        if ($code === '' || $name === '') {
            continue;
        }
        mysqli_stmt_bind_param($st, 'iss', $facilityId, $code, $name);
        mysqli_stmt_execute($st);
    }
    mysqli_stmt_close($st);
}

function hms_insurance_seed_cameroon_carriers_for_facility(mysqli $connection, int $facilityId): void
{
    hms_insurance_seed_carriers_for_facility($connection, $facilityId, hms_insurance_catalog_cameroon());
}

function hms_insurance_catalog(): array
{
    return [
        ['code' => 'CNPS', 'name' => 'CNPS — Caisse Nationale de Prévoyance Sociale'],
        ['code' => 'NSIA', 'name' => 'NSIA Assurances'],
        ['code' => 'ACTIVA', 'name' => 'ACTIVA Assurances'],
        ['code' => 'SANLAM', 'name' => 'Sanlam Allianz Cameroun'],
        ['code' => 'AXA', 'name' => 'AXA Cameroun'],
        ['code' => 'SAAR', 'name' => 'SAAR Assurances'],
        ['code' => 'BICEC', 'name' => 'BICEC Assurances'],
        ['code' => 'ATLANTIQUE', 'name' => 'Atlantique Assurance'],
        ['code' => 'CHANE', 'name' => 'Chanas Assurances'],
        ['code' => 'OCEAN', 'name' => 'Ocean Assurance'],
        ['code' => 'SUNU', 'name' => 'SUNU Assurances Cameroun'],
        ['code' => 'SOCAM', 'name' => 'SOCAM / SOGEM (Cameroun)'],
        ['code' => 'LOYALE', 'name' => 'La Loyale Assurance (Cameroun)'],
        ['code' => 'ASSUR_AFRICA', 'name' => 'Assur Africa'],
        ['code' => 'HOLLARD', 'name' => 'Hollard Insurance (Afrique)'],
        ['code' => 'CORIS', 'name' => 'Coris Assurances (CEMAC)'],
        ['code' => 'LEADWAY', 'name' => 'Leadway Assurance (Afrique de l’Ouest)'],
        ['code' => 'OLD_MUTUAL', 'name' => 'Old Mutual / UAP (Afrique)'],
        ['code' => 'SANLAM_GROUP', 'name' => 'Sanlam (groupe — autre filiale)'],
        ['code' => 'AFRICAN_RE', 'name' => 'African Reinsurance Corporation (réassurance / grands comptes)'],
        ['code' => 'WAICA', 'name' => 'WAICA Re (West African insurance)'],
        ['code' => 'MUTUELLE_SANTE', 'name' => 'Mutuelle santé / association (générique)'],
        ['code' => 'MUTUELLE_ENT', 'name' => 'Mutuelle d’entreprise / comité d’entreprise'],
        ['code' => 'MINSANTE', 'name' => 'Ministère de la Santé publique / établissement public'],
        ['code' => 'ARMY', 'name' => 'Forces armées / police — régime interne'],
        ['code' => 'COMMUNITY', 'name' => 'Mutuelle communautaire / micro-assurance'],
        ['code' => 'CEMAC_PUBLIC', 'name' => 'Régime public / caisse d’un autre État CEMAC'],
        ['code' => 'IPRES_SN', 'name' => 'IPRES / IPM — Sénégal (régime public)'],
        ['code' => 'CNPS_CI', 'name' => 'CNPS — Côte d’Ivoire (régime public / privé)'],
        ['code' => 'CNAM_GA', 'name' => 'CNAM / caisse publique — Gabon'],
        ['code' => 'RAMU_BF', 'name' => 'RAMU — Burkina Faso (public)'],
        ['code' => 'NHIF_KE', 'name' => 'NHIF — Kenya'],
        ['code' => 'NHIF_TZ', 'name' => 'NHIF — Tanzanie'],
        ['code' => 'RSSB_RW', 'name' => 'RSSB — Rwanda (mutuelle santé)'],
        ['code' => 'CIGNA', 'name' => 'Cigna / international'],
        ['code' => 'ALLIANZ', 'name' => 'Allianz'],
        ['code' => 'GENERALI', 'name' => 'Generali'],
        ['code' => 'ZURICH', 'name' => 'Zurich Insurance'],
        ['code' => 'MAPFRE', 'name' => 'MAPFRE'],
        ['code' => 'PRUDENTIAL', 'name' => 'Prudential / partenaire international'],
        ['code' => 'METLIFE', 'name' => 'MetLife'],
        ['code' => 'BUPA_GLOBAL', 'name' => 'Bupa Global'],
        ['code' => 'AETNA', 'name' => 'Aetna / CVS Health (international)'],
        ['code' => 'HUMANA', 'name' => 'Humana'],
        ['code' => 'UHC', 'name' => 'UnitedHealthcare (international)'],
        ['code' => 'BLUECROSS', 'name' => 'Blue Cross / Blue Shield (international)'],
        ['code' => 'KAISER', 'name' => 'Kaiser Permanente (référence internationale)'],
        ['code' => 'MEDICARE_US', 'name' => 'Medicare (États-Unis)'],
        ['code' => 'MEDICAID_US', 'name' => 'Medicaid (États-Unis)'],
        ['code' => 'RAMQ', 'name' => 'RAMQ — Régie de l’assurance maladie (Québec)'],
        ['code' => 'CNAM_FR', 'name' => 'Assurance maladie France (CNAM / Urssaf)'],
        ['code' => 'EHIC_EU', 'name' => 'Assurance maladie UE / EHIC'],
        ['code' => 'TRAVEL', 'name' => 'Assurance voyage / rapatriement sanitaire'],
        ['code' => 'STUDENT_INTL', 'name' => 'Assurance étudiant / internationale'],
        ['code' => 'UN_ORG', 'name' => 'ONU / organisation internationale — régime interne'],
        ['code' => 'NGO_GROUP', 'name' => 'ONG / mission — couverture groupe'],
        ['code' => 'PHARMA_PROG', 'name' => 'Programme patient / pharma (accès médicament)'],
        ['code' => 'NHIF', 'name' => 'NHIF / caisse publique (autre pays — générique)'],
        ['code' => 'CORP_SELF', 'name' => 'Entreprise — assurance collective (nom interne)'],
    ];
}

/** @return array<string, string> code => name */
function hms_insurance_catalog_by_code(): array
{
    $out = [];
    foreach (hms_insurance_catalog() as $row) {
        $out[$row['code']] = $row['name'];
    }

    return $out;
}

/**
 * Build a unique carrier code from a free-text name (for "Other").
 */
function hms_insurance_generate_other_code(mysqli $connection, int $facilityId, string $name): string
{
    $ascii = preg_replace('/[^a-zA-Z0-9\s]+/', ' ', $name);
    $base = strtoupper(preg_replace('/\s+/', '_', trim((string) $ascii)));
    $base = trim((string) preg_replace('/_+/', '_', $base), '_');
    if ($base === '') {
        $base = 'OTHER';
    }
    if (strlen($base) > 24) {
        $base = substr($base, 0, 24);
    }
    $code = $base;
    $n = 0;
    while ($n < 50) {
        $esc = mysqli_real_escape_string($connection, $code);
        $r = mysqli_query(
            $connection,
            'SELECT 1 FROM tbl_insurance_carrier WHERE facility_id = ' . (int) $facilityId . " AND code = '" . $esc . "' LIMIT 1"
        );
        $exists = $r && mysqli_num_rows($r) > 0;
        if ($r) {
            mysqli_free_result($r);
        }
        if (!$exists) {
            return $code;
        }
        $n++;
        $code = $base . '_' . $n;
        if (strlen($code) > 32) {
            $code = substr($base, 0, max(1, 32 - strlen((string) $n) - 1)) . '_' . $n;
        }
    }

    return $base . '_' . (string) time();
}
