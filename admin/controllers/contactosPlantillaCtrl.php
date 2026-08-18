<?php
session_start();
require_once __DIR__ . '/../include/controller_boot.php';
ControllerGate::authorizeScript(__FILE__, '_entrypoint');

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../include/generic_classes.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\Border;

if (!isset($_SESSION['session_user'])) {
    http_response_code(403);
    exit('Acceso denegado');
}

$puedeTodos = SessionData::hasPermission('contactos.todos.manage');

$headers = ['Nombre', 'Correo', 'Cargo', 'Teléfono'];
if ($puedeTodos) {
    $headers[] = 'Correo del propietario';
}

$spreadsheet = new Spreadsheet();
$sheet       = $spreadsheet->getActiveSheet();
$sheet->setTitle('Contactos');

$headerStyle = [
    'font'      => ['bold' => true, 'color' => ['argb' => 'FFFFFFFF'], 'size' => 11],
    'fill'      => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FF20427F']],
    'alignment' => ['horizontal' => Alignment::HORIZONTAL_CENTER, 'vertical' => Alignment::VERTICAL_CENTER, 'wrapText' => true],
    'borders'   => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFFFFFFF']]],
];

$col = 1;
foreach ($headers as $header) {
    $sheet->getCellByColumnAndRow($col, 1)->setValue($header);
    $sheet->getColumnDimensionByColumn($col)->setWidth(26);
    $col++;
}
$sheet->getStyle('A1:' . $sheet->getCellByColumnAndRow($col - 1, 1)->getCoordinate())->applyFromArray($headerStyle);
$sheet->getRowDimension(1)->setRowHeight(30);

$ejemplo = [
    'Nombre'                 => 'Juan Pérez',
    'Correo'                 => 'juan.perez@ejemplo.com',
    'Cargo'                  => 'Secretario de Hacienda',
    'Teléfono'               => '3001234567',
    'Correo del propietario' => $puedeTodos ? '(opcional) correo de inicio de sesión del dueño del contacto' : '',
];

$exampleStyle = [
    'fill'    => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['argb' => 'FFE8EDF7']],
    'font'    => ['italic' => true, 'color' => ['argb' => 'FF555555']],
    'borders' => ['allBorders' => ['borderStyle' => Border::BORDER_THIN, 'color' => ['argb' => 'FFCCCCCC']]],
];

$col = 1;
foreach ($headers as $header) {
    $sheet->getCellByColumnAndRow($col, 2)->setValue($ejemplo[$header] ?? '');
    $col++;
}
$sheet->getStyle('A2:' . $sheet->getCellByColumnAndRow($col - 1, 2)->getCoordinate())->applyFromArray($exampleStyle);

header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
header('Content-Disposition: attachment; filename="plantilla_contactos.xlsx"');
header('Cache-Control: max-age=0');

(new Xlsx($spreadsheet))->save('php://output');
exit;
