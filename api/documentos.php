<?php
/**
 * Documentos adjuntos (evidencias, facturas, remisiones, órdenes firmadas…)
 *
 * Es genérico: se cuelga de cualquier registro mediante módulo + id, así que
 * sirve igual para una cotización que para una orden de compra.
 *
 * Los archivos se guardan con un nombre imposible de adivinar y se sirven
 * SIEMPRE desde aquí, nunca por enlace directo, para que no queden expuestos.
 */
require_once '../config/config.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No autenticado']); exit;
}
if ($_SESSION['rol'] !== ROLE_ADMIN) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Sin permiso']); exit;
}

$uid = $_SESSION['usuario_id'];
$DIR = __DIR__ . '/../uploads/documentos/';

// Módulos a los que se les pueden colgar documentos
const MODULOS = ['cotizacion', 'orden_compra'];
// Tipos de documento que maneja el negocio
const TIPOS = ['factura', 'evidencia', 'orden_compra', 'remision', 'cotizacion_proveedor', 'otro'];

/**
 * Extensiones permitidas y su MIME real.
 * Se incluye XML porque las facturas mexicanas (CFDI) viajan en PDF + XML.
 */
const PERMITIDOS = [
    'pdf'  => 'application/pdf',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png'  => 'image/png',
    'webp' => 'image/webp',
    'gif'  => 'image/gif',
    'xml'  => 'application/xml',
    'txt'  => 'text/plain',
    'csv'  => 'text/csv',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'xls'  => 'application/vnd.ms-excel',
    'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
];
const MAX_BYTES = 15728640; // 15 MB

asegurarTablaDocumentos($pdo);
asegurarCarpeta($DIR);

$action = $_GET['action'] ?? '';

// La descarga responde con el archivo, no con JSON
if ($action === 'descargar') { descargar(); exit; }

header('Content-Type: application/json');
switch ($action) {
    case 'listar':   listar();   break;
    case 'subir':    subir();    break;
    case 'eliminar': eliminar(); break;
    case 'conteos':  conteos();  break;
    default:
        http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
}

// ─── Migración ligera ────────────────────────────────────────────────────────
function asegurarTablaDocumentos($pdo) {
    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS documentos (
                id              INT AUTO_INCREMENT PRIMARY KEY,
                modulo          VARCHAR(30)  NOT NULL,
                registro_id     INT          NOT NULL,
                tipo            VARCHAR(30)  NOT NULL DEFAULT 'otro',
                descripcion     VARCHAR(255) DEFAULT NULL,
                archivo         VARCHAR(255) NOT NULL,
                nombre_original VARCHAR(255) NOT NULL,
                mime            VARCHAR(120) DEFAULT NULL,
                tamano          INT NOT NULL DEFAULT 0,
                subido_por      INT DEFAULT NULL,
                created_at      DATETIME DEFAULT CURRENT_TIMESTAMP,
                KEY idx_doc_reg (modulo, registro_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    } catch (Exception $e) { /* las acciones reportarán el error */ }
}

/** Crea la carpeta y la blinda para que nadie baje archivos por enlace directo. */
function asegurarCarpeta($dir) {
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    $ht = $dir . '.htaccess';
    if (!is_file($ht)) {
        @file_put_contents($ht,
            "# Los documentos solo se entregan por api/documentos.php (valida la sesión)\n" .
            "<IfModule mod_authz_core.c>\n    Require all denied\n</IfModule>\n" .
            "<IfModule !mod_authz_core.c>\n    Order allow,deny\n    Deny from all\n</IfModule>\n");
    }
    if (!is_file($dir . 'index.html')) @file_put_contents($dir . 'index.html', '');
}

// ─── Listado ─────────────────────────────────────────────────────────────────
function contexto(): array {
    $modulo = $_GET['modulo'] ?? ($_POST['modulo'] ?? '');
    $reg    = intval($_GET['registro_id'] ?? ($_POST['registro_id'] ?? 0));
    if (!in_array($modulo, MODULOS, true) || $reg <= 0) return ['', 0];
    return [$modulo, $reg];
}

function listar() {
    global $pdo;
    [$modulo, $reg] = contexto();
    if (!$modulo) { http_response_code(400); echo json_encode(['error' => 'Registro no válido']); return; }

    $stmt = $pdo->prepare("
        SELECT d.id, d.tipo, d.descripcion, d.nombre_original, d.mime, d.tamano, d.created_at,
               u.nombre AS subido_por_nombre
        FROM documentos d
        LEFT JOIN usuarios u ON u.id = d.subido_por
        WHERE d.modulo = ? AND d.registro_id = ?
        ORDER BY d.created_at DESC, d.id DESC
    ");
    $stmt->execute([$modulo, $reg]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

/** Cuántos documentos tiene cada registro de un módulo, para pintar el contador. */
function conteos() {
    global $pdo;
    $modulo = $_GET['modulo'] ?? '';
    if (!in_array($modulo, MODULOS, true)) { http_response_code(400); echo json_encode(['error' => 'Módulo no válido']); return; }
    $stmt = $pdo->prepare("SELECT registro_id, COUNT(*) AS n FROM documentos WHERE modulo = ? GROUP BY registro_id");
    $stmt->execute([$modulo]);
    $out = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['registro_id']] = intval($r['n']);
    echo json_encode(['success' => true, 'data' => $out]);
}

// ─── Subida ──────────────────────────────────────────────────────────────────
function subir() {
    global $pdo, $uid, $DIR;
    [$modulo, $reg] = contexto();
    if (!$modulo) { http_response_code(400); echo json_encode(['error' => 'Registro no válido']); return; }

    $file = $_FILES['archivo'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        http_response_code(400);
        echo json_encode(['error' => mensajeErrorSubida($file['error'] ?? UPLOAD_ERR_NO_FILE)]);
        return;
    }
    if ($file['size'] > MAX_BYTES) {
        http_response_code(400);
        echo json_encode(['error' => 'El archivo pesa más de 15 MB. Comprímelo o súbelo en partes.']);
        return;
    }

    $original = (string) $file['name'];
    $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
    if (!isset(PERMITIDOS[$ext])) {
        http_response_code(400);
        echo json_encode(['error' => 'Tipo de archivo no permitido. Se aceptan PDF, imágenes, XML, Word, Excel, TXT y CSV.']);
        return;
    }
    // Si es imagen, se comprueba que de verdad lo sea (no basta la extensión)
    if (in_array($ext, ['jpg','jpeg','png','webp','gif'], true) && !@getimagesize($file['tmp_name'])) {
        http_response_code(400);
        echo json_encode(['error' => 'El archivo dice ser una imagen pero no lo es.']);
        return;
    }

    $tipo = in_array($_POST['tipo'] ?? '', TIPOS, true) ? $_POST['tipo'] : 'otro';
    $desc = trim($_POST['descripcion'] ?? '');
    if (mb_strlen($desc) > 255) $desc = mb_substr($desc, 0, 255);

    if (!is_dir($DIR)) @mkdir($DIR, 0755, true);
    // Nombre en el disco: imposible de adivinar y sin nada del nombre original
    $nombreDisco = $modulo . '_' . $reg . '_' . bin2hex(random_bytes(12)) . '.' . $ext;

    if (!move_uploaded_file($file['tmp_name'], $DIR . $nombreDisco)) {
        http_response_code(500);
        echo json_encode(['error' => 'No se pudo guardar el archivo en el servidor.']);
        return;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO documentos (modulo, registro_id, tipo, descripcion, archivo, nombre_original, mime, tamano, subido_por)
            VALUES (?,?,?,?,?,?,?,?,?)
        ");
        $stmt->execute([
            $modulo, $reg, $tipo, $desc ?: null, $nombreDisco,
            mb_substr(basename($original), 0, 255),
            PERMITIDOS[$ext], intval($file['size']), $uid,
        ]);
        $id = $pdo->lastInsertId();
        audit($uid, "Subir documento ($tipo) a $modulo #$reg", 'documentos', $id);
        echo json_encode(['success' => true, 'id' => $id]);
    } catch (Exception $e) {
        @unlink($DIR . $nombreDisco); // sin registro no debe quedar el archivo suelto
        http_response_code(500);
        echo json_encode(['error' => 'Error al registrar el documento: ' . $e->getMessage()]);
    }
}

function mensajeErrorSubida($code) {
    switch ($code) {
        case UPLOAD_ERR_NO_FILE:   return 'Elige un archivo antes de subir.';
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE: return 'El archivo excede el tamaño máximo que acepta el servidor.';
        case UPLOAD_ERR_PARTIAL:   return 'La subida se interrumpió. Inténtalo de nuevo.';
        case UPLOAD_ERR_NO_TMP_DIR:
        case UPLOAD_ERR_CANT_WRITE: return 'El servidor no pudo escribir el archivo temporal.';
        default: return 'No se pudo subir el archivo.';
    }
}

// ─── Descarga / vista previa ─────────────────────────────────────────────────
function descargar() {
    global $pdo, $DIR;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo 'ID requerido'; return; }

    $stmt = $pdo->prepare("SELECT * FROM documentos WHERE id = ?");
    $stmt->execute([$id]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$d) { http_response_code(404); echo 'Documento no encontrado'; return; }

    $ruta = $DIR . basename($d['archivo']);
    if (!is_file($ruta)) { http_response_code(404); echo 'El archivo ya no está en el servidor'; return; }

    // inline para ver PDFs e imágenes en el navegador; attachment para bajarlos
    $inline = isset($_GET['inline']) && $_GET['inline'] === '1';
    $verEnLinea = in_array($d['mime'], ['application/pdf','image/jpeg','image/png','image/webp','image/gif','text/plain'], true);
    $disp = ($inline && $verEnLinea) ? 'inline' : 'attachment';

    $nombre = str_replace(['"', "\r", "\n"], '', $d['nombre_original']);
    header('Content-Type: ' . ($d['mime'] ?: 'application/octet-stream'));
    header('Content-Length: ' . filesize($ruta));
    header('Content-Disposition: ' . $disp . '; filename="' . $nombre . '"');
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, max-age=0, must-revalidate');
    readfile($ruta);
}

// ─── Eliminar ────────────────────────────────────────────────────────────────
function eliminar() {
    global $pdo, $uid, $DIR;
    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $stmt = $pdo->prepare("SELECT archivo, modulo, registro_id FROM documentos WHERE id = ?");
    $stmt->execute([$id]);
    $d = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$d) { http_response_code(404); echo json_encode(['error' => 'Documento no encontrado']); return; }

    $pdo->prepare("DELETE FROM documentos WHERE id = ?")->execute([$id]);
    $ruta = $DIR . basename($d['archivo']);
    if (is_file($ruta)) @unlink($ruta);

    audit($uid, "Eliminar documento de {$d['modulo']} #{$d['registro_id']}", 'documentos', $id);
    echo json_encode(['success' => true]);
}

// ─── Auditoría ───────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
