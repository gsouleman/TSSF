<?php
declare(strict_types=1);

if (!function_exists('hms_fin_report_org_name')) {
    require_once dirname(__DIR__) . '/financials_reports_theme.php';
}

$__hmsDocTitle = isset($hms_fin_report_document_title) && is_scalar($hms_fin_report_document_title)
    ? (string) $hms_fin_report_document_title
    : 'REPORT';
$__hmsMetaP = isset($hms_fin_report_meta_primary) && is_array($hms_fin_report_meta_primary) ? $hms_fin_report_meta_primary : [];
$__hmsMetaS = isset($hms_fin_report_meta_secondary) && is_array($hms_fin_report_meta_secondary) ? $hms_fin_report_meta_secondary : [];
$__hmsLogo = hms_fin_report_logo_src();
?>
                <div class="hms-fin-doc">
                    <header class="hms-fin-doc__mast">
                        <div class="hms-fin-doc__mast-logo-wrap">
                            <img src="<?php echo hms_h($__hmsLogo); ?>" alt="" class="hms-fin-doc__mast-logo" width="72" height="72" loading="lazy">
                        </div>
                        <div class="hms-fin-doc__mast-center">
                            <h1 class="hms-fin-doc__title"><?php echo hms_h($__hmsDocTitle); ?></h1>
                            <p class="hms-fin-doc__tagline"><?php echo hms_h(hms_fin_report_brand_tagline()); ?></p>
                        </div>
                    </header>
                    <?php if ($__hmsMetaP !== []) { ?>
                    <div class="hms-fin-doc__fields hms-fin-doc__fields--primary">
                        <?php foreach ($__hmsMetaP as $lk => $lv) { ?>
                        <div class="hms-fin-doc__field">
                            <span class="hms-fin-doc__field-label"><?php echo hms_h(is_scalar($lk) ? (string) $lk : ''); ?></span>
                            <span class="hms-fin-doc__field-value"><?php echo hms_h(is_scalar($lv) ? (string) $lv : ''); ?></span>
                        </div>
                        <?php } ?>
                    </div>
                    <?php } ?>
                    <?php if ($__hmsMetaS !== []) { ?>
                    <div class="hms-fin-doc__fields hms-fin-doc__fields--secondary">
                        <?php foreach ($__hmsMetaS as $lk => $lv) { ?>
                        <div class="hms-fin-doc__field">
                            <span class="hms-fin-doc__field-label"><?php echo hms_h(is_scalar($lk) ? (string) $lk : ''); ?></span>
                            <span class="hms-fin-doc__field-value"><?php echo hms_h(is_scalar($lv) ? (string) $lv : ''); ?></span>
                        </div>
                        <?php } ?>
                    </div>
                    <?php } ?>
                </div>
