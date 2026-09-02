<?php
/**
 * Evidencia fotográfica de los reportes mensuales (solo ADMIN).
 *
 * Es un módulo opcional que se habilita por planta desde la pantalla de
 * empresas (`empresas.requiere_fotos`). Cuando está activo, al generar el
 * reporte se piden hasta 9 fotografías, que se imprimen al final del reporte.
 *
 * Las imágenes se reescalan al subirlas para que el reporte no pese de más,
 * y se guardan con un nombre imposible de adivinar.
 */
require_once '../config/config.php';

header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); echo json_encode(['error' => 'No autenticado']); exit;
}
if ($_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403); echo json_encode(['error' => 'Sin permiso']); exit;
}

$uid = $_SESSION['usuario_id'];
$DIR = __DIR__ . '/../uploads/reportes/';

const MAX_FOTOS   = 9;
const MAX_BYTES   = 12582912; // 12 MB por archivo antes de reescalar
const LADO_MAXIMO = 1400;     // px: suficiente para imprimir, sin inflar el reporte

asegurarTablaFotos($pdo);
if (!is_dir($DIR)) @mkdir($DIR, 0755, true);

switch ($_GET['action'] ?? '') {
    case 'listar':   listar();   break;
    case 'subir':    subir();    break;
    case 'eliminar': eliminar(); break;
    default:
        http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
}

// ─── Migración ligera ────────────────────────────────────────────────────────
function asegurarTablaFotos($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS reporte_fotos (
                id           INT AUTO_INCREMENT PRIMARY KEY,
                reporte_id   INT NOT NULL,
                archivo      VARCHAR(255) NOT NULL,
                descripcion  VARCHAR(255) DEFAULT NULL,
                orden        INT NOT NULL DEFAULT 0,
                subido_por   INT DEFAULT NULL,
                created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_foto_reporte (reporte_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { /* las acciones reportarán el error */ }
}

function reporteValido(PDO $pdo, int $id): bool {
    $st = $pdo->prepare("SELECT id FROM reportes_mensuales WHERE id = ?");
    $st->execute([$id]);
    return (bool) $st->fetchColumn();
}

// ─── Listado ─────────────────────────────────────────────────────────────────
function listar() {
    global $pdo;
    $rid = intval($_GET['reporte_id'] ?? 0);
    if (!$rid) { http_response_code(400); echo json_encode(['error' => 'Reporte requerido']); return; }

    $st = $pdo->prepare("
        SELECT id, archivo, descripcion, orden, created_at
        FROM reporte_fotos WHERE reporte_id = ? ORDER BY orden, id
    ");
    $st->execute([$rid]);
    echo json_encode([
        'success' => true,
        'maximo'  => MAX_FOTOS,
        'data'    => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ─── Subida ──────────────────────────────────────────────────────────────────
function subir() {
    global $pdo, $uid, $DIR;
    $rid = intval($_POST['reporte_id'] ?? ($_GET['reporte_id'] ?? 0));
    if (!$rid || !reporteValido($pdo, $rid)) {
        http_response_code(400); echo json_encode(['error' => 'Reporte no válido']); return;
    }

    $st = $pdo->prepare("SELECT COUNT(*) FROM reporte_fotos WHERE reporte_id = ?");
    $st->execute([$rid]);
    $yaTiene = (int) $st->fetchColumn();
    if ($yaTiene >= MAX_FOTOS) {
        http_response_code(400);
        echo json_encode(['error' => 'Este reporte ya tiene las ' . MAX_FOTOS . ' fotografías permitidas.']);
        return;
    }

    $file = $_FILES['foto'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400); echo json_encode(['error' => 'No se recibió la fotografía.']); return;
    }
    if ($file['size'] > MAX_BYTES) {
        http_response_code(400); echo json_encode(['error' => 'La fotografía pesa más de 12 MB.']); return;
    }

    $info = @getimagesize($file['tmp_name']);
    if (!$info) { http_response_code(400); echo json_encode(['error' => 'El archivo no es una imagen válida.']); return; }

    $nombre = 'rep' . $rid . '_' . bin2hex(random_bytes(10)) . '.jpg';
    if (!guardarComoJpeg($file['tmp_name'], $info[2], $DIR . $nombre)) {
        http_response_code(400);
        echo json_encode(['error' => 'No se pudo procesar la imagen (formato no soportado).']);
        return;
    }

    $desc = trim($_POST['descripcion'] ?? '');
    if (mb_strlen($desc) > 255) $desc = mb_substr($desc, 0, 255);

    try {
        $st = $pdo->prepare("
            INSERT INTO reporte_fotos (reporte_id, archivo, descripcion, orden, subido_por)
            VALUES (?,?,?,?,?)
        ");
        $st->execute([$rid, $nombre, $desc ?: null, $yaTiene, $uid]);
        audit($uid, "Subir foto al reporte #$rid", 'reporte_fotos', $pdo->lastInsertId());
        echo json_encode(['success' => true, 'restantes' => MAX_FOTOS - ($yaTiene + 1)]);
    } catch (Exception $e) {
        @unlink($DIR . $nombre); // sin registro no debe quedar el archivo suelto
        http_response_code(500); echo json_encode(['error' => 'No se pudo registrar la foto: ' . $e->getMessage()]);
    }
}

/**
 * Reescala la imagen y la guarda como JPEG. Las fotos de un celular pesan
 * varios MB cada una; con 9 por reporte, sin reescalar el reporte se vuelve
 * imposible de abrir e imprimir.
 */
function guardarComoJpeg(string $origen, int $tipo, string $destino): bool {
    switch ($tipo) {
        case IMAGETYPE_JPEG: $src = @imagecreatefromjpeg($origen); break;
        case IMAGETYPE_PNG:  $src = @imagecreatefrompng($origen);  break;
        case IMAGETYPE_GIF:  $src = @imagecreatefromgif($origen);  break;
        case IMAGETYPE_WEBP: $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($origen) : false; break;
        default:             $src = false;
    }
    if (!$src) return false;

    $ancho = imagesx($src);
    $alto  = imagesy($src);
    $escala = min(1, LADO_MAXIMO / max($ancho, $alto));
    $nuevoAncho = max(1, (int) round($ancho * $escala));
    $nuevoAlto  = max(1, (int) round($alto  * $escala));

    $dst = imagecreatetruecolor($nuevoAncho, $nuevoAlto);
    // Fondo blanco: las PNG con transparencia quedarían negras al pasar a JPEG
    imagefilledrectangle($dst, 0, 0, $nuevoAncho, $nuevoAlto, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nuevoAncho, $nuevoAlto, $ancho, $alto);

    $ok = imagejpeg($dst, $destino, 82);
    imagedestroy($src);
    imagedestroy($dst);
    return $ok;
}

// ─── Eliminar ────────────────────────────────────────────────────────────────
function eliminar() {
    global $pdo, $uid, $DIR;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $st = $pdo->prepare("SELECT archivo, reporte_id FROM reporte_fotos WHERE id = ?");
    $st->execute([$id]);
    $foto = $st->fetch(PDO::FETCH_ASSOC);
    if (!$foto) { http_response_code(404); echo json_encode(['error' => 'Fotografía no encontrada']); return; }

    $pdo->prepare("DELETE FROM reporte_fotos WHERE id = ?")->execute([$id]);
    $ruta = $DIR . basename($foto['archivo']);
    if (is_file($ruta)) @unlink($ruta);

    audit($uid, "Eliminar foto del reporte #{$foto['reporte_id']}", 'reporte_fotos', $id);
    echo json_encode(['success' => true]);
}

// ─── Auditoría ───────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $st = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $st->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
