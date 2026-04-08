-- ═══════════════════════════════════════════════════════════
--  AVBA CERTIFICACIONES — Migración 001
--  Agrega: reporte_url, plantilla, tabla checklist_secciones
-- ═══════════════════════════════════════════════════════════

-- Columna para URL del reporte de inspección generado
ALTER TABLE `equipos`
  ADD COLUMN IF NOT EXISTS `reporte_url` TEXT DEFAULT NULL AFTER `dictamen_url`;

-- Columna para nombre del archivo de plantilla por tipo de equipo
ALTER TABLE `maquinaria_tipos`
  ADD COLUMN IF NOT EXISTS `plantilla` VARCHAR(100) DEFAULT NULL;

-- Tabla de nombres de secciones del checklist por tipo de equipo
CREATE TABLE IF NOT EXISTS `checklist_secciones` (
  `id`                 INT AUTO_INCREMENT PRIMARY KEY,
  `maquinaria_tipo_id` INT          NOT NULL,
  `codigo`             VARCHAR(10)  NOT NULL,
  `nombre`             VARCHAR(150) NOT NULL,
  `orden`              INT          DEFAULT 0,
  FOREIGN KEY (`maquinaria_tipo_id`) REFERENCES `maquinaria_tipos`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_tipo_seccion` (`maquinaria_tipo_id`, `codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Nombres de secciones para ambos tipos de maquinaria existentes
INSERT IGNORE INTO `checklist_secciones` (`maquinaria_tipo_id`, `codigo`, `nombre`, `orden`)
SELECT m.id, 'E1', 'ESTADO GENERAL DE CHASIS Y ESTRUCTURA', 1 FROM `maquinaria_tipos` m
UNION ALL SELECT m.id, 'E2', 'CABLE',               2 FROM `maquinaria_tipos` m
UNION ALL SELECT m.id, 'E3', 'MALACATE',             3 FROM `maquinaria_tipos` m
UNION ALL SELECT m.id, 'E4', 'GANCHOS Y APAREJOS',   4 FROM `maquinaria_tipos` m
UNION ALL SELECT m.id, 'E5', 'PLUMA',                5 FROM `maquinaria_tipos` m
UNION ALL SELECT m.id, 'E6', 'TORNAMESA',            6 FROM `maquinaria_tipos` m
UNION ALL SELECT m.id, 'E7', 'PISTÓN DE LEVANTE',    7 FROM `maquinaria_tipos` m
UNION ALL SELECT m.id, 'E8', 'LIMITADORES Y SENSORES', 8 FROM `maquinaria_tipos` m;
