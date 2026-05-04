<?php
declare(strict_types=1);

function hms_lab_result_table_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_lab_result');
}

function hms_medical_result_table_ok(mysqli $connection): bool
{
    return hms_db_table_exists($connection, 'tbl_medical_result');
}

/** True when both lab and medical registry tables exist (legacy combined check). */
function hms_lab_registry_tables_ready(mysqli $connection): bool
{
    return hms_lab_result_table_ok($connection) && hms_medical_result_table_ok($connection);
}

/**
 * @return array{code: string, label: string, pill: string}
 */
function hms_lab_result_status_ui(string $status): array
{
    $s = strtolower(trim($status));
    if ($s === 'received') {
        return ['code' => 'received', 'label' => 'Received', 'pill' => 'hms-lab-pill--received'];
    }
    if ($s === 'in_progress' || $s === 'inprogress') {
        return ['code' => 'in_progress', 'label' => 'In Progress', 'pill' => 'hms-lab-pill--in_progress'];
    }

    return ['code' => 'pending', 'label' => 'Pending', 'pill' => 'hms-lab-pill--pending'];
}

function hms_lab_test_display_id(int $id): string
{
    return '#TE' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function hms_medical_record_display_id(int $id): string
{
    return '#MR' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}
