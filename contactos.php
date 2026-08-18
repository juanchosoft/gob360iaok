<?php
include './admin/include/head.php';
require './admin/include/generic_classes.php';

$modulo = 'Directorio de contactos';
date_default_timezone_set('America/Bogota');

$verTodos = SessionData::hasPermission('contactos.todos.view');
$puedeAsignar = SessionData::hasPermission('contactos.todos.manage');
?>
<link rel="stylesheet" href="https://cdn.datatables.net/2.0.3/css/dataTables.bootstrap4.min.css">
<link rel="stylesheet" href="<?php echo Util::versionar('assets/css/contactos.css'); ?>">
<body>
    <?php include './admin/include/navbar.php'; ?>
    <?php include './admin/include/header.php'; ?>

    <div class="pcoded-main-container">
        <div class="pcoded-content contactos-page">
            <div class="row">
                <div class="col-12">
                    <div class="cont-hero">
                        <div>
                            <div class="cont-kicker"><span class="cont-dot"></span> Directorio institucional</div>
                            <h3 class="cont-title"><?php echo htmlspecialchars($modulo, ENT_QUOTES, 'UTF-8'); ?></h3>
                            <div class="cont-subtitle">Guarda a las personas con las que sueles escribirte o reunirte — ALMA puede usarlos para enviar correos o crear eventos con solo decir el nombre.</div>
                        </div>
                        <div class="cont-hero__actions">
                            <a class="btn btn-light btn-sm" href="admin/controllers/contactosPlantillaCtrl.php"><i class="feather icon-download"></i> Plantilla</a>
                            <button type="button" class="btn btn-light btn-sm" id="contImportarBtn"><i class="feather icon-upload"></i> Importar Excel</button>
                            <button type="button" class="btn btn-primary btn-sm" id="contNuevoBtn"><i class="feather icon-user-plus"></i> Nuevo contacto</button>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row">
                <div class="col-12">
                    <div class="card cont-card">
                        <div class="card-header cont-card-header">
                            <div>
                                <h5><i class="feather icon-users"></i> Contactos</h5>
                                <small>Nombre, correo, cargo y teléfono de tus contactos frecuentes.</small>
                            </div>
                            <?php if ($verTodos): ?>
                            <div class="cont-filtro">
                                <label for="contFiltroUsuario">Ver contactos de</label>
                                <select id="contFiltroUsuario" class="form-control" style="width:260px">
                                    <option value="">Todos los usuarios</option>
                                </select>
                            </div>
                            <?php endif; ?>
                        </div>
                        <div class="card-body">
                            <div class="table-responsive">
                                <table id="contTabla" class="table table-hover cont-tabla" style="width:100%">
                                    <thead>
                                        <tr>
                                            <th>Nombre</th>
                                            <th>Correo</th>
                                            <th>Cargo</th>
                                            <th>Teléfono</th>
                                            <?php if ($verTodos): ?><th>Propietario</th><?php endif; ?>
                                            <th class="cont-th-acciones">Acciones</th>
                                        </tr>
                                    </thead>
                                    <tbody></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="modal fade" id="contModal" tabindex="-1" role="dialog" aria-labelledby="contModalTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content cont-modal-content">
                <form id="contForm">
                    <div class="modal-header cont-modal-header">
                        <div>
                            <small>DIRECTORIO</small>
                            <h5 class="modal-title" id="contModalTitle">Nuevo contacto</h5>
                        </div>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <input type="hidden" id="contId">
                        <div class="form-group">
                            <label for="contNombre">Nombre <span class="text-danger">*</span></label>
                            <input id="contNombre" class="form-control" maxlength="255" required>
                        </div>
                        <div class="form-group">
                            <label for="contCorreo">Correo <span class="text-danger">*</span></label>
                            <input id="contCorreo" type="email" class="form-control" maxlength="150" required>
                        </div>
                        <div class="form-row">
                            <div class="form-group col-md-6">
                                <label for="contCargo">Cargo</label>
                                <input id="contCargo" class="form-control" maxlength="255">
                            </div>
                            <div class="form-group col-md-6">
                                <label for="contTelefono">Teléfono</label>
                                <input id="contTelefono" class="form-control" maxlength="50">
                            </div>
                        </div>
                        <?php if ($puedeAsignar): ?>
                        <div class="form-group mb-1">
                            <label for="contPropietario">Pertenece a</label>
                            <select id="contPropietario" class="form-control" style="width:100%"></select>
                        </div>
                        <?php endif; ?>
                        <div id="contFormError" class="cont-form-error" role="alert"></div>
                    </div>
                    <div class="modal-footer">
                        <button id="contEliminarBtn" class="btn btn-outline-danger mr-auto" type="button" hidden><i class="feather icon-trash-2"></i> Eliminar</button>
                        <button class="btn btn-light" type="button" data-dismiss="modal">Cancelar</button>
                        <button class="btn btn-primary" type="submit"><i class="feather icon-save"></i> Guardar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="modal fade" id="contImportModal" tabindex="-1" role="dialog" aria-labelledby="contImportTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content cont-modal-content">
                <form id="contImportForm">
                    <div class="modal-header cont-modal-header">
                        <div>
                            <small>DIRECTORIO</small>
                            <h5 class="modal-title" id="contImportTitle">Importar desde Excel</h5>
                        </div>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <p>Descarga la <a href="admin/controllers/contactosPlantillaCtrl.php">plantilla</a>, complétala y súbela aquí.</p>
                        <div class="form-group mb-1">
                            <label for="contImportFile">Archivo (.xlsx)</label>
                            <input type="file" id="contImportFile" class="form-control-file" accept=".xlsx" required>
                        </div>
                        <div id="contImportResultado" class="cont-import-resultado"></div>
                        <div id="contImportError" class="cont-form-error" role="alert"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-light" type="button" data-dismiss="modal">Cerrar</button>
                        <button class="btn btn-primary" type="submit"><i class="feather icon-upload"></i> Importar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="contToast" class="cont-toast" role="status" aria-live="polite"></div>

    <?php
    $footerFile = './admin/include/footer.php';
    if (is_file($footerFile)) {
        include $footerFile;
    }
    ?>

    <?php include './admin/include/gerenic_script.php'; ?>
    <script src="assets/js/vendor-all.min.js"></script>
    <script src="assets/js/plugins/bootstrap.min.js"></script>
    <script src="assets/js/pcoded.min.js"></script>

    <script>
        window.CONTACTOS_CONFIG = <?php echo json_encode([
            'verTodos' => $verTodos,
            'puedeAsignar' => $puedeAsignar,
        ], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="https://cdn.datatables.net/2.0.3/js/dataTables.js"></script>
    <script src="https://cdn.datatables.net/2.0.3/js/dataTables.bootstrap4.js"></script>
    <?php /* vendor-all.min.js (cargado arriba junto con gerenic_script.php) deja sin efecto el
    plugin de Select2 que gerenic_script.php ya había registrado -- se vuelve a cargar acá,
    después de vendor-all.min.js, para que quede enganchado al jQuery final de la página. */ ?>
    <script src="plugins/select2/js/select2.full.min.js"></script>
    <script src="<?php echo Util::versionar('assets/js/contactos.js'); ?>"></script>
</body>
</html>
