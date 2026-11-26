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
CREATE TABLE IF NOT EXISTS usuarios (
  id             INT AUTO_INCREMENT PRIMARY KEY,
  usuario        VARCHAR(50)  NOT NULL UNIQUE,
  password_hash  VARCHAR(255) NOT NULL,
  rol            VARCHAR(30)  NOT NULL,
  nombre         VARCHAR(150) NOT NULL,
  activo         TINYINT(1)   NOT NULL DEFAULT 1,
  turno          CHAR(1)      NULL,          -- guardias: D (día) | N (noche); decide el turno de lo que registran
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

-- (Ya no hay tabla de puntos de control: la planta tiene un solo acceso y los guardias
--  registran a la vez desde cualquier teléfono. Una base de antes conserva la suya, sin uso.)
