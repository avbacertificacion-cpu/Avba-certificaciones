-- ══════════════════════════════════════════════════════════════
-- AVBA Certificaciones — Migración 004
-- Módulo Personal/Cursos + campo inspector en equipos
-- ══════════════════════════════════════════════════════════════

-- 1. Inspector en inspecciones
ALTER TABLE equipos
  ADD COLUMN inspector VARCHAR(100) NULL AFTER coordenadas_envio;

-- 2. Cursos (gestionados por Calidad)
CREATE TABLE IF NOT EXISTS cursos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(200) NOT NULL,
  duracion_horas  DECIMAL(6,1) NOT NULL DEFAULT 8.0,
  area_tematica   VARCHAR(200) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  creado_por      VARCHAR(100) NULL,
  fecha_creacion  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- 3. Ocupaciones específicas (catálogo gestionado por Calidad)
CREATE TABLE IF NOT EXISTS ocupaciones_especificas (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  nombre          VARCHAR(200) NOT NULL,
  activo          TINYINT(1)   NOT NULL DEFAULT 1,
  fecha_creacion  TIMESTAMP    DEFAULT CURRENT_TIMESTAMP
);

-- 4. Participantes en cursos
CREATE TABLE IF NOT EXISTS participantes_cursos (
  id                    INT AUTO_INCREMENT PRIMARY KEY,
  -- Datos personales
  nombre_completo       VARCHAR(300) NOT NULL,
  curp                  VARCHAR(18)  NOT NULL,
  puesto                VARCHAR(200) NULL,
  ocupacion_id          INT          NULL,
  capacidad             VARCHAR(200) NULL,
  capacidad_na          TINYINT(1)   NOT NULL DEFAULT 0,
  telefono              VARCHAR(20)  NULL,
  -- Datos de empresa (opcionales)
  empresa_nombre        VARCHAR(300) NULL,
  empresa_rfc           VARCHAR(13)  NULL,
  empresa_direccion     TEXT         NULL,
  empresa_representante VARCHAR(300) NULL,
  -- Datos del curso
  curso_id              INT          NOT NULL,
  fecha_curso           DATE         NOT NULL,
  -- Fotografías
  foto_documentacion_url VARCHAR(500) NULL,
  foto_persona_url       VARCHAR(500) NULL,
  -- Auditoría
  estado            ENUM('REGISTRADO','DOCUMENTO_GENERADO') NOT NULL DEFAULT 'REGISTRADO',
  usuario_registro  VARCHAR(100) NULL,
  fecha_registro    TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (curso_id)     REFERENCES cursos(id),
  FOREIGN KEY (ocupacion_id) REFERENCES ocupaciones_especificas(id)
);

-- 5. Documentos generados (DC-3, diplomas, certificados)
CREATE TABLE IF NOT EXISTS participantes_documentos (
  id               INT AUTO_INCREMENT PRIMARY KEY,
  participante_id  INT  NOT NULL,
  tipo_doc         ENUM('DC3','DIPLOMA','CERTIFICADO') NOT NULL,
  url              VARCHAR(500) NOT NULL,
  generado_por     VARCHAR(100) NULL,
  fecha_generacion TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  FOREIGN KEY (participante_id) REFERENCES participantes_cursos(id) ON DELETE CASCADE
);
