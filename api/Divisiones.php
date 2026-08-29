<?php
/**
 * AVBA Certificaciones — Divisiones
 *
 * Cada división de la unidad lleva su propio expediente en su propia base de
 * datos, con el mismo esquema y sus propias cuentas. La aplicación es la misma:
 * se elige la conexión al principio de la petición, según en qué base vive la
 * cuenta que entró, y de ahí en adelante todo el sistema trabaja igual sin
 * saber que existe la otra.
 *
 * Vive en api/ y no en config/ por una razón práctica que ya costó una caída:
 * el despliegue borra la carpeta config antes de subir, para no pisar las
 * credenciales del servidor. Todo lo que api/ necesite tiene que estar en api/,
 * porque config/ nunca viaja.
 *
 * Para dar de alta una división:
 *   1. Crear su base en el panel del hosting, con el mismo usuario de MySQL.
 *   2. Declararla en config/config.php (a mano, en el servidor):
 *        define('DB_DIVISIONES', ['secundaria' => 'u123456_avba_div2']);
 *   3. Entrar y crear ahí su primera cuenta ADMIN.
 *
 * Sin declarar ninguna, el sistema se comporta exactamente como siempre.
 */
class Divisiones {

    /** Una conexión por base dentro de la misma petición. */
    private static array $conexiones = [];

    /**
     * Bases configuradas, por división. La principal siempre existe; las demás
     * sólo si están declaradas en la configuración del servidor.
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

    /**
     * Conexión de una división, o null si no está configurada o no responde.
     *
     * La principal se pide a Database, que es quien la administra. Las demás se
     * abren aquí con las mismas credenciales: Database sólo conoce la suya.
     */
    public static function conexion(string $division): ?PDO {
        $bases = self::bases();
        if (!isset($bases[$division])) return null;

        $nombre = $bases[$division];
        if ($nombre === DB_NAME) return Database::getConnection();
        if (isset(self::$conexiones[$nombre])) return self::$conexiones[$nombre];

        try {
            self::$conexiones[$nombre] = new PDO(
                "mysql:host=" . DB_HOST . ";dbname={$nombre};charset=utf8mb4",
                DB_USER, DB_PASS,
                [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-06:00'",
                ]
            );
        } catch (\Throwable $e) {
            // Una división mal configurada no debe tumbar el sistema entero:
            // se ignora y el resto sigue trabajando.
            error_log("[Divisiones] no se pudo abrir '{$division}' ({$nombre}): " . $e->getMessage());
            return null;
        }
        return self::$conexiones[$nombre];
    }

    /** Todas las conexiones que responden, indexadas por división. */
    public static function conexiones(): array {
        $out = [];
        foreach (array_keys(self::bases()) as $division) {
            $c = self::conexion($division);
            if ($c) $out[$division] = $c;
        }
        return $out;
    }
}
