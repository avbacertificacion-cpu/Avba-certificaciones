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
const MAX_BYTES        = 8  * 1024 * 1024;  // 8 MB por fotografía
const MAX_BYTES_VIDEO  = 60 * 1024 * 1024;  // 60 MB por video
const MAX_LADO         = 1600;              // se redimensiona a este lado mayor
const MAX_POR_IP_DIA   = 5;                 // publicaciones por IP cada 24 h
const DIR_SUBIDAS      = __DIR__ . '/../uploads/muro/';

const MIMES = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
];

// Formatos de video aceptados. MOV entra porque es lo que graba un iPhone.
const MIMES_VIDEO = [
    'video/mp4'       => 'mp4',
    'video/webm'      => 'webm',
    'video/quicktime' => 'mov',
];

/**
 * Un video no se puede reconstruir como una imagen, así que su tipo se
 * confirma leyendo la firma del propio archivo en lugar de confiar en la
 * extensión o en lo que declare el navegador.
 */
function tipoDeVideo(string $ruta): ?string {
    $f = @fopen($ruta, 'rb');
    if (!$f) { return null; }
    $cab = fread($f, 16);
    fclose($f);
    if (strlen($cab) < 12) { return null; }

    // WebM / Matroska
    if (substr($cab, 0, 4) === "\x1A\x45\xDF\xA3") { return 'video/webm'; }

    // MP4 y MOV comparten la caja "ftyp" en los bytes 4 a 8
    if (substr($cab, 4, 4) === 'ftyp') {
        $marca = substr($cab, 8, 4);
        return ($marca === 'qt  ') ? 'video/quicktime' : 'video/mp4';
    }
    return null;
}

/** Límite real de subida del servidor, para avisar antes de intentarlo. */
function limiteServidor(): int {
    $aBytes = static function (string $v): int {
        $v = trim($v); $u = strtolower(substr($v, -1)); $n = (int)$v;
        return match ($u) { 'g' => $n * 1073741824, 'm' => $n * 1048576, 'k' => $n * 1024, default => $n };
    };
    return min($aBytes((string)ini_get('upload_max_filesize')), $aBytes((string)ini_get('post_max_size')));
}

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

    // ?aleatorio=1 devuelve una publicación al azar, para la ventana de bienvenida.
    // Solo se consideran las que tienen fotografía: sin imagen no vale la pena mostrarla.
    if (!empty($_GET['aleatorio'])) {
        $sql = 'SELECT id, nombre, puesto, empresa, comentario, tipo, archivo, poster,
                       ancho, alto, duracion, creado_en
                  FROM muro_publicaciones
                 WHERE estado = "aprobado" AND archivo IS NOT NULL
                 ORDER BY RAND()
                 LIMIT :limite';
        $st = $pdo->prepare($sql);
        $st->bindValue(':limite', $limite, PDO::PARAM_INT);
        $st->execute();
    } else {
        $sql = 'SELECT id, nombre, puesto, empresa, comentario, tipo, archivo, poster,
                       ancho, alto, duracion, creado_en
                  FROM muro_publicaciones
                 WHERE estado = "aprobado"
                 ORDER BY creado_en DESC
                 LIMIT :limite OFFSET :desde';
        $st = $pdo->prepare($sql);
        $st->bindValue(':limite', $limite, PDO::PARAM_INT);
        $st->bindValue(':desde',  $desde,  PDO::PARAM_INT);
        $st->execute();
    }

    $filas = array_map(static function (array $f): array {
        return [
            'id'         => (int)$f['id'],
            'nombre'     => $f['nombre'],
            'puesto'     => $f['puesto'],
            'empresa'    => $f['empresa'],
            'comentario' => $f['comentario'],
            'tipo'       => $f['tipo'] ?? 'imagen',
            // 'imagen' es lo que se muestra en la galería: la fotografía, o
            // la miniatura si se trata de un video.
            'imagen'     => $f['poster'] ? 'uploads/muro/' . $f['poster']
                                         : ($f['archivo'] ? 'uploads/muro/' . $f['archivo'] : null),
            'video'      => (($f['tipo'] ?? '') === 'video' && $f['archivo']) ? 'uploads/muro/' . $f['archivo'] : null,
            'ancho'      => $f['ancho'] ? (int)$f['ancho'] : null,
            'alto'       => $f['alto'] ? (int)$f['alto'] : null,
            'duracion'   => isset($f['duracion']) && $f['duracion'] ? (int)$f['duracion'] : null,
            'fecha'      => $f['creado_en'],
        ];
    }, $st->fetchAll());

    $total = (int)$pdo->query('SELECT COUNT(*) FROM muro_publicaciones WHERE estado = "aprobado"')->fetchColumn();

    responder([
        'status' => 'ok',
        'total'  => $total,
        'limites' => [
            'imagen'   => MAX_BYTES,
            'video'    => min(MAX_BYTES_VIDEO, limiteServidor()),
            'servidor' => limiteServidor(),
        ],
        'publicaciones' => $filas,
    ]);
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

// ── Fotografía o video (opcional) ────────────────────────────
$nombreArchivo = null;
$nombrePoster  = null;
$tipoMedio     = 'imagen';
$ancho = $alto = $duracion = null;

if (isset($_FILES['foto']) && $_FILES['foto']['error'] !== UPLOAD_ERR_NO_FILE) {
    $f = $_FILES['foto'];

    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE) {
        $mb = round(limiteServidor() / 1048576, 1);
        responder(['status' => 'error',
                   'message' => "El archivo supera lo que acepta el servidor ({$mb} MB). Prueba con un video más corto."], 422);
    }
    if ($f['error'] !== UPLOAD_ERR_OK) {
        responder(['status' => 'error', 'message' => 'El archivo no se subió correctamente. Inténtalo de nuevo.'], 422);
    }
    if (!is_uploaded_file($f['tmp_name'])) {
        responder(['status' => 'error', 'message' => 'Archivo inválido.'], 422);
    }

    // El tipo se deduce del contenido real, nunca del nombre ni de lo que diga el navegador.
    $info      = @getimagesize($f['tmp_name']);
    $esImagen  = $info !== false && isset(MIMES[$info['mime']]);
    $mimeVideo = $esImagen ? null : tipoDeVideo($f['tmp_name']);

    if (!$esImagen && !$mimeVideo) {
        responder(['status' => 'error',
                   'message' => 'Solo se aceptan imágenes JPG, PNG o WebP, o videos MP4, WebM o MOV.'], 422);
    }

    // ── Video ────────────────────────────────────────────────
    if ($mimeVideo) {
        $tipoMedio = 'video';

        if ($f['size'] > MAX_BYTES_VIDEO) {
            $mb = round(MAX_BYTES_VIDEO / 1048576);
            responder(['status' => 'error', 'message' => "El video supera los {$mb} MB."], 422);
        }

        // Un video no se puede reconstruir como una imagen, así que se guarda
        // tal cual. Lo que impide que se ejecute es el .htaccess de uploads/,
        // que desactiva PHP en ese directorio.
        if (!is_dir(DIR_SUBIDAS) && !@mkdir(DIR_SUBIDAS, 0755, true) && !is_dir(DIR_SUBIDAS)) {
            responder(['status' => 'error', 'message' => 'No se pudo guardar el video.'], 500);
        }

        $ext = MIMES_VIDEO[$mimeVideo];
        $nombreArchivo = date('Ymd') . '-' . bin2hex(random_bytes(12)) . '.' . $ext;
        if (!@move_uploaded_file($f['tmp_name'], DIR_SUBIDAS . $nombreArchivo)) {
            responder(['status' => 'error', 'message' => 'No se pudo guardar el video.'], 500);
        }
        @chmod(DIR_SUBIDAS . $nombreArchivo, 0644);

        $duracion = isset($_POST['duracion']) ? max(0, min(65535, (int)$_POST['duracion'])) : null;

        // El navegador manda la miniatura del primer fotograma: así la galería
        // no tiene que descargar el video entero solo para mostrarlo.
        if (isset($_FILES['poster']) && $_FILES['poster']['error'] === UPLOAD_ERR_OK) {
            $pi = @getimagesize($_FILES['poster']['tmp_name']);
            if ($pi !== false && isset(MIMES[$pi['mime']]) && $_FILES['poster']['size'] <= MAX_BYTES) {
                $origen = match ($pi['mime']) {
                    'image/jpeg' => @imagecreatefromjpeg($_FILES['poster']['tmp_name']),
                    'image/png'  => @imagecreatefrompng($_FILES['poster']['tmp_name']),
                    'image/webp' => @imagecreatefromwebp($_FILES['poster']['tmp_name']),
                };
                if ($origen) {
                    $escala = min(1, MAX_LADO / max($pi[0], $pi[1]));
                    $ancho  = (int)round($pi[0] * $escala);
                    $alto   = (int)round($pi[1] * $escala);
                    $destino = imagecreatetruecolor($ancho, $alto);
                    imagefill($destino, 0, 0, imagecolorallocate($destino, 255, 255, 255));
                    imagecopyresampled($destino, $origen, 0, 0, 0, 0, $ancho, $alto, $pi[0], $pi[1]);
                    imagedestroy($origen);
                    $nombrePoster = date('Ymd') . '-' . bin2hex(random_bytes(12)) . '-p.jpg';
                    if (imagejpeg($destino, DIR_SUBIDAS . $nombrePoster, 82)) {
                        @chmod(DIR_SUBIDAS . $nombrePoster, 0644);
                    } else {
                        $nombrePoster = null;
                        $ancho = $alto = null;
                    }
                    imagedestroy($destino);
                }
            }
        }

    // ── Fotografía ───────────────────────────────────────────
    } else {
        if ($f['size'] > MAX_BYTES) {
            responder(['status' => 'error', 'message' => 'La imagen supera los 8 MB.'], 422);
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
}

// ── Guardar como pendiente de revisión ───────────────────────
try {
    $st = $pdo->prepare(
        'INSERT INTO muro_publicaciones
           (nombre, puesto, empresa, comentario, tipo, archivo, poster,
            ancho, alto, duracion, consentimiento, ip_hash, user_agent, estado)
         VALUES (?,?,?,?,?,?,?,?,?,?,1,?,?, "pendiente")'
    );
    $st->execute([
        $nombre,
        $puesto  !== '' ? $puesto  : null,
        $empresa !== '' ? $empresa : null,
        $comentario,
        $tipoMedio,
        $nombreArchivo,
        $nombrePoster,
        $ancho,
        $alto,
        $duracion,
        $ipHash,
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
} catch (PDOException $e) {
    if ($nombreArchivo) { @unlink(DIR_SUBIDAS . $nombreArchivo); }
    if ($nombrePoster)  { @unlink(DIR_SUBIDAS . $nombrePoster); }
    responder(['status' => 'error', 'message' => 'No se pudo registrar tu publicación. Inténtalo más tarde.'], 500);
}

responder([
    'status'  => 'ok',
    'message' => '¡Gracias! Tu publicación quedó registrada y aparecerá en el muro una vez que nuestro equipo la revise.',
]);
