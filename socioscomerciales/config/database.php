<?php
/**
 * Socios Comerciales AVBA — Conexión a la base de datos
 * Singleton PDO + autoinstalación y migración de tablas sc_*.
 *
 * Sistema totalmente aislado de la BD de gestión (certificaciones).
 */

require_once __DIR__ . '/config.php';

/** Versión actual del esquema. Subir al añadir migraciones. */
const SC_SCHEMA_VERSION = 8;

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
    public static function instalarEsquema(PDO $pdo): void {
        $version = 0;

        try {
            $stmt = $pdo->query("SELECT valor FROM sc_meta WHERE clave = 'schema_version'");
            $version = (int) ($stmt->fetchColumn() ?: 0);
            if ($version >= SC_SCHEMA_VERSION) return; // Al día: nada que hacer
        } catch (PDOException $e) {
            // sc_meta no existe todavía → instalación desde cero o esquema v1
        }

        // Si algo falla aquí, el mensaje debe llegar al usuario: un error
        // genérico de servidor no dice nada y deja el portal inservible.
        try {
            // El sondeo va ANTES de crear nada. Si se hiciera después, las
            // tablas que acaba de crear crearTablas() se tomarían por prueba
            // de que la base ya estaba al día y las migraciones de columnas
            // (v2 y v3) se saltarían en una base antigua de verdad.
            if ($version === 0) {
                $version = self::detectarVersionPrevia($pdo);
            }

            self::crearTablas($pdo);

            if ($version < 2) self::migrarA2($pdo);
            if ($version < 3) self::migrarA3($pdo);
            if ($version < 4) self::migrarA4($pdo);
            if ($version < 5) self::migrarA5($pdo);
            if ($version < 6) self::migrarA6($pdo);
            if ($version < 7) self::migrarA7($pdo);
            if ($version < 8) self::migrarA8($pdo);

            $pdo->exec("INSERT INTO sc_meta (clave, valor) VALUES ('schema_version', '" . SC_SCHEMA_VERSION . "')
                        ON DUPLICATE KEY UPDATE valor = '" . SC_SCHEMA_VERSION . "'");
        } catch (PDOException $e) {
            // El mensaje crudo de MariaDB incluye usuario, host y tabla
            // ("command denied to user 'u123_sc'@'localhost' for table ...").
            // Se registra en el log del servidor y al cliente le llega un texto
            // fijo: el detalle se consulta con api/index.php?action=DIAGNOSTICO.
            error_log('SC esquema: ' . $e->getMessage());
            throw new RuntimeException(
                'La base de datos está en mantenimiento. Inténtalo en unos minutos.'
            );
        }
    }

    /**
     * Deduce la versión de una BD creada antes de que existiera sc_meta.
     * Se llama antes de crearTablas(), así que lo que ve es el estado real.
     */
    private static function detectarVersionPrevia(PDO $pdo): int {
        $columnas = self::columnasDe($pdo, 'sc_usuarios');

        if (!$columnas)                                    return SC_SCHEMA_VERSION; // Base vacía
        if (self::columnasDe($pdo, 'sc_publicaciones'))      return 8;
        if (in_array('bloqueado_motivo', $columnas, true))   return 7;
        if (in_array('terminos_aceptados', $columnas, true)) return 6;
        if (self::columnasDe($pdo, 'sc_sesiones'))          return 5;
        if (self::columnasDe($pdo, 'sc_intentos'))          return 4;
        if (in_array('reset_token', $columnas, true))       return 3;
        if (in_array('correo_verificado', $columnas, true)) return 2;
        return 1;
    }

    /**
     * Nombres de columna de una tabla, o [] si la tabla no existe.
     * Se usa SHOW COLUMNS sin marcadores: algunos servidores MySQL no
     * admiten parámetros preparados en la cláusula LIKE de SHOW.
     */
    private static function columnasDe(PDO $pdo, string $tabla): array {
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM `{$tabla}`");
            return array_column($stmt->fetchAll(), 'Field');
        } catch (PDOException $e) {
            return [];
        }
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
                terminos_version  VARCHAR(20) NULL,
                terminos_aceptados DATETIME   NULL,
                verif_token       VARCHAR(64)  NULL,
                verif_expira      DATETIME     NULL,
                reset_token       VARCHAR(64)  NULL,
                reset_expira      DATETIME     NULL,
                activo            TINYINT(1) NOT NULL DEFAULT 1,
                bloqueado_motivo  VARCHAR(255) NULL,
                bloqueado_fecha   DATETIME     NULL,
                bloqueado_por     INT UNSIGNED NULL,
                creado            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ultimo_acceso     DATETIME NULL,
                UNIQUE KEY uq_sc_usuarios_correo (correo),
                KEY idx_sc_usuarios_token (session_token),
                KEY idx_sc_usuarios_verif (verif_token),
                KEY idx_sc_usuarios_reset (reset_token)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_intentos (
                id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tipo  VARCHAR(20)  NOT NULL,
                clave VARCHAR(190) NOT NULL,
                ts    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sc_intentos (tipo, clave, ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Una fila por dispositivo con sesión abierta. Las columnas
        // session_token / token_expires de sc_usuarios quedaron sin uso al
        // migrar a la v5; se conservan solo para no romper una base antigua.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_sesiones (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT UNSIGNED NOT NULL,
                token      VARCHAR(64) NOT NULL,
                creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expira     DATETIME NOT NULL,
                ultimo_uso DATETIME NULL,
                ip         VARCHAR(45) NULL,
                agente     VARCHAR(60) NULL,
                UNIQUE KEY uq_sc_sesiones_token (token),
                KEY idx_sc_sesiones_usuario (usuario_id, expira),
                CONSTRAINT fk_sc_sesiones_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Bitácora de quién abrió el CV de quién: son datos personales y
        // debe poder responderse a un candidato que lo pregunte.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_cv_accesos (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                persona_id INT UNSIGNED NOT NULL,
                usuario_id INT UNSIGNED NOT NULL,
                fecha      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip         VARCHAR(45) NULL,
                KEY idx_sc_cv_accesos_persona (persona_id, fecha),
                KEY idx_sc_cv_accesos_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Bitácora de lo que hace administración. Sin claves foráneas a
        // propósito: cuando se borra una cuenta el registro del borrado tiene
        // que sobrevivir, así que se guarda una copia del correo y el nombre.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_admin_log (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id    INT UNSIGNED NOT NULL,
                admin_correo VARCHAR(190) NOT NULL,
                accion      VARCHAR(30)  NOT NULL,
                usuario_id  INT UNSIGNED NULL,
                usuario_correo VARCHAR(190) NULL,
                detalle     VARCHAR(255) NULL,
                fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip          VARCHAR(45) NULL,
                KEY idx_sc_admin_log_fecha (fecha),
                KEY idx_sc_admin_log_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");


        // Muro del portal: publicaciones con texto y/o imagen, y sus
        // comentarios. Ambas cuelgan de sc_usuarios con ON DELETE CASCADE,
        // así que borrar una cuenta se lleva también lo que publicó.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_publicaciones (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT UNSIGNED NOT NULL,
                texto      TEXT NULL,
                imagen_url VARCHAR(255) NULL,
                creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sc_publicaciones_creado (creado),
                KEY idx_sc_publicaciones_usuario (usuario_id),
                CONSTRAINT fk_sc_publicaciones_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_comentarios (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                publicacion_id INT UNSIGNED NOT NULL,
                usuario_id    INT UNSIGNED NOT NULL,
                texto         TEXT NOT NULL,
                creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sc_comentarios_pub (publicacion_id, creado),
                CONSTRAINT fk_sc_comentarios_pub FOREIGN KEY (publicacion_id)
                    REFERENCES sc_publicaciones(id) ON DELETE CASCADE,
                CONSTRAINT fk_sc_comentarios_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
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

    /** v2 → v3: recuperación de contraseña. */
    private static function migrarA3(PDO $pdo): void {
        self::agregarColumna($pdo, 'sc_usuarios', 'reset_token',  "VARCHAR(64) NULL");
        self::agregarColumna($pdo, 'sc_usuarios', 'reset_expira', "DATETIME NULL");

        try {
            $pdo->exec("CREATE INDEX idx_sc_usuarios_reset ON sc_usuarios (reset_token)");
        } catch (PDOException $e) { /* ya existe */ }
    }

    /** v3 → v4: registro de intentos, para limitar fuerza bruta y correos. */
    private static function migrarA4(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_intentos (
                id    INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                tipo  VARCHAR(20)  NOT NULL,
                clave VARCHAR(190) NOT NULL,
                ts    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sc_intentos (tipo, clave, ts)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /**
     * v4 → v5: sesiones por dispositivo y bitácora de accesos a los CV.
     *
     * Antes la sesión era una columna suelta en sc_usuarios, así que solo
     * cabía una: entrar desde el teléfono cerraba la de la computadora. Las
     * sesiones vivas se copian a la tabla nueva para no echar a nadie fuera
     * en el momento del despliegue.
     */
    private static function migrarA5(PDO $pdo): void {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_sesiones (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT UNSIGNED NOT NULL,
                token      VARCHAR(64) NOT NULL,
                creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                expira     DATETIME NOT NULL,
                ultimo_uso DATETIME NULL,
                ip         VARCHAR(45) NULL,
                agente     VARCHAR(60) NULL,
                UNIQUE KEY uq_sc_sesiones_token (token),
                KEY idx_sc_sesiones_usuario (usuario_id, expira),
                CONSTRAINT fk_sc_sesiones_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_cv_accesos (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                persona_id INT UNSIGNED NOT NULL,
                usuario_id INT UNSIGNED NOT NULL,
                fecha      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip         VARCHAR(45) NULL,
                KEY idx_sc_cv_accesos_persona (persona_id, fecha),
                KEY idx_sc_cv_accesos_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Rescatar las sesiones abiertas de la columna antigua
        try {
            $pdo->exec(
                "INSERT IGNORE INTO sc_sesiones (usuario_id, token, expira, ultimo_uso)
                 SELECT id, session_token, token_expires, ultimo_acceso
                 FROM sc_usuarios
                 WHERE session_token IS NOT NULL AND token_expires > NOW()"
            );
        } catch (PDOException $e) {
            // La columna puede no existir en una instalación nueva
            error_log('migrarA5 rescate de sesiones: ' . $e->getMessage());
        }
    }

    /**
     * v5 → v6: constancia de la aceptación de los términos.
     *
     * Se guarda la versión aceptada, no solo un "sí": si mañana cambian los
     * términos hay que poder saber quién aceptó cuáles y a quién toca volver
     * a preguntarle. Las cuentas anteriores quedan en NULL, que es la verdad
     * — nunca se les pidió.
     */
    private static function migrarA6(PDO $pdo): void {
        self::agregarColumna($pdo, 'sc_usuarios', 'terminos_version',   "VARCHAR(20) NULL");
        self::agregarColumna($pdo, 'sc_usuarios', 'terminos_aceptados', "DATETIME NULL");
    }

    /** v6 → v7: bloqueo de cuentas y bitácora de administración. */
    private static function migrarA7(PDO $pdo): void {
        self::agregarColumna($pdo, 'sc_usuarios', 'bloqueado_motivo', "VARCHAR(255) NULL");
        self::agregarColumna($pdo, 'sc_usuarios', 'bloqueado_fecha',  "DATETIME NULL");
        self::agregarColumna($pdo, 'sc_usuarios', 'bloqueado_por',    "INT UNSIGNED NULL");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_admin_log (
                id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                admin_id    INT UNSIGNED NOT NULL,
                admin_correo VARCHAR(190) NOT NULL,
                accion      VARCHAR(30)  NOT NULL,
                usuario_id  INT UNSIGNED NULL,
                usuario_correo VARCHAR(190) NULL,
                detalle     VARCHAR(255) NULL,
                fecha       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                ip          VARCHAR(45) NULL,
                KEY idx_sc_admin_log_fecha (fecha),
                KEY idx_sc_admin_log_usuario (usuario_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /** v7 → v8: muro de publicaciones y comentarios. */
    private static function migrarA8(PDO $pdo): void {
        // Muro del portal: publicaciones con texto y/o imagen, y sus
        // comentarios. Ambas cuelgan de sc_usuarios con ON DELETE CASCADE,
        // así que borrar una cuenta se lleva también lo que publicó.
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_publicaciones (
                id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                usuario_id INT UNSIGNED NOT NULL,
                texto      TEXT NULL,
                imagen_url VARCHAR(255) NULL,
                creado     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sc_publicaciones_creado (creado),
                KEY idx_sc_publicaciones_usuario (usuario_id),
                CONSTRAINT fk_sc_publicaciones_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS sc_comentarios (
                id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                publicacion_id INT UNSIGNED NOT NULL,
                usuario_id    INT UNSIGNED NOT NULL,
                texto         TEXT NOT NULL,
                creado        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                KEY idx_sc_comentarios_pub (publicacion_id, creado),
                CONSTRAINT fk_sc_comentarios_pub FOREIGN KEY (publicacion_id)
                    REFERENCES sc_publicaciones(id) ON DELETE CASCADE,
                CONSTRAINT fk_sc_comentarios_usuario FOREIGN KEY (usuario_id)
                    REFERENCES sc_usuarios(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }

    /** Añade una columna solo si aún no existe. */
    private static function agregarColumna(PDO $pdo, string $tabla, string $columna, string $definicion): void {
        $columnas = self::columnasDe($pdo, $tabla);
        if (!$columnas) return;                              // La tabla no existe
        if (in_array($columna, $columnas, true)) return;     // Ya está

        $pdo->exec("ALTER TABLE `{$tabla}` ADD COLUMN `{$columna}` {$definicion}");
    }

    /** Estado del esquema, para el diagnóstico. */
    public static function estadoEsquema(PDO $pdo): array {
        $version = null;
        try {
            $stmt = $pdo->query("SELECT valor FROM sc_meta WHERE clave = 'schema_version'");
            $version = $stmt->fetchColumn();
        } catch (PDOException $e) {
            $version = 'sc_meta no existe';
        }

        $tablas = [];
        foreach (['sc_meta','sc_usuarios','sc_sesiones','sc_intentos','sc_admin_log','sc_cv_accesos','sc_publicaciones','sc_comentarios','sc_personas',
                  'sc_experiencia','sc_educacion','sc_habilidades','sc_empresas','sc_vacantes',
                  'sc_postulaciones'] as $t) {
            $cols = self::columnasDe($pdo, $t);
            $tablas[$t] = $cols ? count($cols) . ' columnas' : 'NO EXISTE';
        }

        // Se publica el conteo de columnas, no sus nombres: el esquema
        // detallado es material de reconocimiento para un atacante.
        return [
            'schema_version_guardada' => $version,
            'schema_version_esperada' => SC_SCHEMA_VERSION,
            'tablas'                  => $tablas,
        ];
    }

    /** Conecta sin abortar la petición; devuelve [PDO|null, error]. */
    public static function probarConexion(): array {
        try {
            $dsn = "mysql:host=" . SC_DB_HOST . ";dbname=" . SC_DB_NAME . ";charset=utf8mb4";
            $pdo = new PDO($dsn, SC_DB_USER, SC_DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            return [$pdo, null];
        } catch (PDOException $e) {
            return [null, $e->getMessage()];
        }
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
