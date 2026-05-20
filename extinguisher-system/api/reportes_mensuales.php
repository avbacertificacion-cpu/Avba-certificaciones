<?php
require_once '../config/config.php';
header('Content-Type: application/json');

if (!isset($_SESSION['usuario_id'])) {
    http_response_code(401); echo json_encode(['error' => 'No autenticado']); exit;
}

$rol    = $_SESSION['rol'];
$uid    = $_SESSION['usuario_id'];
$action = $_GET['action'] ?? '';

switch ($action) {
    case 'listar':         listar();        break;
    case 'listar_cliente': listarCliente(); break;
    case 'crear':          crear();         break;
    case 'publicar':       publicar();      break;
    case 'despublicar':    despublicar();   break;
    case 'eliminar':       eliminar();      break;
    case 'pdf':            generarPDF();    break;
    default:
        http_response_code(400); echo json_encode(['error' => 'Acción no válida']);
}

// ─── LISTAR (solo admin ve todos los estados) ─────────────────────────────────
function listar() {
    global $pdo, $rol;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $empresa_id = $_GET['empresa_id'] ?? null;
    $where  = $empresa_id ? 'WHERE r.empresa_id = ?' : '';
    $params = $empresa_id ? [$empresa_id] : [];

    $stmt = $pdo->prepare("
        SELECT r.*,
               emp.nombre AS empresa_nombre,
               u.nombre   AS generado_por_nombre
        FROM reportes_mensuales r
        JOIN empresas  emp ON emp.id = r.empresa_id
        JOIN usuarios   u  ON u.id  = r.generado_por
        $where
        ORDER BY r.anio DESC, r.mes DESC
    ");
    $stmt->execute($params);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── LISTAR PARA CLIENTE (solo los que el admin publicó) ──────────────────────
function listarCliente() {
    global $pdo, $rol;

    if ($rol !== ROLE_CLIENTE) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $empresa_id = $_SESSION['empresa_id'];
    if (!$empresa_id) {
        echo json_encode(['success' => true, 'data' => []]); return;
    }

    $stmt = $pdo->prepare("
        SELECT r.id, r.numero_reporte, r.mes, r.anio, r.created_at
        FROM reportes_mensuales r
        WHERE r.empresa_id = ? AND r.estado = 'publicado'
        ORDER BY r.anio DESC, r.mes DESC
    ");
    $stmt->execute([$empresa_id]);
    echo json_encode(['success' => true, 'data' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
}

// ─── CREAR ────────────────────────────────────────────────────────────────────
function crear() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $d = json_decode(file_get_contents('php://input'), true);

    foreach (['empresa_id','mes','anio'] as $c) {
        if (empty($d[$c])) {
            http_response_code(400); echo json_encode(['error' => "Campo requerido: $c"]); return;
        }
    }

    // Número de reporte correlativo por empresa
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM reportes_mensuales WHERE empresa_id = ?");
    $stmt->execute([$d['empresa_id']]);
    $num    = $stmt->fetchColumn() + 1;
    $numero = 'REV-' . str_pad($num, 3, '0', STR_PAD_LEFT);

    $stmt = $pdo->prepare("
        INSERT INTO reportes_mensuales
            (empresa_id, generado_por, mes, anio, numero_reporte, estado, observaciones)
        VALUES (?,?,?,?,?,'borrador',?)
    ");
    $stmt->execute([
        $d['empresa_id'],
        $uid,
        $d['mes'],
        $d['anio'],
        $numero,
        $d['observaciones'] ?? null,
    ]);

    $id = $pdo->lastInsertId();
    audit($uid, "Crear reporte $numero", 'reportes_mensuales', $id);
    echo json_encode(['success' => true, 'id' => $id, 'numero_reporte' => $numero]);
}

// ─── PUBLICAR (hace visible al cliente) ───────────────────────────────────────
function publicar() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $pdo->prepare("UPDATE reportes_mensuales SET estado='publicado' WHERE id=?")->execute([$id]);
    audit($uid, "Publicar reporte", 'reportes_mensuales', $id);
    echo json_encode(['success' => true]);
}

// ─── DESPUBLICAR (ocultar del cliente sin borrar) ─────────────────────────────
function despublicar() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $pdo->prepare("UPDATE reportes_mensuales SET estado='generado' WHERE id=?")->execute([$id]);
    audit($uid, "Despublicar reporte", 'reportes_mensuales', $id);
    echo json_encode(['success' => true]);
}

// ─── ELIMINAR ─────────────────────────────────────────────────────────────────
function eliminar() {
    global $pdo, $rol, $uid;

    if ($rol !== ROLE_ADMIN) {
        http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
    }

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    $pdo->prepare("DELETE FROM reportes_mensuales WHERE id=?")->execute([$id]);
    audit($uid, "Eliminar reporte", 'reportes_mensuales', $id);
    echo json_encode(['success' => true]);
}

// ─── GENERAR / DESCARGAR PDF ──────────────────────────────────────────────────
function generarPDF() {
    global $pdo, $rol;

    $id = intval($_GET['id'] ?? 0);
    if (!$id) { http_response_code(400); echo json_encode(['error' => 'ID requerido']); return; }

    // Cliente solo puede ver reportes publicados de su empresa
    $stmt = $pdo->prepare("
        SELECT r.*, emp.nombre AS empresa_nombre
        FROM reportes_mensuales r
        JOIN empresas   emp ON emp.id = r.empresa_id
        WHERE r.id = ?
    ");
    $stmt->execute([$id]);
    $rep = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$rep) { http_response_code(404); echo json_encode(['error' => 'No encontrado']); return; }

    if ($rol === ROLE_CLIENTE) {
        if ($rep['empresa_id'] != $_SESSION['empresa_id'] || $rep['estado'] !== 'publicado') {
            http_response_code(403); echo json_encode(['error' => 'Sin permiso']); return;
        }
    }

    // TODO: Integrar TCPDF aquí para generar el PDF real
    // Por ahora retornamos los datos para construcción del PDF
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'mensaje' => 'PDF en desarrollo', 'data' => $rep]);
}

// ─── HELPER ──────────────────────────────────────────────────────────────────
function audit($uid, $accion, $tabla, $rid) {
    global $pdo;
    try {
        $pdo->prepare("INSERT INTO auditoria (usuario_id,accion,tabla,registro_id,ip) VALUES (?,?,?,?,?)")
            ->execute([$uid, $accion, $tabla, $rid, $_SERVER['REMOTE_ADDR'] ?? null]);
    } catch (Exception $e) {}
}
