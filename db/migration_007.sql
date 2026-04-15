-- ══════════════════════════════════════════════════════════════
--  migration_007.sql — Plantillas PDF con coordenadas de campos
-- ══════════════════════════════════════════════════════════════

ALTER TABLE maquinaria_tipos
  ADD COLUMN IF NOT EXISTS plantilla_cert_pdf VARCHAR(500) NULL
      COMMENT 'Archivo PDF base para certificado'
      AFTER plantilla_cert,
  ADD COLUMN IF NOT EXISTS plantilla_dict_pdf VARCHAR(500) NULL
      COMMENT 'Archivo PDF base para dictamen'
      AFTER plantilla_dict,
  ADD COLUMN IF NOT EXISTS cert_pdf_campos    JSON          NULL
      COMMENT 'Campos y coordenadas para el certificado PDF'
      AFTER plantilla_cert_pdf,
  ADD COLUMN IF NOT EXISTS dict_pdf_campos    JSON          NULL
      COMMENT 'Campos y coordenadas para el dictamen PDF'
      AFTER plantilla_dict_pdf;

-- Formato de cert_pdf_campos / dict_pdf_campos (array JSON):
-- [
--   {
--     "campo":  "folio",        -- nombre del campo (ver lista abajo)
--     "pagina": 1,              -- número de página (1-based)
--     "x":      120.5,          -- mm desde borde izquierdo
--     "y":      45.2,           -- mm desde borde superior
--     "fuente": "Helvetica",    -- Helvetica | Times | Courier
--     "tamano": 11,             -- tamaño en puntos
--     "negrita": true,          -- true | false
--     "color":  "000000",       -- hex sin #
--     "ancho":  0               -- ancho de celda en mm (0 = sin límite)
--   }, ...
-- ]
--
-- Campos disponibles:
--   folio, cliente, domicilio, maquinaria, marca, modelo,
--   serie, id_equipo, capacidad, fecha, vigencia, anio,
--   qr_imagen   ← imagen QR (requiere "ancho" y "alto" en mm)
