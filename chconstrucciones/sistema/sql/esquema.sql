-- CH Arquitectura y Construcción — Sistema de gestión documental
-- Esquema de la base de datos (MySQL 5.7+ / MariaDB 10.3+).
-- Lo ejecuta instalar.php; no hace falta correrlo a mano.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS usuarios (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  nombre        VARCHAR(120) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  rol           VARCHAR(20)  NOT NULL,
  puesto        VARCHAR(120) NOT NULL DEFAULT '',
  activo        TINYINT(1)   NOT NULL DEFAULT 1,
  ultimo_acceso DATETIME NULL,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_usuarios_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS obras (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave        VARCHAR(40)  NOT NULL,
  nombre       VARCHAR(200) NOT NULL,
  cliente      VARCHAR(200) NOT NULL DEFAULT '',
  ubicacion    VARCHAR(255) NOT NULL DEFAULT '',
  tipo         VARCHAR(60)  NOT NULL DEFAULT '',
  estado       VARCHAR(30)  NOT NULL DEFAULT 'Planeación',
  residente_id INT UNSIGNED NULL,
  inicio       DATE NULL,
  fin          DATE NULL,
  monto        DECIMAL(15,2) NOT NULL DEFAULT 0,
  avance       TINYINT UNSIGNED NOT NULL DEFAULT 0,
  descripcion  TEXT NULL,
  creado       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_obras_clave (clave),
  CONSTRAINT fk_obras_residente FOREIGN KEY (residente_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Qué personas (de los roles que sólo ven sus obras) trabajan en cada obra.
CREATE TABLE IF NOT EXISTS usuario_obra (
  usuario_id INT UNSIGNED NOT NULL,
  obra_id    INT UNSIGNED NOT NULL,
  PRIMARY KEY (usuario_id, obra_id),
  CONSTRAINT fk_uo_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_uo_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Cada archivo guardado en el almacenamiento (local o en la nube).
-- Los documentos, presupuestos y fotos apuntan aquí.
CREATE TABLE IF NOT EXISTS archivos (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave_almacen   VARCHAR(600) NOT NULL,
  nombre_original VARCHAR(255) NOT NULL,
  tipo_mime       VARCHAR(120) NOT NULL DEFAULT 'application/octet-stream',
  tamano          BIGINT UNSIGNED NOT NULL DEFAULT 0,
  estado          VARCHAR(15) NOT NULL DEFAULT 'pendiente', -- pendiente | listo
  destino         VARCHAR(10) NOT NULL DEFAULT 'trabajo',   -- trabajo | archivo
  vinculado       TINYINT(1) NOT NULL DEFAULT 0,
  subido_por      INT UNSIGNED NULL,
  creado          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_archivos_estado (estado, creado),
  CONSTRAINT fk_archivos_usuario FOREIGN KEY (subido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS documentos (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  obra_id     INT UNSIGNED NOT NULL,
  carpeta     VARCHAR(30)  NOT NULL,
  nombre      VARCHAR(255) NOT NULL,
  ext         VARCHAR(10)  NOT NULL DEFAULT '',
  estado      VARCHAR(20)  NOT NULL DEFAULT 'En revisión',
  vence       DATE NULL,
  etiquetas   VARCHAR(500) NOT NULL DEFAULT '',
  descripcion TEXT NULL,
  creado_por  INT UNSIGNED NULL,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  actualizado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_documentos_obra (obra_id, carpeta),
  KEY ix_documentos_vence (vence),
  CONSTRAINT fk_doc_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE CASCADE,
  CONSTRAINT fk_doc_usuario FOREIGN KEY (creado_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Control de versiones: cada actualización agrega una fila; nunca se pisa la anterior.
CREATE TABLE IF NOT EXISTS documento_versiones (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  documento_id INT UNSIGNED NOT NULL,
  version      INT UNSIGNED NOT NULL,
  archivo_id   INT UNSIGNED NOT NULL,
  nota         VARCHAR(500) NOT NULL DEFAULT '',
  usuario_id   INT UNSIGNED NULL,
  creado       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_doc_version (documento_id, version),
  CONSTRAINT fk_dv_doc FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_dv_archivo FOREIGN KEY (archivo_id) REFERENCES archivos(id),
  CONSTRAINT fk_dv_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Documentos compartidos con personas que no están en la obra (o para avisarles).
CREATE TABLE IF NOT EXISTS documento_compartidos (
  documento_id   INT UNSIGNED NOT NULL,
  usuario_id     INT UNSIGNED NOT NULL,
  permiso        VARCHAR(10) NOT NULL DEFAULT 'ver', -- ver | editar
  compartido_por INT UNSIGNED NULL,
  creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (documento_id, usuario_id),
  CONSTRAINT fk_dc_doc FOREIGN KEY (documento_id) REFERENCES documentos(id) ON DELETE CASCADE,
  CONSTRAINT fk_dc_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE CASCADE,
  CONSTRAINT fk_dc_por FOREIGN KEY (compartido_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS presupuestos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  obra_id    INT UNSIGNED NOT NULL,
  folio      VARCHAR(40)  NOT NULL,
  concepto   VARCHAR(255) NOT NULL,
  monto      DECIMAL(15,2) NOT NULL DEFAULT 0,
  estado     VARCHAR(30)  NOT NULL DEFAULT 'Borrador',
  fecha      DATE NULL,
  archivo_id INT UNSIGNED NULL,
  notas      TEXT NULL,
  usuario_id INT UNSIGNED NULL,
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_pres_obra (obra_id),
  CONSTRAINT fk_pres_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE CASCADE,
  CONSTRAINT fk_pres_archivo FOREIGN KEY (archivo_id) REFERENCES archivos(id) ON DELETE SET NULL,
  CONSTRAINT fk_pres_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reportes (
  id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  obra_id     INT UNSIGNED NOT NULL,
  titulo      VARCHAR(200) NOT NULL,
  fecha       DATE NOT NULL,
  periodo     VARCHAR(80) NOT NULL DEFAULT '',
  descripcion TEXT NULL,
  usuario_id  INT UNSIGNED NULL,
  creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_rep_obra (obra_id),
  CONSTRAINT fk_rep_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE CASCADE,
  CONSTRAINT fk_rep_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reporte_fotos (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  reporte_id INT UNSIGNED NOT NULL,
  archivo_id INT UNSIGNED NOT NULL,
  pie        VARCHAR(500) NOT NULL DEFAULT '',
  orden      SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  CONSTRAINT fk_rf_rep FOREIGN KEY (reporte_id) REFERENCES reportes(id) ON DELETE CASCADE,
  CONSTRAINT fk_rf_archivo FOREIGN KEY (archivo_id) REFERENCES archivos(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bitácora del sistema: quién hizo qué y cuándo.
CREATE TABLE IF NOT EXISTS actividad (
  id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  usuario_id INT UNSIGNED NULL,
  accion     VARCHAR(200) NOT NULL,
  obra_id    INT UNSIGNED NULL,
  detalle    VARCHAR(500) NOT NULL DEFAULT '',
  creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_act_fecha (creado),
  KEY ix_act_obra (obra_id),
  CONSTRAINT fk_act_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_act_obra FOREIGN KEY (obra_id) REFERENCES obras(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Catálogo del archivo muerto. Cada fila es un archivo guardado en el
-- almacenamiento de archivo (inmediato o en frío). La ruta sigue la forma
-- archivo-muerto/AÑO/CLAVE - Nombre de la obra/Carpeta/archivo.ext
CREATE TABLE IF NOT EXISTS am_archivos (
  id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  clave_almacen VARCHAR(900) NOT NULL,
  clave_hash    CHAR(40) NOT NULL,
  anio          SMALLINT UNSIGNED NULL,
  obra_clave    VARCHAR(80)  NOT NULL DEFAULT '',
  obra_nombre   VARCHAR(255) NOT NULL DEFAULT '',
  carpeta       VARCHAR(120) NOT NULL DEFAULT '',
  nombre        VARCHAR(255) NOT NULL,
  ext           VARCHAR(10)  NOT NULL DEFAULT '',
  tamano        BIGINT UNSIGNED NOT NULL DEFAULT 0,
  fecha         DATE NULL,
  creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_am_hash (clave_hash),
  KEY ix_am_obra (anio, obra_clave),
  KEY ix_am_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Requisiciones para recuperar archivos del archivo muerto en frío.
CREATE TABLE IF NOT EXISTS am_requisiciones (
  id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  folio           VARCHAR(20) NOT NULL,
  usuario_id      INT UNSIGNED NULL,
  prioridad       VARCHAR(12) NOT NULL DEFAULT 'estandar', -- estandar | economica
  motivo          VARCHAR(500) NOT NULL,
  estado          VARCHAR(15) NOT NULL DEFAULT 'pendiente', -- pendiente | rechazada | restaurando | disponible | expirada
  nota            VARCHAR(500) NOT NULL DEFAULT '',
  solicitada      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  aprobada_por    INT UNSIGNED NULL,
  aprobada        DATETIME NULL,
  listo_estimado  DATETIME NULL,
  disponible      DATETIME NULL,
  expira          DATETIME NULL,
  ultima_revision DATETIME NULL,
  aviso_enviado   TINYINT(1) NOT NULL DEFAULT 0,
  UNIQUE KEY uq_req_folio (folio),
  KEY ix_req_estado (estado),
  CONSTRAINT fk_req_usuario FOREIGN KEY (usuario_id) REFERENCES usuarios(id) ON DELETE SET NULL,
  CONSTRAINT fk_req_aprobo FOREIGN KEY (aprobada_por) REFERENCES usuarios(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS am_requisicion_archivos (
  requisicion_id INT UNSIGNED NOT NULL,
  am_archivo_id  INT UNSIGNED NOT NULL,
  restaurado     TINYINT(1) NOT NULL DEFAULT 0,
  PRIMARY KEY (requisicion_id, am_archivo_id),
  CONSTRAINT fk_ra_req FOREIGN KEY (requisicion_id) REFERENCES am_requisiciones(id) ON DELETE CASCADE,
  CONSTRAINT fk_ra_arch FOREIGN KEY (am_archivo_id) REFERENCES am_archivos(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Intentos fallidos de inicio de sesión (para frenar ataques de fuerza bruta).
CREATE TABLE IF NOT EXISTS intentos_login (
  id     INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email  VARCHAR(190) NOT NULL,
  ip     VARCHAR(45)  NOT NULL,
  creado DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY ix_int_email (email, creado),
  KEY ix_int_ip (ip, creado)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
