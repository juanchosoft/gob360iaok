-- Directorio de contactos personal por usuario, usado tanto por el módulo web (contactos.php)
-- como por ALMA (tool contactos_buscar) para resolver un nombre a un correo antes de enviar/
-- responder un email o invitar a alguien a un evento de calendario.
-- Aplicar sobre la base real de este proyecto (ver admin/classes/DbConection.php -> $dbName).

CREATE TABLE IF NOT EXISTS tbl_contactos (
    id                 INT AUTO_INCREMENT PRIMARY KEY,
    tbl_usuario_id     INT NOT NULL,
    nombre             VARCHAR(255) NOT NULL,
    correo             VARCHAR(150) NOT NULL,
    cargo              VARCHAR(255) NULL,
    telefono           VARCHAR(50) NULL,
    creado_en          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    actualizado_en     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_usuario_correo (tbl_usuario_id, correo),
    KEY idx_usuario (tbl_usuario_id),
    KEY idx_nombre (nombre(50))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
