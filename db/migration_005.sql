-- ══════════════════════════════════════════════════════════════
-- AVBA Certificaciones — Migración 005
-- Normas aplicables por tipo de equipo (máx. 6 por tipo)
-- ══════════════════════════════════════════════════════════════

CREATE TABLE IF NOT EXISTS maquinaria_normas (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  maquinaria_tipo_id INT          NOT NULL,
  norma              VARCHAR(300) NOT NULL,
  orden              TINYINT      NOT NULL DEFAULT 1,
  FOREIGN KEY (maquinaria_tipo_id)
    REFERENCES maquinaria_tipos(id)
    ON DELETE CASCADE
);
