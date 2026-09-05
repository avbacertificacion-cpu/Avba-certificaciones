-- ─────────────────────────────────────────────────────────────
--  AVBA · Muro de operadores ("Orgullo AVBA")
--  Publicaciones enviadas por visitantes, con moderación previa.
-- ─────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS muro_publicaciones (
    id              INT UNSIGNED NOT NULL AUTO_INCREMENT,

    -- Datos que captura el formulario
    nombre          VARCHAR(80)   NOT NULL,
    puesto          VARCHAR(80)   NULL,
    empresa         VARCHAR(120)  NULL,
    comentario      TEXT          NOT NULL,

    -- Fotografía o video compartido
    tipo            ENUM('imagen','video') NOT NULL DEFAULT 'imagen',

    -- Archivo ya normalizado por el servidor (nombre generado, nunca el original)
    archivo         VARCHAR(120)  NULL,
    -- Miniatura del video: se muestra en la galería sin descargar el video entero
    poster          VARCHAR(120)  NULL,
    ancho           SMALLINT UNSIGNED NULL,
    alto            SMALLINT UNSIGNED NULL,
    duracion        SMALLINT UNSIGNED NULL,

    -- Moderación
    estado          ENUM('pendiente','aprobado','rechazado') NOT NULL DEFAULT 'pendiente',
    motivo_rechazo  VARCHAR(255)  NULL,
    moderado_por    VARCHAR(80)   NULL,
    moderado_en     DATETIME      NULL,

    -- Consentimiento (se guarda como evidencia de que fue otorgado)
    consentimiento  TINYINT(1)    NOT NULL DEFAULT 0,

    -- Rastro técnico, para bloquear abuso
    ip_hash         CHAR(64)      NULL,
    user_agent      VARCHAR(255)  NULL,
    creado_en       DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,

    PRIMARY KEY (id),
    KEY idx_estado_fecha (estado, creado_en),
    KEY idx_ip_fecha (ip_hash, creado_en)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
