<?php
include './admin/include/head.php';
require './admin/include/generic_classes.php';
require_once './admin/classes/google/GoogleOAuthService.php';

$modulo = 'Correo';
date_default_timezone_set('America/Bogota');

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}
$googleConnected = GoogleOAuthService::estaConectado((int) SessionData::getUserId());
$googleEmail     = $googleConnected ? GoogleOAuthService::emailConectado((int) SessionData::getUserId()) : null;
?>
<link rel="stylesheet" href="<?php echo Util::versionar('assets/css/correo.css'); ?>">
<body>
    <?php include './admin/include/navbar.php'; ?>
    <?php include './admin/include/header.php'; ?>

    <div class="pcoded-main-container">
        <div class="pcoded-content correo-page">
            <div class="row">
                <div class="col-12">
                    <div class="correo-hero">
                        <div>
                            <div class="correo-kicker"><span class="correo-dot"></span> Bandeja personal</div>
                            <h3 class="correo-title">Correo</h3>
                            <div class="correo-subtitle">
                                <?php if ($googleConnected): ?>
                                    Conectado como <strong><?php echo htmlspecialchars((string) $googleEmail, ENT_QUOTES, 'UTF-8'); ?></strong>
                                <?php else: ?>
                                    Conecta tu cuenta de Google para gestionar tu correo desde aquí y desde ALMA.
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="correo-hero__actions">
                            <?php if ($googleConnected): ?>
                                <span class="correo-status correo-status--connected"><i class="feather icon-check-circle"></i> Google conectado</span>
                                <a class="btn btn-light btn-sm" href="google/disconnect.php"><i class="feather icon-log-out"></i> Desconectar</a>
                            <?php else: ?>
                                <a class="btn correo-google-btn" href="google/connect.php"><span class="correo-google-g">G</span> Conectar Google</a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!$googleConnected): ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card correo-empty-card">
                            <div class="card-body correo-empty">
                                <div class="correo-empty__icon"><i class="feather icon-mail"></i></div>
                                <h4>Conecta tu cuenta para comenzar</h4>
                                <p>Una vez conectada, ALMA también podrá leer, responder y enviar correos por ti (por texto o por voz).</p>
                                <a class="btn correo-google-btn" href="google/connect.php"><span class="correo-google-g">G</span> Conectar con Google</a>
                            </div>
                        </div>
                    </div>
                </div>
            <?php else: ?>
                <div class="row">
                    <div class="col-12">
                        <div class="card correo-card">
                            <div class="correo-layout">
                                <aside class="correo-list-pane">
                                    <div class="correo-toolbar">
                                        <button type="button" class="btn btn-primary btn-sm btn-block" id="correoRedactarBtn"><i class="feather icon-edit"></i> Redactar</button>
                                        <div class="correo-search">
                                            <input type="text" id="correoBuscarInput" class="form-control form-control-sm" placeholder="Buscar (ej. from:juan asunto)">
                                            <button type="button" class="btn btn-light btn-sm" id="correoBuscarBtn"><i class="feather icon-search"></i></button>
                                        </div>
                                        <div class="correo-filtros">
                                            <div class="btn-group btn-group-sm" role="group">
                                                <button type="button" class="btn btn-outline-secondary active" data-filtro="no_leidos">No leídos</button>
                                                <button type="button" class="btn btn-outline-secondary" data-filtro="todos">Todos</button>
                                            </div>
                                            <button type="button" class="btn btn-light btn-sm" id="correoRefrescarBtn" title="Actualizar"><i class="feather icon-refresh-cw"></i></button>
                                        </div>
                                    </div>
                                    <div id="correoLista" class="correo-lista" aria-live="polite">
                                        <div class="correo-lista__vacio">Cargando correos…</div>
                                    </div>
                                </aside>

                                <section class="correo-read-pane" id="correoReadPane">
                                    <div class="correo-read-pane__vacio">
                                        <i class="feather icon-mail"></i>
                                        <p>Selecciona un correo para leerlo.</p>
                                    </div>
                                </section>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="modal fade" id="correoComposeModal" tabindex="-1" role="dialog" aria-labelledby="correoComposeTitle" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered" role="document">
            <div class="modal-content correo-modal-content">
                <form id="correoComposeForm">
                    <div class="modal-header correo-modal-header">
                        <div>
                            <small>NUEVO CORREO</small>
                            <h5 class="modal-title" id="correoComposeTitle">Redactar</h5>
                        </div>
                        <button type="button" class="close text-white" data-dismiss="modal" aria-label="Cerrar"><span aria-hidden="true">&times;</span></button>
                    </div>
                    <div class="modal-body">
                        <div class="form-group">
                            <label for="correoPara">Para <span class="text-danger">*</span></label>
                            <input id="correoPara" type="email" class="form-control" required>
                        </div>
                        <div class="form-group">
                            <label for="correoAsunto">Asunto <span class="text-danger">*</span></label>
                            <input id="correoAsunto" class="form-control" maxlength="500" required>
                        </div>
                        <div class="form-group mb-1">
                            <label for="correoCuerpo">Mensaje <span class="text-danger">*</span></label>
                            <textarea id="correoCuerpo" class="form-control" rows="8" required></textarea>
                        </div>
                        <div id="correoComposeError" class="correo-form-error" role="alert"></div>
                    </div>
                    <div class="modal-footer">
                        <button class="btn btn-light" type="button" data-dismiss="modal">Cancelar</button>
                        <button class="btn btn-primary" type="submit"><i class="feather icon-send"></i> Enviar</button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div id="correoToast" class="correo-toast" role="status" aria-live="polite"></div>

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
        window.CORREO_CONFIG = <?php echo json_encode(['connected' => $googleConnected], JSON_HEX_TAG | JSON_HEX_AMP | JSON_UNESCAPED_UNICODE); ?>;
    </script>
    <script src="<?php echo Util::versionar('assets/js/correo.js'); ?>"></script>
</body>
</html>
