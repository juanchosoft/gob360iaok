<?php
/**
 * Sincroniza los permisos del directorio de contactos (contactos.propio.*, contactos.todos.*)
 * desde PermissionCatalog hacia tbl_permissions, y los otorga:
 *   - contactos.propio.* -> TODOS los roles (cada quien gestiona su propio directorio)
 *   - contactos.todos.*  -> solo super_administrador y administrador (mismo criterio que
 *                           asistente_ia.logs.view en CLAUDE.md)
 * Ejecutar: php admin/db/run_add_contactos_permissions.php (idempotente, se puede re-ejecutar)
 */
require_once __DIR__ . '/../classes/DbConection.php';
require_once __DIR__ . '/../classes/PermissionCatalog.php';

$db  = new DbConection();
$pdo = $db->openConect();

echo "=== Permisos de Contactos ===\n";

try {
    $clavesPropio = ['contactos.propio.view', 'contactos.propio.manage'];
    $clavesTodos  = ['contactos.todos.view', 'contactos.todos.manage'];
    $todasLasClaves = array_merge($clavesPropio, $clavesTodos);

    $stmtPerm = $pdo->prepare(
        "INSERT INTO tbl_permissions (permission_key, module, action, name, legacy_id, dt_create, is_active)
         VALUES (:permission_key, :module, :action, :name, NULL, NOW(), 1)
         ON DUPLICATE KEY UPDATE
            module = VALUES(module),
            action = VALUES(action),
            name = VALUES(name),
            is_active = 1"
    );

    foreach (PermissionCatalog::definitions() as $def) {
        if (!in_array($def['key'], $todasLasClaves, true)) {
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

    $stmtIds = $pdo->prepare("SELECT id, permission_key FROM tbl_permissions WHERE permission_key IN ("
        . implode(',', array_fill(0, count($todasLasClaves), '?')) . ")");
    $stmtIds->execute($todasLasClaves);
    $idsPorClave = [];
    foreach ($stmtIds->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $idsPorClave[$row['permission_key']] = (int) $row['id'];
    }

    $stmtGrant = $pdo->prepare("INSERT IGNORE INTO tbl_role_has_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)");

    // propio.* -> todos los roles
    $roles = $pdo->query("SELECT id, role_key FROM tbl_roles")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($roles as $role) {
        foreach ($clavesPropio as $clave) {
            if (isset($idsPorClave[$clave])) {
                $stmtGrant->execute([':role_id' => (int) $role['id'], ':permission_id' => $idsPorClave[$clave]]);
            }
        }
        echo "[OK] contactos.propio.* otorgado a rol {$role['role_key']}\n";
    }

    // todos.* -> solo super_administrador y administrador
    $stmtRoleId = $pdo->prepare("SELECT id FROM tbl_roles WHERE role_key = :role_key LIMIT 1");
    foreach (['super_administrador', 'administrador'] as $roleKey) {
        $stmtRoleId->execute([':role_key' => $roleKey]);
        $roleId = (int) $stmtRoleId->fetchColumn();
        if ($roleId <= 0) {
            continue;
        }
        foreach ($clavesTodos as $clave) {
            if (isset($idsPorClave[$clave])) {
                $stmtGrant->execute([':role_id' => $roleId, ':permission_id' => $idsPorClave[$clave]]);
            }
        }
        echo "[OK] contactos.todos.* otorgado a rol {$roleKey}\n";
    }

    echo "=== Listo ===\n";
} catch (Throwable $e) {
    echo "[ERROR] " . $e->getMessage() . "\n";
    exit(1);
} finally {
    $db->closeConect();
}
