<?php
declare(strict_types=1);

function hms_csrf_seed(): void
{
    if (empty($_SESSION['hms_csrf'])) {
        $_SESSION['hms_csrf'] = bin2hex(random_bytes(32));
    }
}

function hms_csrf_token(): string
{
    return (string) ($_SESSION['hms_csrf'] ?? '');
}

function hms_csrf_validate(?string $token): bool
{
    $expected = $_SESSION['hms_csrf'] ?? '';
    return is_string($token) && $expected !== '' && hash_equals($expected, $token);
}

function hms_csrf_field(): string
{
    hms_csrf_seed();

    return '<input type="hidden" name="hms_csrf" value="' . hms_h(hms_csrf_token()) . '">';
}
