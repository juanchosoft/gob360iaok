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
if (!SessionData::hasPermission('google.conexion.manage')) {
    http_response_code(403);
    exit('No tienes permiso para conectar una cuenta de Google.');
}

$state = bin2hex(random_bytes(24));
$_SESSION['google_oauth_state'] = $state;

header('Location: ' . GoogleOAuthService::urlAutorizacion($state));
exit;
