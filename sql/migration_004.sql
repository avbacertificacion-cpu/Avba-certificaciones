-- ═══════════════════════════════════════════════════════════
--  AVBA CERTIFICACIONES — Migración 004
--  Agrega datos de empresa a la tabla clientes (para DC3)
-- ═══════════════════════════════════════════════════════════

ALTER TABLE `clientes`
  ADD COLUMN IF NOT EXISTS `rfc`           VARCHAR(20)  DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `representante` VARCHAR(200) DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS `direccion`     VARCHAR(300) DEFAULT NULL;
