-- ═══════════════════════════════════════════════════════════
--  MIGRATION 008 — Plantillas PDF para Personal / Capacitación
-- ═══════════════════════════════════════════════════════════

-- Tabla global de plantillas para los tres tipos de documento de personal
CREATE TABLE IF NOT EXISTS plantillas_personal (
  tipo          ENUM('diploma','certificado','dc3') NOT NULL,
  plantilla_pdf VARCHAR(500)  NULL,
  pdf_campos    JSON          NULL,
  actualizado   TIMESTAMP     DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (tipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Insertar las 3 filas base (idempotente)
INSERT IGNORE INTO plantillas_personal (tipo) VALUES ('diploma'), ('certificado'), ('dc3');

-- Correo del participante (para envío de documentos)
ALTER TABLE participantes_cursos
  ADD COLUMN IF NOT EXISTS correo VARCHAR(200) NULL AFTER telefono;

-- Extender ENUM para incluir CERTIFICADO
ALTER TABLE participantes_documentos
  MODIFY COLUMN tipo_doc ENUM('DC3','DIPLOMA','CERTIFICADO') NOT NULL;
