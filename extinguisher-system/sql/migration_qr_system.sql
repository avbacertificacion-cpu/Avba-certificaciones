-- ════════════════════════════════════════════════════════════════════════════
-- MIGRACIÓN: Sistema de Códigos QR Secuenciales
-- Ejecutar CADA PASO por separado en PhpMyAdmin
-- ════════════════════════════════════════════════════════════════════════════

-- ─── PASO 1: Eliminar índice único actual de codigo_qr ───────────────────────
ALTER TABLE extintores DROP INDEX `codigo_qr`;

-- ─── PASO 2: Cambiar columna codigo_qr a VARCHAR(11) NULLABLE ────────────────
ALTER TABLE extintores
MODIFY COLUMN codigo_qr VARCHAR(11) DEFAULT NULL
COMMENT '11 dígitos numéricos, NULL hasta asignación manual';

-- ─── PASO 3: Limpiar QRs anteriores (hashes) — dejarlos en NULL ──────────────
UPDATE extintores SET codigo_qr = NULL;

-- ─── PASO 4: Restaurar índice UNIQUE (ahora permite múltiples NULL) ───────────
ALTER TABLE extintores ADD UNIQUE KEY uk_codigo_qr (codigo_qr);

-- ─── PASO 5: Crear tabla de control de secuencia QR ──────────────────────────
CREATE TABLE IF NOT EXISTS qr_secuencias (
    id                  INT PRIMARY KEY AUTO_INCREMENT,
    proximo_qr          BIGINT UNSIGNED DEFAULT 1
                        COMMENT 'Siguiente QR sin asignar (1–99999999999)',
    ultima_actualizacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    actualizado_por     INT DEFAULT NULL,
    FOREIGN KEY (actualizado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Registro inicial (solo uno para todo el sistema)
INSERT IGNORE INTO qr_secuencias (id, proximo_qr)
VALUES (1, 1);

-- ─── PASO 6: Crear tabla de auditoría de asignaciones QR ────────────────────
CREATE TABLE IF NOT EXISTS qr_asignaciones (
    id              INT PRIMARY KEY AUTO_INCREMENT,
    extintor_id     INT NOT NULL,
    qr_anterior     VARCHAR(11) DEFAULT NULL,
    qr_nuevo        VARCHAR(11) NOT NULL,
    asignado_por    INT NOT NULL,
    rol_asignador   ENUM('administrador','inspector') NOT NULL,
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (extintor_id)  REFERENCES extintores(id) ON DELETE CASCADE,
    FOREIGN KEY (asignado_por) REFERENCES usuarios(id)   ON DELETE RESTRICT,
    INDEX idx_extintor  (extintor_id),
    INDEX idx_qr_nuevo  (qr_nuevo),
    INDEX idx_fecha     (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ─── VERIFICACIÓN FINAL ──────────────────────────────────────────────────────
SELECT 'qr_secuencias'  AS tabla, COUNT(*) AS registros FROM qr_secuencias
UNION ALL
SELECT 'qr_asignaciones', COUNT(*) FROM qr_asignaciones;

DESCRIBE extintores;
