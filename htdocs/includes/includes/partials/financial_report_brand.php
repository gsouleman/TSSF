<?php
declare(strict_types=1);

if (!function_exists('hms_fin_report_org_name')) {
    require_once dirname(__DIR__) . '/financials_reports_theme.php';
}
$__hmsFinLogo = hms_fin_report_logo_src();
$__hmsFinOrg = hms_fin_report_org_name();
$__hmsFinTag = hms_fin_report_brand_tagline();
?>
                <div class="hms-fin-report-brand" role="banner">
                    <div class="hms-fin-report-brand__mark">
                        <img src="<?php echo hms_h($__hmsFinLogo); ?>" alt="" class="hms-fin-report-brand__logo" width="64" height="64" loading="lazy">
                    </div>
                    <div class="hms-fin-report-brand__text">
                        <div class="hms-fin-report-brand__org"><?php echo hms_h($__hmsFinOrg); ?></div>
                        <div class="hms-fin-report-brand__tag"><?php echo hms_h($__hmsFinTag); ?></div>
                    </div>
                </div>
