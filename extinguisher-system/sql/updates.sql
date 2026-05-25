-- ════════════════════════════════════════════════════════════════════════════
-- ACTUALIZACIONES DE BASE DE DATOS - SISTEMA DE GESTIÓN DE EXTINTORES
-- Ejecutar en orden, una sección a la vez
-- ════════════════════════════════════════════════════════════════════════════

USE extinguisher_management;

-- ─── 1. Tabla inspecciones: agregar columnas fn, gb, rv ──────────────────────
-- (Solo ejecutar si aún no existen)

ALTER TABLE inspecciones
ADD COLUMN IF NOT EXISTS fn  ENUM('OK','NC','NA','PO') DEFAULT NULL AFTER pin,
ADD COLUMN IF NOT EXISTS gb  ENUM('OK','NC','NA','PO') DEFAULT NULL AFTER fn,
ADD COLUMN IF NOT EXISTS rv  ENUM('OK','NC','NA','PO') DEFAULT NULL AFTER gb;

-- ─── 2. Tabla empresas: UNIQUE en nombre ─────────────────────────────────────
-- (Solo ejecutar si aún no existe el constraint)

ALTER TABLE empresas
ADD CONSTRAINT uk_empresa_nombre UNIQUE (nombre);

-- ─── 3. Tabla extintores: cambiar codigo_manual de UNIQUE global a UNIQUE por empresa
-- (Solo ejecutar si existe el índice global)

ALTER TABLE extintores
DROP INDEX IF EXISTS codigo_manual;

-- El constraint correcto (empresa_id + codigo_manual) ya debe existir:
-- UNIQUE KEY uk_extintor_empresa_codigo (empresa_id, codigo_manual)
-- Si no existe, ejecutar:
ALTER TABLE extintores
ADD CONSTRAINT IF NOT EXISTS uk_extintor_empresa_codigo
UNIQUE KEY (empresa_id, codigo_manual);

-- ─── 4. Tabla reportes_mensuales: inspector_id ───────────────────────────────

-- 4a. Hacer plantilla_id nullable
ALTER TABLE reportes_mensuales
MODIFY COLUMN plantilla_id INT;

-- 4b. Agregar columna inspector_id (puede fallar si ya existe)
ALTER TABLE reportes_mensuales
ADD COLUMN IF NOT EXISTS inspector_id INT AFTER generado_por;

-- 4c. Asignar inspector a reportes existentes (usar ID del admin o inspector activo)
--     Reemplaza el 1 por el ID del usuario inspector que corresponda
UPDATE reportes_mensuales
SET inspector_id = (SELECT id FROM usuarios WHERE rol='inspector' AND estado='activo' LIMIT 1)
WHERE inspector_id IS NULL;

-- 4d. Ahora hacer el campo NOT NULL
ALTER TABLE reportes_mensuales
MODIFY COLUMN inspector_id INT NOT NULL;

-- 4e. Agregar FOREIGN KEY
ALTER TABLE reportes_mensuales
ADD CONSTRAINT fk_reportes_inspector
FOREIGN KEY (inspector_id) REFERENCES usuarios(id);

-- ─── 5. Verificar resultado final ────────────────────────────────────────────

DESC inspecciones;
DESC reportes_mensuales;
DESC empresas;
