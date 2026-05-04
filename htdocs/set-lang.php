<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && hms_csrf_validate($_POST['hms_csrf'] ?? null)) {
    hms_set_lang((string) ($_POST['lang'] ?? 'en'));
}
header('Location: ' . (isset($_SERVER['HTTP_REFERER']) ? (string) $_SERVER['HTTP_REFERER'] : 'platform-overview.php'));
exit;
