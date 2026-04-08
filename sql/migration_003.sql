-- ═══════════════════════════════════════════════════════════
--  AVBA CERTIFICACIONES — Migración 003
--  Agrega plantillas Word para versión de envío por correo
-- ═══════════════════════════════════════════════════════════

-- Plantilla para el certificado que se envía por correo
ALTER TABLE `maquinaria_tipos`
  ADD COLUMN IF NOT EXISTS `plantilla_cert_envio` VARCHAR(255) DEFAULT NULL
    COMMENT 'Plantilla .docx del certificado enviado por correo';

-- Plantilla para el dictamen que se envía por correo
ALTER TABLE `maquinaria_tipos`
  ADD COLUMN IF NOT EXISTS `plantilla_dict_envio` VARCHAR(255) DEFAULT NULL
    COMMENT 'Plantilla .docx del dictamen enviado por correo';
