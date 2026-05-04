<?php
declare(strict_types=1);

/**
 * In-house management reporting branding — Solidarity of Hearts Hospital.
 * (Accounting engine may still use SYSCOHADA-style account classes internally.)
 */
function hms_fin_report_org_name(): string
{
    return 'Solidarity of Hearts Hospital';
}

function hms_fin_report_logo_src(): string
{
    return 'assets/img/logo.png';
}

function hms_fin_report_brand_tagline(): string
{
    return 'Better Planning | Financial Reporting System';
}

/**
 * SYSCOHADA class → short English category for trial balance / schedules.
 */
function hms_fin_report_category_from_class(int $class): string
{
    switch ($class) {
        case 1:
            return 'Equity / long-term';
        case 2:
        case 3:
        case 5:
            return 'Asset';
        case 4:
            return 'Third parties';
        case 6:
            return 'Expense';
        case 7:
            return 'Income';
        default:
            return '—';
    }
}

/**
 * Prefer hospital wording on printed reports (e.g. receivable lines).
 */
function hms_fin_report_label_patient_context(string $label): string
{
    $out = preg_replace('/\b(customer|client)\b/iu', 'Patient', $label);

    return is_string($out) ? $out : $label;
}
