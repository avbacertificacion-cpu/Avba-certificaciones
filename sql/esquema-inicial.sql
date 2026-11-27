-- ════════════════════════════════════════════════════════════════════
--  AVBA Control de Accesos — esquema inicial de la base PROPIA
--  (p. ej. u218429682_controlaccesos; NO es la base del sistema principal)
--
--  Las tablas las crea el código solo al primer uso (Auth::asegurarTablas,
--  ControlAccesos::asegurarTabla / asegurarPuntos). Este archivo sirve para
--  importarlas de una vez en phpMyAdmin y como referencia del modelo.
--
--  Lo que hace falta para volver real la maqueta (empresas, personas,
--  documentos, autorizaciones, incidencias, vetos, vales, reglas de jornada)
--  se diseña en la fase 2 de docs/PLAN.md.
-- ════════════════════════════════════════════════════════════════════

SET NAMES utf8mb4;

-- ── Cuentas ─────────────────────────────────────────────────────────
-- Perfiles (rol), los tres de la maqueta («Quién ve qué»):
--   ADMIN    Administración AVBA (todo, incluidas las cuentas)
--   PLANTA   Administrador de accesos de la planta (su planta)
--   ACCESOS  Guardia de caseta (sólo registra en el acceso, en su turno: usuarios.turno)
--   CLIENTE  El cliente: sólo consulta el tablero (gráficas) y descarga Excel y PDF
CREATE TABLE IF NOT EXISTS usuarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  usuario        VARCHAR(50)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  rol            VARCHAR(30)  NOT NULL,
  nombre         VARCHAR(150) NOT NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  turno          CHAR(1)      NULL,          -- guardias: D (día) | N (noche); decide el turno de lo que registran
  modo           CHAR(1)      NULL,          -- equipo fijo: E (sólo entradas) | S (sólo salidas); sin turno: va por el reloj
  fecha_alta     DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  ultimo_acceso  DATETIME     NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── Sesiones (una por equipo; mismo modelo que el sistema principal) ─
CREATE TABLE IF NOT EXISTS sesiones_usuario (
  token       VARCHAR(100) NOT NULL PRIMARY KEY,
  usuario_id  INT          NOT NULL,
  origen      VARCHAR(20)  NOT NULL DEFAULT '',
  dispositivo VARCHAR(120) NOT NULL DEFAULT '',
  ip          VARCHAR(45)  NULL,
  rol_activo  VARCHAR(30)  NULL,
  creado      DATETIME     NOT NULL,
  expira      DATETIME     NOT NULL,
  KEY idx_usuario (usuario_id),
  KEY idx_expira (expira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Intentos fallidos de inicio de sesión (la espera crece) ─────────
CREATE TABLE IF NOT EXISTS login_intentos (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  usuario         VARCHAR(50) NOT NULL,
  intentos        TINYINT UNSIGNED NOT NULL DEFAULT 0,
  bloqueado_hasta DATETIME NULL,
  ultima_vez      TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uk_usuario (usuario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Entradas y salidas (una fila por lectura en la caseta) ──────────
-- codigo = el ID de la credencial (recuadro azul), llave de cada persona;
-- se compara sin ceros a la izquierda (00287 = 287). foto/foto_mini: la foto
-- de la credencial como evidencia (rutas dentro de uploads/accesos/).
CREATE TABLE IF NOT EXISTS accesos_registros (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uid            VARCHAR(64)  NOT NULL,
  tipo           VARCHAR(10)  NOT NULL,
  fecha          DATE         NOT NULL,
  capturado      DATETIME     NOT NULL,
  recibido       DATETIME     NOT NULL,
  lugar          VARCHAR(120) NOT NULL DEFAULT '',
  nota           VARCHAR(255) NOT NULL DEFAULT '',
  foto           VARCHAR(255) NOT NULL DEFAULT '',
  foto_mini      VARCHAR(255) NOT NULL DEFAULT '',
  usuario        VARCHAR(50)  NOT NULL DEFAULT '',
  usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
  turno_fecha    DATE         NULL,
  turno          CHAR(1)      NULL,
  nombre         VARCHAR(150) NOT NULL DEFAULT '',
  codigo         VARCHAR(32)  NOT NULL DEFAULT '',
  origen         VARCHAR(12)  NOT NULL DEFAULT 'foto',
  UNIQUE KEY uk_uid (uid),
  KEY idx_fecha (fecha),
  KEY idx_turno (turno_fecha, turno),
  KEY idx_codigo (codigo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Incidencias (botón INCIDENCIA de la caseta) ─────────────────────
-- tipo: ALCOHOLIMETRO | CONDUCTA | PERSONA | OTRO. Aparte de las entradas y
-- salidas para no mover contadores, «En planta» ni PDF. Turno del reloj.
CREATE TABLE IF NOT EXISTS accesos_incidencias (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uid            VARCHAR(64)  NOT NULL,
  tipo           VARCHAR(20)  NOT NULL,
  detalle        VARCHAR(500) NOT NULL DEFAULT '',
  fecha          DATE         NOT NULL,
  capturado      DATETIME     NOT NULL,
  recibido       DATETIME     NOT NULL,
  turno_fecha    DATE         NOT NULL,
  turno          CHAR(1)      NOT NULL,
  lugar          VARCHAR(120) NOT NULL DEFAULT '',
  codigo         VARCHAR(32)  NOT NULL DEFAULT '',
  nombre         VARCHAR(150) NOT NULL DEFAULT '',
  origen         VARCHAR(12)  NOT NULL DEFAULT 'tarjeta',
  foto           VARCHAR(255) NOT NULL DEFAULT '',
  foto_mini      VARCHAR(255) NOT NULL DEFAULT '',
  usuario        VARCHAR(50)  NOT NULL DEFAULT '',
  usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
  UNIQUE KEY uk_uid (uid),
  KEY idx_turno (turno_fecha, turno),
  KEY idx_codigo (codigo),
  KEY idx_fecha (fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Base del personal (Personal.php): ID de la credencial y nombre correcto ──
-- clave = el ID sin ceros a la izquierda (la llave); codigo = como se muestra (00287).
-- Se carga desde un Excel en personal.html. Al leer una credencial se guarda este nombre.
CREATE TABLE IF NOT EXISTS personal (
  id              INT AUTO_INCREMENT PRIMARY KEY,
  clave           VARCHAR(32)  NOT NULL,
  codigo          VARCHAR(32)  NOT NULL,
  nombre          VARCHAR(150) NOT NULL,
  empresa         VARCHAR(150) NOT NULL DEFAULT '',
  cargo           VARCHAR(150) NOT NULL DEFAULT '',
  creado          DATETIME     NOT NULL,
  actualizado     DATETIME     NOT NULL,
  actualizado_por VARCHAR(150) NOT NULL DEFAULT '',
  UNIQUE KEY uk_clave (clave),
  KEY idx_nombre (nombre)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Permisos especiales de un ID: tipos = HERRAMIENTA,CELULAR,COMPUTADORA,OTRO ──
-- Con la foto del pase o resguardo (uploads/accesos/permisos/) y/o los datos del equipo.
-- origen: caseta (lo agregó el guardia) | admin. Quitar = activo 0 (queda en el historial).
CREATE TABLE IF NOT EXISTS personal_permisos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uid            VARCHAR(64)  NOT NULL,
  clave          VARCHAR(32)  NOT NULL,
  tipos          VARCHAR(80)  NOT NULL,
  detalle        VARCHAR(500) NOT NULL DEFAULT '',
  vigencia       DATE         NULL,
  foto           VARCHAR(255) NOT NULL DEFAULT '',
  foto_mini      VARCHAR(255) NOT NULL DEFAULT '',
  origen         VARCHAR(12)  NOT NULL DEFAULT 'admin',
  creado         DATETIME     NOT NULL,
  usuario        VARCHAR(50)  NOT NULL DEFAULT '',
  usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  actualizado    DATETIME     NOT NULL,
  quitado_por    VARCHAR(150) NOT NULL DEFAULT '',
  UNIQUE KEY uk_uid (uid),
  KEY idx_clave (clave, activo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Ajustes (padron_version: sube con cada cambio en la base o en los permisos) ──
CREATE TABLE IF NOT EXISTS ca_ajustes (
  clave       VARCHAR(50)  NOT NULL PRIMARY KEY,
  valor       VARCHAR(255) NOT NULL DEFAULT '',
  actualizado DATETIME     NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Servicio médico: tomas de tensión arterial (Medico.php; reglas en api/medico.json) ──
-- resultado: A apto | N no apto (lo que marcó quien tomó); calculado: lo que dicen los límites.
-- toma: 1.ª, 2.ª… del día para esa persona. en_base: el ID estaba en la base del personal.
CREATE TABLE IF NOT EXISTS medico_tomas (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  uid            VARCHAR(64)  NOT NULL,
  fecha          DATE         NOT NULL,
  capturado      DATETIME     NOT NULL,
  recibido       DATETIME     NOT NULL,
  codigo         VARCHAR(32)  NOT NULL DEFAULT '',
  clave          VARCHAR(32)  NOT NULL DEFAULT '',
  nombre         VARCHAR(150) NOT NULL DEFAULT '',
  empresa        VARCHAR(150) NOT NULL DEFAULT '',
  cargo          VARCHAR(150) NOT NULL DEFAULT '',
  en_base        TINYINT(1)   NOT NULL DEFAULT 0,
  sistolica      SMALLINT     NOT NULL,
  diastolica     SMALLINT     NOT NULL,
  frecuencia     SMALLINT     NULL,
  categoria      VARCHAR(10)  NOT NULL DEFAULT '',
  calculado      CHAR(1)      NOT NULL DEFAULT 'A',
  resultado      CHAR(1)      NOT NULL DEFAULT 'A',
  toma           TINYINT      NOT NULL DEFAULT 1,
  observaciones  VARCHAR(500) NOT NULL DEFAULT '',
  origen         VARCHAR(12)  NOT NULL DEFAULT 'manual',
  usuario        VARCHAR(50)  NOT NULL DEFAULT '',
  usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
  UNIQUE KEY uk_uid (uid),
  KEY idx_fecha (fecha),
  KEY idx_clave (clave, fecha)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ── Días omitidos (pruebas): lo que se leyó entre «desde» y «hasta» no cuenta en el tablero,
--    los turnos, el PDF, En planta, los movimientos por ID ni las incidencias. No se borra nada:
--    volver a contar = activo 0 (queda el historial). Sólo Administración AVBA. ──
CREATE TABLE IF NOT EXISTS accesos_omitidos (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  desde          DATETIME     NOT NULL,
  hasta          DATETIME     NOT NULL,
  motivo         VARCHAR(150) NOT NULL DEFAULT '',
  usuario        VARCHAR(50)  NOT NULL DEFAULT '',
  usuario_nombre VARCHAR(150) NOT NULL DEFAULT '',
  creado         DATETIME     NOT NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  quitado_por    VARCHAR(150) NOT NULL DEFAULT '',
  quitado        DATETIME     NULL,
  KEY idx_activo (activo, desde)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- (Ya no hay tabla de puntos de control: la planta tiene un solo acceso y los guardias
--  registran a la vez desde cualquier teléfono. Una base de antes conserva la suya, sin uso.)
