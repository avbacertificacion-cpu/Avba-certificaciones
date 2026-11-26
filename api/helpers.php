<?php
/* ══════════════════════════════════════════════════════════════
   AVBA Control de Accesos — funciones de apoyo

   Tomadas del Sistema de Gestión (api/helpers.php de la rama principal),
   sólo las que usa esta plataforma: conexión a la base PROPIA, respuesta
   JSON, token de sesión, texto estándar y compresión de fotos.
   ══════════════════════════════════════════════════════════════ */

/* ── Conexión a la base propia de Control de Accesos ───────────
   Los datos salen de config/config.php (sólo en el servidor). Va aquí y no en
   config/ porque el despliegue no publica esa carpeta. */
class Database {
    private static ?PDO $instancia = null;

    public static function conexion(): PDO {
        if (self::$instancia === null) {
            $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
            try {
                self::$instancia = new PDO($dsn, DB_USER, DB_PASS, [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    // La hora de la planta (México no cambia de horario desde 2022)
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET time_zone = '-06:00'",
                ]);
            } catch (PDOException $e) {
                error_log('[Database] ' . $e->getMessage());
                respuesta(['status' => 'error', 'message' => 'No se pudo conectar a la base de datos.'], 500);
            }
        }
        return self::$instancia;
    }
}

/** Devuelve una respuesta JSON y termina. */
function respuesta(array $data, int $codigo = 200): void {
    while (ob_get_level() > 0) ob_end_clean();       // lo que se haya colado antes (ver api/index.php)
    http_response_code($codigo);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** Token de sesión seguro (64 caracteres hexadecimales). */
function generarToken(): string {
    return bin2hex(random_bytes(32));
}

/**
 * La cuenta de una sesión vigente, o null. Cada equipo tiene su propia sesión
 * (tabla sesiones_usuario): entrar en la computadora no cierra la del teléfono.
 */
function validarToken(PDO $pdo, ?string $token): ?array {
    $token = trim((string)$token);
    if ($token === '' || strlen($token) > 100) return null;
    try {
        $st = $pdo->prepare(
            "SELECT u.id, u.usuario, u.rol, u.nombre
               FROM sesiones_usuario s
               INNER JOIN usuarios u ON u.id = s.usuario_id
              WHERE s.token = ? AND s.expira > ? AND u.activo = 1"
        );
        $st->execute([$token, date('Y-m-d H:i:s')]);
        $usr = $st->fetch(PDO::FETCH_ASSOC);
    } catch (\Throwable $e) {
        return null;                                  // tablas todavía no creadas
    }
    return $usr ?: null;
}

/** El token que manda la pantalla: Authorization: Bearer, X-Token, cuerpo o URL. */
function tokenDePeticion(array $cuerpo = []): ?string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) return $m[1];
    if (!empty($_SERVER['HTTP_X_TOKEN'])) return trim($_SERVER['HTTP_X_TOKEN']);
    if (isset($cuerpo['token']) && is_string($cuerpo['token'])) return $cuerpo['token'];
    if (isset($_GET['token']) && is_string($_GET['token'])) return $_GET['token'];
    return null;
}

/**
 * Texto estándar: mayúsculas, sin acentos (la Ñ se queda), sin símbolos raros.
 * strtoupper() no sirve: trabaja byte por byte y dejaba «ZúñIGA».
 */
function textoEstandar($v): string {
    $s = mb_strtoupper(trim((string)($v ?? '')), 'UTF-8');
    if ($s === '') return '';
    $s = str_replace(["\r\n", "\r"], "\n", $s);
    $s = preg_replace('/[\x{2010}-\x{2015}\x{2212}]/u', '-', $s);
    $s = preg_replace('/(\d)\s*(?:"|\'\'|\x{201D}|\x{2033})/u', '$1 IN', $s);
    $s = preg_replace('/(\d)\s*(?:\'|\x{2019}|\x{2032})/u', '$1 FT', $s);
    $s = str_replace('&', ' Y ', $s);
    $s = str_replace('@', ' A ', $s);
    $s = strtr($s, [
        'Á' => 'A', 'À' => 'A', 'Â' => 'A', 'Ä' => 'A', 'Ã' => 'A', 'Å' => 'A',
        'É' => 'E', 'È' => 'E', 'Ê' => 'E', 'Ë' => 'E',
        'Í' => 'I', 'Ì' => 'I', 'Î' => 'I', 'Ï' => 'I',
        'Ó' => 'O', 'Ò' => 'O', 'Ô' => 'O', 'Ö' => 'O', 'Õ' => 'O',
        'Ú' => 'U', 'Ù' => 'U', 'Û' => 'U', 'Ü' => 'U',
        'Ç' => 'C', 'Ý' => 'Y',
    ]);
    $s = preg_replace('/[\'"`´\x{2018}\x{2019}\x{201C}\x{201D}]/u', '', $s);
    $s = preg_replace('/[^A-Z0-9Ñ .,\/\-_\n]/u', ' ', $s);
    $s = preg_replace('/[ \t]+/', ' ', $s);
    $s = preg_replace('/ *\n */', "\n", $s);
    $s = preg_replace('/\n{2,}/', "\n", $s);
    $s = preg_replace('/ +,/', ',', $s);
    $s = preg_replace('/,{2,}/', ',', $s);
    return trim($s, " \n");
}

/**
 * Comprime y achica una foto (lado mayor a $maxW/$maxH). La guarda como JPEG
 * (o PNG si tiene transparencia) y devuelve el nombre final, o false sin GD.
 */
function comprimirImagen(string $src, string $dest, int $maxW = 1280, int $maxH = 1280, int $calidad = 72) {
    if (!function_exists('imagecreatefromstring') || !function_exists('getimagesize')) return false;
    $info = @getimagesize($src);
    if (!$info) return false;
    [$w, $h] = $info;
    $tipo = $info[2] ?? IMAGETYPE_JPEG;
    $datos = @file_get_contents($src);
    if ($datos === false) return false;
    $img = @imagecreatefromstring($datos);
    if (!$img) return false;

    $k = min($maxW / max(1, $w), $maxH / max(1, $h), 1);
    $nw = max(1, (int)round($w * $k));
    $nh = max(1, (int)round($h * $k));
    $alfa = in_array($tipo, [IMAGETYPE_PNG, IMAGETYPE_WEBP], true);
    if ($nw !== $w || $nh !== $h) {
        $dst = imagecreatetruecolor($nw, $nh);
        if ($alfa) {
            imagealphablending($dst, false);
            imagesavealpha($dst, true);
            imagefilledrectangle($dst, 0, 0, $nw, $nh, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        }
        imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imagedestroy($img);
        $img = $dst;
    }
    $sinExt = preg_replace('/\.[a-zA-Z0-9]+$/', '', $dest);
    $final = $dest;
    try {
        if ($alfa && function_exists('imagepng')) { $final = $sinExt . '.png'; $ok = imagepng($img, $final, 8); }
        else { $final = $sinExt . '.jpg'; $ok = imagejpeg($img, $final, max(40, min(95, $calidad))); }
    } catch (\Throwable $e) {
        $ok = false;
    }
    imagedestroy($img);
    return $ok ? basename($final) : false;
}
