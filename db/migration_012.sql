-- migration_012: Tipo de norma (acreditada vs referencia)
ALTER TABLE maquinaria_normas
  ADD COLUMN IF NOT EXISTS tipo ENUM('acreditada','referencia') NOT NULL DEFAULT 'acreditada'
      COMMENT 'acreditada = norma de acreditación principal; referencia = norma de referencia técnica'
      AFTER norma;
