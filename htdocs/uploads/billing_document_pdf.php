<?php
declare(strict_types=1);

function hms_billing_pdf_autoload_path(): string
{
    return dirname(__DIR__) . '/vendor/autoload.php';
}

function hms_billing_pdf_available(): bool
{
    return is_file(hms_billing_pdf_autoload_path());
}

/**
 * Render HTML to PDF bytes using Dompdf (run `composer install` in the `hms` folder).
 *
 * @return string|false
 */
function hms_billing_html_to_pdf_bytes(string $html)
{
    if (!hms_billing_pdf_available()) {
        return false;
    }
    require_once hms_billing_pdf_autoload_path();

    $options = new \Dompdf\Options();
    $options->set('isRemoteEnabled', false);
    $options->set('isHtml5ParserEnabled', true);
    $options->set('defaultFont', 'Helvetica');
    $options->set('isFontSubsettingEnabled', false);

    $dompdf = new \Dompdf\Dompdf($options);
    $dompdf->loadHtml($html, 'UTF-8');
    $dompdf->setPaper('A4', 'portrait');
    $dompdf->render();

    return $dompdf->output();
}
