<?php
/**
 * Puntos para rótulos: alta con GPS, fotos de referencia y comentarios.
 *
 * Lo usan el administrador y los inspectores; borrar un punto completo es
 * sólo del administrador. Una foto o un comentario los puede borrar quien
 * los subió o el administrador.
 *
 * Cada alta trae un cliente_uuid que genera el teléfono. Si la señal se cae
 * después de que el servidor guardó pero antes de que el teléfono reciba la
 * respuesta, el teléfono lo vuelve a mandar; con el mismo uuid se devuelve
 * lo ya guardado en lugar de duplicarlo.
 */
require_once '../config/config.php';
require_once '../config/mayusculas.php';
require_once '../config/rotulos.php';

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Tu sesión terminó. Vuelve a iniciar sesión.']); exit;
}
$rol = $_SESSION['rol'];
$uid = (int) $_SESSION['usuario_id'];
if (!in_array($rol, [ROLE_ADMIN, ROLE_INSPECTOR], true)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Sin permiso']); exit;
}

try {
    asegurarTablasRotulos($pdo);
} catch (Exception $e) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No se pudieron preparar las tablas de rótulos.']); exit;
}
$DIR = carpetaRotulos();

$action = $_GET['action'] ?? '';
// La foto responde con la imagen, no con JSON
if ($action === 'foto') { servirFoto(); exit; }

header('Content-Type: application/json');
try {
    switch ($action) {
        case 'listar':               listar();             break;
        case 'obtener':              obtener();            break;
        case 'guardar':              guardar();            break;
        case 'subir_foto':           subirFoto();          break;
        case 'eliminar_foto':        eliminarFoto();       break;
        case 'comentar':             comentar();           break;
        case 'eliminar_comentario':  eliminarComentario(); break;
        case 'estado':               cambiarEstado();      break;
        case 'eliminar':             eliminar();           break;
        default:
            http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
    }
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Error del servidor: ' . $e->getMessage()]);
}

// ─── Utilidades ──────────────────────────────────────────────────────────────
function entrada(): array {
    $d = json_decode(file_get_contents('php://input'), true);
    return is_array($d) ? $d : [];
}

function falla(int $codigo, string $mensaje): void {
    http_response_code($codigo);
    echo json_encode(['error' => $mensaje]);
}

/** Un uuid del teléfono: letras, números y guiones; si no, no se usa. */
function uuidCliente($v): ?string {
    $v = is_string($v) ? trim($v) : '';
    return preg_match('/^[A-Za-z0-9-]{8,40}$/', $v) ? $v : null;
}

/** Un número dentro de un rango, o null. */
function numeroEn($v, float $min, float $max): ?float {
    if ($v === null || $v === '' || !is_numeric($v)) return null;
    $n = (float) $v;
    return ($n >= $min && $n <= $max) ? $n : null;
}

function texto($v, int $max): ?string {
    $v = is_string($v) ? trim(preg_replace('/\s+/u', ' ', $v)) : '';
    if ($v === '') return null;
    return aMayusculas(mb_substr($v, 0, $max));
}

function punto(int $id): ?array {
    global $pdo;
    $st = $pdo->prepare("SELECT * FROM rotulos_puntos WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)");
        $stmt->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}

// ─── Listado de una planta ───────────────────────────────────────────────────
function listar() {
    global $pdo;
    $empresaId = intval($_GET['empresa_id'] ?? 0);
    if (!$empresaId) { falla(400, 'Elige una planta.'); return; }

    $st = $pdo->prepare("
        SELECT p.id, p.empresa_id, p.numero, p.rotulo, p.ubicacion, p.lat, p.lng, p.precision_m,
               p.estado, p.colocado_at, p.created_at, p.updated_at, u.nombre AS creado_por_nombre,
               (SELECT COUNT(*) FROM rotulos_fotos f WHERE f.punto_id = p.id) AS fotos,
               (SELECT MIN(f.id) FROM rotulos_fotos f WHERE f.punto_id = p.id AND f.etapa = 'referencia') AS foto_id,
               (SELECT COUNT(*) FROM rotulos_comentarios c WHERE c.punto_id = p.id) AS comentarios
        FROM rotulos_puntos p
        LEFT JOIN usuarios u ON u.id = p.creado_por
        WHERE p.empresa_id = ?
        ORDER BY (p.numero IS NULL), LENGTH(p.numero), p.numero, p.id
    ");
    $st->execute([$empresaId]);
    echo json_encode(['success' => true, 'data' => $st->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── Un punto con sus fotos y comentarios ────────────────────────────────────
function obtener() {
    global $pdo;
    $id = intval($_GET['id'] ?? 0);
    $st = $pdo->prepare("
        SELECT p.*, e.nombre AS empresa_nombre, u.nombre AS creado_por_nombre, uc.nombre AS colocado_por_nombre
        FROM rotulos_puntos p
        JOIN empresas e      ON e.id = p.empresa_id
        LEFT JOIN usuarios u  ON u.id = p.creado_por
        LEFT JOIN usuarios uc ON uc.id = p.colocado_por
        WHERE p.id = ?
    ");
    $st->execute([$id]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) { falla(404, 'El punto ya no existe.'); return; }
    unset($p['cliente_uuid']);

    $st = $pdo->prepare("
        SELECT f.id, f.etapa, f.ancho, f.alto, f.subido_por, f.created_at, u.nombre AS subido_por_nombre
        FROM rotulos_fotos f LEFT JOIN usuarios u ON u.id = f.subido_por
        WHERE f.punto_id = ? ORDER BY f.etapa = 'colocado', f.id
    ");
    $st->execute([$id]);
    $p['fotos'] = $st->fetchAll(PDO::FETCH_ASSOC);

    $st = $pdo->prepare("
        SELECT c.id, c.texto, c.usuario_id, c.created_at, u.nombre AS autor
        FROM rotulos_comentarios c LEFT JOIN usuarios u ON u.id = c.usuario_id
        WHERE c.punto_id = ? ORDER BY c.created_at, c.id
    ");
    $st->execute([$id]);
    $p['comentarios'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $p['mapa'] = enlaceMapa($p['lat'], $p['lng']);
    echo json_encode(['success' => true, 'data' => $p]);
}

// ─── Alta o edición ──────────────────────────────────────────────────────────
function guardar() {
    global $pdo, $uid;
    $d  = entrada();
    $id = intval($d['id'] ?? 0);

    $numero    = texto($d['numero'] ?? '', 40);
    $rotulo    = texto($d['rotulo'] ?? '', 120);
    $ubicacion = texto($d['ubicacion'] ?? '', 255);
    $lat       = numeroEn($d['lat'] ?? null, -90, 90);
    $lng       = numeroEn($d['lng'] ?? null, -180, 180);
    $precision = numeroEn($d['precision_m'] ?? null, 0, 100000);
    $traeGps   = array_key_exists('lat', $d) || array_key_exists('lng', $d);
    if ($traeGps && ($lat === null) !== ($lng === null)) { falla(400, 'La ubicación GPS llegó incompleta.'); return; }

    if ($id) {
        $p = punto($id);
        if (!$p) { falla(404, 'El punto ya no existe.'); return; }
        $sets = ['numero = ?', 'rotulo = ?', 'ubicacion = ?'];
        $vals = [$numero, $rotulo, $ubicacion];
        // La posición sólo cambia si se volvió a tomar
        if ($traeGps) {
            $sets[] = 'lat = ?'; $sets[] = 'lng = ?'; $sets[] = 'precision_m = ?'; $sets[] = 'gps_at = ' . ($lat === null ? 'NULL' : 'NOW()');
            array_push($vals, $lat, $lng, $lat === null ? null : $precision);
        }
        $vals[] = $id;
        $pdo->prepare("UPDATE rotulos_puntos SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        audit($uid, "Editar punto de rótulo #$id", 'rotulos_puntos', $id);
        echo json_encode(['success' => true, 'id' => $id]);
        return;
    }

    $empresaId = intval($d['empresa_id'] ?? 0);
    $st = $pdo->prepare("SELECT id FROM empresas WHERE id = ?");
    $st->execute([$empresaId]);
    if (!$st->fetchColumn()) { falla(400, 'Elige una planta.'); return; }

    $uuid = uuidCliente($d['cliente_uuid'] ?? null);
    if ($uuid) {
        $st = $pdo->prepare("SELECT id FROM rotulos_puntos WHERE cliente_uuid = ?");
        $st->execute([$uuid]);
        if ($ya = $st->fetchColumn()) { echo json_encode(['success' => true, 'id' => (int) $ya, 'repetido' => true]); return; }
    }

    $pdo->beginTransaction();
    try {
        $pdo->prepare("
            INSERT INTO rotulos_puntos (empresa_id, numero, rotulo, ubicacion, lat, lng, precision_m, gps_at, cliente_uuid, creado_por)
            VALUES (?,?,?,?,?,?,?,?,?,?)
        ")->execute([$empresaId, $numero, $rotulo, $ubicacion, $lat, $lng, $lat === null ? null : $precision,
                     $lat === null ? null : date('Y-m-d H:i:s'), $uuid, $uid]);
        $id = (int) $pdo->lastInsertId();

        $nota = texto($d['comentario'] ?? '', 2000);
        if ($nota) {
            $pdo->prepare("INSERT INTO rotulos_comentarios (punto_id, texto, usuario_id) VALUES (?,?,?)")
                ->execute([$id, $nota, $uid]);
        }
        $pdo->commit();
    } catch (Exception $e) {
        $pdo->rollBack();
        throw $e;
    }
    audit($uid, "Alta de punto de rótulo #$id", 'rotulos_puntos', $id);
    echo json_encode(['success' => true, 'id' => $id]);
}

// ─── Fotos ───────────────────────────────────────────────────────────────────
function subirFoto() {
    global $pdo, $uid, $DIR;
    $puntoId = intval($_POST['punto_id'] ?? 0);
    if (!punto($puntoId)) { falla(404, 'El punto ya no existe.'); return; }
    $etapa = ($_POST['etapa'] ?? '') === 'colocado' ? 'colocado' : 'referencia';

    $uuid = uuidCliente($_POST['cliente_uuid'] ?? null);
    if ($uuid) {
        $st = $pdo->prepare("SELECT id FROM rotulos_fotos WHERE cliente_uuid = ?");
        $st->execute([$uuid]);
        if ($ya = $st->fetchColumn()) { echo json_encode(['success' => true, 'id' => (int) $ya, 'repetido' => true]); return; }
    }

    $file = $_FILES['foto'] ?? null;
    if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $err = $file['error'] ?? UPLOAD_ERR_NO_FILE;
        falla(400, in_array($err, [UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE], true)
            ? 'La foto pesa más de lo que acepta el servidor.' : 'No llegó la foto. Inténtalo de nuevo.');
        return;
    }
    if ($file['size'] > 15 * 1024 * 1024) { falla(400, 'La foto pesa más de 15 MB.'); return; }

    try {
        $g = guardarFotoRotulo($file['tmp_name'], $DIR, $puntoId);
    } catch (RuntimeException $e) {
        falla(400, $e->getMessage()); return;
    }
    try {
        $pdo->prepare("
            INSERT INTO rotulos_fotos (punto_id, etapa, archivo, mini, ancho, alto, tamano, cliente_uuid, subido_por)
            VALUES (?,?,?,?,?,?,?,?,?)
        ")->execute([$puntoId, $etapa, $g['archivo'], $g['mini'], $g['ancho'], $g['alto'], $g['tamano'], $uuid, $uid]);
    } catch (Exception $e) {
        borrarArchivosFotos([$g], $DIR);   // sin registro no debe quedar el archivo suelto
        throw $e;
    }
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE rotulos_puntos SET updated_at = NOW() WHERE id = ?")->execute([$puntoId]);
    audit($uid, "Foto ($etapa) al punto de rótulo #$puntoId", 'rotulos_fotos', $id);
    echo json_encode(['success' => true, 'id' => $id]);
}

function servirFoto() {
    global $pdo, $DIR;
    $st = $pdo->prepare("SELECT archivo, mini FROM rotulos_fotos WHERE id = ?");
    $st->execute([intval($_GET['id'] ?? 0)]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) { http_response_code(404); echo 'Foto no encontrada'; return; }

    $archivo = (!empty($_GET['mini']) && $f['mini']) ? $f['mini'] : $f['archivo'];
    $ruta = $DIR . basename($archivo);
    if (!is_file($ruta)) { http_response_code(404); echo 'La foto ya no está en el servidor'; return; }

    $mime = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][strtolower(pathinfo($ruta, PATHINFO_EXTENSION))] ?? 'image/jpeg';
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . filesize($ruta));
    header('X-Content-Type-Options: nosniff');
    // Una foto nunca cambia: si se reemplaza, es otra con otro id
    header('Cache-Control: private, max-age=2592000, immutable');
    readfile($ruta);
}

function eliminarFoto() {
    global $pdo, $uid, $rol, $DIR;
    $id = intval($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT * FROM rotulos_fotos WHERE id = ?");
    $st->execute([$id]);
    $f = $st->fetch(PDO::FETCH_ASSOC);
    if (!$f) { falla(404, 'La foto ya no existe.'); return; }
    if ($rol !== ROLE_ADMIN && (int) $f['subido_por'] !== $uid) { falla(403, 'Sólo quien subió la foto o un administrador puede borrarla.'); return; }

    $pdo->prepare("DELETE FROM rotulos_fotos WHERE id = ?")->execute([$id]);
    borrarArchivosFotos([$f], $DIR);
    audit($uid, "Borrar foto del punto de rótulo #{$f['punto_id']}", 'rotulos_fotos', $id);
    echo json_encode(['success' => true]);
}

// ─── Comentarios ─────────────────────────────────────────────────────────────
function comentar() {
    global $pdo, $uid;
    $d = entrada();
    $puntoId = intval($d['punto_id'] ?? 0);
    if (!punto($puntoId)) { falla(404, 'El punto ya no existe.'); return; }
    $txt = texto($d['texto'] ?? '', 2000);
    if (!$txt) { falla(400, 'Escribe el comentario.'); return; }

    $uuid = uuidCliente($d['cliente_uuid'] ?? null);
    if ($uuid) {
        $st = $pdo->prepare("SELECT id FROM rotulos_comentarios WHERE cliente_uuid = ?");
        $st->execute([$uuid]);
        if ($ya = $st->fetchColumn()) { echo json_encode(['success' => true, 'id' => (int) $ya, 'repetido' => true]); return; }
    }
    $pdo->prepare("INSERT INTO rotulos_comentarios (punto_id, texto, cliente_uuid, usuario_id) VALUES (?,?,?,?)")
        ->execute([$puntoId, $txt, $uuid, $uid]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare("UPDATE rotulos_puntos SET updated_at = NOW() WHERE id = ?")->execute([$puntoId]);
    echo json_encode(['success' => true, 'id' => $id]);
}

function eliminarComentario() {
    global $pdo, $uid, $rol;
    $id = intval($_GET['id'] ?? 0);
    $st = $pdo->prepare("SELECT usuario_id FROM rotulos_comentarios WHERE id = ?");
    $st->execute([$id]);
    $autor = $st->fetchColumn();
    if ($autor === false) { falla(404, 'El comentario ya no existe.'); return; }
    if ($rol !== ROLE_ADMIN && (int) $autor !== $uid) { falla(403, 'Sólo quien lo escribió o un administrador puede borrarlo.'); return; }
    $pdo->prepare("DELETE FROM rotulos_comentarios WHERE id = ?")->execute([$id]);
    echo json_encode(['success' => true]);
}

// ─── Pendiente / colocado ────────────────────────────────────────────────────
function cambiarEstado() {
    global $pdo, $uid;
    $d  = entrada();
    $id = intval($d['id'] ?? 0);
    if (!punto($id)) { falla(404, 'El punto ya no existe.'); return; }
    if (($d['estado'] ?? '') === 'colocado') {
        $pdo->prepare("UPDATE rotulos_puntos SET estado = 'colocado', colocado_at = NOW(), colocado_por = ? WHERE id = ?")
            ->execute([$uid, $id]);
    } else {
        $pdo->prepare("UPDATE rotulos_puntos SET estado = 'pendiente', colocado_at = NULL, colocado_por = NULL WHERE id = ?")
            ->execute([$id]);
    }
    audit($uid, "Punto de rótulo #$id: " . (($d['estado'] ?? '') === 'colocado' ? 'colocado' : 'pendiente'), 'rotulos_puntos', $id);
    echo json_encode(['success' => true]);
}

// ─── Borrar un punto completo ────────────────────────────────────────────────
function eliminar() {
    global $pdo, $uid, $rol, $DIR;
    if ($rol !== ROLE_ADMIN) { falla(403, 'Sólo un administrador puede borrar un punto.'); return; }
    $id = intval($_GET['id'] ?? 0);
    if (!punto($id)) { falla(404, 'El punto ya no existe.'); return; }

    $st = $pdo->prepare("SELECT archivo, mini FROM rotulos_fotos WHERE punto_id = ?");
    $st->execute([$id]);
    $fotos = $st->fetchAll(PDO::FETCH_ASSOC);

    $pdo->beginTransaction();
    $pdo->prepare("DELETE FROM rotulos_fotos WHERE punto_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM rotulos_comentarios WHERE punto_id = ?")->execute([$id]);
    $pdo->prepare("DELETE FROM rotulos_puntos WHERE id = ?")->execute([$id]);
    $pdo->commit();
    borrarArchivosFotos($fotos, $DIR);
    audit($uid, "Borrar punto de rótulo #$id", 'rotulos_puntos', $id);
    echo json_encode(['success' => true]);
}
