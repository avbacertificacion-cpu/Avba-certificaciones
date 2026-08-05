<?php
/**
 * AVBA · Muro de operadores — API
 *
 *   GET  /api/muro.php            → lista las publicaciones aprobadas
 *   POST /api/muro.php            → recibe una publicación (queda pendiente)
 *
 * Toda publicación nace en estado "pendiente": nada aparece en el sitio
 * hasta que alguien de AVBA la aprueba desde /moderacion.php
 */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

// Mientras no exista config/config.php (base de datos aún sin configurar),
// el muro responde vacío en lugar de romper la página con un error de PHP.
$rutaConfig = __DIR__ . '/../config/config.php';
if (!is_file($rutaConfig)) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        http_response_code(200);
        echo json_encode(['status' => 'ok', 'total' => 0, 'publicaciones' => []], JSON_UNESCAPED_UNICODE);
    } else {
        http_response_code(503);
        echo json_encode([
            'status'  => 'error',
            'message' => 'El muro todavía no está habilitado. Vuelve pronto.',
        ], JSON_UNESCAPED_UNICODE);
    }
    exit;
}
require_once $rutaConfig;

// ── Límites ──────────────────────────────────────────────────
const MAX_BYTES        = 8 * 1024 * 1024;   // 8 MB por imagen
const MAX_LADO         = 1600;              // se redimensiona a este lado mayor
const MAX_POR_IP_DIA   = 5;                 // publicaciones por IP cada 24 h
const DIR_SUBIDAS      = __DIR__ . '/../uploads/muro/';

const MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

function responder(array $datos, int $codigo = 200): never {
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

function conectar(): PDO {
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]
        );
    } catch (PDOException $e) {
        responder(['status' => 'error', 'message' => 'No se pudo conectar a la base de datos.'], 500);
    }
}

/** Hash de la IP: permite frenar abuso sin almacenar la IP en claro. */
function hashIp(): string {
    return hash('sha256', IP_SALT . ($_SERVER['REMOTE_ADDR'] ?? ''));
}

// ══════════════════════════════════════════════════════════════
//  GET · publicaciones aprobadas
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $pdo = conectar();

    $limite  = min(60, max(1, (int)($_GET['limite'] ?? 24)));
    $desde   = max(0, (int)($_GET['desde'] ?? 0));

    $sql = 'SELECT id, nombre, puesto, empresa, comentario, archivo, ancho, alto, creado_en
              FROM muro_publicaciones
             WHERE estado = "aprobado"
             ORDER BY creado_en DESC
             LIMIT :limite OFFSET :desde';
    $st = $pdo->prepare($sql);
    $st->bindValue(':limite', $limite, PDO::PARAM_INT);
    $st->bindValue(':desde',  $desde,  PDO::PARAM_INT);
    $st->execute();

    $filas = array_map(static function (array $f): array {
        return [
            'id'         => (int)$f['id'],
            'nombre'     => $f['nombre'],
            'puesto'     => $f['puesto'],
            'empresa'    => $f['empresa'],
            'comentario' => $f['comentario'],
            'imagen'     => $f['archivo'] ? 'uploads/muro/' . $f['archivo'] : null,
            'ancho'      => $f['ancho'] ? (int)$f['ancho'] : null,
            'alto'       => $f['alto'] ? (int)$f['alto'] : null,
            'fecha'      => $f['creado_en'],
        ];
    }, $st->fetchAll());

    $total = (int)$pdo->query('SELECT COUNT(*) FROM muro_publicaciones WHERE estado = "aprobado"')->fetchColumn();

    responder(['status' => 'ok', 'total' => $total, 'publicaciones' => $filas]);
}

// ══════════════════════════════════════════════════════════════
//  POST · nueva publicación
// ══════════════════════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    responder(['status' => 'error', 'message' => 'Método no permitido.'], 405);
}

// Trampa anti-bots: campo oculto que una persona nunca rellena.
if (!empty($_POST['sitio_web'])) {
    responder(['status' => 'ok', 'message' => 'Recibido.']);   // silencioso a propósito
}

$nombre     = trim((string)($_POST['nombre'] ?? ''));
$puesto     = trim((string)($_POST['puesto'] ?? ''));
$empresa    = trim((string)($_POST['empresa'] ?? ''));
$comentario = trim((string)($_POST['comentario'] ?? ''));
$consent    = !empty($_POST['consentimiento']);

// ── Validación de texto ──────────────────────────────────────
$errores = [];
if (mb_strlen($nombre) < 2 || mb_strlen($nombre) > 80) {
    $errores[] = 'Escribe tu nombre (entre 2 y 80 caracteres).';
}
if (mb_strlen($comentario) < 5 || mb_strlen($comentario) > 600) {
    $errores[] = 'El comentario debe tener entre 5 y 600 caracteres.';
}
if (mb_strlen($puesto) > 80)   { $errores[] = 'El puesto es demasiado largo.'; }
if (mb_strlen($empresa) > 120) { $errores[] = 'La empresa es demasiado larga.'; }
if (!$consent) {
    $errores[] = 'Necesitamos tu autorización para publicar la fotografía y el comentario.';
}
if ($errores) {
    responder(['status' => 'error', 'message' => implode(' ', $errores)], 422);
}

$pdo = conectar();

// ── Freno de abuso por IP ────────────────────────────────────
$ipHash = hashIp();
$st = $pdo->prepare('SELECT COUNT(*) FROM muro_publicaciones
                      WHERE ip_hash = ? AND creado_en > (NOW() - INTERVAL 1 DAY)');
$st->execute([$ipHash]);
if ((int)$st->fetchColumn() >= MAX_POR_IP_DIA) {
    responder(['status' => 'error', 'message' => 'Has alcanzado el máximo de publicaciones por hoy. Inténtalo mañana.'], 429);
}

// ── Imagen (opcional) ────────────────────────────────────────
$nombreArchivo = null;
$ancho = $alto = null;

if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['foto'];

    if ($f['error'] !== UPLOAD_ERR_OK) {
        responder(['status' => 'error', 'message' => 'La imagen no se subió correctamente. Revisa su tamaño e inténtalo de nuevo.'], 422);
    }
    if ($f['size'] > MAX_BYTES) {
        responder(['status' => 'error', 'message' => 'La imagen supera los 8 MB.'], 422);
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        responder(['status' => 'error', 'message' => 'Archivo inválido.'], 422);
    }

    // El tipo se deduce del contenido real, nunca del nombre ni de lo que diga el navegador.
    $info = @getimagesize($f['tmp_name']);
    if ($info === false || !isset(MIMES[$info['mime']])) {
        responder(['status' => 'error', 'message' => 'Solo se aceptan imágenes JPG, PNG o WebP.'], 422);
    }
    [$origAncho, $origAlto] = $info;
    $mime = $info['mime'];

    if ($origAncho < 200 || $origAlto < 200) {
        responder(['status' => 'error', 'message' => 'La imagen es demasiado pequeña (mínimo 200 x 200 px).'], 422);
    }

    if (!function_exists('imagecreatetruecolor')) {
        responder(['status' => 'error', 'message' => 'El servidor no puede procesar imágenes en este momento.'], 500);
    }

    // Se reconstruye la imagen desde cero. Esto descarta cualquier contenido
    // incrustado en el archivo original y elimina los metadatos EXIF, que
    // suelen incluir la ubicación GPS donde se tomó la fotografía.
    $origen = match ($mime) {
        'image/jpeg' => @imagecreatefromjpeg($f['tmp_name']),
        'image/png'  => @imagecreatefrompng($f['tmp_name']),
        'image/webp' => @imagecreatefromwebp($f['tmp_name']),
    };
    if (!$origen) {
        responder(['status' => 'error', 'message' => 'No se pudo leer la imagen.'], 422);
    }

    $escala = min(1, MAX_LADO / max($origAncho, $origAlto));
    $ancho  = (int)round($origAncho * $escala);
    $alto   = (int)round($origAlto  * $escala);

    $destino = imagecreatetruecolor($ancho, $alto);
    imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
    imagecopyresampled($destino, $origen, 0, 0, 0, 0, $ancho, $alto, $origAncho, $origAlto);
    imagedestroy($origen);

    if (!is_dir(DIR_SUBIDAS) && !@mkdir(DIR_SUBIDAS, 0755, true) && !is_dir(DIR_SUBIDAS)) {
        imagedestroy($destino);
        responder(['status' => 'error', 'message' => 'No se pudo guardar la imagen.'], 500);
    }

    // Nombre generado por el servidor: el nombre original nunca se usa.
    $nombreArchivo = date('Ymd') . '-' . bin2hex(random_bytes(12)) . '.jpg';
    $ok = imagejpeg($destino, DIR_SUBIDAS . $nombreArchivo, 82);
    imagedestroy($destino);

    if (!$ok) {
        responder(['status' => 'error', 'message' => 'No se pudo guardar la imagen.'], 500);
    }
    @chmod(DIR_SUBIDAS . $nombreArchivo, 0644);
}

// ── Guardar como pendiente de revisión ───────────────────────
try {
    $st = $pdo->prepare(
        'INSERT INTO muro_publicaciones
           (nombre, puesto, empresa, comentario, archivo, ancho, alto,
            consentimiento, ip_hash, user_agent, estado)
         VALUES (?,?,?,?,?,?,?,1,?,?, "pendiente")'
    );
    $st->execute([
        $nombre,
        $puesto  !== '' ? $puesto  : null,
        $empresa !== '' ? $empresa : null,
        $comentario,
        $nombreArchivo,
        $ancho,
        $alto,
        $ipHash,
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
} catch (PDOException $e) {
    if ($nombreArchivo) { @unlink(DIR_SUBIDAS . $nombreArchivo); }
    responder(['status' => 'error', 'message' => 'No se pudo registrar tu publicación. Inténtalo más tarde.'], 500);
}

responder([
    'status'  => 'ok',
    'message' => '¡Gracias! Tu publicación quedó registrada y aparecerá en el muro una vez que nuestro equipo la revise.',
]);
