<?php
declare(strict_types=1);

/**
 * Seeded medical / hospital supplier names for Cameroon (wholesalers, distributors, typical procurement).
 * Fictional and generic labels for demo — replace or extend in deployment to match your approved vendor register.
 *
 * @return list<string>
 */
function hms_cameroon_medical_supplier_seed(): array
{
    return [
        // Major cities — pharmaceutical & medical distribution (generic labels)
        'Grossiste pharmaceutique — Douala (Akwa)',
        'Grossiste pharmaceutique — Douala (Bépanda)',
        'Distributeur médical — Douala Bassa',
        'Fournitures hospitalières — Yaoundé (Mokolo)',
        'Distributeur médical — Yaoundé (Centre)',
        'Approvisionnement médical — Garoua',
        'Grossiste médical — Bamenda',
        'Fournisseur consommables — Bafoussam',
        'Distributeur laboratoire — Douala',
        'Réactifs & consommables labo — Yaoundé',
        // Product types
        'Fournisseur chirurgie & sutures (Cameroun)',
        'Dispositifs médicaux & pansements — gros',
        'Équipement diagnostic & imaging — distributeur',
        'Consommables dialyse & perfusion',
        'Orthopédie & prothèses — importateur',
        'Optique médicale & lentilles — grossiste',
        'Anesthésie & réanimation — fournisseur',
        'Stérilisation & CSSD — consommables',
        'Laboratoire — verrerie & réactifs',
        'Radiologie — consommables & contraste',
        'Pharmacie hospitalière — centrale d’achat',
        'Vaccins & chaîne du froid — distributeur agréé',
        'Médicaments génériques — grossiste régional',
        'Médicaments spécialisés — import direct',
        'Dentaire & stomatologie — fournisseur',
        'Gynécologie & obstétrique — consommables',
        'Pédiatrie & néonatologie — dispositifs',
        'Urgences & SAMU — matériel jetable',
        'Blocs opératoires — textiles & draps',
        'Infection control & EPI — gros',
        // Institutional / typical channels
        'Centrale d’achat ONG / projet santé',
        'Fournisseur MINAS / marché public (cadre)',
        'Importateur direct — Europe / Inde (médical)',
        'Négociant grossiste — marché Mfoundi (médical)',
        'Prestataire logistique médicale — route nationale',
        'Distributeur agréé — chaîne hospitalière privée',
        'Fournisseur maintenance biomédicale & pièces',
        'Location équipement médical — prestataire',
    ];
}

/**
 * Seed + distinct supplier names already used on POs (and optionally expenses) for this facility.
 *
 * @return list<string>
 */
function hms_po_supplier_options(mysqli $connection, int $facilityId): array
{
    if ($facilityId < 1) {
        return hms_cameroon_medical_supplier_seed();
    }

    $seen = [];
    foreach (hms_cameroon_medical_supplier_seed() as $s) {
        $t = trim($s);
        if ($t !== '') {
            $seen[$t] = true;
        }
    }

    if (hms_db_table_exists($connection, 'tbl_purchase_order')) {
        $q = mysqli_query(
            $connection,
            'SELECT DISTINCT TRIM(supplier_name) AS s FROM tbl_purchase_order WHERE facility_id = ' . (int) $facilityId
            . " AND supplier_name IS NOT NULL AND TRIM(supplier_name) <> '' LIMIT 300"
        );
        while ($q && $r = mysqli_fetch_assoc($q)) {
            $t = trim((string) ($r['s'] ?? ''));
            if ($t !== '') {
                $seen[$t] = true;
            }
        }
    }

    if (hms_db_table_exists($connection, 'tbl_expense') && hms_db_column_exists($connection, 'tbl_expense', 'vendor')) {
        $q2 = mysqli_query(
            $connection,
            'SELECT DISTINCT TRIM(vendor) AS s FROM tbl_expense WHERE facility_id = ' . (int) $facilityId
            . " AND vendor IS NOT NULL AND TRIM(vendor) <> '' LIMIT 200"
        );
        while ($q2 && $r2 = mysqli_fetch_assoc($q2)) {
            $t = trim((string) ($r2['s'] ?? ''));
            if ($t !== '') {
                $seen[$t] = true;
            }
        }
    }

    $list = array_keys($seen);
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);

    return $list;
}
