-- ════════════════════════════════════════════════════════════════════════════
-- ACTUALIZACIONES DE BASE DE DATOS - SISTEMA DE GESTIÓN DE EXTINTORES
-- ════════════════════════════════════════════════════════════════════════════

-- Usar la base de datos correcta
USE extinguisher_management;

-- ─── 1. Actualizar tabla reportes_mensuales ──────────────────────────────────

-- Hacer plantilla_id nullable (remover NOT NULL)
ALTER TABLE reportes_mensuales
MODIFY COLUMN plantilla_id INT;

-- Agregar columna inspector_id
ALTER TABLE reportes_mensuales
ADD COLUMN inspector_id INT NOT NULL AFTER generado_por;

-- Agregar FOREIGN KEY para inspector_id
ALTER TABLE reportes_mensuales
ADD CONSTRAINT fk_reportes_inspector
FOREIGN KEY (inspector_id) REFERENCES usuarios(id);

-- ─── 2. Verificar cambios ───────────────────────────────────────────────────

-- Ver estructura de la tabla reportes_mensuales
DESC reportes_mensuales;

-- Ver que todas las claves foráneas están correctas
SELECT CONSTRAINT_NAME, TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
WHERE TABLE_NAME='reportes_mensuales';

-- ════════════════════════════════════════════════════════════════════════════
-- FIN DE ACTUALIZACIONES
-- ════════════════════════════════════════════════════════════════════════════
