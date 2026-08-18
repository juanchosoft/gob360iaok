<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../admin/classes/SessionData.php';
require_once __DIR__ . '/../admin/classes/DbConection.php';
require_once __DIR__ . '/../admin/classes/google/GoogleOAuthService.php';

if (empty($_SESSION['session_user'])) {
    header('Location: ../login.php');
    exit;
}

GoogleOAuthService::desconectar((int) SessionData::getUserId());

header('Location: ../calendario.php');
exit;
