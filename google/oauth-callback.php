<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/../admin/classes/SessionData.php';
require_once __DIR__ . '/../admin/classes/DbConection.php';
require_once __DIR__ . '/../admin/classes/google/GoogleOAuthService.php';

if (!isset($_GET['state'], $_SESSION['google_oauth_state']) || !hash_equals($_SESSION['google_oauth_state'], (string) $_GET['state'])) {
    http_response_code(400);
    exit('Solicitud OAuth inválida.');
}
unset($_SESSION['google_oauth_state']);

if (empty($_SESSION['session_user'])) {
    header('Location: ../login.php');
    exit;
}

if (empty($_GET['code'])) {
    header('Location: ../calendario.php?google=cancelado');
    exit;
}

try {
    $usuarioId = (int) SessionData::getUserId();
    GoogleOAuthService::conectar($usuarioId, (string) $_GET['code']);
} catch (\Throwable $e) {
    error_log('[Google OAuth] ' . $e->getMessage());
    http_response_code(502);
    exit('Google no pudo completar la conexión: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

header('Location: ../calendario.php?google=conectado');
exit;
