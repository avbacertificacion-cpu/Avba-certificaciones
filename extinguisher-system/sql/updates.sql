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
-- Si ves error "#1061 - Nombre duplicado de clave", el constraint ya existe (es normal, puedes ignorar)

-- Para MariaDB: eliminar y recrear si existe
-- DROP INDEX IF EXISTS uk_empresa_nombre ON empresas;
-- ALTER TABLE empresas ADD CONSTRAINT uk_empresa_nombre UNIQUE (nombre);

-- O simplemente ignorar si ya existe
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
ADD UNIQUE KEY IF NOT EXISTS uk_extintor_empresa_codigo (empresa_id, codigo_manual);

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

-- ─── 5. Tabla tipos_extintores: tipos configurables ─────────────────────────

-- 5a. Crear tabla de tipos
CREATE TABLE IF NOT EXISTS tipos_extintores (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    nombre      VARCHAR(50) NOT NULL UNIQUE,
    descripcion VARCHAR(255),
    estado      ENUM('activo','inactivo') DEFAULT 'activo',
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- 5b. Migrar datos existentes (ejecutar solo si extintores.tipo es ENUM)
INSERT IGNORE INTO tipos_extintores (nombre, descripcion) VALUES
    ('PQS', 'Polvo Químico Seco'),
    ('CO2', 'Dióxido de Carbono'),
    ('agua', 'Agua'),
    ('espuma', 'Espuma'),
    ('halotron', 'Halotron'),
    ('otro', 'Otro tipo');

-- 5c. Cambiar extintores.tipo de ENUM a INT
-- (Este paso requiere manejo especial - crear columna temporal, migrar, renombrar)
-- PASO 1: Crear columna temporal
ALTER TABLE extintores
ADD COLUMN tipo_id INT AFTER tipo;

-- PASO 2: Copiar datos usando el mapeo de tipos
UPDATE extintores e
SET tipo_id = (SELECT id FROM tipos_extintores WHERE nombre = e.tipo)
WHERE tipo_id IS NULL;

-- PASO 3: Hacer tipo_id NOT NULL y agregar FK
ALTER TABLE extintores
MODIFY COLUMN tipo_id INT NOT NULL;

ALTER TABLE extintores
ADD CONSTRAINT fk_extintor_tipo
FOREIGN KEY (tipo_id) REFERENCES tipos_extintores(id);

-- PASO 4: Eliminar columna ENUM tipo
ALTER TABLE extintores
DROP COLUMN tipo;

-- PASO 5: Renombrar tipo_id a tipo
ALTER TABLE extintores
CHANGE COLUMN tipo_id tipo INT NOT NULL;

-- ─── 6. Verificar resultado final ────────────────────────────────────────────

DESC inspecciones;
DESC reportes_mensuales;
DESC empresas;
DESC tipos_extintores;
DESC extintores;

-- ─── 7. Crear tabla api_logs para rate limiting ─────────────────────────────

CREATE TABLE IF NOT EXISTS api_logs (
    id          INT PRIMARY KEY AUTO_INCREMENT,
    endpoint    VARCHAR(100),
    ip          VARCHAR(45),
    status_code INT,
    details     TEXT,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ip_endpoint (ip, endpoint, created_at)
);

-- Verificación
DESC api_logs;
