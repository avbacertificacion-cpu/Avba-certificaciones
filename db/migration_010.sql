-- migration_010: Estatus de flujo para accesorios y personal
-- Estados: PENDIENTE → APROBADO_CALIDAD → EMITIDO | DEVUELTO

ALTER TABLE accesorios_sesiones
  ADD COLUMN IF NOT EXISTS estatus VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE' AFTER control;

ALTER TABLE participantes_cursos
  ADD COLUMN IF NOT EXISTS estatus VARCHAR(30) NOT NULL DEFAULT 'PENDIENTE' AFTER correo;
