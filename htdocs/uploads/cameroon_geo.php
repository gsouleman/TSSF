<?php
declare(strict_types=1);

/**
 * Cameroon administrative reference data (regions → départements → communes principales).
 * Villages / quartiers: generic list, enriched for major urban centres.
 */

function hms_cameroon_village_defaults(): array
{
    return [
        '— Choisir —',
        'Centre-ville / chef-lieu',
        'Quartier résidentiel',
        'Zone périphérique / village',
        'Autre (préciser dans le complément)',
    ];
}

function hms_cameroon_villages_for_commune(string $commune): array
{
    $t = trim($commune);
    $c = function_exists('mb_strtolower') ? mb_strtolower($t, 'UTF-8') : strtolower($t);
    $urban = [
        ['needle' => 'yaound', 'list' => [
            'Bastos', 'Mvog-Ada', 'Emana', 'Nlongkak', 'Mokolo', 'Tsinga', 'Ngoa-Ekélé',
            'Obili', 'Etoudi', 'Ekounou', 'Mfandena', 'Odza', 'Nsimeyong', 'Autre (complément)',
        ]],
        ['needle' => 'douala', 'list' => [
            'Akwa', 'Bonanjo', 'Bonapriso', 'Bassa', 'Deido', 'New Bell', 'Bépanda', 'Logpom',
            'Yassa', 'PK8', 'PK10', 'Autre (complément)',
        ]],
        ['needle' => 'garoua', 'list' => ['Roumde Adjia', 'Fouléré', 'Pitoa', 'Demsa', 'Autre (complément)']],
        ['needle' => 'maroua', 'list' => ['Douggoï', 'Domayo', 'Djarengol', 'Autre (complément)']],
        ['needle' => 'bamenda', 'list' => ['Commercial Avenue', 'Nkwen', 'Mankon', 'Bambili', 'Autre (complément)']],
        ['needle' => 'bafoussam', 'list' => ['Djeleng', 'Kamkop', 'Tougang', 'Autre (complément)']],
        ['needle' => 'ngaound', 'list' => ['Madina', 'Dang', 'Autre (complément)']],
        ['needle' => 'bertoua', 'list' => ['Madagascar', 'Nkolbong', 'Autre (complément)']],
        ['needle' => 'ebolowa', 'list' => ['Nko\'ovos', 'Oyack', 'Autre (complément)']],
        ['needle' => 'kumba', 'list' => ['Fiango', 'Mbonge road', 'Station', 'Autre (complément)']],
        ['needle' => 'limbe', 'list' => ['Down Beach', 'Mile 4', 'New Town', 'Autre (complément)']],
        ['needle' => 'buea', 'list' => ['Molyko', 'Great Soppo', 'Small Soppo', 'Muea', 'Autre (complément)']],
    ];
    foreach ($urban as $pair) {
        if ($c !== '' && strpos($c, $pair['needle']) !== false) {
            return array_merge(['— Choisir —'], $pair['list']);
        }
    }
    return hms_cameroon_village_defaults();
}

/**
 * @return array<string, list<string>>
 */
function hms_cameroon_region_departments(): array
{
    return [
        'Adamaoua' => ['Djérem', 'Faro-et-Déo', 'Mayo-Banyo', 'Mbéré', 'Vina'],
        'Centre' => ['Haute-Sanaga', 'Lékié', 'Mbam-et-Inoubou', 'Mbam-et-Kim', 'Méfou-et-Afamba', 'Méfou-et-Akono', 'Mfoundi', 'Nyong-et-Kéllé', 'Nyong-et-Mfoumou', 'Nyong-et-So\'o'],
        'Est' => ['Boumba-et-Ngoko', 'Haut-Nyong', 'Kadey', 'Lom-et-Djerem'],
        'Extrême-Nord' => ['Diamaré', 'Logone-et-Chari', 'Mayo-Danay', 'Mayo-Kani', 'Mayo-Sava', 'Mayo-Tsanaga'],
        'Littoral' => ['Moungo', 'Nkam', 'Sanaga-Maritime', 'Wouri'],
        'Nord' => ['Bénoué', 'Faro', 'Mayo-Louti', 'Mayo-Rey'],
        'Nord-Ouest' => ['Bui', 'Donga-Mantung', 'Menchum', 'Mezam', 'Momo', 'Ngoketunjia', 'Nwa'],
        'Ouest' => ['Bamboutos', 'Haut-Nkam', 'Hauts-Plateaux', 'Koung-Khi', 'Menoua', 'Mifi', 'Ndian', 'Noun'],
        'Sud' => ['Dja-et-Lobo', 'Mvila', 'Océan', 'Vallée-du-Ntem'],
        'Sud-Ouest' => ['Fako', 'Koupé-Manengouba', 'Lebialem', 'Manyu', 'Meme', 'Ndian'],
    ];
}

/**
 * @return array<string, array<string, list<string>>>
 */
function hms_cameroon_communes_tree(): array
{
    $o = function (string $chef, array $more = []): array {
        $x = array_merge([$chef], $more);
        $x[] = 'Autre commune (préciser dans le complément)';
        return array_values(array_unique($x));
    };

    return [
        'Adamaoua' => [
            'Djérem' => $o('Tibati', ['Tignère']),
            'Faro-et-Déo' => $o('Poli', ['Guider']),
            'Mayo-Banyo' => $o('Banyo', ['Bankim']),
            'Mbéré' => $o('Meiganga', ['Ngaoui']),
            'Vina' => $o('Ngaoundéré', ['Martap']),
        ],
        'Centre' => [
            'Haute-Sanaga' => $o('Nanga-Eboko', ['Mbalmayo']),
            'Lékié' => $o('Monatélé', ['Obala', 'Sa\'a']),
            'Mbam-et-Inoubou' => $o('Bafia', ['Ndikinimeki']),
            'Mbam-et-Kim' => $o('Ntui', ['Ngambé-Tikar']),
            'Méfou-et-Afamba' => $o('Mfou', ['Soa']),
            'Méfou-et-Akono' => $o('Ngoumou', ['Akono']),
            'Mfoundi' => $o('Yaoundé', ['Ngousso', 'Essos']),
            'Nyong-et-Kéllé' => $o('Éséka', ['Botourwa']),
            'Nyong-et-Mfoumou' => $o('Akonolinga', ['Endom']),
            'Nyong-et-So\'o' => $o('Mbalmayo', ['Ngomedzap']),
        ],
        'Est' => [
            'Boumba-et-Ngoko' => $o('Yokadouma', ['Gari-Gombo']),
            'Haut-Nyong' => $o('Abong-Mbang', ['Dimako']),
            'Kadey' => $o('Batouri', ['Kentzouo']),
            'Lom-et-Djerem' => $o('Bertoua', ['Bélabo']),
        ],
        'Extrême-Nord' => [
            'Diamaré' => $o('Maroua', ['Douggoï']),
            'Logone-et-Chari' => $o('Kousséri', ['Logone-Birni']),
            'Mayo-Danay' => $o('Yagoua', ['Kaélé']),
            'Mayo-Kani' => $o('Kaélé', ['Guidiguis']),
            'Mayo-Sava' => $o('Mora', ['Tokombéré']),
            'Mayo-Tsanaga' => $o('Mokolo', ['Koza']),
        ],
        'Littoral' => [
            'Moungo' => $o('Nkongsamba', ['Penja', 'Loum']),
            'Nkam' => $o('Yabassi', ['Nkondjock']),
            'Sanaga-Maritime' => $o('Édéa', ['Dibamba', 'Massock']),
            'Wouri' => $o('Douala', ['Bonabéri', 'Manoka']),
        ],
        'Nord' => [
            'Bénoué' => $o('Garoua', ['Demsa', 'Pitoa']),
            'Faro' => $o('Poli', ['Guider']),
            'Mayo-Louti' => $o('Guider', ['Figuil']),
            'Mayo-Rey' => $o('Tcholliré', ['Rey-Bouba']),
        ],
        'Nord-Ouest' => [
            'Bui' => $o('Kumbo', ['Jakiri', 'Oku']),
            'Donga-Mantung' => $o('Ako', ['Nkambé']),
            'Menchum' => $o('Wum', ['Furu-Awa']),
            'Mezam' => $o('Bamenda', ['Bafut', 'Santa']),
            'Momo' => $o('Mbengwi', ['Njikwa']),
            'Ngoketunjia' => $o('Ndop', ['Babessi']),
            'Nwa' => $o('Djahanyi', ['Nwa']),
        ],
        'Ouest' => [
            'Bamboutos' => $o('Mbouda', ['Galim', 'Batcham']),
            'Haut-Nkam' => $o('Kekem', ['Banka']),
            'Hauts-Plateaux' => $o('Baham', ['Bangou']),
            'Koung-Khi' => $o('Bandjoun', ['Bayangam']),
            'Menoua' => $o('Dschang', ['Fokoué']),
            'Mifi' => $o('Bafoussam', ['Badou']),
            'Ndian' => $o('Mundemba', ['Kumba']),
            'Noun' => $o('Foumban', ['Kouoptamo']),
        ],
        'Sud' => [
            'Dja-et-Lobo' => $o('Sangmélima', ['Meyomessala']),
            'Mvila' => $o('Ebolowa', ['Mvangan']),
            'Océan' => $o('Kribi', ['Lolodorf', 'Akom II']),
            'Vallée-du-Ntem' => $o('Ambam', ['Ma\'an']),
        ],
        'Sud-Ouest' => [
            'Fako' => $o('Limbe', ['Buea', 'Tiko', 'Idenau']),
            'Koupé-Manengouba' => $o('Bangem', ['Nguti']),
            'Lebialem' => $o('Menji', ['Fontem']),
            'Manyu' => $o('Mamfe', ['Eyumojock']),
            'Meme' => $o('Kumba', ['Mbonge']),
            'Ndian' => $o('Mundemba', ['Isangele']),
        ],
    ];
}

function hms_cameroon_geo_payload_for_js(): array
{
    $tree = hms_cameroon_communes_tree();
    $villageHints = [];
    foreach ($tree as $reg => $depts) {
        foreach ($depts as $dep => $comms) {
            foreach ($comms as $comm) {
                if (strpos($comm, 'Autre commune') !== false) {
                    continue;
                }
                $key = $reg . '|' . $dep . '|' . $comm;
                $villageHints[$key] = hms_cameroon_villages_for_commune($comm);
            }
        }
    }
    return [
        'regions' => array_keys(hms_cameroon_region_departments()),
        'departments' => hms_cameroon_region_departments(),
        'communes' => $tree,
        'villageDefaults' => hms_cameroon_village_defaults(),
        'villageHints' => $villageHints,
    ];
}

function hms_cameroon_geo_json(): string
{
    $j = json_encode(hms_cameroon_geo_payload_for_js(), JSON_UNESCAPED_UNICODE);
    return $j !== false ? $j : '{}';
}

/**
 * @return array{region: string, division: string, commune: string, village: string, detail: string}
 */
function hms_cameroon_address_parse(string $addr): array
{
    $empty = ['region' => '', 'division' => '', 'commune' => '', 'village' => '', 'detail' => ''];
    $addr = trim($addr);
    if ($addr === '') {
        return $empty;
    }
    if (strpos($addr, ' | ') !== false) {
        $p = explode(' | ', $addr, 5);
        return [
            'region' => trim((string) ($p[0] ?? '')),
            'division' => trim((string) ($p[1] ?? '')),
            'commune' => trim((string) ($p[2] ?? '')),
            'village' => trim((string) ($p[3] ?? '')),
            'detail' => trim((string) ($p[4] ?? '')),
        ];
    }
    return array_merge($empty, ['detail' => $addr]);
}

function hms_cameroon_address_from_request(array $r): string
{
    $region = trim((string) ($r['cm_region'] ?? ''));
    if ($region === '') {
        return trim((string) ($r['address'] ?? ''));
    }
    $division = trim((string) ($r['cm_division'] ?? ''));
    $commune = trim((string) ($r['cm_commune'] ?? ''));
    if ($commune === '__OTHER__' || strpos($commune, 'Autre commune') !== false) {
        $commune = trim((string) ($r['cm_commune_other'] ?? ''));
    }
    $village = trim((string) ($r['cm_village'] ?? ''));
    if ($village === '__OTHER__' || strpos($village, 'Autre') !== false) {
        $v2 = trim((string) ($r['cm_village_other'] ?? ''));
        if ($v2 !== '') {
            $village = $village !== '' && $village !== '__OTHER__' ? $village . ' — ' . $v2 : $v2;
        }
    }
    $detail = trim((string) ($r['address_detail'] ?? ''));
    $parts = array_filter([$region, $division, $commune, $village, $detail], static function ($x) {
        return $x !== '';
    });
    return implode(' | ', $parts);
}
