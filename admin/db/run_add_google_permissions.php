<?php
/**
 * Sincroniza los permisos nuevos de Google/Correo (google.conexion.manage,
 * asistente_ia.google.use, correo.propio.view, correo.propio.manage) desde PermissionCatalog
 * hacia tbl_permissions, y los otorga a TODOS los roles del sistema -- igual que
 * asistente_ia.chat.use: la protección real es que cada usuario solo opera sobre su propia
 * cuenta conectada, no hace falta restringir por rol quién puede conectarla o usar su correo.
 * Ejecutar: php admin/db/run_add_google_permissions.php (idempotente, se puede re-ejecutar)
 */
require_once __DIR__ . '/../classes/DbConection.php';
require_once __DIR__ . '/../classes/PermissionCatalog.php';

$db  = new DbConection();
$pdo = $db->openConect();

echo "=== Permisos de Google ===\n";

try {
    $claves = [
        'google.conexion.manage',
        'asistente_ia.google.use',
        'correo.propio.view',
        'correo.propio.manage',
    ];

    $stmtPerm = $pdo->prepare(
        "INSERT INTO tbl_permissions (permission_key, module, action, name, legacy_id, dt_create, is_active)
         VALUES (:permission_key, :module, :action, :name, NULL, NOW(), 1)
         ON DUPLICATE KEY UPDATE
            module = VALUES(module),
            action = VALUES(action),
            name = VALUES(name),
            is_active = 1"
    );

    $permIds = [];
    foreach (PermissionCatalog::definitions() as $def) {
        if (!in_array($def['key'], $claves, true)) {
            continue;
        }
        $stmtPerm->execute([
            ':permission_key' => $def['key'],
            ':module'         => $def['module'],
            ':action'         => $def['action'],
            ':name'           => $def['name'],
        ]);
        echo "[OK] Permiso sincronizado: {$def['key']}\n";
    }

    $placeholders = implode(',', array_fill(0, count($claves), '?'));
    $stmtIds = $pdo->prepare("SELECT id FROM tbl_permissions WHERE permission_key IN ({$placeholders})");
    $stmtIds->execute($claves);
    $permIds = array_map('intval', $stmtIds->fetchAll(PDO::FETCH_COLUMN));

    if (empty($permIds)) {
        throw new RuntimeException('No se encontraron los permisos recién sincronizados.');
    }

    $roleIds = $pdo->query("SELECT id, role_key FROM tbl_roles")->fetchAll(PDO::FETCH_ASSOC);
    $stmtGrant = $pdo->prepare("INSERT IGNORE INTO tbl_role_has_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)");

    foreach ($roleIds as $role) {
        foreach ($permIds as $permId) {
            $stmtGrant->execute([':role_id' => (int) $role['id'], ':permission_id' => $permId]);
        }
        echo "[OK] Otorgado a rol {$role['role_key']}\n";
    }

    echo "=== Listo ===\n";
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $db->closeConect();
}
