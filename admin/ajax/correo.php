<?php
/**
 * Endpoint AJAX del módulo de correo (bandeja personal conectada por Google).
 * Reutiliza los mismos métodos estáticos que usa ALMA (ToolGmail) -- una sola implementación
 * de "listar/buscar/leer/marcar/enviar/responder", sin duplicar lógica entre el asistente y
 * la interfaz web.
 *
 * POST op=listar        → filtro ('no_leidos'|'todos'), limite
 * POST op=buscar         → consulta, limite
 * POST op=leer            → mensaje_id
 * POST op=marcar_leido    → mensaje_id, leido ('1'|'0')
 * POST op=enviar          → para, asunto, cuerpo
 * POST op=responder       → mensaje_id, cuerpo
 */
declare(strict_types=1);
session_start();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache');

if (empty($_SESSION['session_user'])) {
    http_response_code(401);
    echo json_encode(['output' => ['valid' => false, 'response' => 'Sesión expirada.']]);
    exit;
}

require_once __DIR__ . '/../classes/SessionData.php';

$op = trim($_POST['op'] ?? '');

$soloLectura   = ['listar', 'buscar', 'leer'];
$permisoNeeded = in_array($op, $soloLectura, true) ? 'correo.propio.view' : 'correo.propio.manage';

if (!SessionData::hasPermission($permisoNeeded)) {
    http_response_code(403);
    echo json_encode(['output' => ['valid' => false, 'response' => 'Sin permiso para gestionar correo.']]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['output' => ['valid' => false, 'response' => 'Método no permitido.']]);
    exit;
}

require_once __DIR__ . '/../classes/DbConection.php';
require_once __DIR__ . '/../classes/google/GoogleOAuthService.php';
require_once __DIR__ . '/../classes/google/GmailApi.php';
require_once __DIR__ . '/../classes/google/GoogleSyncService.php';
require_once __DIR__ . '/../classes/ia/herramientas/ToolGmail.php';

$input = $_POST;

$resultado = match ($op) {
    'listar'       => ToolGmail::listarCorreos($input),
    'buscar'       => ToolGmail::buscarCorreos($input),
    'leer'         => ToolGmail::leerCorreo($input),
    'marcar_leido' => ToolGmail::marcarLeido([
        'mensaje_id' => $input['mensaje_id'] ?? '',
        'leido'      => filter_var($input['leido'] ?? true, FILTER_VALIDATE_BOOLEAN),
    ]),
    'enviar'       => ToolGmail::enviarCorreo($input),
    'responder'    => ToolGmail::responderCorreo($input),
    default        => null,
};

if ($resultado === null) {
    http_response_code(404);
    echo json_encode(['output' => ['valid' => false, 'response' => "Operación '{$op}' no reconocida."]]);
    exit;
}

echo json_encode(['output' => ['valid' => true, 'response' => $resultado]], JSON_UNESCAPED_UNICODE);
