-- migration_009: Números de control para accesorios y personal
ALTER TABLE accesorios_sesiones
  ADD COLUMN IF NOT EXISTS control VARCHAR(30) NULL AFTER usuario;

ALTER TABLE participantes_cursos
  ADD COLUMN IF NOT EXISTS control VARCHAR(30) NULL AFTER correo;
