-- migration_013: Inspector STPS registration, company logo and workers' rep
ALTER TABLE usuarios
  ADD COLUMN IF NOT EXISTS registro_stps VARCHAR(100) NULL
      COMMENT 'Número de registro STPS del instructor';

ALTER TABLE clientes
  ADD COLUMN IF NOT EXISTS logo LONGTEXT NULL
      COMMENT 'Logo de la empresa (base64 data URI)',
  ADD COLUMN IF NOT EXISTS representante_trabajadores VARCHAR(300) NULL
      COMMENT 'Nombre del representante de los trabajadores (para DC3)';
