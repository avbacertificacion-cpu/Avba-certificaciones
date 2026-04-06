-- ═══════════════════════════════════════════════════════════
--  AVBA CERTIFICACIONES — Esquema MySQL
--  Migración desde Google Sheets a MySQL
--  Versión: 1.0
-- ═══════════════════════════════════════════════════════════

SET NAMES utf8mb4;
SET time_zone = '-06:00';

-- ── TABLA: usuarios ──────────────────────────────────────
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`             INT AUTO_INCREMENT PRIMARY KEY,
  `usuario`        VARCHAR(50)  NOT NULL UNIQUE,
  `password_hash`  VARCHAR(255) NOT NULL,
  `rol`            ENUM('ADMIN','INSPECTOR','CALIDAD','CERTIFICACIONES','CLIENTE') NOT NULL,
  `nombre`         VARCHAR(100) NOT NULL,
  `id_cliente`     VARCHAR(10)  DEFAULT NULL,
  `activo`         TINYINT(1)   DEFAULT 1,
  `fecha_alta`     DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `ultimo_acceso`  DATETIME     DEFAULT NULL,
  `session_token`  VARCHAR(64)  DEFAULT NULL,
  `token_expires`  DATETIME     DEFAULT NULL,
  INDEX `idx_session_token` (`session_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: clientes ───────────────────────────────────────
CREATE TABLE IF NOT EXISTS `clientes` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `nombre_cliente`  VARCHAR(150) NOT NULL,
  `primera_parte`   VARCHAR(10)  NOT NULL UNIQUE,
  INDEX `idx_primera_parte` (`primera_parte`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: maquinaria_tipos ───────────────────────────────
CREATE TABLE IF NOT EXISTS `maquinaria_tipos` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `nombre`      VARCHAR(150) NOT NULL UNIQUE,
  `clave_drive` VARCHAR(150) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: checklist_items ────────────────────────────────
CREATE TABLE IF NOT EXISTS `checklist_items` (
  `id`                  INT AUTO_INCREMENT PRIMARY KEY,
  `maquinaria_tipo_id`  INT          NOT NULL,
  `seccion`             VARCHAR(5)   NOT NULL,
  `tag`                 VARCHAR(20)  NOT NULL,
  `descripcion`         VARCHAR(250) NOT NULL,
  `orden`               INT          DEFAULT 0,
  FOREIGN KEY (`maquinaria_tipo_id`) REFERENCES `maquinaria_tipos`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_tipo_tag` (`maquinaria_tipo_id`, `tag`),
  INDEX `idx_seccion` (`maquinaria_tipo_id`, `seccion`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: qr_codigos ─────────────────────────────────────
CREATE TABLE IF NOT EXISTS `qr_codigos` (
  `id`            INT AUTO_INCREMENT PRIMARY KEY,
  `identificador` VARCHAR(20) NOT NULL UNIQUE,
  `usado`         TINYINT(1)  DEFAULT 0,
  `equipo_id`     INT         DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: equipos (hoja EQUIPO) ─────────────────────────
CREATE TABLE IF NOT EXISTS `equipos` (
  `id`                INT AUTO_INCREMENT PRIMARY KEY,
  `marca_temporal`    DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `cliente`           VARCHAR(150) NOT NULL,
  `coords_inspeccion` VARCHAR(60)  DEFAULT NULL,
  `maquinaria`        VARCHAR(150) DEFAULT NULL,
  `marca`             VARCHAR(100) DEFAULT NULL,
  `modelo`            VARCHAR(100) DEFAULT NULL,
  `serie`             VARCHAR(100) DEFAULT NULL,
  `id_equipo`         VARCHAR(50)  DEFAULT NULL,
  `fecha_inspeccion`  DATE         DEFAULT NULL,
  `correo`            VARCHAR(150) DEFAULT NULL,
  `control`           VARCHAR(20)  DEFAULT NULL UNIQUE,
  `fecha_enviado`     DATETIME     DEFAULT NULL,
  `evidencia_url`     TEXT         DEFAULT NULL,
  `direccion`         TEXT         DEFAULT NULL,
  `capacidad`         VARCHAR(80)  DEFAULT NULL,
  `disponibilidad`    VARCHAR(50)  DEFAULT NULL,
  `estado`            VARCHAR(30)  DEFAULT 'PENDIENTE',
  `motivo`            TEXT         DEFAULT NULL,
  `qr_codigo`         VARCHAR(20)  DEFAULT NULL,
  `certificado_url`   TEXT         DEFAULT NULL,
  `dictamen_url`      TEXT         DEFAULT NULL,
  `envio_direccion`   TEXT         DEFAULT NULL,
  `coordenadas_envio` VARCHAR(60)  DEFAULT NULL,
  `directo`           VARCHAR(10)  DEFAULT NULL,
  INDEX `idx_control`   (`control`),
  INDEX `idx_estado`    (`estado`),
  INDEX `idx_cliente`   (`cliente`(50)),
  INDEX `idx_qr_codigo` (`qr_codigo`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: inspeccion_checklist ───────────────────────────
CREATE TABLE IF NOT EXISTS `inspeccion_checklist` (
  `id`         INT AUTO_INCREMENT PRIMARY KEY,
  `equipo_id`  INT        NOT NULL,
  `tag`        VARCHAR(20) NOT NULL,
  `valor`      ENUM('C','NC','NA') DEFAULT NULL,
  FOREIGN KEY (`equipo_id`) REFERENCES `equipos`(`id`) ON DELETE CASCADE,
  UNIQUE KEY `uk_equipo_tag` (`equipo_id`, `tag`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: historico_envios ───────────────────────────────
CREATE TABLE IF NOT EXISTS `historico_envios` (
  `id`          INT AUTO_INCREMENT PRIMARY KEY,
  `fecha_envio` DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `cliente`     VARCHAR(150) DEFAULT NULL,
  `control`     VARCHAR(20)  DEFAULT NULL,
  `correo`      VARCHAR(150) DEFAULT NULL,
  `archivo`     VARCHAR(255) DEFAULT NULL,
  `usuario`     VARCHAR(50)  DEFAULT NULL,
  `equipo_id`   INT          DEFAULT NULL,
  FOREIGN KEY (`equipo_id`) REFERENCES `equipos`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ── TABLA: historial_general ──────────────────────────────
CREATE TABLE IF NOT EXISTS `historial_general` (
  `id`              INT AUTO_INCREMENT PRIMARY KEY,
  `fecha_hora`      DATETIME     DEFAULT CURRENT_TIMESTAMP,
  `usuario`         VARCHAR(50)  DEFAULT NULL,
  `equipo_id`       INT          DEFAULT NULL,
  `campo`           VARCHAR(50)  DEFAULT NULL,
  `valor_anterior`  TEXT         DEFAULT NULL,
  `valor_nuevo`     TEXT         DEFAULT NULL,
  `accion`          VARCHAR(50)  DEFAULT NULL,
  FOREIGN KEY (`equipo_id`) REFERENCES `equipos`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ═══════════════════════════════════════════════════════════
--  DATOS INICIALES (SEED)
-- ═══════════════════════════════════════════════════════════

-- Clientes
INSERT IGNORE INTO `clientes` (`nombre_cliente`, `primera_parte`) VALUES
('SEFIN',  '45155'),
('HOLA',   '45156');

-- Tipos de Maquinaria
INSERT IGNORE INTO `maquinaria_tipos` (`nombre`, `clave_drive`) VALUES
('GRUA HIDRAULICA MONTADA SOBRE CAMION',   '1U74GJvRNm1EYXMfuI372mCg41f19VzL45Q_40bkAp5o'),
('GRUA HIDRAULICA SOBRE NEUMATICOS',       '1U74GJvRNm1EYXMfuI372mCg41f19VzL45Q_40bkAp5o');

-- Códigos QR
INSERT IGNORE INTO `qr_codigos` (`identificador`) VALUES
('1234567890'),('4352617894'),('4352617895'),('4352617896'),('4352617897');

-- ── Checklist items (igual para ambos tipos de maquinaria) ─
-- Se insertan usando subconsulta para obtener IDs dinámicamente

INSERT IGNORE INTO `checklist_items` (`maquinaria_tipo_id`, `seccion`, `tag`, `descripcion`, `orden`)
SELECT m.id, 'E1', 'E1_1',  'Pintura', 1                                       FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E1', 'E1_2',  'Estado general de soldadura', 2         FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E1', 'E1_3',  'Elementos corroídos', 3                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E1', 'E1_4',  'Grietas', 4                             FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E1', 'E1_5',  'Elementos deformes', 5                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E1', 'E1_6',  'Pernos o remaches', 6                   FROM maquinaria_tipos m
-- E2: Cable
UNION ALL SELECT m.id, 'E2', 'E2_1',  'Retorcimiento', 7                       FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_2',  'Aplastamiento', 8                       FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_3',  'Formación de cocas', 9                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_4',  'Trefilado reabierto', 10                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_5',  'Encasquillado adecuado', 11             FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_6',  'Corrosión generalizada', 12             FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_7',  'Alambres rotos o cortados', 13          FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_8',  'Tipo de alma adecuada', 14              FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_9',  'Lubricación del cable', 15              FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_10', 'Longitud adecuada', 16                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_11', 'Enrollamiento adecuado en el tambor', 17 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E2', 'E2_12', 'Cable adecuado', 18                     FROM maquinaria_tipos m
-- E3: Tambor
UNION ALL SELECT m.id, 'E3', 'E3_1',  'Sujeción adecuada a la estructura', 19  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_2',  'Tamaño de brida del tambor', 20         FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_3',  'Indicador de rotación del tambor', 21   FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_4',  'Tambor agrietado o desgastado', 22      FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_5',  'Nivel de aceite y fugas', 23            FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_6',  'Pernos sueltos', 24                     FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_7',  'Ruidos extraños o vibraciones', 25      FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_8',  'Estado de las guardas', 26              FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_9',  'Mangueras y conexiones', 27             FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E3', 'E3_10', 'Presión adecuada de mangueras', 28      FROM maquinaria_tipos m
-- E4: Aparejos / Gancho
UNION ALL SELECT m.id, 'E4', 'E4_1',  'Porcentaje de desgaste dentro del límite', 29 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_2',  'Cerrojo del gancho', 30                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_3',  'Conjunto de ganchos etiquetados', 31    FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_4',  'Socket en posición correcta', 32        FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_5',  'Abrasión, Corrosión en la pasteca', 33  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_6',  'Diámetro de poleas', 34                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_7',  'Aparejos con la capacidad adecuada', 35 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_8',  'Rodamientos de poleas engrasados', 36   FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_9',  'Polea agrietada o corroída', 37         FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_10', 'Mordazas del cable (Perno en U)', 38    FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_11', 'Perno en U, colocado correctamente', 39 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_12', 'Ranuras de poleas libres de defectos superficiales', 40 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_13', 'Gancho reabierto o torcido', 41         FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E4', 'E4_14', 'Juego axial del gancho', 42             FROM maquinaria_tipos m
-- E5: Pluma
UNION ALL SELECT m.id, 'E5', 'E5_1',  'Sección base', 43                       FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_2',  'Secciones de pluma', 44                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_3',  'Estructura de pluma', 45                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_4',  'Diámetro del pistón de levante', 46     FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_5',  'Fugas en mangueras', 47                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_6',  'Reparación en estructura de pluma', 48  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_7',  'Fuga en pistón interno', 49             FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_8',  'Cabezal', 50                            FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_9',  'Deslizadores de pluma (cojinetes)', 51  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_10', 'Soporte de fly-jib', 52                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_11', 'Estructura de fly-jib', 53              FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_12', 'Soporte para la pluma, para poder transitar', 54 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E5', 'E5_13', 'Poleas del cabezal', 55                 FROM maquinaria_tipos m
-- E6: Mecanismo de giro
UNION ALL SELECT m.id, 'E6', 'E6_1',  'Estructura', 56                         FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_2',  'Sistema hidráulico', 57                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_3',  'Banco de válvulas', 58                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_4',  'Fugas en mangueras y válvulas', 59      FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_5',  'Estado de mangueras', 60                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_6',  'Lubricación', 61                        FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_7',  'Dentado', 62                            FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_8',  'Juego axial', 63                        FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E6', 'E6_9',  'Tornillería', 64                        FROM maquinaria_tipos m
-- E7: Cilindros hidráulicos
UNION ALL SELECT m.id, 'E7', 'E7_1',  'Fugas en las bielas', 65                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_2',  'Estado de mangueras y conexiones', 66   FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_3',  'Banco de válvulas', 67                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_4',  'Válvulas de retención', 68              FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_5',  'Presión en mangueras', 69               FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_6',  'Estado del perno y chaveta', 70         FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_7',  'Casco abollado', 71                     FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_8',  'Cromo del vástago', 72                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E7', 'E7_9',  'Fugas en las uniones soldadas', 73      FROM maquinaria_tipos m
-- E8: Limitadores y sensores
UNION ALL SELECT m.id, 'E8', 'E8_1',  'Limitadores de levantamiento LMC', 74   FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_2',  'Limitadores de velocidad', 75           FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_3',  'Indicador de ángulo', 76                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_4',  'Tabla de capacidades', 77               FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_5',  'Limitador de giro', 78                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_6',  'Tensiómetro', 79                        FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_7',  'LMI', 80                                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_8',  'Anemómetro', 81                         FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E8', 'E8_9',  'Inclinómetro', 82                       FROM maquinaria_tipos m
-- E9: Seguridad exterior
UNION ALL SELECT m.id, 'E9', 'E9_1',  'Luces', 83                              FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E9', 'E9_10', 'Torretas', 84                           FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E9', 'E9_11', 'Extintor 20lbs (mínimo)', 85            FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E9', 'E9_12', 'Calcomanías informativas', 86           FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E9', 'E9_13', 'Alarma de reversa', 87                  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E9', 'E9_14', 'Alarma de movimiento', 88               FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E9', 'E9_15', 'Reflejantes o pintura de precaución', 89 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E9', 'E9_16', 'Bocinas', 90                            FROM maquinaria_tipos m
-- E10: Cabina
UNION ALL SELECT m.id, 'E10', 'E10_1', 'Limpieza en cabina', 91                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_2', 'Parabrisas y cristales', 92            FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_3', 'Extintor 20lbs (mínimo)', 93           FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_4', 'Mandos de control', 94                 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_5', 'Estado de indicadores en general', 95  FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_6', 'Calcomanías de mandos de operación', 96 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_7', 'Estado de neumáticos', 97              FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_8', 'Birlos de seguridad', 98               FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E10', 'E10_9', 'Estado de la suspensión', 99           FROM maquinaria_tipos m
-- E11: Estabilizadores
UNION ALL SELECT m.id, 'E11', 'E11_1',  'Fuga en pistones', 100                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_2',  'Estado de los pontones', 101          FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_3',  'Almohadillas del tamaño correcto', 102 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_4',  'Elementos deformes agrietados o corroídos', 103 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_5',  'Diámetro correcto del pontón', 104    FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_6',  'Las extensiones alcanzan el tope', 105 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_7',  'Soldadura en estructura de estabilizadores', 106 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_8',  'Estado de mangueras y conexiones', 107 FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_9',  'Cajón de soporte', 108                FROM maquinaria_tipos m
UNION ALL SELECT m.id, 'E11', 'E11_10', 'Extensión hidráulica', 109            FROM maquinaria_tipos m
;

-- ── Usuario Admin por defecto ─────────────────────────────
-- Contraseña: avba2026  (bcrypt — se genera en la instalación)
-- Ejecutar en PHP: echo password_hash('avba2026', PASSWORD_BCRYPT);
-- INSERT INTO usuarios (usuario, password_hash, rol, nombre) VALUES
-- ('admin', '$2y$10$HASH_AQUI', 'ADMIN', 'Administrador AVBA');
