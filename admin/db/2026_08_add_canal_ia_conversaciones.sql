-- Distingue conversaciones del widget de chat de las de la interfaz de voz de gobia.php.
-- Aplicado manualmente en desarrollo el 2026-08-02, sobre la base real de este proyecto: "g360"
-- (ver admin/classes/DbConection.php → $dbName). OJO: no confundir con la BD "santander" del
-- proyecto hermano — no aplicar ahí.

ALTER TABLE tbl_ia_conversaciones
  ADD COLUMN canal ENUM('widget','voz_gobia') NOT NULL DEFAULT 'widget' AFTER tbl_usuario_id;
