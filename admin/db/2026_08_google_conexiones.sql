-- Conexión persistente de cada usuario con su propia cuenta de Google (Gmail + Calendar),
-- usada tanto por ALMA como por el módulo de Calendario. Reemplaza el token que antes vivía
-- solo en $_SESSION (no servía para cron ni para acciones fuera de una sesión activa).
-- Aplicar sobre la base real de este proyecto (ver admin/classes/DbConection.php -> $dbName,
-- hoy "g360" en este equipo -- no asumir el nombre documentado en CLAUDE.md).

CREATE TABLE IF NOT EXISTS tbl_google_conexiones (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tbl_usuario_id     INT NOT NULL,
    google_email       VARCHAR(190) NOT NULL,
    access_token_enc   TEXT NOT NULL,
    refresh_token_enc  TEXT NOT NULL,
    scope              TEXT NOT NULL,
    expires_at         DATETIME NOT NULL,
    estado             ENUM('activo','revocado','error') NOT NULL DEFAULT 'activo',
    ultimo_error       VARCHAR(255) NULL,
    conectado_en       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario (tbl_usuario_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
