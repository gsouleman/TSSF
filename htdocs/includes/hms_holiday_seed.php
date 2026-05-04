<?php
declare(strict_types=1);

/**
 * Seed tbl_hms_holiday with common fixed-date observances + movable Easter-related
 * dates for the given calendar year (Cameroon-oriented public calendar).
 *
 * Skips rows where the same holiday_name already exists for the facility.
 */

/**
 * @return list<array{0: string, 1: string, 2: int}> name, Y-m-d, is_recurring
 */
function hms_holiday_seed_definitions(int $year): array
{
    $y = max(2000, min(2100, $year));
    $rows = [
        ["New Year's Day", sprintf('%04d-01-01', $y), 1],
        ['Youth Day (Cameroon)', sprintf('%04d-02-11', $y), 1],
        ["International Women's Day", sprintf('%04d-03-08', $y), 1],
        ['Labour Day', sprintf('%04d-05-01', $y), 1],
        ['National Day (Cameroon)', sprintf('%04d-05-20', $y), 1],
        ['Assumption Day', sprintf('%04d-08-15', $y), 1],
        ['Christmas Day', sprintf('%04d-12-25', $y), 1],
    ];

    if (function_exists('easter_date')) {
        $t = @easter_date($y);
        if ($t !== false) {
            // Use the same timestamp for offsets (avoid UTC/local string round-trips).
            $goodFriday = date('Y-m-d', strtotime('-2 days', $t));
            $easterMonday = date('Y-m-d', strtotime('+1 day', $t));
            $ascension = date('Y-m-d', strtotime('+39 days', $t));
            $whitMonday = date('Y-m-d', strtotime('+50 days', $t));
            $rows[] = ['Good Friday', $goodFriday, 0];
            $rows[] = ['Easter Monday', $easterMonday, 0];
            $rows[] = ['Ascension Day', $ascension, 0];
            $rows[] = ['Whit Monday', $whitMonday, 0];
        }
    }

    return $rows;
}

/**
 * @return array{inserted: int, skipped: int}
 */
function hms_holiday_seed_major_for_facility(mysqli $connection, int $facilityId, int $year): array
{
    $inserted = 0;
    $skipped = 0;
    if (!hms_db_table_exists($connection, 'tbl_hms_holiday') || $facilityId < 1) {
        return ['inserted' => 0, 'skipped' => 0];
    }

    $chk = mysqli_prepare(
        $connection,
        'SELECT id FROM tbl_hms_holiday WHERE facility_id = ? AND holiday_name = ? LIMIT 1'
    );
    $ins = mysqli_prepare(
        $connection,
        'INSERT INTO tbl_hms_holiday (facility_id, holiday_name, holiday_date, is_recurring) VALUES (?,?,?,?)'
    );
    if (!$chk || !$ins) {
        if ($chk) {
            mysqli_stmt_close($chk);
        }
        if ($ins) {
            mysqli_stmt_close($ins);
        }

        return ['inserted' => 0, 'skipped' => 0];
    }

    foreach (hms_holiday_seed_definitions($year) as $def) {
        [$name, $date, $rec] = $def;
        if ($name === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            continue;
        }
        mysqli_stmt_bind_param($chk, 'is', $facilityId, $name);
        mysqli_stmt_execute($chk);
        mysqli_stmt_store_result($chk);
        $exists = mysqli_stmt_num_rows($chk) > 0;
        if (function_exists('mysqli_stmt_reset')) {
            mysqli_stmt_reset($chk);
        } elseif (function_exists('mysqli_stmt_free_result')) {
            mysqli_stmt_free_result($chk);
        }
        if ($exists) {
            $skipped++;

            continue;
        }
        mysqli_stmt_bind_param($ins, 'issi', $facilityId, $name, $date, $rec);
        if (mysqli_stmt_execute($ins)) {
            $inserted++;
        }
    }

    mysqli_stmt_close($chk);
    mysqli_stmt_close($ins);

    return ['inserted' => $inserted, 'skipped' => $skipped];
}
