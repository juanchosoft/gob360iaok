<?php
/**
 * Cron de sincronización de Gmail/Calendar para todos los usuarios con conexión activa.
 * Ver docs/CONFIGURACION_GOOGLE_CLOUD.md §7 para cómo instalarlo en el crontab del hosting.
 *
 * Uso:
 *   php admin/cron/google_sync.php --tarea=correo
 *   php admin/cron/google_sync.php --tarea=calendario
 *   php admin/cron/google_sync.php --tarea=tokens
 *
 * Cada corrida es independiente por usuario: si uno falla (token revocado, error de red),
 * no interrumpe la sincronización de los demás.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Este script solo puede ejecutarse por línea de comandos.');
}

require_once __DIR__ . '/../classes/DbConection.php';
require_once __DIR__ . '/../classes/google/GoogleOAuthService.php';
require_once __DIR__ . '/../classes/google/GmailApi.php';
require_once __DIR__ . '/../classes/google/GoogleSyncService.php';

$opciones = getopt('', ['tarea:']);
$tarea    = $opciones['tarea'] ?? '';

if (!in_array($tarea, ['correo', 'calendario', 'tokens'], true)) {
    fwrite(STDERR, "Uso: php admin/cron/google_sync.php --tarea=correo|calendario|tokens\n");
    exit(1);
}

$inicio = microtime(true);
echo "[" . date('Y-m-d H:i:s') . "] Iniciando tarea '{$tarea}'\n";

$db  = new DbConection();
$pdo = $db->openConect();
$usuarios = $pdo->query("SELECT tbl_usuario_id FROM tbl_google_conexiones WHERE estado = 'activo'")
    ->fetchAll(PDO::FETCH_COLUMN);
$db->closeConect();

echo "Usuarios con conexión activa: " . count($usuarios) . "\n";

$ok  = 0;
$err = 0;

foreach ($usuarios as $usuarioId) {
    $usuarioId = (int) $usuarioId;
    try {
        switch ($tarea) {
            case 'tokens':
                $resultado = ['refrescado' => GoogleSyncService::refrescarTokenUsuario($usuarioId)];
                break;
            case 'correo':
                $resultado = GoogleSyncService::sincronizarCorreoUsuario($usuarioId);
                break;
            case 'calendario':
                $resultado = GoogleSyncService::sincronizarCalendarioUsuario($usuarioId);
                break;
        }
        $ok++;
        echo "  [OK] usuario {$usuarioId}: " . json_encode($resultado, JSON_UNESCAPED_UNICODE) . "\n";
    } catch (GoogleNoConectadoException $e) {
        // Se revocó justo en este ciclo (invalid_grant al refrescar) -- no es un fallo del
        // cron, el próximo ciclo simplemente ya no la incluirá en la lista de activos.
        echo "  [REVOCADO] usuario {$usuarioId}: " . $e->getMessage() . "\n";
    } catch (\Throwable $e) {
        $err++;
        echo "  [ERROR] usuario {$usuarioId}: " . $e->getMessage() . "\n";
        error_log("[google_sync:{$tarea}] usuario {$usuarioId}: " . $e->getMessage());
    }
}

$ms = (int) ((microtime(true) - $inicio) * 1000);
echo "[" . date('Y-m-d H:i:s') . "] Tarea '{$tarea}' terminada en {$ms}ms — OK: {$ok}, errores: {$err}\n";

exit($err > 0 && $ok === 0 ? 1 : 0);
