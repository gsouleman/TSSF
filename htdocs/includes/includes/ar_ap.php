<?php
declare(strict_types=1);

/**
 * Accounts receivable / payable cross-links (vendor bills, insurer settlements).
 */
function hms_ar_ap_touch_receipt_settlement(mysqli $connection, int $billingDocumentId): void
{
    if ($billingDocumentId < 1) {
        return;
    }
    // Reserved: mark AP/insurance tasks when tbl_ap_* tables are introduced.
}
