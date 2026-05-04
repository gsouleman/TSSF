<?php
declare(strict_types=1);
/** @var array{region:string,division:string,commune:string,village:string,detail:string} $parts */
$parts = $hms_cameroon_address_parts ?? hms_cameroon_address_parse('');
$regions = array_keys(hms_cameroon_region_departments());
$deptMap = hms_cameroon_region_departments();
$commTree = hms_cameroon_communes_tree();

$communeList = [];
if ($parts['region'] !== '' && $parts['division'] !== '' && isset($commTree[$parts['region']][$parts['division']])) {
    $communeList = $commTree[$parts['region']][$parts['division']];
}
$communeInList = $parts['commune'] !== '' && in_array(
    $parts['commune'],
    array_filter(
        $communeList,
        static function ($x) {
            return strpos((string) $x, 'Autre commune') === false;
        }
    ),
    true
);
$showCommuneOther = $parts['commune'] !== '' && !$communeInList;

$vList = ($parts['commune'] !== '') ? hms_cameroon_villages_for_commune($parts['commune']) : hms_cameroon_village_defaults();
$villageInList = $parts['village'] !== '' && in_array($parts['village'], $vList, true);
$showVillageOther = $parts['village'] !== '' && !$villageInList;
$villageOtherVal = $showVillageOther ? $parts['village'] : '';

$composed = implode(' | ', array_filter(
    [$parts['region'], $parts['division'], $parts['commune'], $parts['village'], $parts['detail']],
    static function ($x) {
        return $x !== '';
    }
));
?>
<div class="hms-form-section hms-cameroon-address" data-hms-cameroon-address="1">
    <h2 class="hms-form-section-title">Location (Cameroon)</h2>
    <p class="small text-muted mb-3">Choose at least the <strong>region</strong>. <strong>Department</strong>, <strong>council / city</strong>, and <strong>village or neighborhood</strong> are optional. Amounts shown in the application are in <strong><?php echo hms_h(hms_currency_label()); ?></strong>.</p>
    <div class="row">
        <div class="col-md-6">
            <div class="form-group">
                <label for="cm_region">Region <span class="hms-required">*</span></label>
                <select id="cm_region" name="cm_region" class="form-control" required>
                    <option value="">— Choose a region —</option>
                    <?php foreach ($regions as $rg) {
                        $sel = ($parts['region'] === $rg) ? ' selected' : '';
                        echo '<option value="' . hms_h($rg) . '"' . $sel . '>' . hms_h($rg) . '</option>';
                    } ?>
                </select>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label for="cm_division">Department</label>
                <select id="cm_division" name="cm_division" class="form-control">
                    <option value="">— Choose —</option>
                    <?php
                    if ($parts['region'] !== '' && isset($deptMap[$parts['region']])) {
                        foreach ($deptMap[$parts['region']] as $dp) {
                            $sel = ($parts['division'] === $dp) ? ' selected' : '';
                            echo '<option value="' . hms_h($dp) . '"' . $sel . '>' . hms_h($dp) . '</option>';
                        }
                    }
                    ?>
                </select>
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label for="cm_commune">Council / city</label>
                <select id="cm_commune" name="cm_commune" class="form-control">
                    <option value=""><?php echo $communeList === [] ? '— Choose region and department —' : '— Choose —'; ?></option>
                    <?php
                    foreach (array_filter(
                        $communeList,
                        static function ($x) {
                            return strpos((string) $x, 'Autre commune') === false;
                        }
                    ) as $cm) {
                        $sel = ($parts['commune'] === $cm && !$showCommuneOther) ? ' selected' : '';
                        echo '<option value="' . hms_h((string) $cm) . '"' . $sel . '>' . hms_h((string) $cm) . '</option>';
                    }
                    if ($communeList !== []) {
                        echo '<option value="__OTHER__"' . ($showCommuneOther ? ' selected' : '') . '>Other council…</option>';
                    }
                    ?>
                </select>
            </div>
        </div>
        <div class="col-md-6" id="cm_commune_other_wrap" style="<?php echo $showCommuneOther ? '' : 'display:none;'; ?>">
            <div class="form-group">
                <label for="cm_commune_other">Specify the council</label>
                <input id="cm_commune_other" name="cm_commune_other" class="form-control" type="text" value="<?php echo hms_h($showCommuneOther ? $parts['commune'] : ''); ?>" placeholder="Council name">
            </div>
        </div>
        <div class="col-md-6">
            <div class="form-group">
                <label for="cm_village">Village / neighborhood</label>
                <select id="cm_village" name="cm_village" class="form-control">
                    <option value=""><?php echo $parts['commune'] === '' ? '— Choose a council —' : '— Choose —'; ?></option>
                    <?php
                    foreach ($vList as $v) {
                        if ($v === '— Choisir —') {
                            continue;
                        }
                        $sel = ($parts['village'] === $v && !$showVillageOther) ? ' selected' : '';
                        echo '<option value="' . hms_h($v) . '"' . $sel . '>' . hms_h($v) . '</option>';
                    }
                    echo '<option value="__OTHER__"' . ($showVillageOther ? ' selected' : '') . '>Other…</option>';
                    ?>
                </select>
            </div>
        </div>
        <div class="col-md-6" id="cm_village_other_wrap" style="<?php echo $showVillageOther ? '' : 'display:none;'; ?>">
            <div class="form-group">
                <label for="cm_village_other">Specify the village / neighborhood</label>
                <input id="cm_village_other" name="cm_village_other" class="form-control" type="text" value="<?php echo hms_h($villageOtherVal); ?>" placeholder="Neighborhood, village name…">
            </div>
        </div>
        <div class="col-12">
            <div class="form-group mb-0">
                <label for="address_detail">Additional address line (street, no., building)</label>
                <input id="address_detail" name="address_detail" class="form-control" type="text" value="<?php echo hms_h($parts['detail']); ?>" placeholder="e.g. Joss Street, Les Palmiers building, door 4" autocomplete="address-line2">
            </div>
        </div>
        <div class="col-12 mt-2">
            <label class="small text-muted mb-0">Registered address (preview)</label>
            <input type="text" class="form-control form-control-sm bg-light" id="hms_cm_address_preview" readonly tabindex="-1" value="<?php echo hms_h($composed); ?>">
        </div>
    </div>
    <input type="hidden" name="address" id="hms_cm_address_composed" value="<?php echo hms_h($composed); ?>">
</div>
<script>window.HMS_CAMEROON_GEO=<?php echo hms_cameroon_geo_json(); ?>;</script>
<script src="assets/js/hms-cameroon-address.js?v=2"></script>
