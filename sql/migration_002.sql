-- ═══════════════════════════════════════════════════════════
--  AVBA CERTIFICACIONES — Migración 002
--  Agrega columnas de plantilla Word por tipo de equipo
-- ═══════════════════════════════════════════════════════════

-- Columna para nombre del archivo .docx de plantilla de certificado
ALTER TABLE `maquinaria_tipos`
  ADD COLUMN IF NOT EXISTS `plantilla_cert` VARCHAR(255) DEFAULT NULL
    COMMENT 'Nombre del archivo .docx en uploads/plantillas/ para el certificado';

-- Columna para nombre del archivo .docx de plantilla de dictamen
ALTER TABLE `maquinaria_tipos`
  ADD COLUMN IF NOT EXISTS `plantilla_dict` VARCHAR(255) DEFAULT NULL
    COMMENT 'Nombre del archivo .docx en uploads/plantillas/ para el dictamen';
