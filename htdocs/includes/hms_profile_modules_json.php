<?php
declare(strict_types=1);

/**
 * Deployment profile modules_json shapes:
 * - v1 flat: { "healthcare": true, ... } — nav mask only
 * - v2: { "nav": { ... }, "items": { "item_id": false, ... } } — optional sparse items (false = hide link)
 */

/**
 * @param array<string,mixed> $decoded
 * @return ?array<string,mixed> Raw nav key map for hms_nav_normalize_mask, or null to derive nav from slices only
 */
function hms_profile_modules_extract_nav_array(array $decoded): ?array
{
    if (isset($decoded['nav'])) {
        return is_array($decoded['nav']) ? $decoded['nav'] : null;
    }
    $flip = array_flip(hms_nav_module_key_list());
    $out = [];
    foreach ($decoded as $k => $v) {
        $ks = (string) $k;
        if ($ks === 'items') {
            continue;
        }
        if (isset($flip[$ks])) {
            $out[$ks] = $v;
        }
    }

    return $out === [] ? null : $out;
}

/**
 * @param array<string,mixed> $decoded
 * @return ?array<string,mixed> Sparse map (typically only false), or null if no item overrides
 */
function hms_profile_modules_extract_items_raw(array $decoded): ?array
{
    if (!array_key_exists('items', $decoded)) {
        return null;
    }

    return is_array($decoded['items']) ? $decoded['items'] : [];
}

function hms_profile_modules_json_has_customization(?array $decoded): bool
{
    if (!is_array($decoded) || $decoded === []) {
        return false;
    }
    if (hms_profile_modules_extract_nav_array($decoded) !== null) {
        return true;
    }
    $items = hms_profile_modules_extract_items_raw($decoded);

    return $items !== null && $items !== [];
}
