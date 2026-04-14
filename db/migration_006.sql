-- ══════════════════════════════════════════════════════════════
-- AVBA Certificaciones — Migración 006
-- Módulo Accesorios de Izaje
-- ══════════════════════════════════════════════════════════════

-- Catálogo de tipos de accesorio (gestionado por Calidad)
CREATE TABLE IF NOT EXISTS accesorios_tipos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(200) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  fecha_creacion  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- Sesión de inspección de accesorios (un cliente + lugar + fecha)
CREATE TABLE IF NOT EXISTS accesorios_sesiones (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  cliente         VARCHAR(300) NOT NULL,
  fecha           DATE         NOT NULL,
  coordenadas     VARCHAR(100) NULL,
  direccion       TEXT         NULL,
  usuario         VARCHAR(100) NULL,
  fecha_registro  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- Cada accesorio inspeccionado dentro de una sesión
CREATE TABLE IF NOT EXISTS accesorios_izaje (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  sesion_id       INT          NOT NULL,
  id_accesorio    VARCHAR(100) NULL,
  tipo_id         INT          NULL,
  marca           VARCHAR(200) NULL,
  modelo          VARCHAR(200) NULL,
  serie           VARCHAR(200) NULL,
  capacidad       VARCHAR(200) NULL,
  medidas         VARCHAR(200) NULL,
  estado          ENUM('CUMPLE','NO CUMPLE') NOT NULL DEFAULT 'CUMPLE',
  orden           TINYINT      NOT NULL DEFAULT 1,
  fecha_registro  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (sesion_id) REFERENCES accesorios_sesiones(id) ON DELETE CASCADE,
  FOREIGN KEY (tipo_id)   REFERENCES accesorios_tipos(id)    ON DELETE SET NULL
);

-- Hasta 6 fotos por accesorio
CREATE TABLE IF NOT EXISTS accesorios_fotos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  accesorio_id    INT          NOT NULL,
  url             VARCHAR(500) NOT NULL,
  orden           TINYINT      NOT NULL DEFAULT 1,
  FOREIGN KEY (accesorio_id) REFERENCES accesorios_izaje(id) ON DELETE CASCADE
);
