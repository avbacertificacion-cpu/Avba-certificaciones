<?php
/**
 * Socios Comerciales AVBA — Conexión a la base de datos
 * Singleton PDO + autoinstalación y migración de tablas sc_*.
 *
 * Sistema totalmente aislado de la BD de gestión (certificaciones).
 */

require_once __DIR__ . '/config.php';

/** Versión actual del esquema. Subir al añadir migraciones. */
const SC_SCHEMA_VERSION = 2;

class ScDatabase {
    private static ?PDO $instance = null;

    private function __construct() {}

    public static function getConnection(): PDO {
        if (self::$instance === null) {
            $dsn = "mysql:host=" . SC_DB_HOST . ";dbname=" . SC_DB_NAME . ";charset=utf8mb4";
            $options = [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ];

            try {
                self::$instance = new PDO($dsn, SC_DB_USER, SC_DB_PASS, $options);
            } catch (PDOException $e) {
                http_response_code(500);
                echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos de Socios Comerciales.']);
                exit;
            }

            self::instalarEsquema(self::$instance);
        }
        return self::$instance;
    }

    /**
     * Crea las tablas sc_* si no existen y aplica las migraciones pendientes.
     * En cada request solo hace un SELECT diminuto contra sc_meta.
     */
    private static function instalarEsquema(PDO $pdo): void {
        $version = 0;

        try {
            $stmt = $pdo->query("SELECT valor FROM sc_meta WHERE clave = 'schema_version'");
            $version = (int) ($stmt->fetchColumn() ?: 0);
            if ($version >= SC_SCHEMA_VERSION) return; // Al día: nada que hacer
        } catch (PDOException $e) {
            // sc_meta no existe todavía → instalación desde cero o esquema v1
        }

        self::crearTablas($pdo);

        // Si sc_meta no existía pero sc_usuarios sí, veníamos de la v1
        if ($version === 0) {
            try {
                $pdo->query("SELECT correo_verificado FROM sc_usuarios LIMIT 1");
                $version = 2; // Ya tiene las columnas nuevas
            } catch (PDOException $e) {
                try {
                    $pdo->query("SELECT 1 FROM sc_usuarios LIMIT 1");
                    $version = 1; // Existe pero sin columnas de verificación
                } catch (PDOException $e2) {
                    $version = SC_SCHEMA_VERSION; // Recién creada por crearTablas()
                }
            }
        }

        if ($version < 2) self::migrarA2($pdo);

        $pdo->exec("INSERT INTO sc_meta (clave, valor) VALUES ('schema_version', '" . SC_SCHEMA_VERSION . "')
                    ON DUPLICATE KEY UPDATE valor = VALUES(valor)");
    }

    private static function crearTablas(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_meta (
                clave VARCHAR(50) NOT NULL PRIMARY KEY,
                valor VARCHAR(255) NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_usuarios (
                id                INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tipo              ENUM('persona','empresa') NOT NULL,
                correo            VARCHAR(190) NOT NULL,
                password_hash     VARCHAR(255) NOT NULL,
                session_token     VARCHAR(64)  NULL,
                token_expires     DATETIME     NULL,
                correo_verificado TINYINT(1) NOT NULL DEFAULT 0,
                verif_token       VARCHAR(64)  NULL,
                verif_expira      DATETIME     NULL,
                activo            TINYINT(1) NOT NULL DEFAULT 1,
                creado            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ultimo_acceso     DATETIME NULL,
                UNIQUE KEY uq_sc_usuarios_correo (correo),
                KEY idx_sc_usuarios_token (session_token),
                KEY idx_sc_usuarios_verif (verif_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_personas (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id  INT UNSIGNED NOT NULL,
                nombre      VARCHAR(190) NOT NULL,
                curp        VARCHAR(18)  NULL,
                headline    VARCHAR(190) NULL,
                ubicacion   VARCHAR(190) NULL,
                resumen     TEXT NULL,
                cv_url      VARCHAR(255) NULL,
                foto_url    VARCHAR(255) NULL,
                telefono    VARCHAR(30)  NULL,
                UNIQUE KEY uq_sc_personas_usuario (usuario_id),
                KEY idx_sc_personas_ubicacion (ubicacion),
                CONSTRAINT fk_sc_personas_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_experiencia (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                persona_id  INT UNSIGNED NOT NULL,
                empresa     VARCHAR(190) NOT NULL,
                puesto      VARCHAR(190) NOT NULL,
                desde       DATE NULL,
                hasta       DATE NULL,
                actual      TINYINT(1) NOT NULL DEFAULT 0,
                descripcion TEXT NULL,
                KEY idx_sc_experiencia_persona (persona_id),
                CONSTRAINT fk_sc_experiencia_persona FOREIGN KEY (persona_id)
                    REFERENCES sc_personas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_educacion (
                id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                persona_id   INT UNSIGNED NOT NULL,
                institucion  VARCHAR(190) NOT NULL,
                titulo       VARCHAR(190) NOT NULL,
                anio_inicio  SMALLINT NULL,
                anio_fin     SMALLINT NULL,
                KEY idx_sc_educacion_persona (persona_id),
                CONSTRAINT fk_sc_educacion_persona FOREIGN KEY (persona_id)
                    REFERENCES sc_personas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_habilidades (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                persona_id  INT UNSIGNED NOT NULL,
                habilidad   VARCHAR(120) NOT NULL,
                KEY idx_sc_habilidades_persona (persona_id),
                KEY idx_sc_habilidades_nombre (habilidad),
                CONSTRAINT fk_sc_habilidades_persona FOREIGN KEY (persona_id)
                    REFERENCES sc_personas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_empresas (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id  INT UNSIGNED NOT NULL,
                nombre      VARCHAR(190) NOT NULL,
                giro        VARCHAR(190) NULL,
                descripcion TEXT NULL,
                sitio_web   VARCHAR(255) NULL,
                logo_url    VARCHAR(255) NULL,
                ubicacion   VARCHAR(190) NULL,
                UNIQUE KEY uq_sc_empresas_usuario (usuario_id),
                CONSTRAINT fk_sc_empresas_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_vacantes (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                empresa_id  INT UNSIGNED NOT NULL,
                titulo      VARCHAR(190) NOT NULL,
                descripcion TEXT NULL,
                ubicacion   VARCHAR(190) NULL,
                modalidad   ENUM('presencial','remoto','hibrido') NOT NULL DEFAULT 'presencial',
                salario     VARCHAR(100) NULL,
                estatus     ENUM('abierta','cerrada') NOT NULL DEFAULT 'abierta',
                creado      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sc_vacantes_empresa (empresa_id),
                KEY idx_sc_vacantes_estatus (estatus, creado),
                CONSTRAINT fk_sc_vacantes_empresa FOREIGN KEY (empresa_id)
                    REFERENCES sc_empresas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_postulaciones (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vacante_id  INT UNSIGNED NOT NULL,
                persona_id  INT UNSIGNED NOT NULL,
                mensaje     TEXT NULL,
                estatus     ENUM('enviada','en_revision','aceptada','rechazada') NOT NULL DEFAULT 'enviada',
                fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sc_postulaciones (vacante_id, persona_id),
                KEY idx_sc_postulaciones_persona (persona_id),
                CONSTRAINT fk_sc_postulaciones_vacante FOREIGN KEY (vacante_id)
                    REFERENCES sc_vacantes(id) ON DELETE CASCADE,
                CONSTRAINT fk_sc_postulaciones_persona FOREIGN KEY (persona_id)
                    REFERENCES sc_personas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /** v1 → v2: verificación de correo y campo de mensaje en postulaciones. */
    private static function migrarA2(PDO $pdo): void {
        self::agregarColumna($pdo, 'sc_usuarios', 'correo_verificado', "TINYINT(1) NOT NULL DEFAULT 0");
        self::agregarColumna($pdo, 'sc_usuarios', 'verif_token',       "VARCHAR(64) NULL");
        self::agregarColumna($pdo, 'sc_usuarios', 'verif_expira',      "DATETIME NULL");
        self::agregarColumna($pdo, 'sc_postulaciones', 'mensaje',      "TEXT NULL");

        try {
            $pdo->exec("CREATE INDEX idx_sc_usuarios_verif ON sc_usuarios (verif_token)");
        } catch (PDOException $e) { /* ya existe */ }
        try {
            $pdo->exec("CREATE INDEX idx_sc_habilidades_nombre ON sc_habilidades (habilidad)");
        } catch (PDOException $e) { /* ya existe */ }
    }

    /** Añade una columna solo si aún no existe. */
    private static function agregarColumna(PDO $pdo, string $tabla, string $columna, string $definicion): void {
        $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$tabla}` LIKE ?");
        $stmt->execute([$columna]);
        if ($stmt->fetch()) return;

        $pdo->exec("ALTER TABLE `{$tabla}` ADD COLUMN `{$columna}` {$definicion}");
    }
}

function scDB(): PDO {
    return ScDatabase::getConnection();
}

// ─────────────────────────────────────────────────────────────────────
// gestDB() — Conexión de SOLO LECTURA a la BD del sistema de gestión
// (certificaciones), para verificar certificaciones de empresas/personas.
// DESACTIVADA: no se usa ni se conecta a nada del sistema principal.
// Para activarla:
//   1. Llenar GEST_DB_* en config/config.php
//   2. Descomentar la función completa de abajo
// ─────────────────────────────────────────────────────────────────────
/*
function gestDB(): PDO {
    static $instance = null;
    if ($instance === null) {
        $dsn = "mysql:host=" . GEST_DB_HOST . ";dbname=" . GEST_DB_NAME . ";charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];
        $instance = new PDO($dsn, GEST_DB_USER, GEST_DB_PASS, $options);
    }
    return $instance;
}
*/
