<?php
/*
 * Núcleo del sistema: configuración, base de datos, sesión y utilidades
 * comunes a la API, el instalador y las tareas programadas.
 */
declare(strict_types=1);

define('RAIZ', dirname(__DIR__));

// ── Configuración ─────────────────────────────────────────────────────
function configuracion(): ?array
{
    static $c = false;
    if ($c === false) {
        $archivo = RAIZ . '/config/config.php';
        $c = is_file($archivo) ? require $archivo : null;
    }
    return $c;
}

/** Lee un valor con notación de puntos: conf('db.host'). */
function conf(string $ruta, $porDefecto = null)
{
    $v = configuracion();
    foreach (explode('.', $ruta) as $parte) {
        if (!is_array($v) || !array_key_exists($parte, $v)) return $porDefecto;
        $v = $v[$parte];
    }
    return $v;
}

function instalado(): bool
{
    return configuracion() !== null && is_file(RAIZ . '/storage/.instalado');
}

// ── Base de datos ─────────────────────────────────────────────────────
function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', conf('db.host'), conf('db.nombre'));
        $pdo = new PDO($dsn, (string) conf('db.usuario'), (string) conf('db.clave'), [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);
        // La hora de la base de datos sigue a la de PHP (zona de la empresa).
        $pdo->exec("SET time_zone = '" . (new DateTime())->format('P') . "'");
    }
    return $pdo;
}

/** Ejecuta una consulta preparada y devuelve el PDOStatement. */
function q(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    $st->execute($params);
    return $st;
}
function fila(string $sql, array $params = []): ?array
{
    $r = q($sql, $params)->fetch();
    return $r === false ? null : $r;
}
function filas(string $sql, array $params = []): array
{
    return q($sql, $params)->fetchAll();
}
function valor(string $sql, array $params = [])
{
    $r = q($sql, $params)->fetchColumn();
    return $r === false ? null : $r;
}
function ultimoId(): int
{
    return (int) db()->lastInsertId();
}

// ── Respuestas JSON ───────────────────────────────────────────────────
final class ErrorApi extends Exception
{
    public int $estado;
    public function __construct(string $mensaje, int $estado = 400)
    {
        parent::__construct($mensaje);
        $this->estado = $estado;
    }
}

function fallar(string $mensaje, int $estado = 400): never
{
    throw new ErrorApi($mensaje, $estado);
}

function responder(array $datos, int $estado = 200): never
{
    http_response_code($estado);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($datos, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ── Utilidades ────────────────────────────────────────────────────────
function ahora(): string
{
    return date('Y-m-d H:i:s');
}

/** Fecha de la BD → ISO 8601 para el navegador (o null). */
function iso(?string $fecha): ?string
{
    if ($fecha === null || $fecha === '') return null;
    if (strlen($fecha) === 10) return $fecha; // sólo fecha
    return (new DateTime($fecha))->format(DATE_ATOM);
}

function texto($v, int $max = 255): string
{
    $s = trim((string) ($v ?? ''));
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s) ?? '';
    return mb_substr($s, 0, $max);
}

function fechaValida($v): ?string
{
    $v = trim((string) ($v ?? ''));
    if ($v === '') return null;
    $d = DateTime::createFromFormat('Y-m-d', $v);
    return ($d && $d->format('Y-m-d') === $v) ? $v : null;
}

function extension(string $nombre): string
{
    $e = strtolower(pathinfo($nombre, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,8}$/', $e) ? $e : '';
}

/** Nombre seguro para usar dentro de una ruta de almacenamiento. */
function nombreSeguro(string $nombre): string
{
    $base = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $nombre) ?: 'archivo';
    $base = preg_replace('/[^A-Za-z0-9._-]+/', '-', $base) ?? 'archivo';
    $base = trim($base, '-.');
    return substr($base !== '' ? $base : 'archivo', 0, 120);
}

function ipCliente(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'), 0, 45);
}

// ── Sesión ────────────────────────────────────────────────────────────
function iniciarSesion(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) return;
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    session_name('chc_sesion');
    session_set_cookie_params([
        'lifetime' => 0,
        // La cookie sólo viaja a la carpeta de la API (api/), que es la única que la usa.
        'path'     => rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/') . '/',
        'secure'   => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    // Cierre por inactividad.
    $limite = (int) conf('app.sesion_minutos', 480) * 60;
    if (isset($_SESSION['ultimo_uso']) && time() - $_SESSION['ultimo_uso'] > $limite) {
        $_SESSION = [];
        session_regenerate_id(true);
    }
    $_SESSION['ultimo_uso'] = time();
    if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(32));
}

function arranque(): void
{
    date_default_timezone_set((string) conf('app.zona', 'America/Mexico_City'));
    mb_internal_encoding('UTF-8');
    if (conf('app.debug')) {
        ini_set('display_errors', '1');
        error_reporting(E_ALL);
    } else {
        ini_set('display_errors', '0');
    }
}
