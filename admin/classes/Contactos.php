<?php
require_once 'SessionData.php';
require_once 'DbConection.php';
require_once 'Util.php';

/**
 * Directorio de contactos personal por usuario (Nombre, Correo, Cargo, Teléfono).
 * Alimenta tanto el módulo web (contactos.php) como la tool contactos_buscar de ALMA.
 *
 * Alcance (scope):
 *   - Sin contactos.todos.view/manage: cada usuario solo ve/gestiona SUS PROPIOS contactos
 *     (tbl_usuario_id = sesión), sin importar qué id le manden en la petición.
 *   - Con contactos.todos.view/manage: puede ver/gestionar los de cualquier usuario, y el
 *     listado devuelve además el nombre del propietario de cada contacto.
 */
class Contactos
{
    /**
     * Búsqueda liviana por nombre, siempre acotada a los contactos del propio usuario --
     * usada por ALMA (tool contactos_buscar) para resolver "un contacto mío" a un correo,
     * sin importar si el usuario tiene o no contactos.todos.*.
     */
    public static function buscarPropios(string $nombre, int $usuarioId, int $limite = 5): array
    {
        $db  = new DbConection();
        $pdo = $db->openConect();
        $st  = $pdo->prepare(
            'SELECT nombre, correo, cargo, telefono
               FROM tbl_contactos
              WHERE tbl_usuario_id = :uid AND nombre LIKE :n
              ORDER BY nombre ASC
              LIMIT :lim'
        );
        $st->bindValue(':uid', $usuarioId, PDO::PARAM_INT);
        $st->bindValue(':n', '%' . $nombre . '%');
        $st->bindValue(':lim', max(1, min($limite, 10)), PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $db->closeConect();

        return $rows;
    }

    /** DataTables server-side: lista contactos según el alcance del usuario en sesión. */
    public static function listar($rqst): array
    {
        $usuarioId = (int) SessionData::getUserId();
        $verTodos  = SessionData::hasPermission('contactos.todos.view');

        $db  = new DbConection();
        $pdo = $db->openConect();

        $draw        = (int) ($rqst['draw'] ?? 1);
        $start       = (int) ($rqst['start'] ?? 0);
        $length      = (int) ($rqst['length'] ?? 10);
        $searchValue = trim((string) ($rqst['search']['value'] ?? ''));
        $filtroUsuarioId = $verTodos ? (int) ($rqst['tbl_usuario_id'] ?? 0) : 0;

        $where  = [];
        $params = [];

        if (!$verTodos) {
            $where[]        = 'c.tbl_usuario_id = :uid';
            $params[':uid'] = $usuarioId;
        } elseif ($filtroUsuarioId > 0) {
            $where[]        = 'c.tbl_usuario_id = :uid';
            $params[':uid'] = $filtroUsuarioId;
        }
        if ($searchValue !== '') {
            $where[]     = '(c.nombre LIKE :s OR c.correo LIKE :s OR c.cargo LIKE :s)';
            $params[':s'] = '%' . $searchValue . '%';
        }
        $whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';
        $join     = $verTodos ? ' LEFT JOIN tbl_usuarios u ON u.id = c.tbl_usuario_id' : '';
        $selectOwner = $verTodos ? ", u.nombre AS propietario_nombre, u.apellido AS propietario_apellido" : '';

        $recordsTotal = (int) $pdo->query('SELECT COUNT(*) FROM tbl_contactos')->fetchColumn();

        $stCount = $pdo->prepare("SELECT COUNT(*) FROM tbl_contactos c {$join} {$whereSql}");
        $stCount->execute($params);
        $recordsFiltered = (int) $stCount->fetchColumn();

        $orderColumns = ['nombre', 'correo', 'cargo', 'telefono'];
        $orderIdx     = (int) ($rqst['order'][0]['column'] ?? 0);
        $orderCol     = $orderColumns[$orderIdx] ?? 'nombre';
        $orderDir     = strtolower((string) ($rqst['order'][0]['dir'] ?? 'asc')) === 'desc' ? 'DESC' : 'ASC';

        $sql = "SELECT c.id, c.nombre, c.correo, c.cargo, c.telefono, c.tbl_usuario_id {$selectOwner}
                  FROM tbl_contactos c {$join}
                  {$whereSql}
                 ORDER BY c.{$orderCol} {$orderDir}
                 LIMIT :start, :length";
        $st = $pdo->prepare($sql);
        foreach ($params as $k => $v) {
            $st->bindValue($k, $v);
        }
        $st->bindValue(':start', $start, PDO::PARAM_INT);
        $st->bindValue(':length', $length > 0 ? $length : 10, PDO::PARAM_INT);
        $st->execute();
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        $db->closeConect();

        if ($verTodos) {
            foreach ($rows as &$row) {
                $row['propietario'] = trim(($row['propietario_nombre'] ?? '') . ' ' . ($row['propietario_apellido'] ?? ''));
            }
            unset($row);
        }

        return [
            'draw'            => $draw,
            'recordsTotal'    => $recordsTotal,
            'recordsFiltered' => $recordsFiltered,
            'data'            => $rows,
            'puede_ver_todos' => $verTodos,
        ];
    }

    /** Usuarios para el Select2 de filtro (solo tiene sentido con contactos.todos.view). */
    public static function usuariosParaFiltro($rqst): array
    {
        if (!SessionData::hasPermission('contactos.todos.view')) {
            return ['output' => ['valid' => false, 'response' => 'Sin permiso.']];
        }

        $db  = new DbConection();
        $pdo = $db->openConect();
        $rows = $pdo->query(
            "SELECT DISTINCT u.id, u.nombre, u.apellido
               FROM tbl_contactos c
               JOIN tbl_usuarios u ON u.id = c.tbl_usuario_id
              ORDER BY u.nombre ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $db->closeConect();

        $usuarios = array_map(static fn(array $u): array => [
            'id'     => (int) $u['id'],
            'nombre' => trim($u['nombre'] . ' ' . $u['apellido']),
        ], $rows);

        return ['output' => ['valid' => true, 'response' => $usuarios]];
    }

    /** Todos los usuarios habilitados, para el Select2 de "asignar a" en el modal (solo contactos.todos.manage). */
    public static function usuariosParaAsignar($rqst): array
    {
        if (!SessionData::hasPermission('contactos.todos.manage')) {
            return ['output' => ['valid' => false, 'response' => 'Sin permiso.']];
        }

        $db  = new DbConection();
        $pdo = $db->openConect();
        $rows = $pdo->query(
            "SELECT id, nombre, apellido FROM tbl_usuarios WHERE habilitado = 'si' ORDER BY nombre ASC"
        )->fetchAll(PDO::FETCH_ASSOC);
        $db->closeConect();

        $usuarios = array_map(static fn(array $u): array => [
            'id'     => (int) $u['id'],
            'nombre' => trim($u['nombre'] . ' ' . $u['apellido']),
        ], $rows);

        return ['output' => ['valid' => true, 'response' => $usuarios]];
    }

    /** Crea o edita (si viene id) un contacto, respetando el alcance del usuario en sesión. */
    public static function guardar($rqst): array
    {
        $usuarioId = (int) SessionData::getUserId();
        $puedeTodos = SessionData::hasPermission('contactos.todos.manage');

        $id       = (int) ($rqst['id'] ?? 0);
        $nombre   = trim((string) ($rqst['nombre'] ?? ''));
        $correo   = trim((string) ($rqst['correo'] ?? ''));
        $cargo    = trim((string) ($rqst['cargo'] ?? ''));
        $telefono = trim((string) ($rqst['telefono'] ?? ''));

        if ($nombre === '' || $correo === '') {
            return ['output' => ['valid' => false, 'response' => 'Nombre y correo son obligatorios.']];
        }
        if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
            return ['output' => ['valid' => false, 'response' => 'El correo electrónico no es válido.']];
        }

        // El propietario del contacto: solo quien tiene contactos.todos.manage puede asignarlo
        // a otro usuario -- cualquier otro valor recibido del cliente se ignora por completo.
        $propietarioId = $usuarioId;
        if ($puedeTodos && (int) ($rqst['tbl_usuario_id'] ?? 0) > 0) {
            $propietarioId = (int) $rqst['tbl_usuario_id'];
        }

        $db  = new DbConection();
        $pdo = $db->openConect();

        if ($id > 0) {
            $st = $pdo->prepare('SELECT tbl_usuario_id FROM tbl_contactos WHERE id = :id');
            $st->execute([':id' => $id]);
            $duenoActual = $st->fetchColumn();
            if ($duenoActual === false) {
                $db->closeConect();
                return ['output' => ['valid' => false, 'response' => 'Contacto no encontrado.']];
            }
            if ((int) $duenoActual !== $usuarioId && !$puedeTodos) {
                $db->closeConect();
                return ['output' => ['valid' => false, 'response' => 'No tienes permiso para editar este contacto.']];
            }
            // Si no tiene contactos.todos.manage, el contacto se queda con su dueño actual.
            if (!$puedeTodos) {
                $propietarioId = (int) $duenoActual;
            }
        }

        try {
            if ($id > 0) {
                $st = $pdo->prepare(
                    'UPDATE tbl_contactos
                        SET tbl_usuario_id = :uid, nombre = :nombre, correo = :correo, cargo = :cargo, telefono = :telefono
                      WHERE id = :id'
                );
                $st->execute([
                    ':uid'      => $propietarioId,
                    ':nombre'   => $nombre,
                    ':correo'   => $correo,
                    ':cargo'    => $cargo !== '' ? $cargo : null,
                    ':telefono' => $telefono !== '' ? $telefono : null,
                    ':id'       => $id,
                ]);
                $mensaje = 'Contacto actualizado correctamente.';
            } else {
                $st = $pdo->prepare(
                    'INSERT INTO tbl_contactos (tbl_usuario_id, nombre, correo, cargo, telefono)
                     VALUES (:uid, :nombre, :correo, :cargo, :telefono)'
                );
                $st->execute([
                    ':uid'      => $propietarioId,
                    ':nombre'   => $nombre,
                    ':correo'   => $correo,
                    ':cargo'    => $cargo !== '' ? $cargo : null,
                    ':telefono' => $telefono !== '' ? $telefono : null,
                ]);
                $mensaje = 'Contacto creado correctamente.';
            }
        } catch (PDOException $e) {
            $db->closeConect();
            if ((int) $e->getCode() === 23000) {
                return ['output' => ['valid' => false, 'response' => 'Ese usuario ya tiene un contacto con ese correo.']];
            }
            return ['output' => ['valid' => false, 'response' => 'Error de base de datos: ' . $e->getMessage()]];
        }

        $db->closeConect();
        return ['output' => ['valid' => true, 'response' => $mensaje]];
    }

    public static function eliminar($rqst): array
    {
        $usuarioId  = (int) SessionData::getUserId();
        $puedeTodos = SessionData::hasPermission('contactos.todos.manage');
        $id         = (int) ($rqst['id'] ?? 0);

        if ($id <= 0) {
            return ['output' => ['valid' => false, 'response' => 'Contacto inválido.']];
        }

        $db  = new DbConection();
        $pdo = $db->openConect();

        $st = $pdo->prepare('SELECT tbl_usuario_id FROM tbl_contactos WHERE id = :id');
        $st->execute([':id' => $id]);
        $dueno = $st->fetchColumn();

        if ($dueno === false) {
            $db->closeConect();
            return ['output' => ['valid' => false, 'response' => 'Contacto no encontrado.']];
        }
        if ((int) $dueno !== $usuarioId && !$puedeTodos) {
            $db->closeConect();
            return ['output' => ['valid' => false, 'response' => 'No tienes permiso para eliminar este contacto.']];
        }

        $pdo->prepare('DELETE FROM tbl_contactos WHERE id = :id')->execute([':id' => $id]);
        $db->closeConect();

        return ['output' => ['valid' => true, 'response' => 'Contacto eliminado.']];
    }

    /**
     * Importa contactos desde un Excel (mismo patrón que HaciendaImport::processExcel).
     * Columnas: Nombre, Correo, Cargo, Teléfono y, si el usuario tiene contactos.todos.manage,
     * una columna opcional "Correo del propietario" para asignar el contacto a otro usuario
     * (resuelto por tbl_usuarios.email) -- si se omite o no tiene el permiso, el contacto
     * queda a nombre de quien importa.
     */
    public static function importarExcel(string $filePath, int $usuarioId): array
    {
        $puedeTodos = SessionData::hasPermission('contactos.todos.manage');

        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($filePath);
        $worksheet   = $spreadsheet->getActiveSheet();
        $data        = $worksheet->toArray(null, true, true, true);

        if (empty($data)) {
            return ['inserted' => 0, 'errors' => [['fila' => '-', 'mensaje' => 'El archivo está vacío.']]];
        }

        $rawHeaders   = array_shift($data);
        $excelHeaders = array_map(static fn($v) => trim((string) $v), $rawHeaders);

        $headerMap = [
            'Nombre'                => 'nombre',
            'Correo'                => 'correo',
            'Cargo'                 => 'cargo',
            'Teléfono'              => 'telefono',
            'Correo del propietario' => 'propietario_correo',
        ];

        $colMap = [];
        foreach ($excelHeaders as $colLetter => $label) {
            if (isset($headerMap[$label])) {
                $colMap[$colLetter] = $headerMap[$label];
            }
        }

        $db  = new DbConection();
        $pdo = $db->openConect();

        // Mapa de correo de usuario -> id, para resolver "Correo del propietario" sin una
        // consulta por fila.
        $usuariosPorCorreo = [];
        if ($puedeTodos) {
            $stmtUsr = $pdo->query('SELECT id, email FROM tbl_usuarios WHERE email IS NOT NULL AND email != ""');
            foreach ($stmtUsr->fetchAll(PDO::FETCH_ASSOC) as $u) {
                $usuariosPorCorreo[mb_strtolower(trim($u['email']))] = (int) $u['id'];
            }
        }

        $errors    = [];
        $rowsBatch = [];
        $filaExcel = 2;

        foreach ($data as $row) {
            if (count(array_filter(array_map(static fn($v) => trim((string) $v), $row))) === 0) {
                $filaExcel++;
                continue;
            }

            $fields = [];
            foreach ($colMap as $colLetter => $fieldName) {
                $fields[$fieldName] = isset($row[$colLetter]) ? trim((string) $row[$colLetter]) : '';
            }

            $nombre = $fields['nombre'] ?? '';
            $correo = $fields['correo'] ?? '';
            if ($nombre === '' || $correo === '') {
                $errors[] = ['fila' => $filaExcel, 'mensaje' => 'Nombre y correo son obligatorios.'];
                $filaExcel++;
                continue;
            }
            if (!filter_var($correo, FILTER_VALIDATE_EMAIL)) {
                $errors[] = ['fila' => $filaExcel, 'mensaje' => "Correo inválido: '{$correo}'."];
                $filaExcel++;
                continue;
            }

            $propietarioId = $usuarioId;
            $propietarioCorreo = mb_strtolower($fields['propietario_correo'] ?? '');
            if ($puedeTodos && $propietarioCorreo !== '') {
                if (!isset($usuariosPorCorreo[$propietarioCorreo])) {
                    $errors[] = ['fila' => $filaExcel, 'mensaje' => "No se encontró un usuario con el correo '{$fields['propietario_correo']}'."];
                    $filaExcel++;
                    continue;
                }
                $propietarioId = $usuariosPorCorreo[$propietarioCorreo];
            }

            $rowsBatch[] = [
                'tbl_usuario_id' => $propietarioId,
                'nombre'         => $nombre,
                'correo'         => $correo,
                'cargo'          => $fields['cargo'] ?? '',
                'telefono'       => $fields['telefono'] ?? '',
            ];
            $filaExcel++;
        }

        if (!empty($errors)) {
            $db->closeConect();
            return ['inserted' => 0, 'errors' => $errors];
        }
        if (empty($rowsBatch)) {
            $db->closeConect();
            return ['inserted' => 0, 'errors' => [['fila' => '-', 'mensaje' => 'No se encontraron filas de datos.']]];
        }

        $sql = 'INSERT INTO tbl_contactos (tbl_usuario_id, nombre, correo, cargo, telefono)
                VALUES (:tbl_usuario_id, :nombre, :correo, :cargo, :telefono)
                ON DUPLICATE KEY UPDATE nombre = VALUES(nombre), cargo = VALUES(cargo), telefono = VALUES(telefono)';

        try {
            $stmt = $pdo->prepare($sql);
            $pdo->beginTransaction();
            foreach ($rowsBatch as $params) {
                $stmt->execute($params);
            }
            $pdo->commit();
            $inserted = count($rowsBatch);
        } catch (Exception $e) {
            $pdo->rollBack();
            $db->closeConect();
            return ['inserted' => 0, 'errors' => [['fila' => '-', 'mensaje' => 'Error en BD, se revirtió la carga: ' . $e->getMessage()]]];
        }

        $db->closeConect();
        return ['inserted' => $inserted, 'errors' => []];
    }
}
