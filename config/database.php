<?php
/**
 * AVBA Certificaciones — Conexión a la base de datos
 *
 * Hay más de una división y cada una lleva su propio expediente. En vez de
 * marcar cada registro con su división y acordarse de filtrarla en las
 * cientos de consultas del sistema —donde olvidar una sola significa que un
 * expediente aparece en la división equivocada, y en silencio— cada división
 * tiene su propia base con el mismo esquema.
 *
 * La aplicación es exactamente la misma: se elige la conexión al principio de
 * la petición, según en qué base vive la cuenta que entró, y de ahí en adelante
 * todo el sistema funciona igual sin saber que existe la otra.
 */

class Database {
    /** Una conexión por base, reutilizada dentro de la misma petición. */
    private static array $conexiones = [];

    private function __construct() {}

    public static function getConnection(?string $dbname = null): PDO {
        $dbname = $dbname ?: DB_NAME;
        if (isset(self::$conexiones[$dbname])) return self::$conexiones[$dbname];

        $dsn = "mysql:host=" . DB_HOST . ";dbname={$dbname};charset=utf8mb4";
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-06:00'",
        ];

        try {
            self::$conexiones[$dbname] = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Una división secundaria mal configurada no debe tumbar el sistema
            // entero: quien pregunta decide si puede seguir sin ella.
            if ($dbname !== DB_NAME) {
                error_log("[Database] división '{$dbname}': " . $e->getMessage());
                throw $e;
            }
            http_response_code(500);
            echo json_encode(['status' => 'error', 'message' => 'Error de conexión a la base de datos.']);
            exit;
        }
        return self::$conexiones[$dbname];
    }

    /**
     * Bases configuradas, por división. La principal siempre existe; las demás
     * sólo si están declaradas en la configuración del servidor.
     *
     * Para dar de alta otra división basta con crear su base en el hosting con
     * el mismo esquema y declararla en config/config.php:
     *   define('DB_DIVISIONES', ['secundaria' => 'u123456_avba_div2']);
     */
    public static function bases(): array {
        $bases = ['principal' => DB_NAME];
        if (defined('DB_DIVISIONES') && is_array(DB_DIVISIONES)) {
            foreach (DB_DIVISIONES as $clave => $nombre) {
                $clave  = trim((string)$clave);
                $nombre = trim((string)$nombre);
                // Nunca se deja apuntar una división a la base principal: sería
                // aislamiento sólo de nombre.
                if ($clave === '' || $clave === 'principal' || $nombre === '' || $nombre === DB_NAME) continue;
                $bases[$clave] = $nombre;
            }
        }
        return $bases;
    }

    /** Conexión de una división, o null si no está configurada o no responde. */
    public static function deDivision(string $division): ?PDO {
        $bases = self::bases();
        if (!isset($bases[$division])) return null;
        try {
            return self::getConnection($bases[$division]);
        } catch (\Throwable $e) {
            return null;
        }
    }
}
