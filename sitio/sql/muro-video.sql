-- ─────────────────────────────────────────────────────────────
--  AVBA · Muro de operadores — soporte de video
--
--  Ejecuta este archivo SOLO si ya creaste la tabla con muro.sql
--  antes de que el muro aceptara videos. Si la creas ahora desde
--  cero con muro.sql, ya viene todo incluido y esto no hace falta.
-- ─────────────────────────────────────────────────────────────

ALTER TABLE muro_publicaciones
    ADD COLUMN tipo ENUM('imagen','video') NOT NULL DEFAULT 'imagen' AFTER comentario,
    ADD COLUMN poster VARCHAR(120) NULL AFTER archivo,
    ADD COLUMN duracion SMALLINT UNSIGNED NULL AFTER alto;
