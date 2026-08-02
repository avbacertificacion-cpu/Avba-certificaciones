<?php
/**
 * Socios Comerciales AVBA — Conexión a la base de datos
 * Singleton PDO + autoinstalación de tablas sc_* al primer arranque.
 *
 * Sistema totalmente aislado de la BD de gestión (certificaciones).
 */

require_once __DIR__ . '/config.php';

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
     * Crea las tablas sc_* si no existen todavía (autoinstalación).
     * Se detecta con una consulta ligera para no repetir el CREATE TABLE
     * completo en cada request una vez instalado.
     */
    private static function instalarEsquema(PDO $pdo): void {
        try {
            $pdo->query("SELECT 1 FROM sc_usuarios LIMIT 1");
            return; // Ya instalado
        } catch (PDOException $e) {
            // Tabla no existe todavía → continuar con la instalación
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_usuarios (
                id             INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tipo           ENUM('persona','empresa') NOT NULL,
                correo         VARCHAR(190) NOT NULL,
                password_hash  VARCHAR(255) NOT NULL,
                session_token  VARCHAR(64)  NULL,
                token_expires  DATETIME     NULL,
                activo         TINYINT(1) NOT NULL DEFAULT 1,
                creado         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ultimo_acceso  DATETIME NULL,
                UNIQUE KEY uq_sc_usuarios_correo (correo),
                KEY idx_sc_usuarios_token (session_token)
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
                CONSTRAINT fk_sc_vacantes_empresa FOREIGN KEY (empresa_id)
                    REFERENCES sc_empresas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_postulaciones (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                vacante_id  INT UNSIGNED NOT NULL,
                persona_id  INT UNSIGNED NOT NULL,
                estatus     ENUM('enviada','en_revision','aceptada','rechazada') NOT NULL DEFAULT 'enviada',
                fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uq_sc_postulaciones (vacante_id, persona_id),
                CONSTRAINT fk_sc_postulaciones_vacante FOREIGN KEY (vacante_id)
                    REFERENCES sc_vacantes(id) ON DELETE CASCADE,
                CONSTRAINT fk_sc_postulaciones_persona FOREIGN KEY (persona_id)
                    REFERENCES sc_personas(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}

function scDB(): PDO {
    return ScDatabase::getConnection();
}

// ─────────────────────────────────────────────────────────────────────
// gestDB() — Conexión de SOLO LECTURA a la BD del sistema de gestión
// (certificaciones), para verificar certificaciones de empresas/personas.
// DESACTIVADA en la Fase 1: no se usa ni se conecta a nada del sistema
// principal. Se activará en la Fase 2 sin reescribir código:
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
