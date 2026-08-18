<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['session_user'])) {
    echo json_encode(['valid' => false, 'message' => 'Sesión no iniciada.']);
    exit;
}

require_once __DIR__ . '/../classes/SessionData.php';
if (!SessionData::hasPermission('contactos.propio.manage') && !SessionData::hasPermission('contactos.todos.manage')) {
    echo json_encode(['valid' => false, 'message' => 'Sin permiso para importar contactos.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['excelFile'])) {
    echo json_encode(['valid' => false, 'message' => 'Solicitud inválida.']);
    exit;
}

$allowedTypes = ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'];
if (!in_array($_FILES['excelFile']['type'], $allowedTypes)) {
    echo json_encode(['valid' => false, 'message' => 'Solo se permiten archivos .xlsx.']);
    exit;
}

if ($_FILES['excelFile']['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['valid' => false, 'message' => 'Error al subir el archivo.']);
    exit;
}

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../include/generic_classes.php';
require_once __DIR__ . '/../classes/Contactos.php';

$userId = (int) ($_SESSION['session_user']['id'] ?? 0);

try {
    $result = Contactos::importarExcel($_FILES['excelFile']['tmp_name'], $userId);

    echo json_encode([
        'valid'    => true,
        'inserted' => $result['inserted'],
        'errors'   => $result['errors'],
        'message'  => "Se importaron {$result['inserted']} contacto(s) correctamente.",
    ]);
} catch (\PhpOffice\PhpSpreadsheet\Reader\Exception $e) {
    echo json_encode(['valid' => false, 'message' => 'Error leyendo el Excel: ' . $e->getMessage()]);
} catch (Exception $e) {
    echo json_encode(['valid' => false, 'message' => 'Error inesperado: ' . $e->getMessage()]);
}
