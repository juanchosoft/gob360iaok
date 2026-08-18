-- Caché de metadatos (nunca el cuerpo del correo ni el detalle completo de un evento -- eso
-- siempre se consulta en vivo, ver docs/PLAN_ALMA_GOOGLE_WORKSPACE.md §3) + progreso de
-- sincronización incremental por usuario (Gmail History API / Calendar syncToken).
-- Aplicar sobre la base real de este proyecto (ver admin/classes/DbConection.php -> $dbName).

CREATE TABLE IF NOT EXISTS tbl_google_correo_cache (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tbl_usuario_id     INT NOT NULL,
    gmail_message_id   VARCHAR(64) NOT NULL,
    gmail_thread_id    VARCHAR(64) NOT NULL,
    remitente          VARCHAR(320) NOT NULL,
    destinatarios      VARCHAR(1000) NULL,
    asunto             VARCHAR(500) NULL,
    fragmento          VARCHAR(500) NULL,
    etiquetas          VARCHAR(255) NULL,
    leido              TINYINT(1) NOT NULL DEFAULT 0,
    fecha              DATETIME NOT NULL,
    creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario_mensaje (tbl_usuario_id, gmail_message_id),
    KEY idx_usuario_fecha (tbl_usuario_id, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_google_correo_sync (
    tbl_usuario_id     INT NOT NULL PRIMARY KEY,
    ultimo_history_id  VARCHAR(64) NULL,
    ultima_sync_en     DATETIME NULL,
    ultimo_error       VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_google_calendario_cache (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tbl_usuario_id     INT NOT NULL,
    google_event_id    VARCHAR(190) NOT NULL,
    titulo             VARCHAR(255) NULL,
    inicio             DATETIME NOT NULL,
    fin                DATETIME NULL,
    todo_el_dia        TINYINT(1) NOT NULL DEFAULT 0,
    ubicacion          VARCHAR(500) NULL,
    descripcion        VARCHAR(2000) NULL,
    creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario_evento (tbl_usuario_id, google_event_id),
    KEY idx_usuario_inicio (tbl_usuario_id, inicio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS tbl_google_calendario_sync (
    tbl_usuario_id     INT NOT NULL PRIMARY KEY,
    sync_token         VARCHAR(500) NULL,
    ultima_sync_en     DATETIME NULL,
    ultimo_error       VARCHAR(255) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
