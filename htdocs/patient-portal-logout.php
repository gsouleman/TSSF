<?php
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

hms_patient_portal_logout();
session_regenerate_id(true);
header('Location: patient-portal-login.php');
exit;
